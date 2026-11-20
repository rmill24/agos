<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\ReportPdfService;
use App\Services\ReportSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController
{
    public function index(Team $current_team): View
    {
        return view('reports.index', ['team' => $current_team, 'titles' => ReportSnapshotService::TITLES,
            'reports' => $current_team->reportSnapshots()->with('pdf:id,report_snapshot_id,status')->latest('id')->paginate(20, ['id', 'kind', 'created_at'])]);
    }

    public function store(Request $request, Team $current_team, ReportSnapshotService $service): RedirectResponse
    {
        $inputs = $request->validate(['kind' => ['required', Rule::in(array_diff(array_keys(ReportSnapshotService::TITLES), ['scenario']))],
            'invoice_id' => 'required_if:kind,invoice|integer|min:1']);
        try {
            $snapshot = $service->create($request->user(), $current_team, $inputs['kind'], $inputs);
        } catch (QueryException) {
            logger()->warning('Report snapshot could not be saved.', ['team_id' => $current_team->id]);

            return to_route('reports.index', $current_team)->withErrors(['report' => 'The report could not be saved. Please try again.']);
        }

        return to_route('reports.show', [$current_team, $snapshot->id]);
    }

    public function show(Request $request, Team $current_team, int $snapshot, ReportSnapshotService $service): Response
    {
        $report = $service->preview($request->user(), $current_team, $snapshot);

        return response($report->html)->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }

    public function queuePdf(Request $request, Team $current_team, int $snapshot, ReportPdfService $service): RedirectResponse
    {
        try {
            $service->request($request->user(), $current_team, $snapshot, $request->boolean('retry'));
        } catch (QueryException|\LogicException) {
            logger()->warning('Report PDF queue unavailable.', ['team_id' => $current_team->id]);

            return back()->withErrors(['pdf' => 'The PDF queue is unavailable. Try again later.']);
        }

        return back();
    }

    public function downloadPdf(Request $request, Team $current_team, int $snapshot, ReportSnapshotService $service): Response
    {
        $saved = $service->preview($request->user(), $current_team, $snapshot);
        $pdf = $saved->pdf()->where('status', 'verified')->firstOrFail();

        return response(base64_decode($pdf->content_base64, true) ?: abort(500))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="agos-report-'.$saved->id.'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
