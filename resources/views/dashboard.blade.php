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

            <form method="get" action="{{ route('dashboard', $team) }}" class="mb-5 flex flex-wrap items-center gap-2">
                <label for="forecast-days" class="agos-muted text-sm">Forecast period</label>
                <select id="forecast-days" name="forecast_days" class="rounded-lg border-slate-300 bg-white text-sm shadow-sm dark:border-slate-600 dark:bg-slate-900" onchange="this.form.submit()">
                    @foreach ([7, 14, 30, 60, 90] as $days)
                        <option value="{{ $days }}" @selected($forecastDays === $days)>{{ $days }} days</option>
                    @endforeach
                </select>
            </form>

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

            <dl class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="min-w-0 rounded-2xl bg-brand-900 p-6 text-white">
                    <dt class="text-sm font-medium">Current cash</dt>
                    <dd class="mt-3 wrap-anywhere text-3xl font-semibold tabular-nums">{{ $cashFlow['currentCash'] === null ? 'Unavailable' : 'PHP '.$cashFlow['currentCash'] }}</dd>
                    <p class="mt-2 text-sm text-brand-100">{{ $cashFlow['historyComplete'] ? 'Confirmed cash position' : 'Opening balance is not verified' }}</p>
                </div>
                <div class="agos-card min-w-0 p-6">
                    <dt class="agos-muted text-sm font-medium">Projected cash</dt>
                    <dd class="mt-3 wrap-anywhere text-3xl font-semibold tabular-nums">{{ $cashFlow['daily'][count($cashFlow['daily']) - 1]['projectedCash'] === null ? 'Unavailable' : 'PHP '.$cashFlow['daily'][count($cashFlow['daily']) - 1]['projectedCash'] }}</dd>
                    <p class="agos-muted mt-2 text-sm">Forecast only; never confirmed cash</p>
                </div>
                <div class="agos-card min-w-0 p-6">
                    <dt class="agos-muted text-sm font-medium">Cash-safety threshold</dt>
                    <dd class="mt-3 wrap-anywhere text-3xl font-semibold tabular-nums">{{ $cashFlow['threshold'] === null ? 'Not set' : 'PHP '.$cashFlow['threshold'] }}</dd>
                    <p class="agos-muted mt-2 text-sm">Business-defined minimum balance</p>
                </div>
                <div class="agos-card min-w-0 p-6">
                    <dt class="agos-muted text-sm font-medium">Earliest shortfall</dt>
                    <dd class="mt-3 wrap-anywhere text-2xl font-semibold">{{ $cashFlow['earliestShortfallDate'] ?? ($cashFlow['currentCash'] === null ? 'Unavailable' : 'None detected') }}</dd>
                    <p class="agos-muted mt-2 text-sm">Cash gap: {{ $cashFlow['currentCash'] === null ? 'Unavailable' : 'PHP '.$cashFlow['cashGap'] }}</p>
                </div>
            </dl>

            @if (count($cashFlow['limitations']))
                <aside class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100" role="status" aria-label="Cash data limitations">
                    <p class="font-semibold">Data limitations</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($cashFlow['limitations'] as $limitation)
                            <li>{{ $limitation }}</li>
                        @endforeach
                    </ul>
                </aside>
            @endif

            @php($chartForecast = array_map(function (array $day): array { unset($day['items']); return $day; }, $cashFlow['daily']))
            @php($chartPayload = ['history' => $cashFlow['confirmedHistory'], 'forecast' => $chartForecast, 'threshold' => $cashFlow['threshold']])
            <section class="agos-card mt-6 p-6" aria-labelledby="cash-flow-chart-title">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <flux:heading id="cash-flow-chart-title" level="2" size="lg">Cash-flow forecast</flux:heading>
                        <flux:text>Recent confirmed movements and the next {{ $forecastDays }} days of expected or uncertain activity.</flux:text>
                    </div>
                    <span class="agos-muted text-sm">{{ $cashFlow['asOf'] }} to {{ $cashFlow['periodEnd'] }} · PHP</span>
                </div>
                <div data-cash-flow-chart class="mt-5">
                    <div data-chart-loading class="agos-muted flex h-80 items-center justify-center text-sm" role="status">Loading chart…</div>
                    <div data-chart-error class="hidden rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-700 dark:bg-red-950/30 dark:text-red-100" role="alert">The chart could not be rendered. The accessible forecast table below remains available.</div>
                    <div class="hidden h-80 md:block"><canvas aria-label="Cash-flow forecast chart" role="img"></canvas></div>
                    <script type="application/json" data-cash-flow-data>@json($chartPayload)</script>
                </div>
                <p class="agos-muted mt-3 text-sm">Legend: confirmed ledger movements are actual recorded cash; expected outflows and uncertain collections are forecasts and are not included in current cash. Optional future expenses: none recorded.</p>
                @if (count($cashFlow['items']) === 0)
                    <p class="agos-card-muted mt-4 p-4 text-sm" role="status">No expected or uncertain cash-flow items are scheduled in this forecast period. This does not imply that absolute cash is available.</p>
                @endif
                <details class="mt-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <summary class="cursor-pointer font-medium">View accessible forecast data</summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[42rem] text-left text-sm">
                            <caption class="sr-only">Daily cash-flow forecast values in Philippine pesos</caption>
                            <thead><tr class="border-b border-slate-200 dark:border-slate-700"><th scope="col" class="px-2 py-2">Date</th><th scope="col" class="px-2 py-2">Confirmed in</th><th scope="col" class="px-2 py-2">Confirmed out</th><th scope="col" class="px-2 py-2">Expected out</th><th scope="col" class="px-2 py-2">Uncertain in</th><th scope="col" class="px-2 py-2">Projected balance</th></tr></thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @foreach ($cashFlow['daily'] as $day)
                                    <tr><th scope="row" class="whitespace-nowrap px-2 py-2 font-medium">{{ $day['date'] }}</th><td class="px-2 py-2">PHP {{ $day['confirmedInflow'] }}</td><td class="px-2 py-2">PHP {{ $day['confirmedOutflow'] }}</td><td class="px-2 py-2">PHP {{ $day['expectedOutflow'] }}</td><td class="px-2 py-2">PHP {{ $day['uncertainInflow'] }}</td><td class="px-2 py-2">{{ $day['projectedCash'] === null ? 'Unavailable' : 'PHP '.$day['projectedCash'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </section>

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
