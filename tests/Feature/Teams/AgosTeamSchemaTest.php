<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgosTeamSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_is_the_business_record_with_profile_defaults(): void
    {
        $team = Team::factory()->create()->fresh();

        $this->assertSame('PH', $team->country_code);
        $this->assertSame('PHP', $team->currency_code);
        $this->assertSame('Asia/Manila', $team->timezone);
        $this->assertNull($team->onboarding_completed_at);
        $this->assertFalse($team->isOnboardingComplete());
        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'country_code' => 'PH',
            'currency_code' => 'PHP',
            'timezone' => 'Asia/Manila',
        ]);
    }

    public function test_team_profile_and_onboarding_state_are_persisted(): void
    {
        $completedAt = Carbon::parse('2026-11-17 10:00:00');
        $team = Team::factory()->create([
            'legal_name' => 'AGOS Sample Trading Inc.',
            'business_type' => 'retail',
            'contact_email' => 'owner@example.test',
            'contact_phone' => '+63 900 000 0000',
            'address_line1' => '1 Sample Street',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'postal_code' => '1000',
            'onboarding_completed_at' => $completedAt,
        ]);

        $team = $team->fresh();

        $this->assertSame('AGOS Sample Trading Inc.', $team->legal_name);
        $this->assertTrue($team->isOnboardingComplete());
        $this->assertTrue($team->onboarding_completed_at->equalTo($completedAt));
    }

    public function test_owner_manager_and_bookkeeper_roles_are_persisted_and_cast(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $bookkeeper = User::factory()->create();
        $team = Team::factory()->create();

        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $team->members()->attach($manager, ['role' => TeamRole::Manager]);
        $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper]);

        $this->assertSame($owner->id, $team->fresh()->owner()->id);
        $this->assertSame(TeamRole::Owner, $team->memberships()->where('user_id', $owner->id)->first()->role);
        $this->assertTrue($team->memberships()->where('user_id', $manager->id)->first()->isManager());
        $this->assertTrue($team->memberships()->where('user_id', $bookkeeper->id)->first()->isBookkeeper());
        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $manager->id,
            'role' => 'manager',
        ]);
    }

    public function test_team_invitation_uses_agos_role_values(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'invited_by' => $owner->id,
            'role' => TeamRole::Manager,
        ]);

        $this->assertSame(TeamRole::Manager, $invitation->fresh()->role);
        $this->assertTrue($invitation->fresh()->isManager());
        $this->assertDatabaseHas('team_invitations', [
            'id' => $invitation->id,
            'role' => 'manager',
        ]);
    }

    public function test_team_membership_foreign_keys_and_uniqueness_are_enforced(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $team->members()->attach($user, ['role' => TeamRole::Bookkeeper]);

        $this->expectException(QueryException::class);
        $team->members()->attach($user, ['role' => TeamRole::Manager]);
    }

    public function test_team_membership_foreign_key_rejects_unknown_team(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        Membership::create([
            'team_id' => 999999,
            'user_id' => $user->id,
            'role' => TeamRole::Bookkeeper,
        ]);
    }

    public function test_team_invitation_foreign_keys_and_code_uniqueness_are_enforced(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'invited_by' => $owner->id,
        ]);

        $this->expectException(QueryException::class);
        DB::table('team_invitations')->insert([
            'code' => $invitation->code,
            'team_id' => $team->id,
            'email' => 'another@example.test',
            'role' => TeamRole::Bookkeeper->value,
            'invited_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_team_invitation_foreign_key_rejects_unknown_team(): void
    {
        $owner = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('team_invitations')->insert([
            'code' => 'invalid-team-invitation',
            'team_id' => 999999,
            'email' => 'invited@example.test',
            'role' => TeamRole::Bookkeeper->value,
            'invited_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_agos_does_not_create_duplicate_business_or_membership_tables(): void
    {
        $this->assertFalse(Schema::hasTable('businesses'));
        $this->assertFalse(Schema::hasTable('memberships'));
        $this->assertTrue(Schema::hasTable('teams'));
        $this->assertTrue(Schema::hasTable('team_members'));
    }
}
