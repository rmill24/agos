<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_switcher_creation_redirects_to_business_onboarding(): void
    {
        $owner = User::factory()->create();

        Livewire::actingAs($owner)
            ->test('create-team-modal')
            ->set('teamName', 'Switcher Business')
            ->call('createTeam')
            ->assertRedirect(route('teams.onboarding', ['current_team' => 'switcher-business']));
    }

    public function test_business_owner_can_edit_the_business_profile(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create([
            'onboarding_completed_at' => now(),
            'legal_name' => 'Old Legal Name',
            'cash_safety_threshold' => null,
        ]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        Livewire::actingAs($owner)
            ->test('pages::settings.business')
            ->set('teamName', 'Updated Business')
            ->set('legalName', 'Updated Legal Name')
            ->set('businessType', 'Services')
            ->set('contactEmail', 'updated@example.test')
            ->set('addressLine1', '2 Updated Street')
            ->set('city', 'Cebu City')
            ->set('province', 'Cebu')
            ->set('postalCode', '6000')
            ->set('cashSafetyThreshold', '25000.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Updated Business',
            'legal_name' => 'Updated Legal Name',
            'business_type' => 'Services',
            'city' => 'Cebu City',
            'cash_safety_threshold' => '25000.00',
        ]);
    }

    public function test_profile_edit_does_not_reset_onboarding_completion(): void
    {
        $owner = User::factory()->create();
        $completedAt = now()->subDay()->startOfSecond();
        $team = Team::factory()->create([
            'onboarding_completed_at' => $completedAt,
            'legal_name' => 'Completed Legal Name',
            'address_line1' => '1 Existing Street',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'postal_code' => '1000',
        ]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        Livewire::actingAs($owner)
            ->test('pages::settings.business')
            ->set('businessType', 'Retail')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals($completedAt, $team->fresh()->onboarding_completed_at);
    }

    public function test_manager_and_bookkeeper_cannot_edit_business_profile(): void
    {
        foreach ([TeamRole::Manager, TeamRole::Bookkeeper] as $role) {
            $user = User::factory()->create();
            $team = Team::factory()->create(['onboarding_completed_at' => now()]);
            $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);
            $team->members()->attach($user, ['role' => $role]);
            $user->switchTeam($team);

            Livewire::actingAs($user)
                ->test('pages::settings.business')
                ->assertForbidden();
        }
    }

    public function test_profile_update_rejects_a_cross_team_team_property(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $otherTeam = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $otherTeam->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        Livewire::actingAs($owner)
            ->test('pages::settings.business')
            ->set('teamId', $otherTeam->id)
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('teams', [
            'id' => $otherTeam->id,
            'name' => 'Changed by another business',
        ]);
    }

    public function test_system_administrator_cannot_open_business_profile_settings(): void
    {
        $administrator = User::factory()->systemAdministrator()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner]);
        $administrator->update(['current_team_id' => $team->id]);

        Livewire::actingAs($administrator)
            ->test('pages::settings.business')
            ->assertForbidden();
    }

    public function test_owner_can_save_a_verified_opening_balance_and_audit_event(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        Livewire::actingAs($owner)
            ->test('pages::settings.business')
            ->set('openingCashBalance', '1000.10')
            ->set('openingCashBalanceDate', '2026-11-01')
            ->set('openingCashBalanceVerified', true)
            ->set('legalName', 'Verified Business OPC')
            ->set('businessType', 'Retail')
            ->set('addressLine1', '1 Main Street')
            ->set('city', 'Manila')
            ->set('province', 'Metro Manila')
            ->set('postalCode', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $saved = $team->fresh();
        $this->assertSame('1000.10', $saved->opening_cash_balance);
        $this->assertSame('2026-11-01', $saved->opening_cash_balance_date->toDateString());
        $this->assertSame($owner->id, $saved->opening_cash_balance_verified_by);
        $this->assertSame('business.opening_cash_balance.updated', AuditEvent::where('team_id', $team->id)->latest('id')->value('event_type'));
    }

    public function test_opening_balance_requires_date_and_explicit_verification(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        Livewire::actingAs($owner)
            ->test('pages::settings.business')
            ->set('openingCashBalance', '1000.10')
            ->set('legalName', 'Verified Business OPC')
            ->set('businessType', 'Retail')
            ->set('addressLine1', '1 Main Street')
            ->set('city', 'Manila')
            ->set('province', 'Metro Manila')
            ->set('postalCode', '1000')
            ->call('save')
            ->assertHasErrors('openingCashBalance');

        $this->assertDatabaseMissing('teams', ['id' => $team->id, 'opening_cash_balance' => '1000.10']);
    }
}
