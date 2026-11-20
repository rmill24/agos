<?php

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Models\Obligation;
use App\Models\Transaction;
use App\Services\FinancingReadinessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('dates each source independently using the inclusive Manila window and posted Team evidence', function () {
    $this->travelTo(new DateTimeImmutable('2026-11-19T16:01:00Z'));
    [$actor, $team] = p2Business();
    [, $other] = p2Business();
    $team->update(['opening_cash_balance' => '0.00', 'opening_cash_balance_date' => '2026-10-01', 'opening_cash_balance_verified_at' => now()]);
    foreach ([[$team, 'income', '2026-10-22'], [$team, 'expense', '2026-10-21'], [$team, 'expense', '2026-11-21'], [$other, 'expense', '2026-11-20']] as [$business, $direction, $date]) {
        Transaction::factory()->create(['team_id' => $business->id, 'direction' => $direction, 'transaction_date' => $date]);
    }
    Transaction::factory()->create(['team_id' => $team->id, 'transaction_date' => '2026-11-20'])->delete();
    foreach ([[$team, 'invoice', '2026-11-20'], [$team, 'bill', '2026-11-21'], [$other, 'bill', '2026-11-20']] as [$business, $kind, $date]) {
        $invoice = $kind === 'invoice';
        $contact = ($invoice ? $business->customers() : $business->suppliers())->create(['name' => 'Example']);
        Obligation::create(['team_id' => $business->id, 'kind' => $kind, $invoice ? 'customer_id' : 'supplier_id' => $contact->id,
            'number' => 'DOC-1-'.$kind, 'description' => 'Fixture', 'issue_date' => $date, 'due_date' => $date, 'total' => '1.00']);
    }
    $team->loyverseReceipts()->create(['receipt_number' => 'POS-1', 'merchant_id' => 'm', 'store_id' => 's', 'currency_code' => 'PHP',
        'synced_at' => now(), 'source_updated_at' => now(), 'receipt' => ['receipt_number' => 'POS-1']]);
    $service = app(FinancingReadinessService::class);
    $report = $service->forTeam($actor, $team);
    expect($report['asOf'])->toBe('2026-11-20')->and($report['since'])->toBe('2026-10-22')
        ->and($report['recent'])->toBe(4)
        ->and(array_column($report['items'], 'status', 'label'))->toBe([
            'Transaction records' => 'Recent', 'Income' => 'Recent', 'Expenses' => 'Out of date',
            'Customer invoices' => 'Recent', 'Supplier obligations' => 'Missing', 'Cash-flow summary' => 'Recent', 'Supporting receipts' => 'Missing',
        ]);
    Transaction::factory()->create(['team_id' => $team->id, 'source_type' => 'loyverse', 'source_reference' => 'POS-1', 'transaction_date' => '2026-11-19']);
    expect($service->forTeam($actor, $team)['recent'])->toBe(5);
});

it('renders a dated read-only report for financial readers', function (TeamRole $role) {
    [$actor, $team] = p2Business($role);
    $this->actingAs($actor)->get(route('financing-readiness.index', $team))->assertOk()
        ->assertSee('0 of 7')->assertSee('Incomplete')->assertSee('verified opening balance')
        ->assertSee('Loan approval and eligibility are outside its scope')->assertSee(now('Asia/Manila')->toDateString());
})->with([TeamRole::Owner, TeamRole::Manager, TeamRole::Bookkeeper]);

it('enforces the Team boundary on routes, service calls and component state', function () {
    [$actor, $team] = p2Business();
    [, $other] = p2Business();
    $this->actingAs($actor)->get(route('financing-readiness.index', $other))->assertForbidden();
    expect(fn () => app(FinancingReadinessService::class)->forTeam($actor, $other))->toThrow(AuthorizationException::class);
    Livewire::test('pages::financing-readiness.index', ['current_team' => $other])->assertForbidden();
    $component = Livewire::test('pages::financing-readiness.index', ['current_team' => $team]);
    expect(fn () => $component->set('team', $other))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rechecks access on direct refresh and subsequent route visits', function (string $restriction) {
    [$actor, $team] = p2Business();
    $component = Livewire::actingAs($actor)->test('pages::financing-readiness.index', ['current_team' => $team]);
    match ($restriction) {
        'membership' => $team->members()->detach($actor),
        'administrator' => $actor->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save(),
        'onboarding' => $team->update(['onboarding_completed_at' => null]),
    };
    $component->call('$refresh')->assertForbidden();
    $response = $this->get(route('financing-readiness.index', $team));
    $restriction === 'onboarding' ? $response->assertRedirect(route('teams.onboarding', $team)) : $response->assertForbidden();
})->with(['membership', 'administrator', 'onboarding']);
