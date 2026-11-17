<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $team_id
 * @property int|null $actor_id
 * @property string $event_type
 * @property string|null $auditable_type
 * @property int|string|null $auditable_id
 * @property string $result
 * @property string|null $request_id
 * @property array<string, mixed>|null $before_values
 * @property array<string, mixed>|null $after_values
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 * @property-read User|null $actor
 * @property-read Model|null $auditable
 */
#[Fillable([
    'team_id',
    'actor_id',
    'event_type',
    'auditable_type',
    'auditable_id',
    'result',
    'request_id',
    'before_values',
    'after_values',
    'metadata',
    'occurred_at',
])]
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * Get the Team associated with the event.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who caused the event.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the audited model, when the event targets one.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
