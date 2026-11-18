<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $this->canManageFinancialRecords($user, $team);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->canManageFinancialRecords($user, $transaction->team);
    }

    public function create(User $user, Team $team): bool
    {
        return $this->canManageFinancialRecords($user, $team);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return ! in_array($transaction->source_type, ['payment', 'loyverse'], true) && $this->canManageFinancialRecords($user, $transaction->team);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return ! in_array($transaction->source_type, ['payment', 'loyverse'], true) && $this->canManageFinancialRecords($user, $transaction->team);
    }

    protected function canManageFinancialRecords(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $team->isOnboardingComplete()
            && $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageFinancialRecords);
    }
}
