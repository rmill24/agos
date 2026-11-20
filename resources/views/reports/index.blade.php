<x-layouts::app title="Reports">
    <section class="agos-page space-y-6">
        <header class="agos-page-header">
            <div><p class="agos-eyebrow">{{ $team->name }}</p><flux:heading level="1" size="xl">Reports</flux:heading></div>
        </header>
        @if ($errors->any())
            <div class="agos-card p-4" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('reports.store', $team) }}" x-data="{ saving: false }" x-on:submit="saving = true" class="agos-card grid gap-4 p-5 sm:grid-cols-[1fr_auto] sm:items-end">
            @csrf
            <flux:select name="kind" label="Report type">
                @foreach (array_diff_key($titles, array_flip(['invoice', 'scenario'])) as $kind => $title)
                    <option value="{{ $kind }}">{{ $title }}</option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" x-bind:disabled="saving">Save and preview</flux:button>
            <span x-cloak x-show="saving" role="status">Preparing report…</span>
        </form>
        <p class="agos-muted text-sm">For an individual invoice, open its details in <a class="agos-link" href="{{ route('invoices.index', $team) }}">Invoices</a>.
            @can('runScenarios', $team) Save a hypothetical comparison from the <a class="agos-link" href="{{ route('scenarios.index', $team) }}">Scenario Simulator</a>. @endcan
        </p>
        <section class="agos-card p-5" aria-labelledby="saved-reports">
            <h2 class="font-semibold" id="saved-reports">Saved reports</h2>
            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @forelse ($reports as $report)
                    <li class="py-3"><a class="agos-link" href="{{ route('reports.show', [$team, $report->id]) }}">{{ $titles[$report->kind] }} #{{ $report->id }}</a>
                        <p class="agos-muted text-sm">{{ $report->created_at->setTimezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</p>
                        @if (! $report->pdf || in_array($report->pdf->status, ['converted', 'failed'], true))
                            <form method="post" action="{{ route('reports.pdf.request', [$team, $report->id]) }}" class="mt-2">
                                @csrf
                                @if ($report->pdf?->status === 'failed')
                                    <p class="agos-muted mb-2 text-sm">PDF generation failed. The saved preview is available.</p>
                                    <input type="hidden" name="retry" value="1">
                                    <flux:button size="sm" type="submit">Retry PDF</flux:button>
                                @else
                                    <flux:button size="sm" type="submit">{{ $report->pdf ? 'Verify PDF' : 'Generate PDF' }}</flux:button>
                                @endif
                            </form>
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
    </section>
</x-layouts::app>
