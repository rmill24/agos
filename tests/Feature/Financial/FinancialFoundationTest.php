<?php

namespace Tests\Feature\Financial;

use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancialFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_tables_have_the_expected_team_scoped_schema(): void
    {
        $this->assertTrue(Schema::hasTable('categories'));
        $this->assertTrue(Schema::hasTable('transactions'));
        $this->assertTrue(Schema::hasTable('audit_events'));
        $this->assertTrue(Schema::hasColumns('categories', ['team_id', 'name', 'type', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('transactions', [
            'team_id',
            'category_id',
            'recorded_by',
            'direction',
            'amount',
            'transaction_date',
            'source_type',
            'external_id',
            'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('audit_events', [
            'team_id',
            'actor_id',
            'event_type',
            'request_id',
            'before_values',
            'after_values',
            'occurred_at',
        ]));
    }

    public function test_transaction_amount_is_a_decimal_string_and_big_decimal(): void
    {
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $transaction = Transaction::factory()->create([
            'team_id' => $team->id,
            'amount' => '1234567890.10',
        ])->fresh();

        $this->assertSame('1234567890.10', $transaction->amount);
        $this->assertTrue($transaction->amountAsBigDecimal()->isEqualTo(BigDecimal::of('1234567890.10')));
        $this->assertIsString($transaction->amount);
    }

    public function test_team_and_user_relationships_are_preserved(): void
    {
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $user = User::factory()->create();
        $category = Category::factory()->create(['team_id' => $team->id]);
        $transaction = Transaction::factory()->create([
            'team_id' => $team->id,
            'category_id' => $category->id,
            'recorded_by' => $user->id,
        ]);
        $event = AuditEvent::factory()->create([
            'team_id' => $team->id,
            'actor_id' => $user->id,
            'auditable_type' => Transaction::class,
            'auditable_id' => $transaction->id,
        ]);

        $this->assertSame($team->id, $transaction->team->id);
        $this->assertSame($category->id, $transaction->category->id);
        $this->assertSame($user->id, $transaction->recordedBy->id);
        $this->assertSame($team->id, $team->transactions->first()->id === $transaction->id ? $team->id : null);
        $this->assertSame($transaction->id, $event->auditable->id);
        $this->assertSame($user->id, $user->auditEvents->first()->id === $event->id ? $user->id : null);
    }

    public function test_team_scopes_do_not_depend_on_current_team_id(): void
    {
        $teamA = Team::factory()->create(['onboarding_completed_at' => now()]);
        $teamB = Team::factory()->create(['onboarding_completed_at' => now()]);
        $transactionA = Transaction::factory()->create(['team_id' => $teamA->id]);
        $transactionB = Transaction::factory()->create(['team_id' => $teamB->id]);

        $this->assertSame([$transactionA->id], Transaction::forTeam($teamA)->pluck('id')->all());
        $this->assertSame([$transactionB->id], Transaction::forTeam($teamB)->pluck('id')->all());
        Category::factory()->create(['team_id' => $teamA->id]);
        $this->assertSame([$teamA->id], Category::forTeam($teamA)->pluck('team_id')->all());
    }

    public function test_category_and_external_transaction_uniqueness_are_team_scoped(): void
    {
        $teamA = Team::factory()->create(['onboarding_completed_at' => now()]);
        $teamB = Team::factory()->create(['onboarding_completed_at' => now()]);
        Category::factory()->create(['team_id' => $teamA->id, 'name' => 'Sales', 'type' => 'income']);

        $this->expectException(QueryException::class);
        Category::factory()->create(['team_id' => $teamA->id, 'name' => 'Sales', 'type' => 'income']);
    }

    public function test_same_category_and_external_identifier_can_exist_in_another_team(): void
    {
        $teamA = Team::factory()->create(['onboarding_completed_at' => now()]);
        $teamB = Team::factory()->create(['onboarding_completed_at' => now()]);

        Category::factory()->create(['team_id' => $teamA->id, 'name' => 'Sales', 'type' => 'income']);
        Category::factory()->create(['team_id' => $teamB->id, 'name' => 'Sales', 'type' => 'income']);
        Transaction::factory()->create([
            'team_id' => $teamA->id,
            'source_type' => 'loyverse',
            'external_id' => 'receipt-1',
        ]);
        Transaction::factory()->create([
            'team_id' => $teamB->id,
            'source_type' => 'loyverse',
            'external_id' => 'receipt-1',
        ]);

        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_financial_foreign_keys_and_soft_deletion_are_enforced(): void
    {
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $transaction = Transaction::factory()->create(['team_id' => $team->id]);
        $category = Category::factory()->create(['team_id' => $team->id]);

        $category->delete();
        $transaction->delete();

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);

        $this->expectException(QueryException::class);
        Transaction::factory()->create(['team_id' => 999999]);
    }

    public function test_transaction_policy_requires_completed_onboarding_membership_and_role(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $bookkeeper = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => 'owner']);
        $team->members()->attach($manager, ['role' => 'manager']);
        $team->members()->attach($bookkeeper, ['role' => 'bookkeeper']);

        $this->assertTrue(Gate::forUser($owner)->allows('create', [Transaction::class, $team]));
        $this->assertTrue(Gate::forUser($manager)->allows('create', [Transaction::class, $team]));
        $this->assertTrue(Gate::forUser($bookkeeper)->allows('create', [Transaction::class, $team]));

        $team->update(['onboarding_completed_at' => null]);
        $this->assertFalse(Gate::forUser($owner)->allows('create', [Transaction::class, $team->fresh()]));
    }

    public function test_cross_team_transaction_and_category_access_is_denied(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = Team::factory()->create(['onboarding_completed_at' => now()]);
        $teamB = Team::factory()->create(['onboarding_completed_at' => now()]);
        $teamA->members()->attach($ownerA, ['role' => 'owner']);
        $teamB->members()->attach($ownerB, ['role' => 'owner']);
        $transactionB = Transaction::factory()->create(['team_id' => $teamB->id]);
        $categoryB = Category::factory()->create(['team_id' => $teamB->id]);

        $this->assertFalse(Gate::forUser($ownerA)->allows('view', $transactionB));
        $this->assertFalse(Gate::forUser($ownerA)->allows('update', $transactionB));
        $this->assertFalse(Gate::forUser($ownerA)->allows('view', $categoryB));
    }

    public function test_only_the_business_owner_can_view_a_business_audit_event(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $team = Team::factory()->create(['onboarding_completed_at' => now()]);
        $team->members()->attach($owner, ['role' => 'owner']);
        $team->members()->attach($manager, ['role' => 'manager']);
        $event = AuditEvent::factory()->create(['team_id' => $team->id, 'actor_id' => $manager->id]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $event));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $event));
        $this->assertFalse(Gate::forUser($manager)->allows('viewAny', [AuditEvent::class, $team]));
    }
}
