<?php

use App\Http\Middleware\EnsureBusinessOnboarding;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Team;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return view('welcome');
    }

    $team = auth()->user()->currentTeam;

    if ($team && ! $team->is_personal && $team->isOnboardingComplete()) {
        return redirect()->route('dashboard', ['current_team' => $team->slug]);
    }

    if ($team) {
        return redirect()->route('teams.onboarding', ['current_team' => $team->slug]);
    }

    return view('welcome');
})->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('onboarding', 'pages::teams.onboarding')->name('teams.onboarding');
        Route::view('dashboard', 'dashboard')
            ->middleware(EnsureBusinessOnboarding::class)
            ->name('dashboard');
        Route::livewire('categories', 'pages::categories.index')
            ->middleware(EnsureBusinessOnboarding::class)
            ->name('categories.index');
        Route::livewire('transactions', 'pages::transactions.index')
            ->middleware(EnsureBusinessOnboarding::class)
            ->name('transactions.index');
        Route::get('imports', fn (Team $current_team) => to_route('transactions.index', $current_team))
            ->middleware([EnsureBusinessOnboarding::class, 'can:manageFinancialRecords,current_team'])
            ->name('imports.index');
        foreach (['invoices', 'bills'] as $page) {
            Route::livewire($page, 'pages::obligations.index')
                ->middleware([EnsureBusinessOnboarding::class, 'can:manageFinancialRecords,current_team'])
                ->name($page.'.index');
        }
        Route::livewire('customers', 'pages::contacts.index')
            ->middleware([EnsureBusinessOnboarding::class, 'can:manageContacts,current_team'])
            ->name('customers.index');
        Route::livewire('suppliers', 'pages::contacts.index')
            ->middleware([EnsureBusinessOnboarding::class, 'can:manageContacts,current_team'])
            ->name('suppliers.index');
    });

require __DIR__.'/settings.php';
