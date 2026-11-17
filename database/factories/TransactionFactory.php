<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'category_id' => null,
            'recorded_by' => User::factory(),
            'direction' => 'income',
            'amount' => '1000.00',
            'transaction_date' => now()->toDateString(),
            'description' => fake()->sentence(),
            'source_type' => 'manual',
            'source_reference' => null,
            'external_id' => null,
            'currency_code' => 'PHP',
        ];
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'expense',
        ]);
    }
}
