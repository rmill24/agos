<?php

namespace App\Services;

use App\Models\Obligation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RecommendationService
{
    /**
     * @return list<array{action: string, priority: string, reason: string, document: string, amount: string, due_date: string, evidence: array{team_id: int, kind: string, obligation_id: int}}>
     */
    public function candidates(User $actor, Team $team): array
    {
        Gate::forUser($actor)->authorize('viewRecommendations', $team->fresh());

        $today = now('Asia/Manila')->toDateString();
        $obligations = Obligation::query()->where('team_id', $team->id)
            ->whereIn('kind', ['invoice', 'bill'])->where('due_date', '<=', $today)
            ->with('payments.transaction')->orderBy('due_date')->orderBy('id')->get();

        $candidates = [];
        foreach ($obligations as $obligation) {
            $balance = $obligation->balance();
            if (! $balance->isPositive()) {
                continue;
            }

            $overdue = $obligation->due_date < $today;
            $candidates[] = [
                'action' => $obligation->kind === 'invoice' ? 'Follow up on collection' : 'Review supplier payment',
                'priority' => $overdue ? 'High' : 'Normal',
                'reason' => ucfirst($obligation->kind).($overdue ? ' has an overdue balance.' : ' has a balance due today.'),
                'amount' => (string) $balance,
                'document' => $obligation->number ?? $obligation->description ?? '#'.$obligation->id,
                'due_date' => $obligation->due_date,
                'evidence' => ['team_id' => $team->id, 'kind' => $obligation->kind, 'obligation_id' => $obligation->id],
            ];
        }

        return $candidates;
    }
}
