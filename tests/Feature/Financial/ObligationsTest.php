<?php

use App\Enums\TeamRole;
use App\Models\Transaction;
use App\Services\ObligationService;
use App\Services\TransactionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reconciles partial and final payments and retains archived contact history', function (string $kind, TeamRole $role) {
    [$actor,$team] = p2Business($role);
    $this->travelTo(now()->parse('2026-11-18 15:59:59', 'UTC'));
    $invoice = $kind === 'invoice';
    $contact = ($invoice ? $team->customers() : $team->suppliers())->create(['name' => 'Example']);
    $service = app(ObligationService::class);
    $doc = $service->create($actor, $team, $kind, [
        'contact_id' => $contact->id, 'issue_date' => '2026-11-01', 'due_date' => '2026-11-18',
        'number' => 'I-1', 'lines' => [['description' => 'Parts', 'quantity' => 3, 'unit_price' => '333.33'], ['description' => 'Adjustment', 'quantity' => 1, 'unit_price' => '0.01']],
        'description' => 'Supplies', 'total' => '1000.00',
    ]);
    expect((string) $doc->balance())->toBe('1000.00')->and(Transaction::count())->toBe(0);
    $contact->delete();
    $key = (string) Str::uuid();
    $first = $service->pay($actor, $team, $kind, $doc->id, '250.00', '2026-11-18', $key);
    expect($service->pay($actor, $team, $kind, $doc->id, '250', '2026-11-18', $key)->id)->toBe($first->id);
    expect($first->transaction->direction)->toBe($invoice ? 'income' : 'expense');
    $doc->refresh();
    expect((string) $doc->balance())->toBe('750.00')->and($doc->settlement())->toBe('Partially paid')->and($doc->dueStatus())->toBe('Due today');
    $this->travel(1)->second();
    expect($doc->dueStatus())->toBe('Overdue');
    foreach ([['751', '2026-11-18'], ['0', '2026-11-18'], ['1', '2026-11-20']] as [$amount,$date]) {
        expect(fn () => $service->pay($actor, $team, $kind, $doc->id, $amount, $date, (string) Str::uuid()))->toThrow(ValidationException::class);
    }
    expect(fn () => app(TransactionService::class)->archive($actor, $team, $first->transaction))->toThrow(AuthorizationException::class);
    expect(fn () => app(TransactionService::class)->update($actor, $team, $first->transaction, []))->toThrow(AuthorizationException::class);
    $service->pay($actor, $team, $kind, $doc->id, '750', '2026-11-19', (string) Str::uuid());
    expect((string) $doc->refresh()->balance())->toBe('0.00')->and($doc->dueStatus())->toBe('Settled')->and(Transaction::count())->toBe(2);
    $this->actingAs($actor)->get(route($invoice ? 'invoices.index' : 'bills.index', $team))->assertOk()->assertSee('Example')->assertSee('Paid');
})->with([['invoice', TeamRole::Bookkeeper], ['bill', TeamRole::Manager]]);

it('denies foreign contacts, records, and actions after onboarding changes', function () {
    [$actor,$team] = p2Business();
    [, $other] = p2Business();
    $contact = $other->customers()->create(['name' => 'Private']);
    $this->actingAs($actor);
    $component = Livewire::test('pages::obligations.index', ['current_team' => $team]);
    $component->set('form', ['contact_id' => $contact->id, 'issue_date' => '2026-11-01', 'due_date' => '2026-11-02', 'number' => 'X', 'lines' => [['quantity' => 1, 'unit_price' => '1', 'description' => 'Test']]])->call('save')->assertNotFound();
    $this->get(route('bills.index', $other))->assertForbidden();
    $component = Livewire::test('pages::obligations.index', ['current_team' => $team]);
    $team->update(['onboarding_completed_at' => null]);
    $component->call('addLine')->assertForbidden();
    $this->get(route('invoices.index', $team))->assertRedirect(route('teams.onboarding', $team));
});

it('filters the document directory across pages and resets pagination when searching', function () {
    [$actor, $team] = p2Business();
    $customer = $team->customers()->create(['name' => 'Directory buyer']);
    $service = app(ObligationService::class);
    foreach (range(1, 11) as $index) {
        $service->create($actor, $team, 'invoice', [
            'contact_id' => $customer->id, 'number' => 'INV-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'issue_date' => '2025-01-01', 'due_date' => '2025-01-02',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00']],
        ]);
    }
    $this->actingAs($actor);
    Livewire::test('pages::obligations.index', ['current_team' => $team])
        ->set('filter', 'Overdue')->assertSee('Page 1 of 2')->assertDontSee('INV-11')
        ->call('nextPage')->assertSee('INV-11')
        ->set('search', 'INV-03')->assertSee('Page 1 of 1')->assertSee('INV-03')->assertDontSee('INV-11')
        ->set('search', 'directory buyer')->assertSee('Page 1 of 2')
        ->set('filter', 'Settled')->assertSee('No matching invoices.');
});
