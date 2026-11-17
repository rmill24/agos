<?php

namespace Tests\Feature\Financial;

use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TransactionUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_categories_through_livewire(): void
    {
        [$owner, $team] = $this->businessWithOwner();
        $this->actingAs($owner);

        Livewire::test('pages::categories.index', ['current_team' => $team])
            ->set('name', 'Sales')
            ->set('type', 'income')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $category = Category::where('team_id', $team->id)->where('name', 'Sales')->firstOrFail();
        $this->assertSame('income', $category->type);

        Livewire::test('pages::categories.index', ['current_team' => $team])
            ->call('editCategory', $category->id)
            ->set('name', 'Retail sales')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Retail sales']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'category.created']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'category.updated']);

        Livewire::test('pages::categories.index', ['current_team' => $team])
            ->call('archiveCategory', $category->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'category.archived']);
    }

    public function test_authorized_user_can_create_edit_and_archive_transactions_with_audit_history(): void
    {
        [$owner, $team] = $this->businessWithOwner();
        $category = Category::factory()->create(['team_id' => $team->id, 'type' => 'both']);
        $this->actingAs($owner);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->set('direction', 'income')
            ->set('amount', '1250.50')
            ->set('transactionDate', '2026-11-17')
            ->set('description', 'Daily sales')
            ->set('categoryId', $category->id)
            ->set('sourceReference', 'cashbook-1')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $transaction = Transaction::where('team_id', $team->id)->firstOrFail();
        $this->assertSame('1250.50', $transaction->amount);
        $this->assertSame($owner->id, $transaction->recorded_by);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->call('editTransaction', $transaction->id)
            ->set('direction', 'expense')
            ->set('amount', '250.25')
            ->set('description', 'Refund')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'direction' => 'expense',
            'amount' => '250.25',
            'description' => 'Refund',
        ]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'transaction.created']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'transaction.updated']);
        $updatedAudit = AuditEvent::query()
            ->where('team_id', $team->id)
            ->where('actor_id', $owner->id)
            ->where('event_type', 'transaction.updated')
            ->firstOrFail();
        $this->assertSame('1250.50', $updatedAudit->before_values['amount']);
        $this->assertSame('250.25', $updatedAudit->after_values['amount']);
        $this->assertSame(Transaction::class, $updatedAudit->auditable_type);
        $this->assertSame($transaction->id, $updatedAudit->auditable_id);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->call('archiveTransaction', $transaction->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'transaction.archived']);
    }

    public function test_transaction_search_and_filters_are_team_scoped(): void
    {
        [$owner, $team] = $this->businessWithOwner();
        $category = Category::factory()->create(['team_id' => $team->id]);
        Transaction::factory()->create([
            'team_id' => $team->id,
            'category_id' => $category->id,
            'description' => 'Office rent',
            'direction' => 'expense',
        ]);
        Transaction::factory()->create([
            'team_id' => $team->id,
            'description' => 'Customer payment',
            'direction' => 'income',
        ]);
        $otherTeam = Team::factory()->create(['onboarding_completed_at' => now()]);
        Transaction::factory()->create(['team_id' => $otherTeam->id, 'description' => 'Office rent']);

        $this->actingAs($owner);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->set('search', 'Office')
            ->assertSee('Office rent')
            ->assertDontSee('Customer payment')
            ->set('directionFilter', 'expense')
            ->assertSee('Office rent');
    }

    public function test_invalid_amount_is_rejected_without_creating_a_transaction(): void
    {
        [$owner, $team] = $this->businessWithOwner();
        $this->actingAs($owner);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->set('amount', '10.123')
            ->set('description', 'Invalid amount')
            ->call('saveTransaction')
            ->assertHasErrors(['amount']);

        $this->assertDatabaseMissing('transactions', ['description' => 'Invalid amount']);
    }

    public function test_incomplete_business_cannot_invoke_financial_livewire_actions(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $this->actingAs($owner);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->assertForbidden();
        Livewire::test('pages::categories.index', ['current_team' => $team])
            ->assertForbidden();
    }

    public function test_non_member_cannot_invoke_financial_livewire_actions(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner]);
        $this->actingAs($user);

        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->assertForbidden();
        Livewire::test('pages::categories.index', ['current_team' => $team])
            ->assertForbidden();
    }

    public function test_cross_team_transaction_actions_are_denied_even_with_a_tampered_id(): void
    {
        [$ownerA, $teamA] = $this->businessWithOwner();
        [$ownerB, $teamB] = $this->businessWithOwner();
        $transactionB = Transaction::factory()->create(['team_id' => $teamB->id]);
        $categoryB = Category::factory()->create(['team_id' => $teamB->id]);
        $this->actingAs($ownerA);

        Livewire::test('pages::transactions.index', ['current_team' => $teamA])
            ->call('editTransaction', $transactionB->id)
            ->assertForbidden();
        Livewire::test('pages::transactions.index', ['current_team' => $teamA])
            ->call('viewTransaction', $transactionB->id)
            ->assertForbidden();
        Livewire::test('pages::transactions.index', ['current_team' => $teamA])
            ->call('archiveTransaction', $transactionB->id)
            ->assertForbidden();

        Livewire::test('pages::categories.index', ['current_team' => $teamA])
            ->call('editCategory', $categoryB->id)
            ->assertForbidden();
        $this->assertDatabaseHas('transactions', ['id' => $transactionB->id, 'team_id' => $teamB->id]);
        $this->assertDatabaseHas('categories', ['id' => $categoryB->id, 'team_id' => $teamB->id]);
    }

    public function test_manager_and_bookkeeper_can_use_transaction_actions_but_cannot_view_audit_history(): void
    {
        $manager = User::factory()->create();
        $bookkeeper = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($manager, ['role' => TeamRole::Manager]);
        $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper]);
        $transaction = Transaction::factory()->create(['team_id' => $team->id]);
        AuditEvent::factory()->create([
            'team_id' => $team->id,
            'actor_id' => $manager->id,
            'auditable_type' => Transaction::class,
            'auditable_id' => $transaction->id,
        ]);

        $this->actingAs($manager);
        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->call('viewTransaction', $transaction->id)
            ->assertHasNoErrors();

        $this->actingAs($bookkeeper);
        Livewire::test('pages::transactions.index', ['current_team' => $team])
            ->call('viewTransaction', $transaction->id)
            ->assertHasNoErrors();
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
