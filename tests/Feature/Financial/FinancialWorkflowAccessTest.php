<?php

use App\Enums\TeamRole;
use App\Models\Obligation;
use App\Services\CsvImportService;
use App\Services\ObligationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('scopes document detail and settlement to the authorized team even for a member of both', function (string $kind) {
    [$actor,$team] = p2Business();
    [, $other] = p2Business();
    $other->members()->attach($actor, ['role' => TeamRole::Bookkeeper]);
    $contact = ($kind === 'invoice' ? $other->customers() : $other->suppliers())->create(['name' => 'Contact']);
    $doc = app(ObligationService::class)->create($actor, $other, $kind, [
        'contact_id' => $contact->id, 'issue_date' => '2026-01-01', 'due_date' => '2026-01-07',
        'description' => 'Parts', 'total' => '10.00', 'number' => 'S1', 'lines' => [['description' => 'Parts', 'quantity' => 1, 'unit_price' => '10']],
    ]);
    $this->actingAs($actor);
    Livewire::test('pages::obligations.index', ['current_team' => $team])->call('open', $doc->id)->assertNotFound();
    expect(fn () => app(ObligationService::class)->pay($actor, $team, $kind, $doc->id, '5', '2026-01-05', (string) Str::uuid()))->toThrow(ModelNotFoundException::class);
    // Prove the database also enforces contact ownership on a direct write.
    expect(fn () => DB::transaction(fn () => Obligation::whereKey($doc->id)->update(['team_id' => $team->id])))->toThrow(QueryException::class);
    expect($doc->payments()->count())->toBe(0);
})->with(['invoice', 'bill']);

it('keeps drafts private and denies stale import commits and downloads', function () {
    [$user,$team] = p2Business(TeamRole::Manager);
    [$otherUser,$otherTeam] = p2Business();
    $team->members()->attach($otherUser, ['role' => TeamRole::Manager]);
    $service = app(CsvImportService::class);
    $batch = $service->upload($user, $team, "date,type,amount,currency,description\n2026-11-01,expense,5,PHP,Delivery");
    $service->review($user, $team, $batch->id, array_flip($batch->headers), 'Y-m-d', true);
    $this->actingAs($otherUser);
    foreach ([$team, $otherTeam] as $context) {
        Livewire::test('pages::imports.index', ['current_team' => $context])->call('open', $batch->id)->assertNotFound();
        expect(fn () => $service->commit($otherUser, $context, $batch->id, 1, [2]))->toThrow(ModelNotFoundException::class);
    }
    $this->actingAs($user);
    $component = Livewire::test('pages::imports.index', ['current_team' => $team])->call('open', $batch->id);
    $component->set('format', 'd-m-Y')->call('commitReviewed')->assertHasErrors('review');
    $team->members()->detach($user);
    $component->call('commitReviewed')->assertForbidden();
    $team->members()->attach($user, ['role' => TeamRole::Manager]);
    $component = Livewire::test('pages::imports.index', ['current_team' => $team])->call('open', $batch->id);
    $team->update(['onboarding_completed_at' => null]);
    $this->get(route('imports.index', $team))->assertRedirect(route('teams.onboarding', $team));
    $component->call('download')->assertForbidden();
    expect($team->transactions()->count())->toBe(0);
});

it('validates invoice uniqueness, dates, prices and active contacts before recording', function () {
    [$actor,$team] = p2Business();
    $customer = $team->customers()->create(['name' => 'Buyer']);
    $invoice = ['contact_id' => $customer->id, 'number' => 'INV-1', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-02', 'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '2']]];
    $save = fn ($data) => app(ObligationService::class)->create($actor, $team, 'invoice', $data);
    $save($invoice);
    expect(fn () => $save($invoice))->toThrow(ValidationException::class);
    $invoice['number'] = 'INV-2';
    foreach ([['due_date' => '2026-10-31'], ['lines' => []], ['lines' => [['quantity' => '1.5', 'unit_price' => '0.001', 'description' => 'Item']]]] as $changes) {
        expect(fn () => $save(array_replace($invoice, $changes)))->toThrow(ValidationException::class);
    }
    $customer->delete();
    expect(fn () => $save($invoice))->toThrow(ModelNotFoundException::class);
    expect(Obligation::count())->toBe(1);
});
