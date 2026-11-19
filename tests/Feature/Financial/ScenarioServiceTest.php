<?php

namespace Tests\Feature\Financial;

use App\Enums\TeamRole;
use App\Models\Obligation;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScenarioServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_increase_is_decimal_safe_and_does_not_change_actual_records(): void
    {
        [$owner, $team] = $this->business();
        Transaction::factory()->create(['team_id' => $team->id, 'amount' => '100.00', 'transaction_date' => '2026-11-01']);
        $beforeTransactions = Transaction::where('team_id', $team->id)->count();
        $beforeObligations = Obligation::where('team_id', $team->id)->count();

        $result = app(ScenarioService::class)->run($owner, $team, 'expense_increase', [
            'forecast_days' => 7, 'amount' => '0.01', 'date' => '2026-11-02',
        ], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'));

        $item = $result['cashFlow']['items'][0];
        $this->assertSame('0.01', $item['amount']);
        $this->assertSame('optional_outflow', $item['classification']);
        $this->assertTrue($item['hypothetical']);
        $this->assertFalse($result['persisted']);
        $this->assertSame($beforeTransactions, Transaction::where('team_id', $team->id)->count());
        $this->assertSame($beforeObligations, Obligation::where('team_id', $team->id)->count());
    }

    public function test_payment_and_supplier_delays_override_only_the_hypothetical_forecast_date(): void
    {
        [$owner, $team] = $this->business();
        $customer = $team->customers()->create(['name' => 'Customer']);
        $supplier = $team->suppliers()->create(['name' => 'Supplier']);
        $invoice = Obligation::create(['team_id' => $team->id, 'kind' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => '2026-11-01', 'due_date' => '2026-11-03', 'total' => '10.00', 'number' => 'INV-1']);
        $bill = Obligation::create(['team_id' => $team->id, 'kind' => 'bill', 'supplier_id' => $supplier->id, 'issue_date' => '2026-11-01', 'due_date' => '2026-11-04', 'total' => '20.00', 'description' => 'Stock']);

        $invoiceResult = app(ScenarioService::class)->run($owner, $team, 'payment_delay', ['forecast_days' => 14, 'obligation_id' => $invoice->id, 'delay_days' => 2], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'));
        $billResult = app(ScenarioService::class)->run($owner, $team, 'supplier_delay', ['forecast_days' => 14, 'obligation_id' => $bill->id, 'delay_days' => 3], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'));

        $this->assertSame('2026-11-05', $invoiceResult['inputs']['hypotheticalDate']);
        $this->assertSame('2026-11-07', $billResult['inputs']['hypotheticalDate']);
        $this->assertDatabaseHas('obligations', ['id' => $invoice->id, 'due_date' => '2026-11-03']);
        $this->assertDatabaseHas('obligations', ['id' => $bill->id, 'due_date' => '2026-11-04']);
    }

    public function test_customer_deposit_and_purchase_are_optional_hypothetical_flows(): void
    {
        [$owner, $team] = $this->business();
        $asOf = CarbonImmutable::parse('2026-11-01', 'Asia/Manila');

        $deposit = app(ScenarioService::class)->run($owner, $team, 'customer_deposit', ['forecast_days' => 14, 'amount' => '50.00', 'date' => '2026-11-03'], $asOf);
        $purchase = app(ScenarioService::class)->run($owner, $team, 'proposed_purchase', ['forecast_days' => 14, 'amount' => '80.00', 'date' => '2026-11-03', 'payment_days' => 2, 'related_income' => '120.00'], $asOf);

        $this->assertSame('optional_inflow', $deposit['cashFlow']['items'][0]['classification']);
        $this->assertCount(2, $purchase['cashFlow']['items']);
        $this->assertSame(['optional_outflow', 'optional_inflow'], array_column($purchase['cashFlow']['items'], 'classification'));
        $this->assertSame('2026-11-05', $purchase['inputs']['relatedIncomeDate']);
    }

    public function test_percentage_sales_reduction_discloses_when_no_confirmed_future_sales_exist(): void
    {
        [$owner, $team] = $this->business();

        $result = app(ScenarioService::class)->run($owner, $team, 'sales_reduction', ['forecast_days' => 7, 'mode' => 'percentage', 'value' => '10.00'], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'));

        $this->assertContains('No confirmed future sales exist in the selected period; the percentage reduction has no modeled cash amount.', $result['limitations']);
        $this->assertSame('0.00', $result['inputs']['amountModeled']);
        $this->assertSame([], $result['cashFlow']['items']);
    }

    public function test_scenarios_require_manager_or_owner(): void
    {
        [$owner, $team] = $this->business();
        $bookkeeper = User::factory()->create();
        $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper]);
        $this->expectException(AuthorizationException::class);
        app(ScenarioService::class)->run($bookkeeper, $team, 'expense_increase', ['forecast_days' => 7, 'amount' => '1.00', 'date' => '2026-11-02']);
    }

    public function test_scenarios_cannot_use_another_teams_obligation(): void
    {
        [$owner, $team] = $this->business();
        [, $otherTeam] = $this->business();
        $customer = $otherTeam->customers()->create(['name' => 'Other customer']);
        $invoice = Obligation::create(['team_id' => $otherTeam->id, 'kind' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => '2026-11-01', 'due_date' => '2026-11-03', 'total' => '10.00', 'number' => 'OTHER-1']);

        $this->expectException(ValidationException::class);
        app(ScenarioService::class)->run($owner, $team, 'payment_delay', ['forecast_days' => 7, 'obligation_id' => $invoice->id, 'delay_days' => 2], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'));
    }

    public function test_invalid_scenario_inputs_are_rejected(): void
    {
        [$owner, $team] = $this->business();

        $this->expectException(ValidationException::class);
        app(ScenarioService::class)->run($owner, $team, 'expense_increase', ['forecast_days' => 7, 'amount' => '1.001', 'date' => '2026-11-02'], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'));
    }

    public function test_comparison_reports_cash_and_threshold_impact_with_verified_opening_balance(): void
    {
        [$owner, $team] = $this->business();
        $team->update(['cash_safety_threshold' => '80.00']);

        $comparison = app(ScenarioService::class)->compare($owner, $team, 'expense_increase', [
            'forecast_days' => 7, 'amount' => '30.00', 'date' => '2026-11-02',
        ], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'), '100.00');

        $this->assertSame('calculated', $comparison['impact']['status']);
        $this->assertSame('-30.00', $comparison['impact']['projectedCashDelta']);
        $this->assertSame('10.00', $comparison['impact']['cashGapDelta']);
        $this->assertNull($comparison['impact']['earliestShortfall']['baseline']);
        $this->assertSame('2026-11-02', $comparison['impact']['earliestShortfall']['scenario']);
    }

    public function test_purchase_affordability_returns_four_read_only_alternatives_and_explains_missing_history(): void
    {
        [$owner, $team] = $this->business();
        $team->update(['cash_safety_threshold' => '80.00']);
        $before = Transaction::where('team_id', $team->id)->count();

        $analysis = app(ScenarioService::class)->purchaseAffordability($owner, $team, [
            'forecast_days' => 14,
            'amount' => '30.00',
            'date' => '2026-11-02',
            'payment_days' => 0,
            'related_income' => '40.00',
            'delay_days' => 3,
            'reduction_percentage' => '50.00',
            'deposit_amount' => '10.00',
        ], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'));

        $this->assertSame(['purchase_now', 'delay_purchase', 'reduce_purchase', 'request_deposit'], array_column($analysis['alternatives'], 'key'));
        $this->assertSame('Insufficient verified opening history for an affordability conclusion.', $analysis['alternatives'][0]['affordability']['reason']);
        $this->assertNull($analysis['alternatives'][0]['affordability']['affordable']);
        $this->assertContains('Affordability cannot be concluded without a verified opening balance or complete cash history.', $analysis['limitations']);
        $this->assertSame($before, Transaction::where('team_id', $team->id)->count());
        $this->assertFalse($analysis['persisted']);
    }

    public function test_purchase_reduction_can_be_affordable_when_opening_balance_and_threshold_are_verified(): void
    {
        [$owner, $team] = $this->business();
        $team->update(['cash_safety_threshold' => '80.00']);

        $analysis = app(ScenarioService::class)->purchaseAffordability($owner, $team, [
            'forecast_days' => 14, 'amount' => '30.00', 'date' => '2026-11-02', 'payment_days' => 0,
            'related_income' => '0.01', 'delay_days' => 3, 'reduction_percentage' => '50.00', 'deposit_amount' => '10.00',
        ], CarbonImmutable::parse('2026-11-01', 'Asia/Manila'), '100.00');

        $this->assertFalse($analysis['alternatives'][0]['affordability']['affordable']);
        $this->assertTrue($analysis['alternatives'][2]['affordability']['affordable']);
        $this->assertSame('0.01', $analysis['alternatives'][2]['assumptions']['related_income']);
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
