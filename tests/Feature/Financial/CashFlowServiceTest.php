<?php

namespace Tests\Feature\Financial;

use App\Enums\TeamRole;
use App\Models\Obligation;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_flow_reconciles_confirmed_ledger_and_classifies_forecast_inputs(): void
    {
        [$owner, $team] = $this->business();
        $team->update(['cash_safety_threshold' => '80.00']);
        Transaction::factory()->create([
            'team_id' => $team->id,
            'direction' => 'income',
            'amount' => '100.80',
            'transaction_date' => '2026-10-10',
        ]);
        Transaction::factory()->expense()->create([
            'team_id' => $team->id,
            'direction' => 'expense',
            'amount' => '25.00',
            'transaction_date' => '2026-10-12',
        ]);
        $customer = $team->customers()->create(['name' => 'Customer']);
        Obligation::create([
            'team_id' => $team->id,
            'kind' => 'invoice',
            'customer_id' => $customer->id,
            'due_date' => '2026-10-12',
            'issue_date' => '2026-10-01',
            'total' => '40.00',
            'number' => 'INV-1',
        ]);
        $supplier = $team->suppliers()->create(['name' => 'Supplier']);
        Obligation::create([
            'team_id' => $team->id,
            'kind' => 'bill',
            'supplier_id' => $supplier->id,
            'due_date' => '2026-10-11',
            'issue_date' => '2026-10-01',
            'total' => '50.00',
            'description' => 'Stock',
        ]);

        $result = app(CashFlowService::class)->forTeam($owner, $team, '0.00', CarbonImmutable::parse('2026-10-10', 'Asia/Manila'), 3);

        $this->assertSame('100.80', $result['confirmed']['inflow']);
        $this->assertSame('0.00', $result['confirmed']['outflow']);
        $this->assertSame('100.80', $result['confirmed']['netMovement']);
        $this->assertSame('100.80', $result['currentCash']);
        $this->assertTrue($result['historyComplete']);
        $this->assertSame('uncertain_collection', collect($result['items'])->firstWhere('sourceType', 'invoice')['classification']);
        $this->assertSame('expected_outflow', collect($result['items'])->firstWhere('sourceType', 'bill')['classification']);
        $this->assertSame('2026-10-11', $result['earliestShortfallDate']);
        $this->assertSame('29.20', $result['cashGap']);
    }

    public function test_missing_opening_balance_is_disclosed_and_does_not_claim_absolute_cash(): void
    {
        [$owner, $team] = $this->business();
        Transaction::factory()->create(['team_id' => $team->id, 'amount' => '75.80', 'transaction_date' => '2026-10-10']);

        $result = app(CashFlowService::class)->forTeam($owner, $team, null, CarbonImmutable::parse('2026-10-10', 'Asia/Manila'), 2);

        $this->assertNull($result['currentCash']);
        $this->assertSame('75.80', $result['confirmed']['netMovement']);
        $this->assertFalse($result['historyComplete']);
        $this->assertNotEmpty($result['limitations']);
        $this->assertNull($result['earliestShortfallDate']);
    }

    public function test_cash_flow_requires_authorized_onboarded_team_membership(): void
    {
        $actor = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => null]);

        $this->expectException(AuthorizationException::class);
        app(CashFlowService::class)->forTeam($actor, $team, '0.00');
    }

    /** @return array{0: User, 1: Team} */
    private function business(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        return [$owner, $team];
    }
}
