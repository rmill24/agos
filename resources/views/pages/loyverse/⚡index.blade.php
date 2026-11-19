<?php

use App\Models\Team;
use App\Services\LoyverseSyncService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Locked]
    public Team $team;

    #[Locked]
    public ?int $reviewId = null;

    public function mount(Team $current_team): void
    {
        Gate::authorize('syncLoyverse', $current_team);
        $this->team = $current_team;
    }

    public function sync(LoyverseSyncService $service): void
    {
        Gate::authorize('syncLoyverse', $this->team);
        $service->start(auth()->user(), $this->team);
        $this->resetPage();
    }

    public function review(int $id): void
    {
        Gate::authorize('syncLoyverse', $this->team);
        $this->reviewId = $this->team->loyverseSyncs()->findOrFail($id)->id;
        $this->resetPage('heldPage');
    }

    public function with(): array
    {
        Gate::authorize('syncLoyverse', $this->team);
        $runs = $this->team->loyverseSyncs();
        $latest = (clone $runs)->latest('id')->first();
        $connection = config("loyverse.connections.{$this->team->id}");
        $configured = filled($connection['token'] ?? null) && filled($connection['store'] ?? null) && filled($connection['merchant'] ?? null);
        $busy = $latest?->status === 'queued' && $latest->updated_at->gt(now()->subMinutes(3));
        return [
            'configured' => $configured, 'busy' => $busy,
            'lastSuccess' => (clone $runs)->where('status', 'complete')->latest('id')->first(),
            'latest' => $latest,
            'held' => $this->reviewId ? (clone $runs)->findOrFail($this->reviewId)->entries()
                ->whereNotNull('reason')->orderBy('id')->simplePaginate(10, ['receipt_number', 'reason'], 'heldPage') : null,
            'history' => (clone $runs)->withCount(['entries', 'entries as held_count' => fn ($query) => $query->whereNotNull('reason')])->latest('id')->simplePaginate(5),
        ];
    }
}; ?>

<div class="agos-page" @if($busy) wire:poll.5s @endif>
    <header class="agos-page-header">
        <div>
            <p class="agos-eyebrow">{{ $team->name }}</p>
            <flux:heading level="1" size="xl">Loyverse sync</flux:heading>
            <flux:subheading>Import receipts from your mapped store and review receipts held out of cash totals.</flux:subheading>
        </div>
        <flux:button variant="primary" icon="arrow-path" wire:click="sync" :disabled="! $configured || $busy" wire:loading.attr="disabled" wire:target="sync">
            {{ $busy ? 'Sync queued or running' : ($latest && $latest->status !== 'complete' ? 'Resume sync' : 'Sync receipts') }}
        </flux:button>
    </header>
    <section class="agos-card space-y-2 p-5" aria-live="polite" aria-label="Synchronization status">
        <flux:heading level="2">{{ ! $configured ? 'Connection required' : match ($latest?->status) {
            'queued' => $busy ? 'Synchronization in progress' : 'Sync interrupted',
            'incomplete' => 'Incomplete sync', 'complete' => 'Sync complete', default => 'Ready to sync',
        } }}</flux:heading>
        @unless($configured)<flux:text>Configure this business’s merchant, store and access token on the server to enable synchronization.</flux:text>@endunless
        @if($latest && $latest->status !== 'complete')
            <flux:text>{{ $latest->failure ?? 'New results become available after all pages have been verified.' }}</flux:text>
            <flux:text>Last saved checkpoint: {{ $latest->pages }} {{ Str::plural('page', $latest->pages) }}.</flux:text>
        @endif
        <flux:text>Last successful sync: {{ $lastSuccess ? $lastSuccess->completed_at->timezone('Asia/Manila')->format('M j, Y · g:i A').' PHT' : 'Never' }}</flux:text>
    </section>
    <section class="mt-6 space-y-3" aria-label="Sync history">
        <flux:heading level="2" size="lg">Sync history</flux:heading>
        @forelse($history as $run)
            <article class="agos-card space-y-2 p-5" wire:key="run-{{ $run->id }}">
                <flux:heading>{{ $run->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</flux:heading>
                <flux:text>{{ match ($run->status) { 'complete' => 'Complete', 'queued' => 'Pending completion', default => 'Incomplete' } }} · {{ $run->pages }} {{ Str::plural('page', $run->pages) }} · {{ $run->entries_count }} {{ Str::plural('receipt', $run->entries_count) }} reviewed · {{ $run->held_count }} quarantined</flux:text>
                @if($run->held_count)
                    <details>
                        <summary class="agos-link cursor-pointer">Quarantine reasons</summary>
                        <ul class="mt-2 space-y-1 break-words text-sm">
                            @foreach($run->entries()->whereNotNull('reason')->selectRaw('reason, count(*) as quantity')->groupBy('reason')->orderBy('reason')->get() as $group)
                                <li>{{ $group->quantity }} × {{ $group->reason }}</li>
                            @endforeach
                        </ul>
                        <flux:button size="sm" class="mt-3" wire:click="review({{ $run->id }})">Review receipts</flux:button>
                    </details>
                @endif
            </article>
        @empty
            <p class="agos-card p-5 text-sm text-slate-600 dark:text-slate-300">No receipt sync has run for this business.</p>
        @endforelse
        {{ $history->links() }}
    </section>
    @if($held)
        <section class="agos-card mt-6 space-y-3 p-5" aria-live="polite">
            <flux:heading level="2">Quarantined receipts · sync #{{ $reviewId }}</flux:heading>
            @foreach($held as $entry)
                <div class="break-words border-t border-slate-200 dark:border-slate-700 pt-2">
                    <strong>{{ $entry->receipt_number ?? 'Missing receipt identifier' }}</strong>
                    <flux:text>{{ $entry->reason }}</flux:text>
                </div>
            @endforeach
            {{ $held->links() }}
        </section>
    @endif
</div>
