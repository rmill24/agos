<?php

namespace App\Services;

use App\Models\Obligation;
use App\Models\Team;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class CashFlowService
{
    /**
     * Build a deterministic cash position and short-term forecast for a Team.
     *
     * An opening balance is deliberately an explicit input. A null opening
     * balance means absolute cash and threshold crossings cannot be claimed.
     *
     * @return array<string, mixed>
     */
    public function forTeam(User $actor, Team $team, ?string $openingBalance = null, ?CarbonImmutable $asOf = null, int $days = 30): array
    {
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor)->authorize('viewDashboard', $team);
        abort_if($team->is_personal, 403);

        $asOf ??= CarbonImmutable::now($team->timezone ?: 'Asia/Manila')->startOfDay();
        $days = max(1, min($days, 366));
        $end = $asOf->addDays($days - 1);
        $zero = BigDecimal::of('0.00');
        $inflow = $zero;
        $outflow = $zero;
        $futureItems = [];
        $historyStart = $asOf->subDays($days - 1);
        $history = [];
        for ($date = $historyStart; $date <= $asOf; $date = $date->addDay()) {
            $history[$date->toDateString()] = ['date' => $date->toDateString(), 'inflow' => $zero, 'outflow' => $zero, 'net' => $zero];
        }

        $transactions = $team->transactions()
            ->whereDate('transaction_date', '<=', $asOf->toDateString())
            ->orderBy('transaction_date')->orderBy('id')->get();
        foreach ($transactions as $transaction) {
            if ($transaction->direction === 'income') {
                $inflow = $inflow->plus($transaction->amountAsBigDecimal());
                if (isset($history[$transaction->transaction_date->toDateString()])) {
                    $history[$transaction->transaction_date->toDateString()]['inflow'] = $history[$transaction->transaction_date->toDateString()]['inflow']->plus($transaction->amountAsBigDecimal());
                    $history[$transaction->transaction_date->toDateString()]['net'] = $history[$transaction->transaction_date->toDateString()]['net']->plus($transaction->amountAsBigDecimal());
                }
            } else {
                $outflow = $outflow->plus($transaction->amountAsBigDecimal());
                if (isset($history[$transaction->transaction_date->toDateString()])) {
                    $history[$transaction->transaction_date->toDateString()]['outflow'] = $history[$transaction->transaction_date->toDateString()]['outflow']->plus($transaction->amountAsBigDecimal());
                    $history[$transaction->transaction_date->toDateString()]['net'] = $history[$transaction->transaction_date->toDateString()]['net']->minus($transaction->amountAsBigDecimal());
                }
            }
        }

        $futureTransactions = $team->transactions()
            ->whereDate('transaction_date', '>', $asOf->toDateString())
            ->whereDate('transaction_date', '<=', $end->toDateString())
            ->orderBy('transaction_date')->orderBy('id')->get();
        foreach ($futureTransactions as $transaction) {
            $futureItems[] = $this->item($transaction->transaction_date->toDateString(), $transaction->amountAsBigDecimal(), $transaction->direction === 'income' ? 'confirmed_inflow' : 'confirmed_outflow', 'transaction', $transaction->id, $transaction->description);
        }

        $obligations = Obligation::query()->where('team_id', $team->id)->with('payments.transaction')->get();
        foreach ($obligations as $obligation) {
            $balance = $obligation->balance();
            if (! $balance->isPositive() || $obligation->due_date < $asOf->toDateString() || $obligation->due_date > $end->toDateString()) {
                continue;
            }
            $isInvoice = $obligation->kind === 'invoice';
            $futureItems[] = $this->item(
                (string) $obligation->due_date,
                $balance,
                $isInvoice ? 'uncertain_collection' : 'expected_outflow',
                $isInvoice ? 'invoice' : 'bill',
                $obligation->id,
                $obligation->number ?: $obligation->description
            );
        }

        $confirmedMovement = $inflow->minus($outflow);
        $opening = $openingBalance === null ? null : BigDecimal::of($openingBalance)->toScale(2);
        $currentCash = $opening?->plus($confirmedMovement);
        $threshold = $team->cash_safety_threshold === null ? null : BigDecimal::of($team->cash_safety_threshold);
        $daily = [];
        $running = $currentCash;
        $runningMovement = $confirmedMovement;
        $earliestShortfall = null;
        $cashGap = $zero;
        $limitations = [];
        if ($opening === null) {
            $limitations[] = 'Absolute cash and threshold crossings require a verified opening balance or complete cash history.';
        }
        if ($threshold === null) {
            $limitations[] = 'Configure a cash-safety threshold to identify projected shortfalls.';
        }
        $latestSync = $team->loyverseSyncs()->latest('id')->first();
        if ($latestSync !== null && $latestSync->status !== 'complete') {
            $limitations[] = 'The latest Loyverse synchronization is incomplete; previously published records may be stale.';
        }

        for ($date = $asOf; $date <= $end; $date = $date->addDay()) {
            $day = $date->toDateString();
            $dayItems = array_values(array_filter($futureItems, fn (array $item): bool => $item['date'] === $day));
            $dayNet = $zero;
            $confirmedInflow = $zero;
            $confirmedOutflow = $zero;
            $expectedInflow = $zero;
            $expectedOutflow = $zero;
            $uncertainInflow = $zero;
            $optionalOutflow = $zero;
            foreach ($dayItems as $item) {
                $amount = BigDecimal::of($item['amount']);
                if ($item['classification'] === 'confirmed_inflow') {
                    $confirmedInflow = $confirmedInflow->plus($amount);
                } elseif ($item['classification'] === 'confirmed_outflow') {
                    $confirmedOutflow = $confirmedOutflow->plus($amount);
                } elseif ($item['classification'] === 'expected_inflow') {
                    $expectedInflow = $expectedInflow->plus($amount);
                } elseif ($item['classification'] === 'expected_outflow') {
                    $expectedOutflow = $expectedOutflow->plus($amount);
                } elseif ($item['classification'] === 'uncertain_collection') {
                    $uncertainInflow = $uncertainInflow->plus($amount);
                } elseif ($item['classification'] === 'optional_outflow') {
                    $optionalOutflow = $optionalOutflow->plus($amount);
                }
                $dayNet = $item['direction'] === 'inflow' ? $dayNet->plus($amount) : $dayNet->minus($amount);
            }
            if ($date->isSameDay($asOf)) {
                $dayNet = $zero;
            }
            $runningMovement = $runningMovement->plus($dayNet);
            if ($running !== null) {
                $running = $running->plus($dayNet);
                if ($threshold !== null && $running->isLessThan($threshold)) {
                    $earliestShortfall ??= $day;
                    $gap = $threshold->minus($running);
                    if ($gap->isGreaterThan($cashGap)) {
                        $cashGap = $gap;
                    }
                }
            }
            $daily[] = [
                'date' => $day,
                'netMovement' => $this->money($dayNet),
                'projectedMovement' => $this->money($runningMovement),
                'projectedCash' => $running === null ? null : $this->money($running),
                'confirmedInflow' => $this->money($confirmedInflow),
                'confirmedOutflow' => $this->money($confirmedOutflow),
                'expectedInflow' => $this->money($expectedInflow),
                'expectedOutflow' => $this->money($expectedOutflow),
                'uncertainInflow' => $this->money($uncertainInflow),
                'optionalOutflow' => $this->money($optionalOutflow),
                'items' => $dayItems,
            ];
        }

        return [
            'asOf' => $asOf->toDateString(),
            'periodEnd' => $end->toDateString(),
            'confirmed' => [
                'inflow' => $this->money($inflow),
                'outflow' => $this->money($outflow),
                'netMovement' => $this->money($confirmedMovement),
            ],
            'openingBalance' => $opening === null ? null : $this->money($opening),
            'currentCash' => $currentCash === null ? null : $this->money($currentCash),
            'threshold' => $threshold === null ? null : $this->money($threshold),
            'historyComplete' => $opening !== null,
            'limitations' => $limitations,
            'items' => $futureItems,
            'confirmedHistory' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'inflow' => $this->money($day['inflow']),
                'outflow' => $this->money($day['outflow']),
                'net' => $this->money($day['net']),
            ], array_values($history)),
            'daily' => $daily,
            'earliestShortfallDate' => $earliestShortfall,
            'cashGap' => $this->money($cashGap),
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $date, BigDecimal $amount, string $classification, string $sourceType, int $sourceId, ?string $description): array
    {
        return [
            'date' => $date,
            'amount' => $this->money($amount),
            'direction' => str_contains($classification, 'inflow') || $classification === 'uncertain_collection' ? 'inflow' : 'outflow',
            'classification' => $classification,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'description' => $description,
        ];
    }

    private function money(BigDecimal $amount): string
    {
        return $amount->toScale(2)->toString();
    }
}
