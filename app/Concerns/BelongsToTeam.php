<?php

namespace App\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTeam
{
    /**
     * Restrict a query to one authorized business Team.
     *
     * The caller must authorize the Team separately; this scope never treats
     * the current Team selector as proof of access.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTeam(Builder $query, Team|int $team): Builder
    {
        $teamId = $team instanceof Team ? $team->getKey() : $team;

        return $query->where($query->getModel()->qualifyColumn('team_id'), $teamId);
    }

    /**
     * Determine whether the record belongs to the given Team.
     */
    public function belongsToTeam(Team|int $team): bool
    {
        $teamId = $team instanceof Team ? $team->getKey() : $team;

        return (int) $this->team_id === (int) $teamId;
    }
}
