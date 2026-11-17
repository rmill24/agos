<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Category;
use App\Models\Team;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $this->canManageCategories($user, $team);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->canManageCategories($user, $category->team);
    }

    public function create(User $user, Team $team): bool
    {
        return $this->canManageCategories($user, $team);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->canManageCategories($user, $category->team);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->canManageCategories($user, $category->team);
    }

    protected function canManageCategories(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $team->isOnboardingComplete()
            && $user->belongsToTeam($team)
            && $user->hasTeamPermission($team, TeamPermission::ManageFinancialRecords);
    }
}
