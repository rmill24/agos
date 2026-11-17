<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditEventService
{
    /**
     * Record a business-scoped event for a Team-owned model.
     *
     * The caller must authorize the business operation before recording it.
     * This method still verifies that the target model belongs to the Team.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User $actor,
        Team $team,
        string $eventType,
        ?Model $auditable = null,
        array $before = [],
        array $after = [],
        string $result = 'success',
        array $metadata = [],
    ): AuditEvent {
        abort_unless(
            ! $team->is_personal
                && $team->isOnboardingComplete()
                && $actor->belongsToTeam($team),
            403,
        );

        if ($auditable !== null) {
            abort_unless(
                isset($auditable->team_id) && (int) $auditable->team_id === (int) $team->id,
                403,
            );
        }

        return AuditEvent::create([
            'team_id' => $team->id,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'result' => $result,
            'request_id' => (string) Str::uuid(),
            'before_values' => $before,
            'after_values' => $after,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
