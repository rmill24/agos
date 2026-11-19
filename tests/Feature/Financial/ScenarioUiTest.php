<?php

namespace Tests\Feature\Financial;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScenarioUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_run_a_scenario_from_the_livewire_page(): void
    {
        $manager = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);
        $team->members()->attach($manager, ['role' => TeamRole::Manager]);

        Livewire::actingAs($manager)
            ->test('pages::scenarios.index', ['current_team' => $team])
            ->set('scenarioType', 'expense_increase')
            ->set('amount', '10.00')
            ->set('date', now('Asia/Manila')->addDay()->toDateString())
            ->call('runScenario')
            ->assertHasNoErrors()
            ->assertSee('Hypothetical result only')
            ->assertSee('Persisted actual records were not modified');
    }

    public function test_bookkeeper_cannot_open_or_directly_invoke_the_scenario_page(): void
    {
        $bookkeeper = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);
        $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper]);

        Livewire::actingAs($bookkeeper)
            ->test('pages::scenarios.index', ['current_team' => $team])
            ->assertForbidden();
        $this->actingAs($bookkeeper)->get(route('scenarios.index', $team))->assertForbidden();
    }

    public function test_scenario_route_redirects_incomplete_business_to_onboarding(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => null]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        $this->actingAs($owner)->get(route('scenarios.index', $team))
            ->assertRedirect(route('teams.onboarding', $team));
    }

    public function test_member_cannot_open_another_teams_scenario_page(): void
    {
        $member = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $otherTeam = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);
        $otherTeam->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);
        $otherTeam->members()->attach($member, ['role' => TeamRole::Manager]);

        $this->actingAs($member)->get(route('scenarios.index', $team))->assertForbidden();
    }
}
