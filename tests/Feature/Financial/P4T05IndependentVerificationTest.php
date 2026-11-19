<?php

namespace Tests\Feature\Financial;

use App\Enums\TeamRole;
use App\Models\LoyverseSync;
use App\Models\Obligation;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashFlowService;
use App\Services\ScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P4T05IndependentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Obligation $invoice;

    private Obligation $bill;

    public function test_independent_fixture_reconciles_precision_partial_payments_refund_and_boundaries(): void
    {
        [$owner, $team] = $this->fixtureBusiness();
        $fixture = require base_path('tests/Fixtures/financial-independent-cash-flow.php');
        $this->seedFixture($owner, $team, $fixture);

        $result = app(CashFlowService::class)->forTeam(
            $owner,
            $team,
            $fixture['opening_balance'],
            CarbonImmutable::parse($fixture['as_of'], 'Asia/Manila'),
            $fixture['days'],
        );

        $expected = $fixture['expected'];
        $this->assertSame($expected['confirmed_inflow'], $result['confirmed']['inflow']);
        $this->assertSame($expected['confirmed_outflow'], $result['confirmed']['outflow']);
        $this->assertSame($expected['confirmed_net'], $result['confirmed']['netMovement']);
        $this->assertSame($expected['current_cash'], $result['currentCash']);
        $this->assertSame($expected['earliest_shortfall'], $result['earliestShortfallDate']);
        $this->assertSame($expected['cash_gap'], $result['cashGap']);
        $this->assertSame($expected['invoice_balance'], $this->invoice->balance()->toScale(2)->toString());
        $this->assertSame($expected['bill_balance'], $this->bill->balance()->toScale(2)->toString());
        $this->assertSame($expected['dec_2_projected_cash'], $this->daily($result, '2026-12-02')['projectedCash']);
        $this->assertSame($expected['dec_3_projected_cash'], $this->daily($result, '2026-12-03')['projectedCash']);
        $this->assertSame($expected['period_end_projected_cash'], $this->daily($result, '2026-12-07')['projectedCash']);
        $this->assertSame('uncertain_collection', $this->sourceItem($result, 'invoice')['classification']);
        $this->assertSame('expected_outflow', $this->sourceItem($result, 'bill')['classification']);
        $this->assertSame('confirmed_inflow', $this->item($result, 'Boundary precision sale')['classification']);
        $this->assertSame('confirmed_outflow', $this->item($result, 'Confirmed scheduled expense')['classification']);
    }

    public function test_stale_sync_and_incomplete_history_are_explicitly_disclosed(): void
    {
        [$owner, $team] = $this->fixtureBusiness();
        $fixture = require base_path('tests/Fixtures/financial-independent-cash-flow.php');
        $this->seedFixture($owner, $team, $fixture);
        LoyverseSync::create([
            'team_id' => $team->id, 'user_id' => $owner->id, 'status' => 'failed',
            'source_mapping' => 'fixture', 'window_start' => '2026-12-01 00:00:00',
            'window_end' => '2026-12-01 23:59:59', 'failure' => 'Fixture outage',
        ]);

        $service = app(CashFlowService::class);
        $incomplete = $service->forTeam($owner, $team, null, CarbonImmutable::parse($fixture['as_of'], 'Asia/Manila'), $fixture['days']);
        $complete = $service->forTeam($owner, $team, $fixture['opening_balance'], CarbonImmutable::parse($fixture['as_of'], 'Asia/Manila'), $fixture['days']);

        $this->assertNull($incomplete['currentCash']);
        $this->assertNull($incomplete['earliestShortfallDate']);
        $this->assertContains('Absolute cash and threshold crossings require a verified opening balance or complete cash history.', $incomplete['limitations']);
        $this->assertContains('The latest Loyverse synchronization is incomplete; previously published records may be stale.', $incomplete['limitations']);
        $this->assertSame('110.00', $complete['currentCash']);
    }

    public function test_all_scenario_types_are_independent_and_preserve_actual_records(): void
    {
        [$owner, $team] = $this->fixtureBusiness();
        $fixture = require base_path('tests/Fixtures/financial-independent-cash-flow.php');
        $this->seedFixture($owner, $team, $fixture);
        $asOf = CarbonImmutable::parse($fixture['as_of'], 'Asia/Manila');
        $before = $this->snapshot($team);
        $service = app(ScenarioService::class);

        $inputs = [
            'payment_delay' => ['forecast_days' => 7, 'obligation_id' => $this->invoice->id, 'delay_days' => 2],
            'sales_reduction' => ['forecast_days' => 7, 'mode' => 'amount', 'value' => '1.01'],
            'expense_increase' => ['forecast_days' => 7, 'amount' => '2.02', 'date' => '2026-12-02'],
            'supplier_delay' => ['forecast_days' => 7, 'obligation_id' => $this->bill->id, 'delay_days' => 2],
            'customer_deposit' => ['forecast_days' => 7, 'amount' => '3.03', 'date' => '2026-12-04'],
            'proposed_purchase' => ['forecast_days' => 7, 'amount' => '4.04', 'date' => '2026-12-05', 'payment_days' => 1, 'related_income' => '5.05'],
        ];

        foreach ($inputs as $type => $input) {
            $result = $service->run($owner, $team, $type, $input, $asOf, $fixture['opening_balance']);
            $this->assertSame($type, $result['type']);
            $this->assertFalse($result['persisted']);
            $this->assertNotEmpty($result['cashFlow']['daily']);
            $this->assertSame($before, $this->snapshot($team), $type.' changed persisted records');
        }
    }

    public function test_baseline_comparison_and_purchase_alternatives_match_independent_expectations(): void
    {
        [$owner, $team] = $this->fixtureBusiness();
        $fixture = require base_path('tests/Fixtures/financial-independent-cash-flow.php');
        $this->seedFixture($owner, $team, $fixture);
        $asOf = CarbonImmutable::parse($fixture['as_of'], 'Asia/Manila');
        $service = app(ScenarioService::class);

        $comparison = $service->compare($owner, $team, 'expense_increase', [
            'forecast_days' => 7, 'amount' => '2.50', 'date' => '2026-12-02',
        ], $asOf, $fixture['opening_balance']);
        $this->assertSame('calculated', $comparison['impact']['status']);
        $this->assertSame('-2.50', $comparison['impact']['projectedCashDelta']);
        $this->assertSame('2.50', $comparison['impact']['cashGapDelta']);
        $this->assertSame('2026-12-03', $comparison['impact']['earliestShortfall']['baseline']);
        $this->assertSame('2026-12-03', $comparison['impact']['earliestShortfall']['scenario']);

        $analysis = $service->purchaseAffordability($owner, $team, [
            'forecast_days' => 7, 'amount' => '20.00', 'date' => '2026-12-02', 'payment_days' => 0,
            'related_income' => '0.01', 'delay_days' => 2, 'reduction_percentage' => '50.00', 'deposit_amount' => '10.00',
        ], $asOf, $fixture['opening_balance']);
        $this->assertSame(['purchase_now', 'delay_purchase', 'reduce_purchase', 'request_deposit'], array_column($analysis['alternatives'], 'key'));
        $this->assertSame('20.00', $analysis['alternatives'][0]['assumptions']['amount']);
        $this->assertSame('2026-12-04', $analysis['alternatives'][1]['assumptions']['date']);
        $this->assertSame('10.00', $analysis['alternatives'][2]['assumptions']['amount']);
        $this->assertSame('10.00', $analysis['alternatives'][3]['assumptions']['deposit_amount']);
        $this->assertFalse($analysis['persisted']);
        $this->assertSame('110.00', $analysis['alternatives'][0]['comparison']['baseline']['currentCash']);
    }

    public function test_team_and_role_authorization_are_enforced_at_the_service_boundary(): void
    {
        [$owner, $team] = $this->fixtureBusiness();
        [, $otherTeam] = $this->fixtureBusiness();
        $manager = User::factory()->create();
        $team->members()->attach($manager, ['role' => TeamRole::Manager]);
        $bookkeeper = User::factory()->create();
        $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper]);
        $input = ['forecast_days' => 7, 'amount' => '1.00', 'date' => '2026-12-02'];

        $this->assertSame('expense_increase', app(ScenarioService::class)->run($manager, $team, 'expense_increase', $input, CarbonImmutable::parse('2026-12-01', 'Asia/Manila'))['type']);
        try {
            app(ScenarioService::class)->run($bookkeeper, $team, 'expense_increase', $input, CarbonImmutable::parse('2026-12-01', 'Asia/Manila'));
            $this->fail('Bookkeeper scenario access should be denied.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(ScenarioService::class)->run($owner, $otherTeam, 'expense_increase', $input, CarbonImmutable::parse('2026-12-01', 'Asia/Manila'));
            $this->fail('Cross-Team scenario access should be denied.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array{0: User, 1: Team} */
    private function fixtureBusiness(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now(), 'cash_safety_threshold' => '80.00']);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        return [$owner, $team];
    }

    /** @param array<string, mixed> $fixture */
    private function seedFixture(User $owner, Team $team, array $fixture): void
    {
        foreach ([...$fixture['historical_transactions'], ...$fixture['future_transactions']] as $record) {
            Transaction::factory()->create([
                'team_id' => $team->id, 'recorded_by' => $owner->id, 'direction' => $record['direction'],
                'amount' => $record['amount'], 'transaction_date' => $record['date'], 'description' => $record['description'],
            ]);
        }
        $customer = $team->customers()->create(['name' => 'Independent Customer']);
        $supplier = $team->suppliers()->create(['name' => 'Independent Supplier']);
        $this->invoice = Obligation::create([
            'team_id' => $team->id, 'kind' => 'invoice', 'customer_id' => $customer->id,
            'issue_date' => '2026-11-20', 'due_date' => $fixture['invoice']['due_date'],
            'total' => $fixture['invoice']['total'], 'number' => 'IND-INV-1',
        ]);
        $this->bill = Obligation::create([
            'team_id' => $team->id, 'kind' => 'bill', 'supplier_id' => $supplier->id,
            'issue_date' => '2026-11-20', 'due_date' => $fixture['bill']['due_date'],
            'total' => $fixture['bill']['total'], 'description' => 'Independent bill',
        ]);
        $invoicePayment = Transaction::query()->where('description', 'Partial invoice payment')->sole();
        $billPayment = Transaction::query()->where('description', 'Partial supplier payment')->sole();
        Payment::create(['team_id' => $team->id, 'obligation_id' => $this->invoice->id, 'transaction_id' => $invoicePayment->id, 'submission_key' => '00000000-0000-0000-0000-000000000001']);
        Payment::create(['team_id' => $team->id, 'obligation_id' => $this->bill->id, 'transaction_id' => $billPayment->id, 'submission_key' => '00000000-0000-0000-0000-000000000002']);
    }

    /** @return array<string, mixed> */
    private function snapshot(Team $team): array
    {
        return [
            'transactions' => Transaction::query()->where('team_id', $team->id)->withTrashed()->orderBy('id')->get()->map(fn (Transaction $transaction): array => [...$transaction->only(['id', 'amount', 'direction', 'description']), 'transaction_date' => $transaction->transaction_date?->toDateString(), 'deleted_at' => $transaction->deleted_at?->toISOString()])->all(),
            'obligations' => Obligation::query()->where('team_id', $team->id)->orderBy('id')->get()->map(fn (Obligation $obligation): array => [...$obligation->only(['id', 'total']), 'due_date' => (string) $obligation->due_date])->all(),
            'payments' => Payment::query()->where('team_id', $team->id)->orderBy('id')->get()->map(fn (Payment $payment): array => $payment->only(['id', 'obligation_id', 'transaction_id', 'submission_key']))->all(),
        ];
    }

    /** @param array<string, mixed> $result */
    private function daily(array $result, string $date): array
    {
        return collect($result['daily'])->firstWhere('date', $date);
    }

    /** @param array<string, mixed> $result */
    private function item(array $result, string $description): array
    {
        return collect($result['items'])->firstWhere('description', $description);
    }

    /** @param array<string, mixed> $result */
    private function sourceItem(array $result, string $sourceType): array
    {
        return collect($result['items'])->firstWhere('sourceType', $sourceType);
    }
}
