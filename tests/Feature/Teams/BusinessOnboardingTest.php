<?php

namespace Tests\Feature\Teams;

use App\Enums\GlobalRole;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_business_team_is_owned_by_creator_and_requires_onboarding(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner);

        Livewire::test('pages::teams.index')
            ->set('name', 'New Business')
            ->call('createTeam')
            ->assertRedirect(route('teams.onboarding', ['current_team' => 'new-business']));

        $team = Team::where('name', 'New Business')->firstOrFail();

        $this->assertSame($owner->id, $team->owner()->id);
        $this->assertFalse($team->isOnboardingComplete());
        $this->assertFalse($team->is_personal);
    }

    public function test_business_owner_can_complete_onboarding(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Unconfigured Business']);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        $this->actingAs($owner);

        Livewire::test('pages::teams.onboarding', ['current_team' => $team])
            ->set('teamName', 'Configured Business')
            ->set('legalName', 'Configured Business OPC')
            ->set('businessType', 'Retail')
            ->set('contactEmail', 'business@example.test')
            ->set('addressLine1', '1 Business Street')
            ->set('city', 'Manila')
            ->set('province', 'Metro Manila')
            ->set('postalCode', '1000')
            ->call('completeOnboarding')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', ['current_team' => 'configured-business']));

        $this->assertTrue($team->fresh()->isOnboardingComplete());
        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Configured Business',
            'legal_name' => 'Configured Business OPC',
            'currency_code' => 'PHP',
            'timezone' => 'Asia/Manila',
        ]);
    }

    public function test_incomplete_business_cannot_access_dashboard(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        $response = $this->actingAs($owner)->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertRedirect(route('teams.onboarding', ['current_team' => $team->slug]));
    }

    public function test_non_owner_cannot_complete_business_onboarding(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $team->members()->attach($manager, ['role' => TeamRole::Manager]);

        $this->actingAs($manager);

        Livewire::test('pages::teams.onboarding', ['current_team' => $team])
            ->assertForbidden();
    }

    public function test_manager_and_bookkeeper_have_business_permissions_but_not_owner_permissions(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $bookkeeper = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);

        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $team->members()->attach($manager, ['role' => TeamRole::Manager]);
        $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper]);

        $ownerPermissions = $owner->toTeamPermissions($team);
        $managerPermissions = $manager->toTeamPermissions($team);
        $bookkeeperPermissions = $bookkeeper->toTeamPermissions($team);

        $this->assertTrue($ownerPermissions->canUpdateTeam);
        $this->assertTrue($ownerPermissions->canManageBusinessUsers);
        $this->assertTrue($managerPermissions->canManageFinancialRecords);
        $this->assertTrue($managerPermissions->canRunScenarios);
        $this->assertFalse($managerPermissions->canUpdateTeam);
        $this->assertFalse($managerPermissions->canCreateInvitation);
        $this->assertTrue($bookkeeperPermissions->canManageFinancialRecords);
        $this->assertFalse($bookkeeperPermissions->canRunScenarios);
        $this->assertFalse($bookkeeperPermissions->canUpdateTeam);
        $this->assertFalse($bookkeeperPermissions->canCreateInvitation);

        $this->assertSame(TeamRole::Manager, TeamRole::Admin);
        $this->assertSame(TeamRole::Bookkeeper, TeamRole::Member);
        $this->assertTrue(TeamRole::Manager->hasPermission(TeamPermission::ManageFinancialRecords));
        $this->assertFalse(TeamRole::Manager->hasPermission(TeamPermission::DeleteTeam));
    }

    public function test_users_cannot_access_another_business_by_team_slug(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $otherTeam = Team::factory()->create(['onboarding_completed_at' => now()]);
        $otherTeam->members()->attach($otherOwner, ['role' => TeamRole::Owner]);

        $response = $this->actingAs($user)->get(route('dashboard', ['current_team' => $otherTeam->slug]));

        $response->assertForbidden();
    }

    public function test_system_administrator_is_global_and_has_no_default_business_access(): void
    {
        $administrator = User::factory()->systemAdministrator()->create();
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        $this->assertTrue($administrator->fresh()->isSystemAdministrator());
        $this->assertSame(GlobalRole::SystemAdministrator, $administrator->fresh()->global_role);
        $this->assertFalse(Gate::forUser($administrator)->allows('create', Team::class));

        $response = $this->actingAs($administrator)->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertForbidden();
        $this->assertDatabaseMissing('team_members', [
            'user_id' => $administrator->id,
            'role' => 'system_administrator',
        ]);
    }

    public function test_owner_can_complete_onboarding_with_verified_opening_balance(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        Livewire::actingAs($owner)
            ->test('pages::teams.onboarding', ['current_team' => $team])
            ->set('teamName', 'Verified Cash Business')
            ->set('legalName', 'Verified Cash Business OPC')
            ->set('businessType', 'Retail')
            ->set('contactEmail', 'verified@example.test')
            ->set('addressLine1', '1 Cash Street')
            ->set('city', 'Manila')
            ->set('province', 'Metro Manila')
            ->set('postalCode', '1000')
            ->set('openingCashBalance', '500.00')
            ->set('openingCashBalanceDate', '2026-11-01')
            ->set('openingCashBalanceVerified', true)
            ->call('completeOnboarding')
            ->assertHasNoErrors();

        $saved = $team->fresh();
        $this->assertSame('500.00', $saved->opening_cash_balance);
        $this->assertSame('2026-11-01', $saved->opening_cash_balance_date->toDateString());
        $this->assertSame($owner->id, $saved->opening_cash_balance_verified_by);
    }
}
