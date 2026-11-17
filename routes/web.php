<?php

use App\Http\Middleware\EnsureBusinessOnboarding;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('onboarding', 'pages::teams.onboarding')->name('teams.onboarding');
        Route::view('dashboard', 'dashboard')
            ->middleware(EnsureBusinessOnboarding::class)
            ->name('dashboard');
    });

require __DIR__.'/settings.php';
