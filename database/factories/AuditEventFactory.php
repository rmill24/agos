<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'actor_id' => User::factory(),
            'event_type' => 'transaction.created',
            'auditable_type' => null,
            'auditable_id' => null,
            'result' => 'success',
            'request_id' => fake()->uuid(),
            'before_values' => null,
            'after_values' => ['amount' => '1000.00'],
            'metadata' => ['source' => 'test'],
            'occurred_at' => now(),
        ];
    }
}
