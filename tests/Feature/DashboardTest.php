<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard'));

        $response->assertOk()->assertSee('Set up your business')->assertDontSee('Net cash movement');
    }

    public function test_month_to_date_cash_uses_only_active_team_movements_through_today_in_manila(): void
    {
        $this->travelTo(now()->parse('2026-11-01 16:00:00', 'UTC'));
        [$actor, $team] = p2Business();
        [, $other] = p2Business();
        $movement = Transaction::factory()->state(['team_id' => $team->id, 'recorded_by' => $actor->id, 'transaction_date' => '2026-11-01']);
        $movement->create(['amount' => '100.10', 'description' => 'Cash received']);
        $movement->expense()->create(['transaction_date' => '2026-11-02', 'amount' => '19.99']);
        $movement->create(['team_id' => $other->id, 'description' => 'Other business']);
        $movement->create(['description' => 'Archived movement'])->delete();
        $movement->create(['transaction_date' => '2026-10-31', 'description' => 'Earlier month']);
        $movement->create(['transaction_date' => '2026-11-03', 'description' => 'Future movement']);
        $this->actingAs($actor)->get(route('dashboard', $team))->assertOk()
            ->assertSee('₱80.11')->assertSee('₱100.10')->assertSee('₱19.99')->assertSee('Cash received')
            ->assertDontSee('Other business')->assertDontSee('Archived movement')->assertDontSee('Earlier month')
            ->assertDontSee('Future movement')->assertSee('Current cash balance is unknown');
    }
}
