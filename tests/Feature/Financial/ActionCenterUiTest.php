<?php

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Integrations\Ai\GroqExplanationProvider;
use App\Models\ImportBatch;
use App\Models\LoyverseSync;
use App\Models\Obligation;
use App\Models\Team;
use App\Services\RecommendationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->parse('2026-11-20 02:00:00', 'UTC'));
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::assertNothingSent();
});

function actionCenterDocument(Team $team, string $kind, string $amount, string $date, string $label): Obligation
{
    $contact = ($kind === 'invoice' ? $team->customers() : $team->suppliers())->create(['name' => $label]);

    return Obligation::create([
        'team_id' => $team->id, 'kind' => $kind, $kind === 'invoice' ? 'customer_id' : 'supplier_id' => $contact->id,
        'number' => $label, 'description' => $label, 'issue_date' => '2026-11-01', 'due_date' => $date, 'total' => $amount,
    ]);
}

it('renders service order, labeled financial exposure, record links and calculation evidence', function () {
    [$actor, $team] = p2Business();
    $team->update([
        'opening_cash_balance' => '1000.01', 'opening_cash_balance_date' => '2026-11-01',
        'opening_cash_balance_verified_at' => now(), 'opening_cash_balance_verified_by' => $actor->id, 'cash_safety_threshold' => '500.00',
    ]);
    $invoice = actionCenterDocument($team, 'invoice', '10000.01', '2026-11-19', 'INV-UI');
    $bill = actionCenterDocument($team, 'bill', '1200.02', '2026-11-21', 'BILL-UI');
    $invoiceUrl = route('invoices.index', ['current_team' => $team, 'document' => $invoice->id]);
    $billUrl = route('bills.index', ['current_team' => $team, 'document' => $bill->id]);

    $this->actingAs($actor)->get(route('recommendations.index', $team))->assertOk()
        ->assertSeeInOrder(['Critical priority', 'Review projected cash shortfall', 'High priority', 'Follow up on collection', 'Normal priority', 'Review supplier payment'])
        ->assertSee('₱10,000.01')->assertSee('₱1,200.02')->assertSee('₱200.01')->assertSee('₱-200.01')
        ->assertSee('2026-11-21')->assertSee('Uncertain collection')->assertSee('Expected outflow')
        ->assertSee('Projected risk')->assertSee('Confirmed current cash')->assertSee('Limitations for this recommendation')
        ->assertSee('Expected inflow')->assertSee('Optional outflow')->assertSee('Future-dated confirmed entries remain forecast inputs')
        ->assertSee('href="#cash-flow-evidence"', false)->assertSee('id="cash-flow-evidence"', false)
        ->assertSee($invoiceUrl)->assertSee($billUrl);
    $this->get($invoiceUrl)->assertOk()->assertSee('INV-UI');
    $this->get($billUrl)->assertOk()->assertSee('BILL-UI');
});

it('renders an optional validated AI explanation without changing deterministic recommendation cards', function () {
    [$actor, $team] = p2Business();
    $invoice = actionCenterDocument($team, 'invoice', '100.01', '2026-11-19', 'AI-UI-INVOICE');
    config(['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.groq.api_key' => 'synthetic-test-credential', 'ai.groq.model' => 'openai/gpt-oss-20b']);
    $baseline = app(RecommendationService::class)->forTeam($actor, $team);
    Http::fake(function (Request $request) {
        $input = json_decode($request['messages'][1]['content'], true, 32, JSON_THROW_ON_ERROR);
        $plan = [
            'schema_version' => '1', 'snapshot_id' => $input['snapshot_id'],
            'summary_action_ids' => [$input['actions'][0]['id']],
            'actions' => array_map(fn (array $action) => [
                'action_id' => $action['id'], 'explanation_codes' => ['why_this_action', 'cash_treatment'],
                'evidence_references' => $action['evidence_references'],
            ], $input['actions']),
            'limitation_ids' => array_keys($input['limitations']),
        ];

        return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($plan, JSON_THROW_ON_ERROR)]]]]);
    });
    $component = Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->assertSee('Deterministic fallback active')->assertSee('Explain verified recommendations')
        ->call('requestExplanation')->assertSee('AI-generated explanation available')
        ->assertSee('AI-generated explanation:')->assertSee('uncertain collection, not confirmed cash')
        ->assertSeeInOrder(['Follow up on collection', 'AI-generated explanation:']);
    expect(app(RecommendationService::class)->forTeam($actor, $team))->toBe($baseline);
    expect(fn () => $component->set('aiExplanation', ['actions' => [['explanation' => 'forged']]]))->toThrow(CannotUpdateLockedPropertyException::class);
    Http::assertSent(fn (Request $request) => $request->url() === GroqExplanationProvider::ENDPOINT);
    Http::fake();
});

it('discloses when AI explains only the highest-priority bounded subset', function () {
    [$actor, $team] = p2Business();
    for ($index = 1; $index <= 11; $index++) {
        actionCenterDocument($team, 'invoice', '100.01', '2026-11-19', 'AI-SUBSET-'.$index);
    }
    config(['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.groq.api_key' => 'synthetic-test-credential', 'ai.groq.model' => 'openai/gpt-oss-20b']);
    Http::fake(function (Request $request) {
        $input = json_decode($request['messages'][1]['content'], true, 32, JSON_THROW_ON_ERROR);
        $plan = [
            'schema_version' => '1', 'snapshot_id' => $input['snapshot_id'],
            'summary_action_ids' => [$input['actions'][0]['id']],
            'actions' => array_map(fn (array $action) => [
                'action_id' => $action['id'], 'explanation_codes' => ['why_this_action'],
                'evidence_references' => $action['evidence_references'],
            ], $input['actions']),
            'limitation_ids' => array_keys($input['limitations']),
        ];

        return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($plan, JSON_THROW_ON_ERROR)]]]]);
    });
    Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->call('requestExplanation')->assertSee('AI explanation covers the highest-priority 10 of 11 recommendations.')
        ->assertSee('AI-SUBSET-11')->assertSee('AI-generated explanation available');
    Http::fake();
});

it('keeps deterministic guidance visible when an AI response is rejected and reauthorizes AI requests', function () {
    [$actor, $team] = p2Business();
    actionCenterDocument($team, 'invoice', '100.01', '2026-11-19', 'AI-REJECTED');
    config(['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.groq.api_key' => 'synthetic-test-credential', 'ai.groq.model' => 'openai/gpt-oss-20b']);
    Http::fake(function (Request $request) {
        $input = json_decode($request['messages'][1]['content'], true, 32, JSON_THROW_ON_ERROR);
        $plan = [
            'schema_version' => '1', 'snapshot_id' => $input['snapshot_id'], 'summary_action_ids' => [$input['actions'][0]['id']],
            'actions' => [['action_id' => 'fabricated-action', 'explanation_codes' => ['why_this_action'], 'evidence_references' => []]],
            'limitation_ids' => array_keys($input['limitations']),
        ];

        return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($plan, JSON_THROW_ON_ERROR)]]]]);
    });
    Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->call('requestExplanation')->assertSee('AI explanation rejected for safety')
        ->assertSee('Retry AI explanation')->assertSee('Follow up on collection')->assertDontSee('fabricated-action');
    Http::fake();
    $component = Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team]);
    $team->members()->detach($actor);
    $component->call('requestExplanation')->assertForbidden();
});

it('shows a rate-limit fallback without removing deterministic guidance', function () {
    [$actor, $team] = p2Business();
    actionCenterDocument($team, 'invoice', '100.01', '2026-11-19', 'AI-RATE-LIMIT');
    config(['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.groq.api_key' => 'synthetic-test-credential', 'ai.groq.model' => 'openai/gpt-oss-20b']);
    Http::fake([GroqExplanationProvider::ENDPOINT => Http::response([], 429)]);
    Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->call('requestExplanation')->assertSee('AI explanation temporarily unavailable')
        ->assertSee('Retry AI explanation')->assertSee('Follow up on collection');
    Http::fake();
});

it('renders threshold-only guidance and the exact service rationale', function () {
    [$actor, $team] = p2Business();
    $team->update([
        'opening_cash_balance' => '100.00', 'opening_cash_balance_date' => '2026-11-01',
        'opening_cash_balance_verified_at' => now(), 'opening_cash_balance_verified_by' => $actor->id, 'cash_safety_threshold' => '100.01',
    ]);
    $risk = app(RecommendationService::class)->forTeam($actor, $team)['recommendations'][0];
    Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->assertSee('Protect the cash-safety threshold')->assertSee($risk['rationale'])
        ->assertSee('Maximum threshold gap')->assertSee('₱0.01')->assertSee('First threshold shortfall:');
});

it('shows incomplete history, import and stale-sync warnings without fabricated cash safety', function () {
    [$actor, $team] = p2Business();
    ImportBatch::create(['team_id' => $team->id, 'user_id' => $actor->id, 'headers' => [], 'committed_at' => now(), 'incomplete_history' => true]);
    $sync = ['team_id' => $team->id, 'user_id' => $actor->id, 'source_mapping' => 'fixture', 'window_start' => now()->subDay(), 'window_end' => now()];
    LoyverseSync::create(['status' => 'complete', 'completed_at' => '2026-11-19 02:00:00'] + $sync);
    LoyverseSync::create(['status' => 'failed'] + $sync);
    Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->assertSee('Incomplete cash history')->assertSee('verified opening cash is unavailable')
        ->assertSee('Stale Loyverse data warning')->assertSee('Committed CSV imports include incomplete history')
        ->assertSee('Nov 19, 2026 10:00 AM')->assertSee('No action identified')
        ->assertSee('not an assurance of cash safety')->assertSee('Unavailable')
        ->assertDontSee('Review projected cash shortfall');
});

it('shows uncertainty limitations and keeps collection advice separate from confirmed cash', function () {
    [$actor, $team] = p2Business();
    actionCenterDocument($team, 'invoice', '20.00', '2026-11-19', 'OVERDUE');
    actionCenterDocument($team, 'invoice', '200.00', '2026-11-21', 'FUTURE');
    Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->assertSee('Follow up on collection')->assertSee('Uncertain collection')
        ->assertSee('these are not confirmed cash')->assertSee('Outstanding receivables are uncertain collections')
        ->assertSee('receipt and timing are not guaranteed');
});

it('regenerates recommendations on refresh and does not hydrate financial evidence as editable state', function () {
    [$actor, $team] = p2Business();
    $component = Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->assertSee('No action identified');
    actionCenterDocument($team, 'bill', '99.01', '2026-11-21', 'NEW-BILL');
    $component->call('refreshRecommendations')->assertSee('Recommendations refreshed.')
        ->assertSee('NEW-BILL')->assertSee('₱99.01')->assertDontSee('No action identified');
    expect($component->snapshot['data'])->not->toHaveKey('report')->not->toHaveKey('cashFlow');
});

it('shows a safe failure and retries without displaying provider or database details', function () {
    [$actor, $team] = p2Business();
    $real = app(RecommendationService::class);
    $fake = Mockery::mock(RecommendationService::class);
    $fake->shouldReceive('forTeam')->once()->andThrow(new QueryException('sqlite', 'private SQL placeholder', [], new RuntimeException('private failure placeholder')));
    app()->instance(RecommendationService::class, $fake);
    $component = Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team])
        ->assertSee('Recommendations could not be loaded')->assertSee('Retry recommendations')
        ->assertDontSee('private SQL placeholder')->assertDontSee('private failure placeholder')
        ->assertDontSee('No action identified');
    app()->instance(RecommendationService::class, $real);
    $component->call('refreshRecommendations')->assertSee('Recommendations refreshed.')
        ->assertSee('No action identified')->assertDontSee('Recommendations could not be loaded');
});

it('keeps another teams records and calculation evidence out of the current review even with membership of both', function () {
    [$actor, $team] = p2Business();
    [, $other] = p2Business();
    $other->members()->attach($actor, ['role' => TeamRole::Bookkeeper]);
    $foreign = actionCenterDocument($other, 'invoice', '987654.32', '2026-11-19', 'FOREIGN-INVOICE');
    $other->update(['cash_safety_threshold' => '123456.78']);
    $this->actingAs($actor)->get(route('recommendations.index', ['current_team' => $team, 'document' => $foreign->id, 'evidence' => 'team:'.$other->id.':invoice:'.$foreign->id]))
        ->assertOk()->assertDontSee('FOREIGN-INVOICE')->assertDontSee('987,654.32')->assertDontSee('123,456.78');
    $this->get(route('invoices.index', ['current_team' => $team, 'document' => $foreign->id]))->assertNotFound();
    Livewire::test('pages::obligations.index', ['current_team' => $team])->call('open', $foreign->id)->assertNotFound();
});

it('requires authentication and independently rejects foreign team mounts', function () {
    [$actor, $team] = p2Business();
    [$outsider] = p2Business();
    $this->get(route('recommendations.index', $team))->assertRedirect(route('login'));
    $this->actingAs($outsider)->get(route('recommendations.index', $team))->assertForbidden();
    Livewire::test('pages::recommendations.index', ['current_team' => $team])->assertForbidden();
});

it('shows role-aware navigation and permits all three financial reader roles', function (TeamRole $role) {
    [$actor, $team] = p2Business($role);
    $this->actingAs($actor)->get(route('dashboard', $team))->assertOk()->assertSee(route('recommendations.index', $team));
    $this->get(route('recommendations.index', $team))->assertOk()->assertSee('Action Center');
    Livewire::test('pages::recommendations.index', ['current_team' => $team])->call('refreshRecommendations')->assertOk();
})->with([TeamRole::Owner, TeamRole::Manager, TeamRole::Bookkeeper]);

it('rechecks revoked membership, administrator status and onboarding on direct refresh actions', function (string $action, string $restriction) {
    [$actor, $team] = p2Business();
    $component = Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team]);
    match ($restriction) {
        'membership' => $team->members()->detach($actor),
        'administrator' => $actor->fresh()->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save(),
        'onboarding' => $team->update(['onboarding_completed_at' => null]),
    };
    $component->call($action)->assertForbidden();
})->with(['refreshRecommendations', '$refresh'])->with(['membership', 'administrator', 'onboarding']);

it('keeps the team context locked against property tampering', function () {
    [$actor, $team] = p2Business();
    [, $other] = p2Business();
    $component = Livewire::actingAs($actor)->test('pages::recommendations.index', ['current_team' => $team]);
    expect(fn () => $component->set('team', $other))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('redirects incomplete businesses and denies personal teams and global administrators', function () {
    [$actor, $team] = p2Business();
    $this->actingAs($actor);
    $team->update(['onboarding_completed_at' => null]);
    $this->get(route('recommendations.index', $team))->assertRedirect(route('teams.onboarding', $team));
    Livewire::test('pages::recommendations.index', ['current_team' => $team])->assertForbidden();
    $team->update(['onboarding_completed_at' => now(), 'is_personal' => true]);
    $this->get(route('recommendations.index', $team))->assertForbidden();
    $this->get(route('dashboard', $team))->assertOk()->assertDontSee(route('recommendations.index', $team));
    $team->update(['is_personal' => false]);
    $actor->forceFill(['global_role' => GlobalRole::SystemAdministrator])->save();
    $this->get(route('recommendations.index', $team))->assertForbidden();
});

it('formats decimal strings without losing cents at the financial storage limit', function () {
    foreach (['9999999999999.99' => '₱9,999,999,999,999.99', '-1000.01' => '₱-1,000.01', '0.00' => '₱0.00'] as $amount => $formatted) {
        expect(Blade::render('<x-php-amount :amount="$amount" />', compact('amount')))->toContain($formatted);
    }
    expect(Blade::render('<x-php-amount />'))->toContain('Unavailable');
});
