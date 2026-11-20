<?php

namespace App\Services;

use App\Jobs\GenerateReportPdf;
use App\Models\ReportPdf;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;

class ReportPdfService
{
    public function request(User $actor, Team $team, int $snapshotId, bool $manualRetry = false): ReportPdf
    {
        return DB::transaction(function () use ($actor, $team, $snapshotId, $manualRetry) {
            $team = $team->fresh() ?? abort(404);
            Gate::forUser($actor->fresh() ?? abort(403))->authorize('viewReports', $team);
            $snapshot = $team->reportSnapshots()->select('id', 'team_id')->findOrFail($snapshotId);
            // The pending limit covers every Team sharing the one Render instance.
            $this->lockAdmission();
            $existing = $snapshot->pdf()->first(['id', 'status', 'report_snapshot_id', 'generation']);
            abort_if($manualRetry && ! $existing, 404);
            if ($existing && $existing->status !== 'converted' && ! ($manualRetry && $existing->status === 'failed')) {
                return $existing;
            }
            if ($this->full()) {
                throw ValidationException::withMessages(['pdf' => 'The PDF queue is full. Try again after a waiting report starts.']);
            }
            $queueDatabase = config('queue.connections.database.connection') ?? DB::getDefaultConnection();
            if ($queueDatabase !== DB::getDefaultConnection() || config('queue.connections.database.driver') !== 'database') {
                throw new LogicException('The PDF queue must use the application database for atomic admission.');
            }
            $pdf = $existing ?? $snapshot->pdf()->make();
            if ($manualRetry && $pdf->status === 'failed') {
                $pdf->generation++;
                $pdf->content_base64 = null;
            }
            $pdf->fill(['requested_by' => $actor->id, 'status' => 'queued'])->save();
            Queue::connection('database')->pushOn('pdf', (new GenerateReportPdf($team->id, $pdf->id, generation: $pdf->generation ?? 1))->beforeCommit());
            app(AuditEventService::class)->record($actor, $team, $manualRetry ? 'report.pdf_retried' : 'report.pdf_requested', $snapshot);

            return $pdf;
        }, 3);
    }

    public function defer(ReportPdf $pdf, int $teamId, int $delay, int $deadline): bool
    {
        return DB::transaction(function () use ($pdf, $teamId, $delay, $deadline) {
            $this->lockAdmission();
            $team = Team::find($teamId);
            if (! $team || ! $pdf->requester || Gate::forUser($pdf->requester)->denies('viewReports', $team)
                || $this->full() || $delay >= $deadline - time() || ! $pdf->snapshot()->whereBelongsTo($team)->exists()) {
                return false;
            }
            if (! ReportPdf::whereKey($pdf->id)->where('generation', $pdf->generation)->where('status', 'processing')->update(['status' => 'retry_queued'])) {
                return false;
            }
            Queue::connection('database')->later($delay, (new GenerateReportPdf($teamId, $pdf->id, $deadline, $pdf->generation))->beforeCommit(), queue: 'pdf');

            return true;
        });
    }

    private function lockAdmission(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::selectOne("select pg_advisory_xact_lock(hashtext('agos.pdf.admission'))");
        }
    }

    private function full(): bool
    {
        return ReportPdf::whereIn('status', ['queued', 'retry_queued'])->count() >= 2;
    }
}
