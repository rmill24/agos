<?php

namespace App\Services;

use App\Data\LoyverseReceiptIssue;
use App\Jobs\SyncLoyversePage;
use App\Models\LoyverseReceipt;
use App\Models\LoyverseSync;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class LoyverseSyncService
{
    public function __construct(private LoyverseReceiptAdapter $adapter, private LoyverseReconciliation $reconciliation) {}

    public function start(User $actor, Team $team): void
    {
        DB::transaction(function () use ($actor, $team) {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            Gate::forUser($actor)->authorize('syncLoyverse', $team);
            $run = $team->loyverseSyncs()->latest('id')->first();
            $mapping = $this->mapping($team);
            if ($run && $run->status !== 'complete' && $run->source_mapping === $mapping) {
                if ($run->status === 'queued' && $run->updated_at?->gt(now()->subMinutes(3))) {
                    return;
                }
                $run->update(['status' => 'queued', 'failure' => null, 'attempt' => $run->attempt + 1, 'user_id' => $actor->id]);
            } else {
                $previous = $team->loyverseSyncs()->where('status', 'complete')->latest('id')->first();
                $from = $previous && $previous->source_mapping === $mapping ? $previous->window_end->subSecond() : CarbonImmutable::createFromTimestampUTC(0);
                if ($run && $run->status !== 'complete') {
                    $run->update(['status' => 'incomplete', 'failure' => 'The source mapping changed.']);
                }
                $run = $team->loyverseSyncs()->create([
                    'user_id' => $actor->id, 'status' => 'queued', 'source_mapping' => $mapping,
                    'window_start' => $from, 'window_end' => now(), 'attempt' => 1, 'pages' => 0,
                ]);
            }
            app(AuditEventService::class)->record($actor, $team, 'loyverse.sync.requested', $run);
            $this->enqueue($run);
        });
    }

    private function mapping(Team $team): string
    {
        $connection = config("loyverse.connections.{$team->id}", []);

        return hash('sha256', serialize([$connection['merchant'] ?? null, $connection['store'] ?? null, $connection['collected_methods'] ?? []]));
    }

    private function enqueue(LoyverseSync $run): void
    {
        SyncLoyversePage::dispatch($run->team_id, $run->id, $run->attempt, $run->pages)->onConnection('database');
    }

    public function process(int $teamId, int $syncId, int $attempt, int $page): void
    {
        try {
            DB::transaction(function () use ($teamId, $syncId, $attempt, $page) {
                $team = Team::query()->lockForUpdate()->findOrFail($teamId);
                $run = $team->loyverseSyncs()->findOrFail($syncId);
                if ($run->attempt !== $attempt || $run->pages !== $page || $run->status !== 'queued') {
                    return;
                }
                $actor = $run->user()->firstOrFail();
                Gate::forUser($actor)->authorize('syncLoyverse', $team);
                if ($run->source_mapping !== $this->mapping($team)) {
                    throw new RuntimeException('The source mapping changed.');
                }
                $cursor = $this->adapter->stagePage($actor, $team, $run->window_start, $run->window_end, $run->cursor,
                    function (LoyverseReceipt $receipt) use ($run, $team) {
                        abort_unless($receipt->belongsToTeam($team), 403);
                        $result = $this->reconciliation->evaluate($receipt, config("loyverse.connections.{$team->id}.collected_methods", []));
                        $run->entries()->updateOrCreate(['receipt_number' => $receipt->receipt_number], $result + ['reason' => null]);
                    },
                    function (LoyverseReceiptIssue $issue) use ($run) {
                        $number = $issue->receiptNumber;
                        $data = ['receipt_number' => $number, 'reason' => $issue->reason, 'business_date' => null, 'movements' => null];
                        if ($number === null) {
                            $run->entries()->create($data);
                        } else {
                            $run->entries()->updateOrCreate(['receipt_number' => $number], $data);
                        }
                    });
                $visited = $run->cursor_history ?? [];
                if ($cursor !== null) {
                    $fingerprint = hash('sha256', $cursor);
                    if (in_array($fingerprint, $visited, true)) {
                        throw new DomainException('Invalid Loyverse continuation cursor.');
                    }
                    $visited[] = $fingerprint;
                }
                $run->update(['pages' => $page + 1, 'cursor' => $cursor, 'cursor_history' => $visited]);
                if ($cursor !== null) {
                    $this->enqueue($run);

                    return;
                }
                $this->publish($run, $team);
                $run->update(['status' => 'complete', 'completed_at' => now()]);
                app(AuditEventService::class)->record($actor, $team, 'loyverse.sync.completed', $run);
            });
        } catch (Throwable $error) {
            // The failed page has rolled back. Only this worker's checkpoint may change.
            DB::transaction(function () use ($teamId, $syncId, $attempt, $page, $error) {
                $run = LoyverseSync::query()->where('team_id', $teamId)->whereKey($syncId)
                    ->where('attempt', $attempt)->where('pages', $page)->where('status', 'queued')->lockForUpdate()->first();
                if (! $run) {
                    return;
                }
                $failure = 'The page could not be verified. Check the store connection and retry.';
                if ($error instanceof DomainException && $error->getMessage() === 'Invalid Loyverse continuation cursor.') {
                    $run->entries()->delete();
                    $run->fill(['cursor' => null, 'pages' => 0, 'cursor_history' => []]);
                    $failure = 'The cursor expired. Retry to replay the same update window.';
                }
                $run->fill(['status' => 'incomplete', 'failure' => $failure])->save();
            });
        }
    }

    private function publish(LoyverseSync $run, Team $team): void
    {
        foreach ($run->entries()->lazyById() as $entry) {
            if ($entry->receipt_number === null) {
                continue;
            }
            $existing = $team->transactions()->where('source_type', 'loyverse')->where('source_reference', $entry->receipt_number);
            $keep = [];
            foreach ($entry->movements ?? [] as $index => $movement) {
                $key = hash('sha256', $entry->receipt_number.':'.$index);
                $record = (clone $existing)->withTrashed()->firstOrNew(['external_id' => $key]);
                $record->fill($movement + [
                    'source_type' => 'loyverse', 'source_reference' => $entry->receipt_number,
                    'description' => 'Loyverse receipt '.$entry->receipt_number,
                    'transaction_date' => $entry->business_date, 'currency_code' => 'PHP', 'recorded_by' => $run->user_id,
                ]);
                $record->deleted_at = null;
                $record->save();
                $keep[] = $key;
            }
            $existing->whereNotIn('external_id', $keep)->delete();
        }
    }
}
