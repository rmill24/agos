<?php

namespace App\Jobs;

use App\Integrations\Gotenberg\GotenbergClient;
use App\Integrations\Gotenberg\TemporaryConversionFailure;
use App\Models\ReportPdf;
use App\Services\AuditEventService;
use App\Services\ReportPdfService;
use App\Services\ReportPdfVerifier;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class GenerateReportPdf implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    // Waiting for another worker's lock can release this job without contacting Render.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public ?int $retryDeadline = null;

    public int $generation = 1;

    public function __construct(public int $teamId, public int $pdfId, ?int $retryDeadline = null, int $generation = 1)
    {
        $this->retryDeadline = $retryDeadline;
        $this->generation = $generation;
    }

    public function handle(GotenbergClient $gotenberg): void
    {
        $deadline = $this->retryDeadline ?? time() + $this->timeout;
        $store = Cache::store('database')->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The PDF worker requires a database lock store.');
        }
        $lock = $store->lock('agos.pdf.conversion', 90);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }
        try {
            $pdf = $this->record()->select('id', 'requested_by', 'report_snapshot_id', 'status', 'content_base64', 'generation')
                ->with(['requester', 'snapshot:id,team_id,html', 'snapshot.team'])->first();
            $expected = $this->retryDeadline === null ? 'queued' : 'retry_queued';
            if (! $pdf || ! in_array($pdf->status, [$expected, 'processing'], true)) {
                return;
            }
            // A previous worker may have stopped after submitting to the provider.
            // Do not replay a conversion whose outcome is unknown.
            if ($pdf->status === 'processing' || $deadline <= time() || ! $pdf->requester || ! $pdf->snapshot->team
                || Gate::forUser($pdf->requester)->denies('viewReports', $pdf->snapshot->team)) {
                $pdf->update(['status' => 'failed']);

                return;
            }
            $pdf->update(['status' => 'processing']);
            try {
                $bytes = $pdf->content_base64 === null ? $gotenberg->convert($pdf->snapshot->html, $deadline - time())
                    : (base64_decode($pdf->content_base64, true) ?: '');
                app(ReportPdfVerifier::class)->verify($bytes, $pdf->snapshot->html, $deadline - time());
                if (time() >= $deadline) {
                    throw new RuntimeException('Report verification exceeded the job deadline.');
                }
            } catch (TemporaryConversionFailure $error) {
                if ($this->retryDeadline === null && app(ReportPdfService::class)->defer($pdf, $this->teamId, $error->delay, $deadline)) {
                    return;
                }
                $this->finish($pdf);

                return;
            } catch (RuntimeException) {
                $this->finish($pdf);

                return;
            }
            $this->finish($pdf, $bytes);
        } catch (QueryException) {
            // Queue failure logs must never include PDF bytes from a bound SQL value.
            throw new RuntimeException('The PDF worker could not access report storage.');
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->record()->whereIn('status', [$this->retryDeadline === null ? 'queued' : 'retry_queued', 'processing'])->update(['status' => 'failed']);
    }

    /** @return Builder<ReportPdf> */
    private function record(): Builder
    {
        return ReportPdf::whereKey($this->pdfId)->where('generation', $this->generation)->whereHas('snapshot', fn ($query) => $query->where('team_id', $this->teamId));
    }

    private function finish(ReportPdf $pdf, ?string $bytes = null): void
    {
        DB::transaction(function () use ($pdf, $bytes) {
            $verified = $bytes !== null;
            $status = $verified ? 'verified' : 'failed';
            $updated = $this->record()->where('status', 'processing')->update([
                'status' => $status, 'content_base64' => $verified ? base64_encode($bytes) : null,
            ]);
            if ($updated && $pdf->requester && $pdf->snapshot->team) {
                app(AuditEventService::class)->record($pdf->requester, $pdf->snapshot->team, 'report.pdf_'.$status, $pdf->snapshot,
                    result: $verified ? 'success' : 'failure', metadata: ['generation' => $this->generation]);
            }
        });
    }
}
