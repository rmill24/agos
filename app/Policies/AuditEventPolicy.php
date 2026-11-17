<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;

class AuditEventPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $team->isOnboardingComplete()
            && $user->ownsTeam($team);
    }

    public function view(User $user, AuditEvent $event): bool
    {
        return $event->team !== null && $this->viewAny($user, $event->team);
    }

    public function create(User $user, ?Team $team = null): bool
    {
        return false;
    }

    public function update(User $user, AuditEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, AuditEvent $event): bool
    {
        return false;
    }
}
