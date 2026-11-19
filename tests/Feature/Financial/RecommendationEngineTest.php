<?php

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Models\ImportBatch;
use App\Models\LoyverseSync;
use App\Models\Obligation;
use App\Models\Team;
use App\Models\Transaction;
use App\Services\CashFlowService;
use App\Services\ObligationService;
use App\Services\RecommendationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->parse('2026-11-20 02:00:00', 'UTC'));
});

function recommendationDocument(Team $team, string $kind, string $amount, string $date): Obligation
{
    $contact = $kind === 'invoice' ? $team->customers()->create(['name' => 'Customer']) : $team->suppliers()->create(['name' => 'Supplier']);

    return Obligation::create([
        'team_id' => $team->id, 'kind' => $kind, $kind === 'invoice' ? 'customer_id' : 'supplier_id' => $contact->id,
        'issue_date' => '2026-11-01', 'due_date' => $date, 'total' => $amount,
    ]);
}

function recommendationOpening(Team $team, int $actorId, string $amount, ?string $threshold = '50.00'): void
{
    $team->update([
        'opening_cash_balance' => $amount, 'opening_cash_balance_date' => '2026-11-01',
        'opening_cash_balance_verified_at' => now(), 'opening_cash_balance_verified_by' => $actorId,
        'cash_safety_threshold' => $threshold,
    ]);
}

it('activates collection and supplier rules only for outstanding records inside their supported dates', function () {
    [$actor, $team] = p2Business();
    $small = recommendationDocument($team, 'invoice', '100.01', '2026-11-19');
    app(ObligationService::class)->pay($actor, $team, 'invoice', $small->id, '99.99', '2026-11-19', (string) Str::uuid());
    $large = recommendationDocument($team, 'invoice', '20.01', '2026-11-19');
    $older = recommendationDocument($team, 'bill', '1.00', '2026-11-18');
    $today = recommendationDocument($team, 'invoice', '2.00', '2026-11-20');
    $upcoming = recommendationDocument($team, 'bill', '10.00', '2026-11-26');
    recommendationDocument($team, 'bill', '999.00', '2026-11-27');
    recommendationDocument($team, 'invoice', '999.00', '2026-11-21');
    $settled = recommendationDocument($team, 'bill', '10.00', '2026-11-19');
    app(ObligationService::class)->pay($actor, $team, 'bill', $settled->id, '10.00', '2026-11-19', (string) Str::uuid());

    $result = app(RecommendationService::class)->forTeam($actor, $team, 7);
    expect(array_column($result['recommendations'], 'evidence'))->toBe(array_map(fn ($document) => ['team_id' => $team->id, 'kind' => $document->kind, 'obligation_id' => $document->id], [$older, $large, $small, $today, $upcoming]))
        ->and(array_column($result['recommendations'], 'amount'))->toBe(['1.00', '20.01', '0.02', '2.00', '10.00'])
        ->and(array_column($result['recommendations'], 'priority'))->toBe(['High', 'High', 'High', 'Normal', 'Normal']);
});

it('reuses verified cash calculations for negative cash and gives critical exposure precedence', function () {
    [$actor, $team] = p2Business();
    recommendationOpening($team, $actor->id, '100.01');
    recommendationDocument($team, 'invoice', '2000.00', '2026-11-19');
    recommendationDocument($team, 'bill', '100.02', '2026-11-22');
    $service = app(RecommendationService::class);
    $result = $service->forTeam($actor, $team, 7);
    $risk = $result['recommendations'][0];
    $evidence = $result['evidence'][$risk['evidence_references'][0]];
    expect($risk['rule'])->toBe('negative_cash')->and($risk['priority'])->toBe('Critical')
        ->and($risk['due_date'])->toBe('2026-11-22')->and($risk['amount'])->toBe('0.01')
        ->and($risk['amounts']['projected_cash'])->toBe('-0.01')
        ->and($evidence['service'])->toBe('CashFlowService')
        ->and($evidence['result'])->toBe(app(CashFlowService::class)->forTeam($actor, $team, days: 7))
        ->and(array_column($result['recommendations'], 'rule'))->not->toContain('threshold_risk')
        ->and($service->forTeam($actor, $team, 7))->toBe($result);
});

it('supports current negative cash without a threshold and threshold risk without negative cash', function () {
    [$actor, $team] = p2Business();
    recommendationOpening($team, $actor->id, '-0.01', null);
    $risk = app(RecommendationService::class)->forTeam($actor, $team, 7)['recommendations'][0];
    expect($risk['rule'])->toBe('negative_cash')->and($risk['due_date'])->toBe('2026-11-20')
        ->and($risk['amounts']['maximum_threshold_gap'])->toBeNull();
    recommendationOpening($team, $actor->id, '60.00');
    recommendationDocument($team, 'bill', '10.01', '2026-11-21');
    $risk = app(RecommendationService::class)->forTeam($actor, $team, 7)['recommendations'][0];
    expect($risk['rule'])->toBe('threshold_risk')->and($risk['priority'])->toBe('High')
        ->and($risk['amount'])->toBe('0.01')->and($risk['amounts']['projected_cash'])->toBe('49.99');
});

it('does not invent a risk at threshold equality or claim safety when evidence supports no action', function () {
    [$actor, $team] = p2Business();
    recommendationOpening($team, $actor->id, '50.00');
    $result = app(RecommendationService::class)->forTeam($actor, $team);
    expect($result['recommendations'])->toBe([])->and($result['status'])->toBe('no_supported_action')
        ->and($result['message'])->toContain('not an assurance');
    $team->update(['opening_cash_balance_verified_at' => null]);
    $result = app(RecommendationService::class)->forTeam($actor, $team);
    expect($result['recommendations'])->toBe([])->and($result['history_complete'])->toBeFalse()
        ->and(implode(' ', $result['limitations']))->toContain('verified opening balance');
});

it('discloses uncertain collections without promising or confirming their cash effect', function () {
    [$actor, $team] = p2Business();
    recommendationOpening($team, $actor->id, '0.00', '1.00');
    recommendationDocument($team, 'invoice', '100.00', '2026-11-21');
    recommendationDocument($team, 'invoice', '10.00', '2026-11-19');
    $result = app(RecommendationService::class)->forTeam($actor, $team, 7);
    $flow = collect($result['evidence'])->firstWhere('type', 'calculation')['result'];
    expect($flow['currentCash'])->toBe('0.00')->and($flow['daily'][1]['uncertainInflow'])->toBe('100.00')
        ->and(implode(' ', $result['limitations']))->toContain('not confirmed cash');
    foreach ($result['recommendations'] as $item) {
        expect($item['cash_effect'])->toBeNull()->and($item['advisory'])->toBeTrue()
            ->and($item['limitations'])->not->toBeEmpty();
    }
});

it('discloses incomplete imports and stale sync while retaining only supported record advice', function () {
    [$actor, $team] = p2Business();
    recommendationDocument($team, 'invoice', '10.00', '2026-11-19');
    ImportBatch::create(['team_id' => $team->id, 'user_id' => $actor->id, 'headers' => [], 'committed_at' => now(), 'incomplete_history' => true]);
    LoyverseSync::create(['team_id' => $team->id, 'user_id' => $actor->id, 'status' => 'failed', 'source_mapping' => 'fixture', 'window_start' => now(), 'window_end' => now()]);
    $result = app(RecommendationService::class)->forTeam($actor, $team);
    expect(array_column($result['recommendations'], 'rule'))->toBe(['collection_review'])
        ->and($result['history_complete'])->toBeFalse()
        ->and($result['freshness'])->toBe(['latest_sync_status' => 'failed', 'last_successful_sync_at' => null])
        ->and(implode(' ', $result['recommendations'][0]['limitations']))->toContain('stale', 'incomplete history', 'verified opening balance');
});

it('returns only resolvable authorized evidence and never writes business data', function () {
    [$actor, $team] = p2Business();
    [$outsider, $other] = p2Business();
    recommendationOpening($team, $actor->id, '0.00');
    recommendationDocument($team, 'bill', '2.01', '2026-11-21');
    $foreign = recommendationDocument($other, 'invoice', '999999.99', '2026-11-19');
    Transaction::factory()->create(['team_id' => $other->id, 'amount' => '999999.99']);
    $snapshot = fn () => collect(['teams', 'obligations', 'payments', 'transactions', 'invoice_lines', 'audit_events', 'import_batches', 'loyverse_syncs'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $result = app(RecommendationService::class)->forTeam($actor, $team, 7);
    foreach ($result['recommendations'] as $item) {
        expect($item['evidence_references'])->not->toBeEmpty();
        foreach ($item['evidence_references'] as $reference) {
            expect($result['evidence'])->toHaveKey($reference);
            $source = $result['evidence'][$reference];
            expect($source['team_id'])->toBe($team->id);
            if ($source['type'] === 'record') {
                expect(Obligation::where('team_id', $team->id)->where('kind', $source['kind'])->find($source['record_id']))->not->toBeNull()
                    ->and($source['record_id'])->not->toBe($foreign->id);
            }
        }
    }
    expect($snapshot())->toBe($before);
});

it('authorizes every service read for each permitted business role', function (TeamRole $role) {
    [$actor, $team] = p2Business($role);
    expect(app(RecommendationService::class)->forTeam($actor, $team)['team_id'])->toBe($team->id);
    $team->members()->detach($actor);
    expect(fn () => app(RecommendationService::class)->forTeam($actor, $team))->toThrow(AuthorizationException::class);
})->with([TeamRole::Owner, TeamRole::Manager, TeamRole::Bookkeeper]);

it('rejects cross-Team context, global administrators and incomplete onboarding at service and livewire boundaries', function () {
    [$actor, $team] = p2Business();
    [$outsider] = p2Business();
    $outsider->update(['current_team_id' => $team->id]);
    expect(fn () => app(RecommendationService::class)->forTeam($outsider, $team))->toThrow(AuthorizationException::class);
    $this->actingAs($actor);
    $component = Livewire::test('pages::recommendations.index', ['current_team' => $team]);
    $actor->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save();
    expect(fn () => app(RecommendationService::class)->forTeam($actor, $team))->toThrow(AuthorizationException::class);
    $component->call('$refresh')->assertForbidden();
    $actor->forceFill(['global_role' => null])->save();
    $component = Livewire::test('pages::recommendations.index', ['current_team' => $team]);
    $team->update(['onboarding_completed_at' => null]);
    expect(fn () => app(RecommendationService::class)->forTeam($actor, $team))->toThrow(AuthorizationException::class);
    $component->call('$refresh')->assertForbidden();
    $this->get(route('recommendations.index', $team))->assertRedirect(route('teams.onboarding', $team));
});

it('rejects unsupported forecast periods rather than silently changing the recommendation window', function () {
    [$actor, $team] = p2Business();
    expect(fn () => app(RecommendationService::class)->forTeam($actor, $team, 0))->toThrow(ValidationException::class);
});

it('reloads actor security state instead of trusting an earlier authorized instance', function () {
    [$actor, $team] = p2Business();
    $service = app(RecommendationService::class);
    $service->forTeam($actor, $team);
    $actor->fresh()->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save();
    expect($actor->isSystemAdministrator())->toBeFalse()
        ->and(fn () => $service->forTeam($actor, $team))->toThrow(AuthorizationException::class);
});

it('does not infer absolute cash from an opening balance that is not yet effective', function () {
    [$actor, $team] = p2Business();
    recommendationOpening($team, $actor->id, '100.00');
    $team->update(['opening_cash_balance_date' => '2026-11-21']);
    recommendationDocument($team, 'bill', '200.00', '2026-11-22');
    $result = app(RecommendationService::class)->forTeam($actor, $team, 7);
    expect(array_column($result['recommendations'], 'rule'))->toBe(['supplier_obligation'])
        ->and($result['history_complete'])->toBeFalse()
        ->and(implode(' ', $result['limitations']))->toContain('effective after');
});

it('reports last published sync time during a later failure without claiming fresh data', function () {
    [$actor, $team] = p2Business();
    $attributes = ['team_id' => $team->id, 'user_id' => $actor->id, 'source_mapping' => 'fixture', 'window_start' => now()->subDays(3), 'window_end' => now()->subDays(2)];
    $success = LoyverseSync::create(['status' => 'complete', 'completed_at' => now()->subDays(2)] + $attributes);
    LoyverseSync::create(['status' => 'failed'] + $attributes);
    $result = app(RecommendationService::class)->forTeam($actor, $team);
    expect($result['freshness']['last_successful_sync_at'])->toBe($success->completed_at->toIso8601String())
        ->and($result['freshness']['latest_sync_status'])->toBe('failed')
        ->and(implode(' ', $result['limitations']))->toContain('stale');
});
