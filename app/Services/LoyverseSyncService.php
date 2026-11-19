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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LoyverseSyncService
{
    public function __construct(private LoyverseReceiptAdapter $adapter, private LoyverseReconciliation $reconciliation, private LoyverseConnectionService $connections, private LoyverseCategoryService $categories) {}

    public function start(User $actor, Team $team): void
    {
        DB::transaction(function () use ($actor, $team) {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            Gate::forUser($actor)->authorize('syncLoyverse', $team);
            $this->categories->resolve($team);
            $this->backfillUncategorized($team);
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
        $connection = $this->connections->resolve($team);

        return hash('sha256', serialize([$connection['merchant'] ?? null, $connection['store'] ?? null, $connection['collected_methods']]));
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
                        $result = $this->reconciliation->evaluate($receipt, $this->connections->resolve($team)['collected_methods']);
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
                $reference = (string) Str::uuid();
                [$failureCode, $failure] = $this->failureDetails($error, $reference);
                Log::warning('Loyverse receipt sync page failed.', [
                    'team_id' => $teamId,
                    'sync_id' => $syncId,
                    'attempt' => $attempt,
                    'page' => $page,
                    'failure_code' => $failureCode,
                    'failure_reference' => $reference,
                    'exception' => $error::class,
                    'detail' => $this->safeDiagnosticDetail($error),
                ]);
                if ($error instanceof DomainException && $error->getMessage() === 'Invalid Loyverse continuation cursor.') {
                    $run->entries()->delete();
                    $run->fill(['cursor' => null, 'pages' => 0, 'cursor_history' => []]);
                    $failure = 'The cursor expired. Retry to replay the same update window.';
                    $failureCode = 'invalid_cursor';
                }
                $run->fill([
                    'status' => 'incomplete', 'failure' => $failure,
                    'failure_code' => $failureCode, 'failure_reference' => $reference,
                ])->save();
            });
        }
    }

    /** @return array{0: string, 1: string} */
    private function failureDetails(Throwable $error, string $reference): array
    {
        $message = $error->getMessage();
        if ($error instanceof DomainException && $message === 'Invalid Loyverse continuation cursor.') {
            return ['invalid_cursor', 'The Loyverse continuation cursor expired. Retry to replay the same receipt window.'];
        }
        if (str_contains($message, 'HTTP 401') || str_contains($message, 'HTTP 403')) {
            return ['provider_access', 'Loyverse rejected the access token or its receipt permissions. Reconnect the store and retry.'];
        }
        if (str_contains($message, 'HTTP 400')) {
            preg_match('/HTTP 400(?:,\s*([A-Z_]+))?/', $message, $matches);
            $providerCode = $matches[1] ?? null;
            $status = $providerCode ? 'HTTP 400, '.$providerCode : 'HTTP 400';

            return ['provider_request', "Loyverse rejected the receipt request ({$status}). Reconnect the store and retry."];
        }
        if (str_contains($message, 'HTTP 404') || str_contains($message, 'different store')) {
            return ['invalid_store', 'The selected Loyverse store could not be verified. Reconnect and choose the store again.'];
        }
        if (preg_match('/HTTP 5(?:00|02|03)/', $message) === 1) {
            return ['provider_unavailable', 'Loyverse is temporarily unavailable. Wait a moment and retry.'];
        }
        if (str_contains($message, 'HTTP 429')) {
            return ['rate_limited', 'Loyverse temporarily rate-limited the request. Wait a moment and retry.'];
        }
        if (str_contains($message, 'could not be reached')) {
            return ['provider_unavailable', 'Loyverse could not be reached. Check the connection and retry.'];
        }
        if (str_contains($message, 'Invalid Loyverse') || str_contains($message, 'receipt page') || str_contains($message, 'required peso amount') || str_contains($message, 'effective date')) {
            return ['invalid_receipt', 'Loyverse returned receipt data AGOS could not validate. No financial records were changed; review the store data and retry.'];
        }
        if (str_contains($message, 'source mapping changed')) {
            return ['configuration_changed', 'The Loyverse connection changed during synchronization. Retry the sync with the current store connection.'];
        }

        return ['unexpected', 'The Loyverse sync encountered an unexpected verification error. No financial records were changed. Reference: '.$reference];
    }

    private function safeDiagnosticDetail(Throwable $error): string
    {
        $message = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [redacted]', $error->getMessage()) ?? '';
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 300);
    }

    private function publish(LoyverseSync $run, Team $team): void
    {
        $categories = $this->categories->resolve($team);
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
                    'category_id' => $categories[$movement['direction']]->id,
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

    private function backfillUncategorized(Team $team): void
    {
        $categories = $this->categories->resolve($team);
        foreach (['income', 'expense'] as $direction) {
            $team->transactions()
                ->where('source_type', 'loyverse')
                ->where('direction', $direction)
                ->whereNull('category_id')
                ->update(['category_id' => $categories[$direction]->id]);
        }
    }
}
