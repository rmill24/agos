<?php

use App\Models\Team;
use App\Services\FinancingReadinessService;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Financing readiness')] class extends Component
{
    #[Locked]
    public Team $team;

    public function mount(Team $current_team): void
    {
        $this->authorize('viewFinancingReadiness', $current_team);
        $this->team = $current_team;
    }

    public function with(FinancingReadinessService $service): array
    {
        try {
            return ['report' => $service->forTeam(auth()->user() ?? abort(403), $this->team)];
        } catch (QueryException $exception) {
            logger()->warning('Financing readiness could not be loaded.', ['team_id' => $this->team->id]);

            return ['report' => null];
        }
    }
}; ?>

<section class="agos-page space-y-6">
    <header class="agos-page-header">
        <div class="min-w-0">
            <p class="agos-eyebrow wrap-anywhere">{{ $team->name }}</p>
            <flux:heading level="1" size="xl" class="mt-2">Financing readiness</flux:heading>
            <flux:subheading>This report reviews the availability and age of business records. Loan approval and eligibility are outside its scope.</flux:subheading>
        </div>
        <flux:button icon="arrow-path" wire:click="$refresh" wire:loading.attr="disabled">Refresh report</flux:button>
    </header>
    <p wire:loading role="status" class="agos-muted text-sm">Updating report…</p>
    @if ($report)
        <section class="agos-card space-y-2 p-5" aria-labelledby="assessment">
            <h2 id="assessment" class="font-semibold">Assessment for <time datetime="{{ $report['asOf'] }}">{{ $report['asOf'] }}</time></h2>
            <p>{{ $report['recent'] }} of {{ count($report['items']) }} record groups have recent evidence.</p>
            <p class="agos-muted text-sm">The 30-day review window runs from {{ $report['since'] }} through {{ $report['asOf'] }} in Manila. A recent entry shows activity. Gaps in the ledger and documents held outside AGOS require separate review.</p>
        </section>
        <section class="agos-card p-5" aria-labelledby="checklist">
            <h2 id="checklist" class="text-lg font-semibold">Record checklist</h2>
            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach ($report['items'] as $item)
                    <li class="space-y-1 py-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <h3 class="font-medium">{{ $item['label'] }}</h3>
                            <strong class="text-sm">{{ $item['status'] }}</strong>
                        </div>
                        <p class="agos-muted text-sm">{{ $item['detail'] }}</p>
                        @if ($item['date'])
                            <p class="text-sm">Latest evidence <time datetime="{{ $item['date'] }}">{{ $item['date'] }}</time></p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @else
        <div class="agos-card p-5" role="alert">The report could not be loaded. Use Refresh report to try again.</div>
    @endif
</section>
