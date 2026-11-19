<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\DashboardSummaryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController
{
    public function __invoke(Request $request, Team $current_team, DashboardSummaryService $summary): View
    {
        Gate::authorize('viewDashboard', $current_team);
        $team = $current_team;
        if ($team->is_personal) {
            return view('dashboard', compact('team'));
        }

        return view('dashboard', $summary->forTeam($request->user(), $team));
    }
}
