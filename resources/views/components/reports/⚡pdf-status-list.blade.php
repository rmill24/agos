<?php

use App\Models\Team;
use App\Services\ReportPdfService;
use App\Services\ReportSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Locked]
    public Team $team;

    public function mount(Team $team): void
    {
        Gate::authorize('viewReports', $team);
        $this->team = $team;
    }

    public function queuePdf(int $snapshotId, ReportPdfService $service): void
    {
        $this->submitPdfRequest($snapshotId, false, $service);
    }

    public function retryPdf(int $snapshotId, ReportPdfService $service): void
    {
        $this->submitPdfRequest($snapshotId, true, $service);
    }

    private function submitPdfRequest(int $snapshotId, bool $retry, ReportPdfService $service): void
    {
        Gate::authorize('viewReports', $this->team);
        $snapshot = $this->team->reportSnapshots()->select('id', 'team_id')->findOrFail($snapshotId);

        try {
            $service->request(auth()->user() ?? abort(403), $this->team, $snapshot->id, $retry);
        } catch (ValidationException $exception) {
            $this->addError('pdf', $exception->errors()['pdf'][0] ?? 'The PDF queue is unavailable. Try again later.');
        } catch (QueryException|\LogicException) {
            logger()->warning('Report PDF queue unavailable.', ['team_id' => $this->team->id]);
            $this->addError('pdf', 'The PDF queue is unavailable. Try again later.');
        }
    }

    public function with(): array
    {
        Gate::authorize('viewReports', $this->team);
        $reports = $this->team->reportSnapshots()
            ->with('pdf:id,report_snapshot_id,status')
            ->latest('id')
            ->paginate(20, ['id', 'kind', 'created_at']);

        return [
            'reports' => $reports,
            'titles' => ReportSnapshotService::TITLES,
            'polling' => $reports->getCollection()->contains(
                fn ($report) => in_array($report->pdf?->status, ['queued', 'processing', 'retry_queued'], true),
            ),
        ];
    }
}; ?>

<section class="agos-card p-5" aria-labelledby="saved-reports" @if($polling) wire:poll.2s @endif>
    <h2 class="font-semibold" id="saved-reports">Saved reports</h2>
    @error('pdf')
        <p class="mt-3 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>
    @enderror
    <ul class="divide-y divide-slate-200 dark:divide-slate-700" aria-live="polite">
        @forelse ($reports as $report)
            <li class="py-3" wire:key="report-{{ $report->id }}">
                <a class="agos-link" href="{{ route('reports.show', [$team, $report->id]) }}">{{ $titles[$report->kind] }} #{{ $report->id }}</a>
                <p class="agos-muted text-sm">{{ $report->created_at->setTimezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</p>
                @if (! $report->pdf || in_array($report->pdf->status, ['converted', 'failed'], true))
                    @if ($report->pdf?->status === 'failed')
                        <p class="agos-muted mb-2 text-sm">PDF generation failed. The saved preview is available.</p>
                        <flux:button size="sm" wire:click="retryPdf({{ $report->id }})" wire:loading.attr="disabled" wire:target="retryPdf({{ $report->id }})">Retry PDF</flux:button>
                    @else
                        <flux:button size="sm" wire:click="queuePdf({{ $report->id }})" wire:loading.attr="disabled" wire:target="queuePdf({{ $report->id }})">{{ $report->pdf ? 'Verify PDF' : 'Generate PDF' }}</flux:button>
                    @endif
                @elseif ($report->pdf->status === 'verified')
                    <a class="agos-link text-sm" href="{{ route('reports.pdf.download', [$team, $report->id]) }}">Download verified PDF</a>
                @elseif ($report->pdf->status === 'retry_queued')
                    <p class="text-sm">PDF waiting for one more conversion attempt.</p>
                @else
                    <p class="text-sm">PDF {{ $report->pdf->status }}.</p>
                @endif
            </li>
        @empty
            <li class="agos-muted py-3">Your business has no saved reports yet.</li>
        @endforelse
    </ul>
    {{ $reports->links() }}
</section>
