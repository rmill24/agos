<?php

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Services\ObligationService;
use App\Services\RecommendationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('prioritizes outstanding obligations at Manila midnight with resolvable team evidence', function () {
    $this->travelTo(now()->parse('2026-11-18 15:59:59', 'UTC'));
    [$actor, $team] = p2Business(TeamRole::Bookkeeper);
    [$otherActor, $other] = p2Business();
    $documents = app(ObligationService::class);
    $rules = app(RecommendationService::class);
    $billData = [
        'contact_id' => $team->suppliers()->create(['name' => 'Supplier'])->id,
        'description' => 'Delivery', 'total' => '90.00', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-19',
    ];
    $bill = $documents->create($actor, $team, 'bill', $billData);
    $documents->create($otherActor, $other, 'bill', [
        'contact_id' => $other->suppliers()->create(['name' => 'Other supplier'])->id,
        'due_date' => '2026-11-17',
    ] + $billData);
    expect($rules->candidates($actor, $team))->toBe([]);

    $invoice = $documents->create($actor, $team, 'invoice', [
        'contact_id' => $team->customers()->create(['name' => 'Customer'])->id, 'number' => 'I-1',
        'issue_date' => '2026-11-01', 'due_date' => '2026-11-18',
        'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '100.01']],
    ]);
    $documents->pay($actor, $team, 'invoice', $invoice->id, '99.99', '2026-11-18', (string) Str::uuid());
    expect($rules->candidates($actor, $team)[0]['priority'])->toBe('Normal');
    $this->travel(1)->second();
    $results = $rules->candidates($actor, $team);
    expect(array_column($results, 'priority'))->toBe(['High', 'Normal'])
        ->and(array_column($results, 'amount'))->toBe(['0.02', '90.00'])
        ->and(array_column($results, 'evidence'))->toBe([
            ['team_id' => $team->id, 'kind' => 'invoice', 'obligation_id' => $invoice->id],
            ['team_id' => $team->id, 'kind' => 'bill', 'obligation_id' => $bill->id],
        ])->and($rules->candidates($actor, $team))->toBe($results);

    $invoiceUrl = route('invoices.index', ['current_team' => $team, 'document' => $invoice->id]);
    $this->actingAs($actor)->get(route('recommendations.index', $team))->assertOk()
        ->assertSeeInOrder(['Follow up on collection', '₱0.02', 'Review supplier payment', '₱90.00'])
        ->assertSee($invoiceUrl);
    $this->get($invoiceUrl)->assertOk()->assertSee('Invoice details')->assertSee('I-1');
    $this->get(route('bills.index', ['current_team' => $team, 'document' => $invoice->id]))->assertNotFound();

    $documents->pay($actor, $team, 'invoice', $invoice->id, '0.02', '2026-11-19', (string) Str::uuid());
    expect(array_column($rules->candidates($actor, $team), 'action'))->toBe(['Review supplier payment']);
    $this->get(route('recommendations.index', $team))->assertDontSee('Follow up on collection');
});

it('checks membership, administrator role and current onboarding on direct recommendation reads', function () {
    [$actor, $team] = p2Business();
    [$outsider] = p2Business();
    $url = route('recommendations.index', $team);
    $this->actingAs($outsider)->get($url)->assertForbidden();
    $this->actingAs($actor)->get($url)->assertOk()->assertSee('No invoices or bills need attention');
    $component = Livewire::test('pages::recommendations.index', ['current_team' => $team]);
    $rules = app(RecommendationService::class);
    expect(fn () => $rules->candidates($outsider, $team))->toThrow(AuthorizationException::class);
    $actor->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save();
    $this->get($url)->assertForbidden();
    $component->call('$refresh')->assertForbidden();
    expect(fn () => $rules->candidates($actor, $team))->toThrow(AuthorizationException::class);
    $actor->forceFill(['global_role' => null])->save();
    $team->fresh()->update(['onboarding_completed_at' => null]);
    $this->get($url)->assertRedirect(route('teams.onboarding', $team));
    expect(fn () => $rules->candidates($actor, $team))->toThrow(AuthorizationException::class);
});
