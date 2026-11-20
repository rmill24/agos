<?php

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Models\ReportSnapshot;
use App\Services\ObligationService;
use App\Services\ReportSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('saves the dated report and serves exactly the captured HTML to assigned readers', function (string $kind) {
    [$user, $team] = p2Business(TeamRole::Bookkeeper);
    $this->actingAs($user)->get(route('reports.index', $team))->assertOk()->assertSee('no saved reports');
    $response = $this->post(route('reports.store', $team), ['kind' => $kind]);
    $snapshot = $team->reportSnapshots()->sole();
    $response->assertRedirect(route('reports.show', [$team, $snapshot->id]));
    expect($snapshot->team->is($team))->toBeTrue()->and($snapshot->data['business'])->toBe($team->name)
        ->and($snapshot->data['generated'])->toBe($snapshot->created_at->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'))
        ->and($snapshot->data['generated'])->toStartWith(now('Asia/Manila')->toDateString());
    $preview = $this->get(route('reports.show', [$team, $snapshot->id]))->assertOk()->assertSee($snapshot->data['title']);
    expect($preview->getContent())->toBe($snapshot->html);
    $this->get(route('reports.index', $team))->assertSee($snapshot->data['title'].' #'.$snapshot->id);
    $this->assertDatabaseHas('audit_events', ['team_id' => $team->id, 'event_type' => 'report.created', 'auditable_id' => $snapshot->id]);
})->with(['cash-flow', 'receivables', 'payables', 'recommendations', 'financing-readiness']);

it('freezes exact invoice and payable balances and escapes stored text', function () {
    [$user, $team] = p2Business();
    $billing = app(ObligationService::class);
    $snapshots = app(ReportSnapshotService::class);
    $base = ['issue_date' => '2025-01-01', 'due_date' => '2025-01-05'];
    $invoice = $billing->create($user, $team, 'invoice', $base + [
        'contact_id' => $team->customers()->create(['name' => 'Customer'])->id, 'number' => 'INV-REPORT',
        'lines' => [['description' => '<script>example</script>', 'unit_price' => '10.15', 'quantity' => 3]],
    ]);
    $bill = $billing->create($user, $team, 'bill', $base + [
        'contact_id' => $team->suppliers()->create(['name' => 'Supplier'])->id, 'description' => 'Delivery', 'total' => '18.93',
    ]);
    $billing->pay($user, $team, 'bill', $bill->id, '1.02', '2025-01-03', (string) Str::uuid());
    $billing->pay($user, $team, 'invoice', $invoice->id, '2.04', '2025-01-03', (string) Str::uuid());
    $this->actingAs($user)->post(route('reports.store', $team), ['kind' => 'invoice', 'invoice_id' => $invoice->id])->assertRedirect();
    $saved = $team->reportSnapshots()->sole();
    expect($saved->html)->toContain('30.45', '2.04', '28.41', '&lt;script&gt;example')->not->toContain('<script>');
    expect($snapshots->create($user, $team, 'payables')->data['report']['outstanding'])->toBe('17.91');
    $billing->pay($user, $team, 'invoice', $invoice->id, '1.00', '2025-01-04', (string) Str::uuid());
    expect($snapshots->create($user, $team, 'receivables')->data['report']['outstanding'])->toBe('27.41')
        ->and($snapshots->preview($user, $team, $saved->id)->html)->toBe($saved->html);
});

it('enforces append-only snapshots in the database', function (string $operation) {
    [$user, $team] = p2Business();
    $row = app(ReportSnapshotService::class)->create($user, $team, 'cash-flow');
    $query = DB::table('report_snapshots')->where('id', $row->id);
    expect(fn () => DB::transaction(fn () => $operation === 'delete' ? $query->delete() : $query->update(['html' => 'changed'])))
        ->toThrow(QueryException::class);
    expect($row->fresh()->html)->toBe($row->html);
})->with(['update', 'delete']);

it('requires an existing Team for every snapshot', function () {
    expect(fn () => DB::transaction(fn () => DB::table('report_snapshots')->insert([
        'team_id' => 987654321, 'kind' => 'payables', 'created_at' => now(), 'data' => '{}', 'html' => '<p>Fixture</p>',
    ])))->toThrow(QueryException::class);
});

it('enforces business ownership and onboarding on saved reports and invoice inputs', function () {
    [$owner, $team] = p2Business();
    [$other, $foreign] = p2Business();
    $snapshots = app(ReportSnapshotService::class);
    $row = $snapshots->create($other, $foreign, 'payables');
    $this->actingAs($owner)->get(route('reports.show', [$team, $row->id]))->assertNotFound();
    $this->get(route('reports.show', [$foreign, $row->id]))->assertForbidden();
    $this->post(route('reports.store', $foreign), ['kind' => 'receivables'])->assertForbidden();
    $invoice = app(ObligationService::class)->create($other, $foreign, 'invoice', ['number' => 'FOREIGN',
        'contact_id' => $foreign->customers()->create(['name' => 'Foreign'])->id, 'issue_date' => '2025-01-01', 'due_date' => '2025-01-02',
        'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00']]]);
    $this->post(route('reports.store', $team), ['kind' => 'invoice', 'invoice_id' => $invoice->id])->assertNotFound();
    $team->update(['onboarding_completed_at' => null]);
    $this->post(route('reports.store', $team), ['kind' => 'receivables'])->assertRedirect(route('teams.onboarding', $team));
    $team->update(['onboarding_completed_at' => now()]);
    $owner->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save();
    $this->get(route('reports.index', $team))->assertForbidden();
    expect($team->reportSnapshots()->count())->toBe(0);
});

it('recalculates scenario reports on the server and rechecks permission on direct actions', function () {
    [$actor, $team] = p2Business(TeamRole::Manager);
    $team->update(['opening_cash_balance' => '100.00', 'opening_cash_balance_date' => now()->subWeek(),
        'opening_cash_balance_verified_at' => now(), 'cash_safety_threshold' => '10.00']);
    $component = Livewire::actingAs($actor)->test('pages::scenarios.index', ['current_team' => $team])
        ->set('comparison', null)->set('amount', '12.34')->call('saveReport');
    $saved = $team->reportSnapshots()->sole();
    $component->assertRedirect(route('reports.show', [$team, $saved->id]));
    expect($saved->html)->toContain('100.00', '87.66', '12.34')->and($team->transactions()->count())->toBe(0);
    $component = Livewire::test('pages::scenarios.index', ['current_team' => $team]);
    $team->members()->updateExistingPivot($actor->id, ['role' => TeamRole::Bookkeeper->value]);
    $component->call('saveReport')->assertForbidden();
    expect(ReportSnapshot::count())->toBe(1);
});

it('renders each purchase alternative and keeps the underlying financial records intact', function () {
    [$actor, $team] = p2Business();
    $result = app(ReportSnapshotService::class)->create($actor, $team, 'scenario', [
        'type' => 'purchase_affordability', 'forecast_days' => 30, 'amount' => '100.00', 'date' => now('Asia/Manila')->addDay()->toDateString(),
        'payment_days' => 2, 'related_income' => '15.00', 'delay_days' => 4, 'reduction_percentage' => '20', 'deposit_amount' => '5.00',
    ]);
    expect($result->html)->toContain('Purchase now', 'Delay purchase', 'Reduce purchase amount', 'Request a customer deposit', 'Unknown')
        ->and($result->data['report']['results'])->toHaveCount(4)->and($team->transactions()->count())->toBe(0);
});
