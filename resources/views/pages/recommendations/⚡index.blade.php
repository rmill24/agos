<?php

use App\Models\Team;
use App\Services\RecommendationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Action Center')] class extends Component
{
    public Team $team;

    public function mount(Team $current_team): void
    {
        $this->team = $current_team;
        Gate::authorize('viewRecommendations', $this->team->fresh());
    }

    public function with(RecommendationService $service): array
    {
        try {
            return ['candidates' => $service->candidates(auth()->user(), $this->team)];
        } catch (QueryException $error) {
            logger()->warning('Action Center recommendations could not be loaded.', ['team_id' => $this->team->id]);

            return ['candidates' => null];
        }
    }
}; ?>
<section class="agos-page">
    <header class="agos-page-header">
        <div>
            <flux:heading level="1" size="xl">Action Center</flux:heading>
            <flux:subheading>Review outstanding collections and supplier payments in order of urgency.</flux:subheading>
        </div>
        <flux:button icon="arrow-path" wire:click="$refresh">Refresh</flux:button>
    </header>

    <p wire:loading role="status" class="mb-3 text-sm">Updating recommendations…</p>
    @if ($candidates === null)
        <div class="agos-card p-6" role="alert">Recommendations could not be loaded. Select Refresh to try again.</div>
    @elseif ($candidates === [])
        <div class="agos-card p-6">
            <flux:heading level="2">No invoices or bills need attention</flux:heading>
            <flux:text>There are no outstanding balances due today or overdue.</flux:text>
        </div>
    @else
        <ol class="agos-card divide-y divide-slate-200" aria-label="Prioritized recommendations">
            @foreach ($candidates as $item)
                @php($source = $item['evidence'])
                <li wire:key="obligation-{{ $source['obligation_id'] }}" class="grid gap-4 p-5 sm:grid-cols-[1fr_auto] sm:p-6">
                    <div class="min-w-0 space-y-2">
                        <flux:badge size="sm" :color="$item['priority'] === 'High' ? 'amber' : 'zinc'">{{ $item['priority'] }} priority</flux:badge>
                        <flux:heading level="2">{{ $item['action'] }}</flux:heading>
                        <flux:text>{{ $item['reason'] }} Due <time datetime="{{ $item['due_date'] }}">{{ $item['due_date'] }}</time>.</flux:text>
                        <a class="agos-link wrap-anywhere text-sm" href="{{ route($source['kind'] === 'invoice' ? 'invoices.index' : 'bills.index', ['current_team' => $team, 'document' => $source['obligation_id']]) }}" wire:navigate>View {{ $source['kind'] }} · {{ $item['document'] }}</a>
                    </div>
                    <div class="sm:text-right">
                        <p class="agos-muted text-sm">Outstanding</p>
                        <p class="text-xl font-semibold tabular-nums">₱{{ $item['amount'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
