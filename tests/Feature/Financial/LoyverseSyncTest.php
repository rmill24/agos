<?php

use App\Enums\TeamRole;
use App\Jobs\SyncLoyversePage;
use App\Services\LoyverseSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->owner, $this->team] = p2Business();
    $this->owner->switchTeam($this->team);
    $this->actingAs($this->owner);
    config(["loyverse.connections.{$this->team->id}" => ['token' => 'fixture', 'merchant' => 'm1', 'store' => 's1', 'collected_methods' => ['cash']]]);
    Queue::fake();
    Http::preventStrayRequests();
    $this->sequence = Http::sequence();
    Http::fake([
        '*/merchant/' => Http::response(['id' => 'm1', 'currency' => ['code' => 'PHP']]),
        '*/receipts?*' => $this->sequence,
    ]);
    $this->receipt = [
        'receipt_number' => 'A1', 'store_id' => 's1', 'updated_at' => '2026-10-09T19:10:00Z',
        'receipt_date' => '2026-10-09T18:00:00Z', 'receipt_type' => 'SALE', 'total_money' => '12.34',
        'payments' => [['payment_type_id' => 'cash', 'money_amount' => '12.34']],
    ];
    $this->service = app(LoyverseSyncService::class);
});

it('publishes only a completed window and resumes after an outage without duplicating cash', function () {
    $revision = array_merge($this->receipt, ['updated_at' => '2026-10-10T19:00:00Z', 'total_money' => '10', 'payments' => [['payment_type_id' => 'cash', 'money_amount' => '10']]]);
    $unknown = array_merge($this->receipt, ['receipt_number' => 'B1', 'payments' => []]);
    $this->sequence->push(['receipts' => [$this->receipt]])
        ->push(['receipts' => [$revision], 'cursor' => 'next'])->push('', 503)
        ->push(['receipts' => [$unknown, ['store_id' => 's1']]]);
    $component = Livewire::test('pages::loyverse.index', ['current_team' => $this->team])->assertSee('Ready to sync')->call('sync');
    $first = $this->team->loyverseSyncs()->sole();
    $this->service->process($this->team->id, $first->id, 1, 0);
    expect($first->fresh()->status)->toBe('complete');
    $payment = $this->team->transactions()->sole();
    expect($payment->amount)->toBe('12.34')->and($payment->transaction_date->toDateString())->toBe('2026-10-10')
        ->and($this->owner->can('update', $payment))->toBeFalse();
    $component->call('sync')->call('sync');
    $second = $this->team->loyverseSyncs()->latest('id')->first();
    expect($second->window_start->equalTo($first->window_end->subSecond()))->toBeTrue();
    $this->service->process($this->team->id, $second->id, 1, 0);
    $this->service->process($this->team->id, $second->id, 1, 1);
    $component->call('$refresh')->assertSee('Incomplete sync')->assertSee('Resume sync');
    expect($second->fresh()->cursor)->toBe('next')->and($payment->fresh()->amount)->toBe('12.34');
    $this->get(route('dashboard', $this->team))->assertSee('Previously published records may be stale');
    $component->call('sync');
    $this->service->process($this->team->id, $second->id, 1, 1);
    $this->service->process($this->team->id, $second->id, 2, 1);
    $component->call('$refresh')->assertSee('2 quarantined')->assertSee('Payment evidence is missing');
    $component->call('review', $second->id)->assertSee('B1')->assertSee('Missing receipt identifier');
    expect($this->team->transactions()->sole()->amount)->toBe('10.00')->and($second->fresh()->status)->toBe('complete');
    Queue::assertPushed(SyncLoyversePage::class, 4);
});

it('publishes the discounted tax-inclusive split sale and its later refund exactly once across replays', function () {
    config(["loyverse.connections.{$this->team->id}.collected_methods" => ['cash', 'card']]);
    $fixture = file_get_contents(base_path('tests/Fixtures/loyverse/discounted-sale-and-refund.json'));
    $this->sequence->push($fixture)->push($fixture);

    $originalIds = null;
    foreach ([1, 2] as $iteration) {
        $this->service->start($this->owner, $this->team);
        $run = $this->team->loyverseSyncs()->latest('id')->first();
        $this->service->process($this->team->id, $run->id, 1, 0);
        expect($run->fresh()->status)->toBe('complete')
            ->and($run->entries()->whereNotNull('reason')->count())->toBe(0);

        $ledger = $this->team->transactions()->orderBy('id')->get();
        // Gross PHP 112.00 less PHP 11.20 discount includes PHP 10.80 VAT.
        // The two collections less the later PHP 25.00 return leave PHP 75.80.
        expect($ledger->map(fn ($movement) => [
            $movement->source_reference, $movement->transaction_date->toDateString(), $movement->direction, $movement->amount,
        ])->all())->toBe([
            ['SALE-42', '2026-10-10', 'income', '60.00'],
            ['SALE-42', '2026-10-10', 'income', '40.80'],
            ['RETURN-7', '2026-10-12', 'expense', '25.00'],
        ]);
        if ($iteration === 1) {
            $originalIds = $ledger->modelKeys();
        } else {
            expect($ledger->modelKeys())->toBe($originalIds);
        }
    }
    $sale = $this->team->loyverseReceipts()->where('receipt_number', 'SALE-42')->sole()->receipt;
    expect(array_column($sale['payments'], 'payment_type_id'))->toBe(['cash', 'card'])
        ->and($sale['total_discounts'][0]['money_amount'])->toBe('11.20')
        ->and($sale['total_taxes'][0])->toMatchArray(['type' => 'INCLUDED', 'money_amount' => '10.80'])
        ->and($this->team->loyverseReceipts()->where('receipt_number', 'RETURN-7')->sole()->receipt['refund_for'])->toBe('SALE-42');
});

it('uses collected amounts and quarantines conflicting refund signs or amounts outside tolerance', function (
    string $type, string $total, string $paid, ?string $direction
) {
    $this->sequence->push(['receipts' => [array_merge($this->receipt, [
        'receipt_type' => $type, 'total_money' => $total, 'payments' => [['payment_type_id' => 'cash', 'money_amount' => $paid]],
    ])]]);
    $this->service->start($this->owner, $this->team);
    $run = $this->team->loyverseSyncs()->sole();
    $this->service->process($this->team->id, $run->id, 1, 0);
    expect($run->fresh()->status)->toBe('complete');
    if ($direction === null) {
        expect($this->team->transactions()->count())->toBe(0)->and($run->entries()->sole()->reason)->not->toBeNull();
    } else {
        $movement = $this->team->transactions()->sole();
        expect($movement->direction)->toBe($direction)->and($movement->amount)->toBe(ltrim($paid, '-'))
            ->and($run->entries()->sole()->reason)->toBeNull();
    }
})->with([
    'one cent difference uses the collected amount' => ['SALE', '70.00', '69.99', 'income'],
    'two cents difference requires review' => ['SALE', '70.00', '69.98', null],
    'negative refund representation counts once' => ['REFUND', '-25.00', '-25.00', 'expense'],
    'conflicting refund signs require review' => ['REFUND', '-25.00', '25.00', null],
]);

it('replays an expired cursor in the original window', function () {
    $this->sequence->push(['receipts' => [$this->receipt], 'cursor' => 'expired'])
        ->push(['errors' => [['code' => 'INVALID_CURSOR']]], 400)->push(['receipts' => [$this->receipt]]);
    $this->service->start($this->owner, $this->team);
    $run = $this->team->loyverseSyncs()->sole();
    $this->service->process($this->team->id, $run->id, 1, 0);
    $this->service->process($this->team->id, $run->id, 1, 1);
    expect($run->fresh()->cursor)->toBeNull()->and($run->fresh()->pages)->toBe(0)->and($this->team->transactions()->count())->toBe(0);
    $this->service->start($this->owner, $this->team);
    $this->service->process($this->team->id, $run->id, 2, 0);
    expect($run->fresh()->window_start->equalTo($run->window_start))->toBeTrue()->and($this->team->transactions()->count())->toBe(1);
});

it('checks onboarding and team ownership on the route, action and worker', function () {
    [$other, $otherTeam] = p2Business(TeamRole::Manager);
    $this->get(route('loyverse.index', $this->team))->assertOk();
    $component = Livewire::test('pages::loyverse.index', ['current_team' => $this->team])->call('sync');
    $run = $this->team->loyverseSyncs()->sole();
    $this->service->process($otherTeam->id, $run->id, 1, 0);
    expect($run->fresh()->status)->toBe('queued');
    [$anotherOwner, $anotherTeam] = p2Business();
    $this->actingAs($anotherOwner);
    Livewire::test('pages::loyverse.index', ['current_team' => $anotherTeam])->call('review', $run->id)->assertNotFound();
    $this->actingAs($this->owner);
    $this->team->members()->updateExistingPivot($this->owner, ['role' => TeamRole::Bookkeeper]);
    $component->call('sync')->assertForbidden();
    $this->get(route('loyverse.index', $this->team))->assertForbidden();
    $this->service->process($this->team->id, $run->id, 1, 0);
    expect($run->fresh()->status)->toBe('incomplete');
    $this->actingAs($other);
    Livewire::test('pages::loyverse.index', ['current_team' => $this->team])->assertForbidden();
    $this->team->members()->updateExistingPivot($this->owner, ['role' => TeamRole::Owner]);
    $this->actingAs($this->owner);
    $mounted = Livewire::test('pages::loyverse.index', ['current_team' => $this->team]);
    $this->team->update(['onboarding_completed_at' => null]);
    $this->get(route('loyverse.index', $this->team))->assertRedirect(route('teams.onboarding', $this->team));
    $mounted->call('sync')->assertForbidden();
    Http::assertNothingSent();
});

it('enforces sync ownership and unique receipt identities in the database', function () {
    $this->service->start($this->owner, $this->team);
    $run = $this->team->loyverseSyncs()->sole();
    $entry = $run->entries()->create(['receipt_number' => 'Q-1', 'reason' => 'Missing date']);
    expect($entry->sync->team->is($this->team))->toBeTrue()->and($run->user->is($this->owner))->toBeTrue();
    expect(fn () => DB::transaction(fn () => $entry->replicate()->save()))->toThrow(QueryException::class);
    $orphan = $entry->replicate();
    $orphan->loyverse_sync_id = $run->id + 1000;
    expect(fn () => DB::transaction(fn () => $orphan->save()))->toThrow(QueryException::class);
});

it('quarantines conflicting revisions and keeps an older replay out of cash', function () {
    $conflict = array_merge($this->receipt, ['total_money' => '17.00', 'payments' => [['payment_type_id' => 'cash', 'money_amount' => '17.00']]]);
    $older = array_merge($this->receipt, ['updated_at' => '2026-10-08T19:00:00Z']);
    $this->sequence->push(['receipts' => [$this->receipt]])->push(['receipts' => [$conflict]])->push(['receipts' => [$older]]);
    foreach ([1, 0, 0] as $expected) {
        $this->service->start($this->owner, $this->team);
        $run = $this->team->loyverseSyncs()->latest('id')->first();
        $this->service->process($this->team->id, $run->id, 1, 0);
        expect($run->fresh()->status)->toBe('complete')->and($this->team->transactions()->count())->toBe($expected);
    }
});

it('holds unverified payment methods and removes previously published cash on a cancelled revision', function () {
    $unpaid = array_merge($this->receipt, ['receipt_number' => 'unpaid', 'payments' => [['payment_type_id' => 'credit-account', 'money_amount' => '12.34']]]);
    $this->sequence->push(['receipts' => [$this->receipt, $unpaid]])
        ->push(['receipts' => [array_merge($this->receipt, ['cancelled_at' => '2026-10-12T12:00:00Z', 'updated_at' => '2026-10-12T12:00:00Z'])]]);
    foreach ([1, 0] as $expected) {
        $this->service->start($this->owner, $this->team);
        $run = $this->team->loyverseSyncs()->latest('id')->first();
        $this->service->process($this->team->id, $run->id, 1, 0);
        expect($run->fresh()->status)->toBe('complete')->and($this->team->transactions()->count())->toBe($expected);
    }
});
