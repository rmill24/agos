<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(private AuditEventService $auditEvents)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, Team $team, array $attributes): Transaction
    {
        Gate::forUser($actor)->authorize('create', [Transaction::class, $team]);
        $attributes = $this->prepareAttributes($team, $attributes);

        return DB::transaction(function () use ($actor, $team, $attributes): Transaction {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $transaction = $team->transactions()->create([
                ...$attributes,
                'recorded_by' => $actor->id,
            ]);

            $this->auditEvents->record(
                $actor,
                $team,
                'transaction.created',
                $transaction,
                after: $this->auditSnapshot($transaction),
            );

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Team $team, Transaction $transaction, array $attributes): Transaction
    {
        Gate::forUser($actor)->authorize('update', $transaction);
        abort_unless($transaction->belongsToTeam($team), 403);
        $attributes = $this->prepareAttributes($team, $attributes);

        return DB::transaction(function () use ($actor, $team, $transaction, $attributes): Transaction {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $transaction);
            abort_unless($transaction->belongsToTeam($team), 403);
            $before = $this->auditSnapshot($transaction);

            if ($transaction->source_type === 'csv') {
                $attributes['source_type'] = 'csv';
                $attributes['external_id'] = $transaction->external_id;
            }
            $transaction->update($attributes);
            $transaction = $transaction->fresh();

            $this->auditEvents->record(
                $actor,
                $team,
                'transaction.updated',
                $transaction,
                before: $before,
                after: $this->auditSnapshot($transaction),
            );

            return $transaction;
        });
    }

    public function archive(User $actor, Team $team, Transaction $transaction): void
    {
        Gate::forUser($actor)->authorize('delete', $transaction);
        abort_unless($transaction->belongsToTeam($team), 403);

        DB::transaction(function () use ($actor, $team, $transaction): void {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $transaction);
            abort_unless($transaction->belongsToTeam($team), 403);
            $before = $this->auditSnapshot($transaction);
            $transaction->delete();

            $this->auditEvents->record(
                $actor,
                $team,
                'transaction.archived',
                $transaction,
                before: $before,
                after: ['deleted' => true],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function prepareAttributes(Team $team, array $attributes): array
    {
        if (isset($attributes['category_id'])) {
            abort_unless(is_int($attributes['category_id']) || is_string($attributes['category_id']), 403);
            $category = Category::query()->find($attributes['category_id']);
            abort_unless($category !== null && $category->belongsToTeam($team), 403);

            if ($category->type !== 'both' && $category->type !== ($attributes['direction'] ?? null)) {
                throw ValidationException::withMessages([
                    'category_id' => 'The selected category does not apply to this transaction direction.',
                ]);
            }
        }

        if (! in_array($attributes['direction'] ?? null, ['income', 'expense'], true)) {
            throw ValidationException::withMessages([
                'direction' => 'The transaction direction is invalid.',
            ]);
        }

        try {
            $amount = BigDecimal::of((string) ($attributes['amount'] ?? ''))
                ->toScale(2, RoundingMode::Unnecessary)
                ->toString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be a nonnegative decimal with up to two decimal places.',
            ]);
        }

        if (BigDecimal::of($amount)->isNegative()) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be nonnegative.',
            ]);
        }

        return [
            'category_id' => $attributes['category_id'] ?? null,
            'direction' => $attributes['direction'],
            'amount' => $amount,
            'transaction_date' => $attributes['transaction_date'],
            'description' => $attributes['description'],
            'source_type' => $attributes['source_type'] ?? 'manual',
            'source_reference' => $attributes['source_reference'] ?? null,
            'external_id' => $attributes['external_id'] ?? null,
            'currency_code' => 'PHP',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditSnapshot(Transaction $transaction): array
    {
        return [
            'category_id' => $transaction->category_id,
            'direction' => $transaction->direction,
            'amount' => (string) $transaction->amount,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'description' => $transaction->description,
            'source_type' => $transaction->source_type,
            'source_reference' => $transaction->source_reference,
            'external_id' => $transaction->external_id,
            'currency_code' => $transaction->currency_code,
        ];
    }
}
