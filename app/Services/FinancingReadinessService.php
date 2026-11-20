<?php

namespace App\Services;

use App\Models\Obligation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class FinancingReadinessService
{
    /**
     * @return array{asOf: string, since: string, items: list<array{label: string, date: ?string, status: string, detail: string}>, recent: int}
     */
    public function forTeam(User $actor, Team $team): array
    {
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor->fresh() ?? abort(403))->authorize('viewFinancingReadiness', $team);

        $now = now('Asia/Manila')->toImmutable();
        $asOf = $now->toDateString();
        $since = $now->subDays(29)->toDateString();
        $ledger = $team->transactions()->where('transaction_date', '<=', $asOf);
        $documents = Obligation::query()->whereBelongsTo($team)->where('issue_date', '<=', $asOf);
        $receipts = (clone $ledger)->where('source_type', 'loyverse')
            ->whereIn('source_reference', $team->loyverseReceipts()->select('receipt_number'));

        $latest = (clone $ledger)->max('transaction_date');
        $opening = $team->opening_cash_balance_date?->toDateString();
        $verified = $team->opening_cash_balance_verified_at !== null && $team->opening_cash_balance !== null
            && $opening !== null && $opening <= $asOf;

        $sources = [
            ['Transaction records', $latest, 'Latest posted transaction date'],
            ['Income', (clone $ledger)->where('direction', 'income')->max('transaction_date'), 'Latest income entry in Transactions'],
            ['Expenses', (clone $ledger)->where('direction', 'expense')->max('transaction_date'), 'Latest expense entry in Transactions'],
            ['Customer invoices', (clone $documents)->where('kind', 'invoice')->max('issue_date'), 'Latest invoice issue date'],
            ['Supplier obligations', (clone $documents)->where('kind', 'bill')->max('issue_date'), 'Latest supplier bill issue date'],
            ['Cash-flow summary', $verified ? max($opening, $latest) : $latest, 'Latest input to the dashboard cash position (opening balance or posted movement)'],
            ['Supporting receipts', $receipts->max('transaction_date'), 'Latest movement date backed by a published Loyverse receipt; external documents are outside this check'],
        ];
        $items = [];
        foreach ($sources as [$label, $date, $source]) {
            $status = match (true) {
                $date === null => 'Missing',
                $date < $since => 'Out of date',
                default => 'Recent',
            };
            $detail = match ($status) {
                'Missing' => "$source. No matching record is available through the assessment date.",
                'Out of date' => "$source. The record predates the review window.",
                default => "$source.",
            };
            if ($label === 'Cash-flow summary' && ! $verified) {
                $status = 'Incomplete';
                $detail = 'The dashboard needs a verified opening balance effective by the assessment date to establish current cash.';
            }
            $items[] = compact('label', 'date', 'status', 'detail');
        }

        return ['asOf' => $asOf, 'since' => $since, 'items' => $items, 'recent' => count(array_filter($items, fn ($item) => $item['status'] === 'Recent'))];
    }
}
