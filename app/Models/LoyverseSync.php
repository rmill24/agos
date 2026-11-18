<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $window_start
 * @property CarbonImmutable $window_end
 * @property CarbonImmutable|null $completed_at
 * @property list<string>|null $cursor_history
 */
class LoyverseSync extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['cursor', 'source_mapping'];

    protected function casts(): array
    {
        return ['cursor_history' => 'array', 'window_start' => 'immutable_datetime', 'window_end' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LoyverseSyncEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LoyverseSyncEntry::class);
    }
}
