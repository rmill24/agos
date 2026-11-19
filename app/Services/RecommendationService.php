<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\Obligation;
use App\Models\Team;
use App\Models\User;
use App\Support\RecommendationPriority;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class RecommendationService
{
    public function __construct(private CashFlowService $cashFlow) {}

    /**
     * Read-only, deterministic advisory snapshot. References resolve only in
     * this response's evidence map; regenerate and reauthorize before display.
     * No caller-supplied balances, scenarios, records or evidence are accepted.
     *
     * @return array<string, mixed>
     */
    public function forTeam(User $actor, Team $team, int $days = 30): array
    {
        $actor = $actor->fresh() ?? abort(403);
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor)->authorize('viewRecommendations', $team);
        Validator::make(['days' => $days], ['days' => 'integer|in:7,14,30,60,90'])->validate();
        $asOf = CarbonImmutable::now('Asia/Manila')->startOfDay();
        $flow = $this->cashFlow->forTeam($actor, $team, null, $asOf, $days);
        $limitations = [...$flow['limitations'], 'Advisory only; no payment, collection, saving or negotiated change is guaranteed.'];
        $limitations[] = 'Forecasts use recorded dates; overdue obligations are not rescheduled into the cash forecast.';
        if (array_any($flow['items'], fn (array $item): bool => $item['classification'] === 'uncertain_collection')) {
            $limitations[] = 'The forecast includes uncertain invoice collections on their due dates; these are not confirmed cash and may not arrive.';
        }
        if (ImportBatch::query()->where('team_id', $team->id)->whereNotNull('committed_at')->where('incomplete_history', true)->exists()) {
            $limitations[] = 'Committed CSV imports include incomplete history; a verified opening balance does not verify all source records.';
        }
        $latestSync = $team->loyverseSyncs()->latest('id')->first();
        $lastSuccess = $team->loyverseSyncs()->where('status', 'complete')->latest('id')->first();
        $limitations[] = 'Freshness reflects recorded synchronization status and timestamps; manual records and elapsed sync age are not independently verified.';
        $limitations = array_values(array_unique($limitations));
        $calculationId = 'team:'.$team->id.':cash_flow:'.$flow['asOf'].':'.$flow['periodEnd'];
        $evidence = [$calculationId => [
            'type' => 'calculation', 'team_id' => $team->id, 'service' => 'CashFlowService',
            'result' => $flow,
        ]];
        $recommendations = [];
        $obligations = Obligation::query()->where('team_id', $team->id)
            ->whereIn('kind', ['invoice', 'bill'])->where('due_date', '<=', $flow['periodEnd'])
            ->where(fn ($query) => $query->where('kind', 'bill')->orWhere('due_date', '<=', $flow['asOf']))
            ->with('payments.transaction')->orderBy('id')->get();
        foreach ($obligations as $obligation) {
            $balance = $obligation->balance();
            if (! $balance->isPositive()) {
                continue;
            }
            $overdue = $obligation->due_date < $flow['asOf'];
            $invoice = $obligation->kind === 'invoice';
            $reference = 'team:'.$team->id.':'.$obligation->kind.':'.$obligation->id;
            $amount = $balance->toScale(2)->toString();
            $reason = ucfirst($obligation->kind).($overdue ? ' has an overdue balance.' : ($obligation->due_date === $flow['asOf'] ? ' has a balance due today.' : ' has an upcoming balance within the forecast period.'));
            $evidence[$reference] = [
                'type' => 'record', 'team_id' => $team->id, 'kind' => $obligation->kind,
                'record_id' => $obligation->id, 'amount' => $amount, 'due_date' => $obligation->due_date,
                'calculation' => 'Obligation::balance', 'updated_at' => $obligation->updated_at?->toIso8601String(),
            ];
            $recommendations[] = [
                'id' => $reference, 'rule' => $invoice ? 'collection_review' : 'supplier_obligation',
                'action' => $invoice ? 'Follow up on collection' : 'Review supplier payment',
                'priority' => $overdue ? 'High' : 'Normal', 'reason' => $reason, 'rationale' => $reason,
                'amount' => $amount, 'amounts' => ['outstanding' => $amount],
                'document' => $obligation->number ?? $obligation->description ?? '#'.$obligation->id,
                'due_date' => $obligation->due_date,
                'evidence' => ['team_id' => $team->id, 'kind' => $obligation->kind, 'obligation_id' => $obligation->id],
                'evidence_references' => [$reference], 'cash_effect' => null, 'advisory' => true,
                'limitations' => [...$limitations, $invoice ? 'Outstanding receivables are uncertain collections, not confirmed cash.' : 'Review the obligation and agree any payment change with the supplier; no deferral is assumed.'],
            ];
        }

        // Select existing service results; never rebuild cash, forecasts or gaps.
        $negativeDay = null;
        $thresholdDay = null;
        foreach ($flow['daily'] as $day) {
            if ($negativeDay === null && $day['projectedCash'] !== null && BigDecimal::of($day['projectedCash'])->isNegative()) {
                $negativeDay = $day;
            }
            if ($day['date'] === $flow['earliestShortfallDate']) {
                $thresholdDay = $day;
            }
        }
        if ($negativeDay !== null || $flow['earliestShortfallDate'] !== null) {
            $negative = $negativeDay !== null;
            $date = $negative ? $negativeDay['date'] : $flow['earliestShortfallDate'];
            $reason = $negative
                ? 'Recorded cash-flow inputs project a negative cash balance; review collections and payment commitments before making new commitments.'
                : 'Projected cash falls below the configured cash-safety threshold; review discretionary commitments and payment timing.';
            $recommendations[] = [
                'id' => $calculationId.($negative ? ':negative_cash' : ':threshold_risk'),
                'rule' => $negative ? 'negative_cash' : 'threshold_risk',
                'action' => $negative ? 'Review projected cash shortfall' : 'Protect the cash-safety threshold',
                'priority' => $negative ? 'Critical' : 'High', 'reason' => $reason, 'rationale' => $reason,
                'amount' => $negative ? BigDecimal::of($negativeDay['projectedCash'])->abs()->toScale(2)->toString() : $flow['cashGap'],
                'amounts' => ['projected_cash' => $negative ? $negativeDay['projectedCash'] : $thresholdDay['projectedCash'], 'threshold' => $flow['threshold'], 'maximum_threshold_gap' => $flow['threshold'] === null ? null : $flow['cashGap']],
                'dates' => ['first_negative_cash' => $negativeDay['date'] ?? null, 'first_threshold_shortfall' => $flow['earliestShortfallDate']],
                'due_date' => $date, 'evidence_references' => [$calculationId],
                'cash_effect' => null, 'advisory' => true, 'limitations' => $limitations,
            ];
        }
        usort($recommendations, RecommendationPriority::compare(...));

        return [
            'mode' => 'deterministic', 'team_id' => $team->id, 'currency' => 'PHP',
            'generated_at' => now()->toIso8601String(), 'as_of' => $flow['asOf'], 'period_end' => $flow['periodEnd'],
            'status' => $recommendations === [] ? 'no_supported_action' : 'supported_actions',
            'message' => $recommendations === [] ? 'No action identified from available evidence; this is not an assurance of cash safety.' : 'Review these evidence-supported advisory actions.',
            'recommendations' => $recommendations, 'evidence' => $evidence, 'limitations' => $limitations,
            'history_complete' => $flow['historyComplete'],
            'freshness' => ['latest_sync_status' => $latestSync?->status, 'last_successful_sync_at' => $lastSuccess?->completed_at?->toIso8601String()],
        ];
    }

    /**
     * Compatibility projection for the existing obligation-only UI. P5-T02
     * can consume forTeam() and render calculation evidence and limitations.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(User $actor, Team $team): array
    {
        $result = $this->forTeam($actor, $team);

        return array_values(array_filter($result['recommendations'], fn (array $item): bool => isset($item['evidence']) && $item['due_date'] <= $result['as_of']));
    }
}
