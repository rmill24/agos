<?php

namespace Tests\Feature\Financial;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashFlowService;
use App\Services\ScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P4T06OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_flow_automatically_uses_verified_opening_balance_from_its_effective_date(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create([
            'onboarding_completed_at' => now(),
            'cash_safety_threshold' => '40.00',
            'opening_cash_balance' => '50.00',
            'opening_cash_balance_date' => '2026-11-01',
            'opening_cash_balance_verified_at' => now(),
            'opening_cash_balance_verified_by' => $owner->id,
        ]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        Transaction::factory()->create(['team_id' => $team->id, 'amount' => '100.00', 'transaction_date' => '2026-10-31']);
        Transaction::factory()->create(['team_id' => $team->id, 'amount' => '10.00', 'transaction_date' => '2026-11-02']);

        $result = app(CashFlowService::class)->forTeam($owner, $team, null, CarbonImmutable::parse('2026-11-02', 'Asia/Manila'), 7);

        $this->assertSame('50.00', $result['openingBalance']);
        $this->assertSame('60.00', $result['currentCash']);
        $this->assertTrue($result['historyComplete']);
        $this->assertSame('60.00', $result['daily'][1]['projectedCash']);

        $scenario = app(ScenarioService::class)->compare($owner, $team, 'expense_increase', [
            'forecast_days' => 7, 'amount' => '5.00', 'date' => '2026-11-03',
        ], CarbonImmutable::parse('2026-11-02', 'Asia/Manila'));
        $this->assertSame('60.00', $scenario['baseline']['currentCash']);
    }

    public function test_unverified_opening_balance_is_not_used_for_absolute_cash(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create([
            'onboarding_completed_at' => now(),
            'opening_cash_balance' => '50.00',
            'opening_cash_balance_date' => '2026-11-01',
            'opening_cash_balance_verified_at' => null,
        ]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        $result = app(CashFlowService::class)->forTeam($owner, $team, null, CarbonImmutable::parse('2026-11-02', 'Asia/Manila'), 7);

        $this->assertNull($result['openingBalance']);
        $this->assertNull($result['currentCash']);
        $this->assertFalse($result['historyComplete']);
    }
}
