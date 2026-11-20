<?php

namespace App\Providers;

use App\Contracts\AiExplanationProvider;
use App\Integrations\Ai\GroqExplanationProvider;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiExplanationProvider::class, GroqExplanationProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Event::listen([Login::class, Failed::class], function (Login|Failed $event): void {
            AuditEvent::create([
                'actor_id' => $event->user?->getAuthIdentifier(),
                'event_type' => 'auth.login', 'result' => $event instanceof Login ? 'success' : 'failure',
                'request_id' => (string) Str::uuid(), 'occurred_at' => now(),
            ]);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
