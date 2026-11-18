<?php

use App\Enums\TeamRole;
use App\Models\LoyverseReceipt;
use App\Services\LoyverseReceiptAdapter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->owner, $this->business] = p2Business();
    $this->adapter = app(LoyverseReceiptAdapter::class);
    config(["loyverse.connections.{$this->business->id}" => ['token' => 'fixture-token', 'merchant' => 'merchant-a', 'store' => 'store-a']]);
    Http::preventStrayRequests();
    $this->base = 'https://api.loyverse.com/v1.0';
    $this->merchant = ['id' => 'merchant-a', 'currency' => ['code' => 'PHP']];
    $this->row = ['store_id' => 'store-a', 'receipt_number' => '1-1', 'updated_at' => '2026-11-01T06:30:00.123456Z'];
});

it('stages precise financial evidence and replays revisions without publishing cash', function () {
    $page = '{"receipts":[{"store_id":"store-a","receipt_number":"1-1","updated_at":"2026-11-01T06:30:00.123456Z","total_money":1234567890123.40,"payments":[{"payment_type_id":"cash","money_amount":1234567890123.40,"payment_details":{"private":"omit"}}],"note":"ignore \"total_money\":12"}],"cursor":"page two"}';
    Http::fake([
        "$this->base/merchant/" => Http::response($this->merchant),
        "$this->base/receipts?*" => Http::sequence()->push($page)->push(['receipts' => [$this->row]]),
        "$this->base/receipts/1-1" => Http::sequence()
            ->push(array_merge($this->row, ['updated_at' => '2026-11-01T06:30:00.123457Z', 'cancelled_at' => '2026-11-01T06:30:00Z']))
            ->push($this->row),
    ]);
    $from = now()->subDay();
    $until = now();
    expect($this->adapter->stagePage($this->owner, $this->business, $from, $until))->toBe('page two');
    $record = $this->business->loyverseReceipts()->sole();
    expect($record->receipt['total_money'])->toBe('1234567890123.40')
        ->and($record->receipt['payments'][0])->toBe(['payment_type_id' => 'cash', 'money_amount' => '1234567890123.40'])
        ->and($record->receipt)->not->toHaveKey('note')
        ->and($record->team->is($this->business))->toBeTrue()
        ->and($record->currency_code)->toBe('PHP')
        ->and($record->source_updated_at->utc()->toISOString())->toBe('2026-11-01T06:30:00.123456Z');
    expect($this->adapter->stagePage($this->owner, $this->business, $from, $until, 'page two'))->toBeNull();
    Http::assertSent(function (Request $request) use ($from, $until) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $params);

        return $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer fixture-token')
            && $params === ['store_id' => 'store-a', 'updated_at_min' => $from->toISOString(), 'updated_at_max' => $until->toISOString(), 'cursor' => 'page two'];
    });
    $this->adapter->stageReceipt($this->owner, $this->business, '1-1');
    $this->adapter->stageReceipt($this->owner, $this->business, '1-1');
    expect($this->business->loyverseReceipts()->sole()->receipt)->toHaveKey('cancelled_at')
        ->and($this->business->transactions()->count())->toBe(0);
    expect(fn () => $record->replicate()->save())->toThrow(QueryException::class);
});

it('requires an onboarded owner on direct adapter calls', function () {
    [$manager, $other] = p2Business(TeamRole::Manager);
    [$outsider] = p2Business();
    foreach ([[$manager, $other], [$outsider, $this->business]] as [$user, $team]) {
        expect(fn () => $this->adapter->stageReceipt($user, $team, '1-1'))->toThrow(AuthorizationException::class);
    }
    $this->business->update(['onboarding_completed_at' => null]);
    expect(fn () => $this->adapter->stagePage($this->owner, $this->business, now()->subDay(), now()))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('rejects a mismatched merchant before requesting receipts', function () {
    Http::fake(["$this->base/merchant/" => Http::response(['id' => 'other', 'currency' => ['code' => 'PHP']])]);
    expect(fn () => $this->adapter->stageReceipt($this->owner, $this->business, '1-1'))->toThrow(RuntimeException::class, 'Cannot verify');
    Http::assertSentCount(1);
});

it('requires the credential assigned to the requested business', function () {
    [$owner, $team] = p2Business();
    expect(fn () => $this->adapter->stageReceipt($owner, $team, '1-1'))->toThrow(RuntimeException::class, 'connection is incomplete');
    Http::assertNothingSent();
});

it('keeps matching receipt numbers independent across businesses and enforces the team foreign key', function () {
    [$owner, $team] = p2Business();
    config(["loyverse.connections.$team->id" => ['token' => 'fixture-b', 'store' => 'store-b', 'merchant' => 'merchant-b']]);
    Http::fake([
        "$this->base/merchant/" => Http::sequence()->push($this->merchant)->push(['id' => 'merchant-b', 'currency' => ['code' => 'USD']]),
        "$this->base/receipts/1-1" => Http::sequence()->push($this->row)->push(array_merge($this->row, ['store_id' => 'store-b'])),
    ]);
    $this->adapter->stageReceipt($this->owner, $this->business, '1-1');
    $this->adapter->stageReceipt($owner, $team, '1-1');
    expect($team->loyverseReceipts()->sole()->currency_code)->toBe('USD')
        ->and($this->business->loyverseReceipts()->sole()->store_id)->toBe('store-a')
        ->and($team->transactions()->count())->toBe(0);
    $orphan = $team->loyverseReceipts()->sole()->replicate();
    $orphan->team_id = 999999;
    expect(fn () => $orphan->save())->toThrow(QueryException::class);
});

it('rejects a mixed-store page atomically', function () {
    Http::fake([
        "$this->base/merchant/" => Http::response($this->merchant),
        "$this->base/receipts?*" => Http::response(['receipts' => [$this->row, array_merge($this->row, ['store_id' => 'other'])]]),
    ]);
    expect(fn () => $this->adapter->stagePage($this->owner, $this->business, now()->subDay(), now()))->toThrow(RuntimeException::class, 'identity or store');
    expect(LoyverseReceipt::count())->toBe(0);
});

it('fails safely on provider errors', function (string $failure) {
    Http::fake([
        "$this->base/merchant/" => Http::response($this->merchant),
        "$this->base/receipts?*" => match ($failure) {
            'timeout' => Http::failedConnection(),
            'malformed' => Http::response('{"receipts":{}}'),
            'redirect' => Http::response('', 302, ['Location' => 'https://example.org']),
            default => Http::response('private provider details', 503),
        },
    ]);
    expect(fn () => $this->adapter->stagePage($this->owner, $this->business, now()->subDay(), now()))->toThrow(RuntimeException::class);
    expect(LoyverseReceipt::count())->toBe(0);
})->with(['timeout', 'malformed', 'redirect', 'outage']);
