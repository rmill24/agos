<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessOnboarding
{
    /**
     * Ensure a business has completed onboarding before entering a business module.
     *
     * Personal Teams are account placeholders and are not financial businesses.
     * They remain available until the user creates and onboards a business Team.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $this->team($request);

        abort_if(! $team, 404);

        if (! $team->is_personal && ! $team->isOnboardingComplete()) {
            return redirect()->route('teams.onboarding', ['current_team' => $team->slug]);
        }

        return $next($request);
    }

    protected function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if (is_string($team)) {
            $team = Team::where('slug', $team)->first();
        }

        return $team;
    }
}
