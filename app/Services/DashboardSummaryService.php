<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Gate;

class DashboardSummaryService
{
    public function __construct(private RecommendationService $recommendations, private CashFlowService $cashFlow) {}

    /** @return array<string, mixed> */
    public function forTeam(User $actor, Team $team, int $forecastDays = 30): array
    {
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor)->authorize('viewDashboard', $team);
        abort_if($team->is_personal, 403);

        $today = now('Asia/Manila');
        $entries = $team->transactions()
            ->where('transaction_date', '>=', $today->copy()->startOfMonth()->toDateString())
            ->where('transaction_date', '<', $today->copy()->addDay()->toDateString())
            ->orderByDesc('transaction_date')->orderByDesc('id');
        $income = $expense = BigDecimal::of('0.00');
        $recent = [];
        foreach ($entries->cursor() as $entry) {
            if ($entry->direction === 'income') {
                $income = $income->plus($entry->amountAsBigDecimal());
            } else {
                $expense = $expense->plus($entry->amountAsBigDecimal());
            }
            if (count($recent) < 5) {
                $recent[] = $entry;
            }
        }

        $net = $income->minus($expense);
        $actions = Gate::forUser($actor)->allows('viewRecommendations', $team)
            ? array_slice($this->recommendations->candidates($actor, $team), 0, 3) : [];
        $latestSync = $team->loyverseSyncs()->latest('id')->first();
        $lastSuccess = $team->loyverseSyncs()->where('status', 'complete')->latest('id')->first();
        $cashFlow = $this->cashFlow->forTeam($actor, $team, null, days: $forecastDays);

        return compact('team', 'today', 'income', 'expense', 'net', 'recent', 'actions', 'latestSync', 'lastSuccess', 'cashFlow', 'forecastDays');
    }
}
