<?php

use App\Models\Team;
use App\Services\AuditEventService;
use App\Services\LoyverseConnectionService;
use App\Services\LoyverseReceiptAdapter;
use App\Services\LoyverseSyncService;
use Flux\Flux;
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

    public string $accessToken = '';

    public string $merchantName = '';

    public string $merchantId = '';

    /** @var list<array{id: string, name: string}> */
    public array $stores = [];

    public string $selectedStoreId = '';

    /** @var list<array{id: string, name: string, type: string, stores: list<string>}> */
    public array $paymentTypes = [];

    /** @var list<string> */
    public array $selectedPaymentTypeIds = [];

    public bool $metadataLoaded = false;

    public function mount(Team $current_team): void
    {
        Gate::authorize('syncLoyverse', $current_team);
        $this->team = $current_team;
        $connection = app(LoyverseConnectionService::class)->resolve($current_team);
        $this->merchantId = (string) ($connection['merchant'] ?? '');
        $this->selectedStoreId = (string) ($connection['store'] ?? '');
        $this->selectedPaymentTypeIds = $connection['collected_methods'] ?? [];
    }

    public function loadMetadata(LoyverseReceiptAdapter $adapter, AuditEventService $audit): void
    {
        Gate::authorize('syncLoyverse', $this->team);
        $this->validate(['accessToken' => ['nullable', 'string', 'max:1000']]);
        $metadata = $adapter->metadata(auth()->user(), $this->team, ['token' => $this->accessToken]);
        $this->merchantId = $metadata['merchant']['id'];
        $this->merchantName = $metadata['merchant']['name'];
        $this->stores = $metadata['stores'];
        $this->paymentTypes = $metadata['payment_types'];
        $this->metadataLoaded = true;
        $storeIds = array_column($this->stores, 'id');
        if (! in_array($this->selectedStoreId, $storeIds, true)) {
            $this->selectedStoreId = '';
        }
        $this->selectedPaymentTypeIds = array_values(array_intersect(
            $this->selectedPaymentTypeIds,
            array_column($this->paymentTypes, 'id'),
        ));
        $audit->record(auth()->user(), $this->team, 'loyverse.connection.tested', metadata: [
            'merchant_id' => $this->merchantId, 'store_count' => count($this->stores),
            'payment_type_count' => count($this->paymentTypes),
        ]);
        Flux::toast(variant: 'success', text: 'Loyverse details loaded. Choose a store and collected payment types.');
    }

    public function saveConnection(LoyverseConnectionService $service, LoyverseReceiptAdapter $adapter): void
    {
        Gate::authorize('syncLoyverse', $this->team);
        $this->validate([
            'merchantId' => ['required', 'string', 'max:255'],
            'selectedStoreId' => ['required', 'string', 'max:255'],
            'selectedPaymentTypeIds' => ['array'],
        ]);
        abort_unless($this->metadataLoaded, 422, 'Load Loyverse details before saving the connection.');
        try {
            $adapter->probeReceiptAccess(auth()->user(), $this->team, $this->selectedStoreId, [
                'token' => $this->accessToken, 'merchant' => $this->merchantId, 'store' => $this->selectedStoreId,
            ]);
        } catch (\RuntimeException $error) {
            $this->addError('selectedStoreId', $error->getMessage());

            return;
        }
        $connection = $service->save(auth()->user(), $this->team, [
            'merchant' => $this->merchantId, 'store' => $this->selectedStoreId,
            'token' => $this->accessToken, 'collected_methods' => implode(',', $this->selectedPaymentTypeIds),
        ]);
        $connection->update(['verified_at' => now()]);
        $this->accessToken = '';
        Flux::toast(variant: 'success', text: 'Loyverse connection saved. Receipt synchronization is ready.');
    }

    public function clearConnection(LoyverseConnectionService $service): void
    {
        Gate::authorize('syncLoyverse', $this->team);
        $service->clear(auth()->user(), $this->team);
        $this->merchantName = $this->merchantId = $this->selectedStoreId = $this->accessToken = '';
        $this->stores = $this->paymentTypes = $this->selectedPaymentTypeIds = [];
        $this->metadataLoaded = false;
        Flux::toast(variant: 'success', text: 'Loyverse connection cleared.');
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
        $connection = app(LoyverseConnectionService::class)->resolve($this->team);
        $configured = filled($connection['token'] ?? null) && filled($connection['store'] ?? null) && filled($connection['merchant'] ?? null);
        $busy = $latest?->status === 'queued' && $latest->updated_at->gt(now()->subMinutes(3));
        return [
            'configured' => $configured, 'busy' => $busy, 'polling' => $latest?->status === 'queued',
            'maskedToken' => app(LoyverseConnectionService::class)->maskedToken($this->team),
            'selectedStoreName' => collect($this->stores)->firstWhere('id', $this->selectedStoreId)['name'] ?? null,
            'lastSuccess' => (clone $runs)->where('status', 'complete')->latest('id')->first(),
            'latest' => $latest,
            'held' => $this->reviewId ? (clone $runs)->findOrFail($this->reviewId)->entries()
                ->whereNotNull('reason')->orderBy('id')->simplePaginate(10, ['receipt_number', 'reason'], 'heldPage') : null,
            'history' => (clone $runs)->withCount(['entries', 'entries as held_count' => fn ($query) => $query->whereNotNull('reason')])->latest('id')->simplePaginate(5),
        ];
    }
}; ?>

<div class="agos-page" @if($polling) wire:poll.2s @endif>
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
    <section class="agos-card mt-6 space-y-4 p-5" aria-label="Loyverse connection settings">
        <div>
            <flux:heading level="2">Connection settings</flux:heading>
            <flux:text>Connect with one Loyverse access token. AGOS will find your business, stores, and payment types automatically.</flux:text>
        </div>
        <form wire:submit="loadMetadata" class="space-y-4">
            <flux:input wire:model="accessToken" type="password" label="Loyverse access token" autocomplete="new-password" required />
            <flux:text class="text-sm">Create a token in Loyverse Back Office under <strong>Access Tokens</strong>. AGOS uses it only for read-only account details and receipts.</flux:text>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="loadMetadata">{{ $metadataLoaded ? 'Refresh Loyverse details' : 'Connect and find my stores' }}</flux:button>
        </form>
        @if($metadataLoaded)
            <div class="space-y-4 border-t border-slate-200 pt-4 dark:border-slate-700">
                <flux:text><strong>Connected business:</strong> {{ $merchantName }}</flux:text>
                <form wire:submit="saveConnection" class="space-y-4">
                    <flux:select wire:model="selectedStoreId" label="Store" required>
                        <option value="">Choose the store to import</option>
                        @foreach($stores as $store)
                            <option value="{{ $store['id'] }}">{{ $store['name'] }}</option>
                        @endforeach
                    </flux:select>
                    <div class="space-y-2">
                        <flux:heading level="3" size="sm">Collected payment types</flux:heading>
                        <flux:text class="text-sm">Select the payment methods that represent money actually collected or returned.</flux:text>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach(collect($paymentTypes)->filter(fn ($paymentType) => ! $selectedStoreId || in_array($selectedStoreId, $paymentType['stores'], true)) as $paymentType)
                                <flux:checkbox wire:model="selectedPaymentTypeIds" value="{{ $paymentType['id'] }}" label="{{ $paymentType['name'] }}" description="{{ $paymentType['type'] }}" />
                            @endforeach
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" variant="primary">Save and enable sync</flux:button>
                        @if($maskedToken)<flux:button type="button" wire:click="clearConnection" variant="danger">Clear connection</flux:button>@endif
                    </div>
                </form>
            </div>
        @endif
        @if($maskedToken)<flux:text>Stored token: {{ $maskedToken }}</flux:text>@endif
    </section>
    <section class="mt-4 agos-card space-y-2 p-5" aria-live="polite" aria-label="Synchronization status">
        <flux:heading level="2">{{ ! $configured ? 'Connection required' : match ($latest?->status) {
            'queued' => $busy ? 'Synchronization in progress' : 'Sync interrupted',
            'incomplete' => 'Incomplete sync', 'complete' => 'Sync complete', default => 'Ready to sync',
        } }}</flux:heading>
        @unless($configured)<flux:text>Configure this business’s merchant, store and access token on the server to enable synchronization.</flux:text>@endunless
        @if($latest && $latest->status !== 'complete')
            <flux:text>{{ $latest->failure ?? 'New results become available after all pages have been verified.' }}</flux:text>
            @if($latest->failure_code)<flux:text class="text-xs text-slate-500">Diagnostic: {{ $latest->failure_code }}@if($latest->failure_reference) · Reference {{ $latest->failure_reference }}@endif</flux:text>@endif
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
