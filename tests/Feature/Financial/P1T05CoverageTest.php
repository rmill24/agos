<?php

namespace Tests\Feature\Financial;

use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class P1T05CoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_and_bookkeeper_can_update_and_archive_financial_records(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $bookkeeper = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $team->members()->attach($manager, ['role' => TeamRole::Manager]);
        $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper]);
        $transaction = Transaction::factory()->create(['team_id' => $team->id]);
        $category = Category::factory()->create(['team_id' => $team->id]);

        foreach ([$manager, $bookkeeper] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('update', $transaction));
            $this->assertTrue(Gate::forUser($user)->allows('delete', $transaction));
            $this->assertTrue(Gate::forUser($user)->allows('update', $category));
            $this->assertTrue(Gate::forUser($user)->allows('delete', $category));
        }

        $this->assertTrue(Gate::forUser($owner)->allows('view', $transaction));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $category));
        $this->assertTrue(Gate::forUser($owner)->allows('view', AuditEvent::factory()->create([
            'team_id' => $team->id,
            'actor_id' => $manager->id,
        ])));
    }

    public function test_category_direction_mismatch_is_rejected_by_direct_livewire_action(): void
    {
        [$owner, $team] = $this->businessWithOwner();
        $incomeCategory = Category::factory()->create([
            'team_id' => $team->id,
            'type' => 'income',
        ]);
        $this->actingAs($owner);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->set('direction', 'expense')
            ->set('amount', '100.00')
            ->set('transactionDate', '2026-11-17')
            ->set('description', 'Mismatched category')
            ->set('categoryId', $incomeCategory->id)
            ->call('saveTransaction')
            ->assertHasErrors(['category_id']);

        $this->assertDatabaseMissing('transactions', ['description' => 'Mismatched category']);
    }

    public function test_incomplete_business_is_blocked_by_both_financial_routes(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        $this->actingAs($owner);

        $this->get(route('categories.index', ['current_team' => $team->slug]))
            ->assertRedirect(route('teams.onboarding', ['current_team' => $team->slug]));
        $this->get(route('transactions.index', ['current_team' => $team->slug]))
            ->assertRedirect(route('teams.onboarding', ['current_team' => $team->slug]));
    }

    public function test_system_administrator_has_no_implicit_financial_policy_or_livewire_access(): void
    {
        $administrator = User::factory()->systemAdministrator()->create();
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $transaction = Transaction::factory()->create(['team_id' => $team->id]);
        $category = Category::factory()->create(['team_id' => $team->id]);
        $event = AuditEvent::factory()->create(['team_id' => $team->id]);

        $this->assertFalse(Gate::forUser($administrator)->allows('create', [Transaction::class, $team]));
        $this->assertFalse(Gate::forUser($administrator)->allows('view', $transaction));
        $this->assertFalse(Gate::forUser($administrator)->allows('create', [Category::class, $team]));
        $this->assertFalse(Gate::forUser($administrator)->allows('view', $category));
        $this->assertFalse(Gate::forUser($administrator)->allows('view', $event));

        $this->actingAs($administrator);
        Livewire::test('pages::transactions.index', ['current_team' => $team])->assertForbidden();
        Livewire::test('pages::categories.index', ['current_team' => $team])->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Team}
     */
    private function businessWithOwner(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);

        return [$owner, $team];
    }
}
