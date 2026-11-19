<x-layouts::app :title="__('Dashboard')">
    <div class="agos-page">
        <livewire:pages::teams.pending-invitations-modal />
        @if ($team->is_personal)
            <header class="agos-page-header"><flux:heading level="1" size="xl">Your workspace</flux:heading></header>
            <section class="agos-card space-y-3 p-6">
                <flux:heading level="2">Set up your business</flux:heading>
                <flux:text>Create or select a business to see its cash dashboard.</flux:text>
                <a class="agos-link" href="{{ route('teams.index') }}" wire:navigate>Manage teams</a>
            </section>
        @else
            <header class="agos-page-header">
                <div>
                    <p class="agos-eyebrow">{{ $team->name }}</p>
                    <flux:heading level="1" size="xl" class="mt-2">Cash dashboard</flux:heading>
                    <flux:subheading>Month to date · {{ $today->format('M j, Y') }} PHT</flux:subheading>
                </div>
                <flux:button href="{{ route('transactions.index', $team) }}" icon="arrows-right-left" wire:navigate>View transactions</flux:button>
            </header>

            <dl class="grid gap-4 md:grid-cols-3">
                <div class="min-w-0 rounded-2xl bg-brand-900 p-6 text-white">
                    <dt class="text-sm font-medium">Net cash movement</dt>
                    <dd class="mt-3 wrap-anywhere text-3xl font-semibold tabular-nums">₱{{ $net }}</dd>
                    <p class="mt-2 text-sm text-brand-100">Recorded cash in less cash out</p>
                </div>
                @foreach (['Cash in' => $income, 'Cash out' => $expense] as $label => $amount)
                    <div class="agos-card min-w-0 p-6">
                        <dt class="agos-muted text-sm font-medium">{{ $label }}</dt>
                        <dd class="mt-3 wrap-anywhere text-3xl font-semibold tabular-nums">₱{{ $amount }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="agos-muted mt-3 text-sm">Current cash balance is unknown. A verified opening balance or complete cash history is required.</p>

            <div class="mt-6 grid items-start gap-5 xl:grid-cols-[3fr_2fr]">
                <section class="agos-card min-w-0 p-6">
                    <flux:heading level="2" size="lg">Recent cash movements</flux:heading>
                    <ul class="mt-3 divide-y divide-slate-200 dark:divide-slate-700">
                        @forelse ($recent as $entry)
                            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                                <div class="min-w-0">
                                    <p class="wrap-anywhere font-medium">{{ $entry->description }}</p>
                                    <p class="agos-muted text-sm">{{ $entry->transaction_date->format('M j, Y') }} · {{ $entry->direction === 'income' ? 'Income' : 'Expense' }}</p>
                                </div>
                                <span class="font-semibold tabular-nums">{{ $entry->direction === 'income' ? '+' : '−' }}₱{{ $entry->amount }}</span>
                            </li>
                        @empty
                            <li class="agos-muted py-3 text-sm">No cash movements recorded this month.</li>
                        @endforelse
                    </ul>
                </section>

                @can('viewRecommendations', $team)
                    <section class="agos-card min-w-0 p-6">
                        <flux:heading level="2" size="lg">Action Center</flux:heading>
                        <ul class="mt-3 divide-y divide-slate-200 dark:divide-slate-700">
                            @forelse ($actions as $action)
                                @php($source = $action['evidence'])
                                <li class="space-y-1 py-3">
                                    <p class="text-sm font-semibold">{{ $action['action'] }}</p>
                                    <a class="agos-link wrap-anywhere text-sm" href="{{ route($source['kind'] === 'invoice' ? 'invoices.index' : 'bills.index', ['current_team' => $team, 'document' => $source['obligation_id']]) }}" wire:navigate>{{ $action['document'] }}</a>
                                    <p class="agos-muted text-sm">₱{{ $action['amount'] }} outstanding · Due {{ $action['due_date'] }}</p>
                                </li>
                            @empty
                                <li class="agos-muted py-3 text-sm">No invoices or bills are due today or overdue.</li>
                            @endforelse
                        </ul>
                        <a class="agos-link mt-3 inline-block text-sm" href="{{ route('recommendations.index', $team) }}" wire:navigate>Open Action Center</a>
                    </section>
                @endcan
            </div>

            <section class="agos-card mt-5 flex flex-wrap items-center justify-between gap-4 px-6 py-4" aria-label="Loyverse status">
                <div>
                    <flux:heading level="2">Loyverse receipts</flux:heading>
                    <flux:text>
                        @if ($latestSync && $latestSync->status !== 'complete')Latest sync incomplete. Previously published records may be stale. @endif
                        Last successful sync: {{ $lastSuccess ? $lastSuccess->completed_at->timezone('Asia/Manila')->format('M j, Y g:i A').' PHT' : 'Never' }}.
                    </flux:text>
                </div>
                @can('syncLoyverse', $team)
                    <a class="agos-link text-sm" href="{{ route('loyverse.index', $team) }}" wire:navigate>View sync history</a>
                @endcan
            </section>
            @can('manageContacts', $team)
                <nav aria-label="Contact directories" class="mt-5 flex flex-wrap gap-5 text-sm">
                    <a class="agos-link" href="{{ route('customers.index', $team) }}" wire:navigate>View customers</a>
                    <a class="agos-link" href="{{ route('suppliers.index', $team) }}" wire:navigate>View suppliers</a>
                </nav>
            @endcan
        @endif
    </div>
</x-layouts::app>
