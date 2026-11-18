<?php

namespace Tests\Feature\Financial;

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_show_only_their_directory_for_the_requested_team(): void
    {
        [$actor, $team] = $this->business(TeamRole::Bookkeeper);
        $team->customers()->create(['name' => 'Customer Directory Entry']);
        $team->suppliers()->create(['name' => 'Supplier Directory Entry']);

        $this->actingAs($actor)->get(route('dashboard', $team))
            ->assertOk()->assertSee('View customers')->assertSee('View suppliers')
            ->assertSee(route('customers.index', $team))->assertSee(route('suppliers.index', $team));

        $this->actingAs($actor)->get(route('customers.index', $team))
            ->assertOk()->assertSee('Customer Directory Entry')->assertDontSee('Supplier Directory Entry');
        $this->get(route('suppliers.index', $team))
            ->assertOk()->assertSee('Supplier Directory Entry')->assertDontSee('Customer Directory Entry');
    }

    public function test_direct_customer_actions_validate_and_audit_the_lifecycle(): void
    {
        [$actor, $team] = $this->business(TeamRole::Manager);
        $this->actingAs($actor);
        $component = Livewire::test('pages::contacts.index', ['current_team' => $team]);
        $component->call('save')->assertHasErrors(['name' => 'required'])
            ->set('name', 'Local customer')->call('save')->assertHasNoErrors();
        $customer = $team->customers()->sole();
        $this->assertTrue($customer->team->is($team));

        $component->set('search', 'unmatched')->assertSee('No matching contacts.')
            ->set('search', 'local')->assertSee('Local customer')->set('search', '')
            ->call('openProfile', $customer->id)->assertSee('Customer profile')
            ->call('edit', $customer->id)->set('name', 'Updated customer')
            ->call('save')->assertSee('Archive Updated customer?')->assertSee('Archive customer')
            ->assertDontSee('wire:confirm', false);
        $this->assertNotSoftDeleted($customer);
        $component->call('archive', $customer->id)->assertSee('Updated customer was archived.')->assertSee('No customers yet.');
        $this->assertSoftDeleted($customer);
        $component->set('status', 'archived')->call('openProfile', $customer->id)
            ->assertSee('Updated customer')->assertDontSee('Edit details');
        foreach (['created', 'updated', 'archived'] as $action) {
            $this->assertDatabaseHas('audit_events', ['event_type' => 'customer.'.$action, 'team_id' => $team->id]);
        }
    }

    public function test_onboarding_and_membership_guard_routes_and_direct_actions(): void
    {
        [$actor, $team] = $this->business();
        [, $otherTeam] = $this->business();
        $other = $otherTeam->customers()->create(['name' => 'Private customer']);
        $this->actingAs($actor);
        $this->get(route('customers.index', $otherTeam))->assertForbidden();
        Livewire::test('pages::contacts.index', ['current_team' => $team])
            ->call('openProfile', $other->id)->assertNotFound();
        Livewire::test('pages::contacts.index', ['current_team' => $team])
            ->assertDontSee('Private customer')->call('edit', $other->id)->assertNotFound();
        Livewire::test('pages::contacts.index', ['current_team' => $team])
            ->call('archive', $other->id)->assertNotFound();
        $component = Livewire::test('pages::contacts.index', ['current_team' => $team])->set('name', 'Denied');
        $team->update(['onboarding_completed_at' => null]);
        $this->get(route('suppliers.index', $team))->assertRedirect(route('teams.onboarding', $team));
        $component->call('save')->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'Denied']);

        $admin = User::factory()->create(['global_role' => GlobalRole::SystemAdministrator]);
        $this->actingAs($admin)->get(route('suppliers.index', $otherTeam))->assertForbidden();
        Livewire::test('pages::contacts.index', ['current_team' => $otherTeam])->assertForbidden();
    }

    public function test_directory_sort_and_pagination_apply_to_the_full_result_set(): void
    {
        [$actor, $team] = $this->business();
        foreach (range(1, 11) as $number) {
            $team->customers()->create(['name' => sprintf('Buyer %02d', $number)]);
        }

        $this->actingAs($actor);
        Livewire::test('pages::contacts.index', ['current_team' => $team])
            ->assertSeeInOrder(['Buyer 01', 'Buyer 10'])->assertDontSee('Buyer 11')
            ->call('nextPage')->assertSee('Page 2 of 2')->assertSee('Buyer 11')
            ->set('sort', 'name_desc')->assertSee('Page 1 of 2')->assertSeeInOrder(['Buyer 11', 'Buyer 02'])
            ->assertDontSee('Buyer 01')->set('search', 'Buyer 03')->assertSee('1 record')->assertSee('Page 1 of 1');
    }

    private function business(TeamRole $role = TeamRole::Owner): array
    {
        $actor = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($actor, ['role' => $role]);

        return [$actor, $team];
    }
}
