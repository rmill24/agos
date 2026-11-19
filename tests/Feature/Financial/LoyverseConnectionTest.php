<?php

use App\Enums\TeamRole;
use App\Models\LoyverseConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows the owner to save and test an encrypted connection', function () {
    [$owner, $team] = p2Business();
    $this->actingAs($owner);
    Http::fake(['https://api.loyverse.com/v1.0/merchant/' => Http::response([
        'id' => 'merchant-coffee', 'currency' => ['code' => 'PHP'],
        'business_name' => 'Coffee Shop',
    ]),
        'https://api.loyverse.com/v1.0/stores' => Http::response(['stores' => [
            ['id' => 'store-manila', 'name' => 'Manila Main'],
        ]]),
        'https://api.loyverse.com/v1.0/payment_types' => Http::response(['payment_types' => [
            ['id' => 'cash-id', 'name' => 'Cash', 'type' => 'CASH', 'stores' => ['store-manila']],
            ['id' => 'card-id', 'name' => 'Card', 'type' => 'NONINTEGRATEDCARD', 'stores' => ['store-manila']],
        ]]),
        'https://api.loyverse.com/v1.0/receipts?*' => Http::response(['receipts' => []]),
    ]);

    Livewire::test('pages::loyverse.index', ['current_team' => $team])
        ->set('accessToken', 'secret-token')
        ->call('loadMetadata')
        ->assertSet('merchantName', 'Coffee Shop')
        ->assertSet('stores.0.name', 'Manila Main')
        ->set('selectedStoreId', 'store-manila')
        ->set('selectedPaymentTypeIds', ['cash-id', 'card-id'])
        ->call('saveConnection')
        ->assertHasNoErrors();

    $connection = LoyverseConnection::query()->where('team_id', $team->id)->sole();
    expect($connection->access_token)->toBe('secret-token')
        ->and($connection->collected_method_ids)->toBe(['cash-id', 'card-id'])
        ->and($connection->verified_at)->not->toBeNull();
    expect($connection->getRawOriginal('access_token'))->not->toBe('secret-token');
    $this->assertDatabaseHas('audit_events', ['team_id' => $team->id, 'event_type' => 'loyverse.connection.saved']);
    $this->assertDatabaseHas('audit_events', ['team_id' => $team->id, 'event_type' => 'loyverse.connection.tested']);
});
it('keeps the stored token when the owner updates the mapping', function () {
    [$owner, $team] = p2Business();
    $team->loyverseConnection()->create([
        'access_token' => 'old-token', 'merchant_id' => 'merchant-old', 'store_id' => 'store-old',
        'collected_method_ids' => ['cash-id'],
    ]);
    Http::fake([
        'https://api.loyverse.com/v1.0/merchant/' => Http::response(['id' => 'merchant-new', 'business_name' => 'New Shop', 'currency' => ['code' => 'PHP']]),
        'https://api.loyverse.com/v1.0/stores' => Http::response(['stores' => [['id' => 'store-new', 'name' => 'New Store']]]),
        'https://api.loyverse.com/v1.0/payment_types' => Http::response(['payment_types' => [['id' => 'card-id', 'name' => 'Card', 'type' => 'NONINTEGRATEDCARD', 'stores' => ['store-new']]]]),
        'https://api.loyverse.com/v1.0/receipts?*' => Http::response(['receipts' => []]),
    ]);

    Livewire::actingAs($owner)->test('pages::loyverse.index', ['current_team' => $team])
        ->call('loadMetadata')->set('selectedStoreId', 'store-new')->set('selectedPaymentTypeIds', ['card-id'])
        ->call('saveConnection')->assertHasNoErrors();

    $connection = $team->loyverseConnection()->firstOrFail();
    expect($connection->access_token)->toBe('old-token')->and($connection->merchant_id)->toBe('merchant-new');
});

it('uses the configured environment connection when no database connection exists', function () {
    [$owner, $team] = p2Business();
    config(["loyverse.connections.{$team->id}" => [
        'token' => 'env-token', 'merchant' => 'env-merchant', 'store' => 'env-store', 'collected_methods' => ['cash-id'],
    ]]);

    Livewire::actingAs($owner)->test('pages::loyverse.index', ['current_team' => $team])
        ->assertSet('merchantId', 'env-merchant')->assertSet('selectedStoreId', 'env-store');
});

it('rejects non-owner, cross-team, system-admin and incomplete direct actions', function () {
    [$owner, $team] = p2Business();
    $manager = User::factory()->create();
    $team->members()->attach($manager, ['role' => TeamRole::Manager]);
    $other = Team::factory()->create(['onboarding_completed_at' => now()]);
    $other->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);

    Livewire::actingAs($manager)->test('pages::loyverse.index', ['current_team' => $team])->assertForbidden();
    Livewire::actingAs($owner)->test('pages::loyverse.index', ['current_team' => $other])->assertForbidden();

    $team->update(['onboarding_completed_at' => null]);
    Livewire::actingAs($owner)->test('pages::loyverse.index', ['current_team' => $team])->assertForbidden();
});

it('does not overwrite the connection when merchant verification fails', function () {
    [$owner, $team] = p2Business();
    $team->loyverseConnection()->create([
        'access_token' => 'old-token', 'merchant_id' => 'merchant-old', 'store_id' => 'store-old',
        'collected_method_ids' => [],
    ]);
    Http::fake(['https://api.loyverse.com/v1.0/merchant/' => Http::response(['id' => 'wrong-merchant', 'currency' => ['code' => 'PHP']])]);

    expect(fn () => Livewire::actingAs($owner)->test('pages::loyverse.index', ['current_team' => $team])
        ->set('accessToken', 'new-token')->call('loadMetadata'))->toThrow(RuntimeException::class);

    expect($team->loyverseConnection()->firstOrFail()->access_token)->toBe('old-token');
});
