<?php

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Models\Obligation;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AgosCoffeeHouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a coherent AGOS Coffee House demonstration dataset', function () {
    $this->seed(AgosCoffeeHouseSeeder::class);

    $team = Team::where('name', 'AGOS Coffee House')->firstOrFail();
    $owner = User::where('email', 'jane.doe@agos-coffee.test')->firstOrFail();
    $manager = User::where('email', 'miguel.santos@agos-coffee.test')->firstOrFail();
    $bookkeeper = User::where('email', 'aira.cruz@agos-coffee.test')->firstOrFail();
    $administrator = User::where('email', 'paolo.reyes@agos-coffee.test')->firstOrFail();

    expect(User::count())->toBe(4)
        ->and(Team::count())->toBe(1)
        ->and($team->slug)->toBe('agos-coffee-house')
        ->and($team->isOnboardingComplete())->toBeTrue()
        ->and($team->opening_cash_balance)->toBe('65000.00')
        ->and($team->memberships()->where('user_id', $owner->id)->value('role'))->toBe(TeamRole::Owner)
        ->and($team->memberships()->where('user_id', $manager->id)->value('role'))->toBe(TeamRole::Manager)
        ->and($team->memberships()->where('user_id', $bookkeeper->id)->value('role'))->toBe(TeamRole::Bookkeeper)
        ->and($administrator->global_role)->toBe(GlobalRole::SystemAdministrator)
        ->and($administrator->teamMemberships()->count())->toBe(0)
        ->and($team->customers()->count())->toBe(20)
        ->and($team->suppliers()->count())->toBe(5)
        ->and(Obligation::where('team_id', $team->id)->where('kind', 'invoice')->count())->toBe(100)
        ->and(Obligation::where('team_id', $team->id)->where('kind', 'bill')->count())->toBe(25)
        ->and(Transaction::where('team_id', $team->id)->count())->toBe(75);

    $start = CarbonImmutable::now('Asia/Manila')->startOfMonth()->toDateString();
    $today = CarbonImmutable::now('Asia/Manila')->toDateString();
    expect(Transaction::where('team_id', $team->id)->whereDate('transaction_date', '<', $start)->count())->toBe(0)
        ->and(Transaction::where('team_id', $team->id)->whereDate('transaction_date', '>', $today)->count())->toBe(0)
        ->and(Payment::where('team_id', $team->id)->count())->toBe(45)
        ->and(Payment::distinct('transaction_id')->count('transaction_id'))->toBe(45);

    $documents = Obligation::where('team_id', $team->id)->with('payments.transaction')->get();
    expect($documents->filter(fn (Obligation $document) => $document->settlement() === 'Paid')->count())->toBeGreaterThan(0)
        ->and($documents->filter(fn (Obligation $document) => $document->settlement() === 'Partially paid')->count())->toBeGreaterThan(0)
        ->and($documents->filter(fn (Obligation $document) => $document->settlement() === 'Unpaid')->count())->toBeGreaterThan(0);
});
