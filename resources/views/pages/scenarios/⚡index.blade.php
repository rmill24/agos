<?php

use App\Models\Obligation;
use App\Models\Team;
use App\Services\ScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Scenario Simulator')] class extends Component
{
    public Team $team;

    public string $scenarioType = 'expense_increase';

    public string $forecastDays = '30';

    public string $amount = '';

    public string $percentage = '';

    public string $reductionMode = 'amount';

    public string $date = '';

    public string $delayDays = '7';

    public string $obligationId = '';

    public string $paymentDays = '0';

    public string $relatedIncome = '';

    public string $reductionPercentage = '20';

    public string $depositAmount = '';

    public ?array $result = null;

    public ?array $comparison = null;

    public ?array $purchaseAnalysis = null;

    public string $failure = '';

    public function mount(Team $current_team): void
    {
        $this->team = $current_team;
        $this->permit();
        $this->date = CarbonImmutable::now($this->team->timezone ?: 'Asia/Manila')->addDay()->toDateString();
    }

    public function updatedScenarioType(): void
    {
        $this->resetValidation();
        $this->result = null;
        $this->comparison = null;
        $this->purchaseAnalysis = null;
        $this->failure = '';
    }

    public function runScenario(ScenarioService $service): void
    {
        $this->permit();
        $this->resetValidation();
        $this->failure = '';

        try {
            if ($this->scenarioType === 'purchase_affordability') {
                $this->purchaseAnalysis = $service->purchaseAffordability(auth()->user(), $this->team, $this->input());
                $this->comparison = null;
                $this->result = null;
            } else {
                $this->comparison = $service->compare(auth()->user(), $this->team, $this->scenarioType, $this->input());
                $this->result = $this->comparison['scenario'];
                $this->purchaseAnalysis = null;
            }
        } catch (QueryException) {
            report(new \RuntimeException('Scenario data could not be loaded.'));
            $this->failure = 'The scenario could not be calculated. Check the selected records and try again.';
        }
    }

    private function permit(): void
    {
        Gate::forUser(auth()->user())->authorize('runScenarios', $this->team->fresh());
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $input = ['forecast_days' => $this->forecastDays];

        return match ($this->scenarioType) {
            'payment_delay', 'supplier_delay' => $input + ['obligation_id' => $this->obligationId, 'delay_days' => $this->delayDays],
            'sales_reduction' => $input + ['mode' => $this->reductionMode, 'value' => $this->reductionMode === 'percentage' ? $this->percentage : $this->amount],
            'expense_increase', 'customer_deposit' => $input + ['amount' => $this->amount, 'date' => $this->date],
            'proposed_purchase' => $input + ['amount' => $this->amount, 'date' => $this->date, 'payment_days' => $this->paymentDays, 'related_income' => $this->relatedIncome],
            'purchase_affordability' => $input + ['amount' => $this->amount, 'date' => $this->date, 'payment_days' => $this->paymentDays, 'related_income' => $this->relatedIncome, 'delay_days' => $this->delayDays, 'reduction_percentage' => $this->reductionPercentage, 'deposit_amount' => $this->depositAmount],
            default => $input,
        };
    }

    /** @return array{invoices: \Illuminate\Support\Collection<int, Obligation>, bills: \Illuminate\Support\Collection<int, Obligation>} */
    public function with(): array
    {
        $this->permit();
        $records = Obligation::query()->where('team_id', $this->team->id)->with('payments.transaction')->orderBy('due_date')->orderBy('id')->get()->filter(fn (Obligation $record): bool => $record->balance()->isPositive());

        return [
            'invoices' => $records->where('kind', 'invoice')->values(),
            'bills' => $records->where('kind', 'bill')->values(),
        ];
    }
}; ?>
<section class="agos-page">
    <header class="agos-page-header">
        <div>
            <p class="agos-eyebrow">{{ $team->name }}</p>
            <flux:heading level="1" size="xl" class="mt-2">Scenario Simulator</flux:heading>
            <flux:subheading>Test a hypothetical change without modifying actual financial records.</flux:subheading>
        </div>
    </header>

    <div class="grid items-start gap-6 xl:grid-cols-[2fr_3fr]">
        <form wire:submit="runScenario" class="agos-card space-y-5 p-6">
            <flux:select wire:model.live="scenarioType" label="Hypothetical event">
                <option value="payment_delay">Delay a customer payment</option>
                <option value="sales_reduction">Reduce sales</option>
                <option value="expense_increase">Increase an expense</option>
                <option value="supplier_delay">Delay a supplier payment</option>
                <option value="customer_deposit">Add a customer deposit</option>
                <option value="proposed_purchase">Propose a purchase</option>
                <option value="purchase_affordability">Analyze purchase affordability</option>
            </flux:select>
            <flux:select wire:model="forecastDays" label="Forecast period">
                @foreach ([7, 14, 30, 60, 90] as $days)
                    <option value="{{ $days }}">{{ $days }} days</option>
                @endforeach
            </flux:select>

            @if ($scenarioType === 'payment_delay')
                <flux:select wire:model="obligationId" label="Customer invoice" placeholder="Select an outstanding invoice">
                    @foreach ($invoices as $invoice)
                        <option value="{{ $invoice->id }}">{{ $invoice->number ?: '#'.$invoice->id }} · PHP {{ $invoice->balance() }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="delayDays" label="Delay by days" type="number" min="1" max="365" required />
            @elseif ($scenarioType === 'supplier_delay')
                <flux:select wire:model="obligationId" label="Supplier bill" placeholder="Select an outstanding bill">
                    @foreach ($bills as $bill)
                        <option value="{{ $bill->id }}">{{ $bill->description ?: '#'.$bill->id }} · PHP {{ $bill->balance() }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="delayDays" label="Delay by days" type="number" min="1" max="365" required />
            @elseif ($scenarioType === 'sales_reduction')
                <flux:select wire:model.live="reductionMode" label="Reduction input">
                    <option value="amount">Amount in PHP</option>
                    <option value="percentage">Percentage of confirmed forecast sales</option>
                </flux:select>
                <flux:input wire:model="{{ $reductionMode === 'percentage' ? 'percentage' : 'amount' }}" label="Reduction value" inputmode="decimal" required />
            @elseif (in_array($scenarioType, ['proposed_purchase', 'purchase_affordability'], true))
                <flux:input wire:model="amount" label="Purchase amount (PHP)" inputmode="decimal" required />
                <flux:input wire:model="date" label="Proposed purchase date" type="date" required />
                <flux:input wire:model="paymentDays" label="Payment terms (days)" type="number" min="0" max="365" required />
                <flux:input wire:model="relatedIncome" label="Expected related income (PHP)" inputmode="decimal" required />
                @if ($scenarioType === 'purchase_affordability')
                    <flux:input wire:model="delayDays" label="Delay alternative (days)" type="number" min="1" max="365" required />
                    <flux:input wire:model="reductionPercentage" label="Reduced amount alternative (%)" inputmode="decimal" required />
                    <flux:input wire:model="depositAmount" label="Requested customer deposit (PHP)" inputmode="decimal" required />
                @endif
            @else
                <flux:input wire:model="amount" label="Hypothetical amount (PHP)" inputmode="decimal" required />
                <flux:input wire:model="date" label="Hypothetical date" type="date" required />
            @endif

            <div wire:loading wire:target="runScenario" class="text-sm" role="status">Calculating hypothetical forecast…</div>
            @if ($failure)
                <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-700 dark:bg-red-950/30 dark:text-red-100" role="alert">{{ $failure }}</div>
            @endif
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="runScenario">Run scenario</flux:button>
        </form>

        <section class="agos-card min-w-0 p-6" aria-live="polite">
            <flux:heading level="2" size="lg">Scenario result</flux:heading>
            @if ($comparison === null && $purchaseAnalysis === null)
                <div class="agos-card-muted mt-4 p-5">
                    <flux:text>Choose a hypothetical event and run it to see its isolated forecast. No actual records will be changed.</flux:text>
                </div>
            @elseif ($comparison !== null)
                @php($baseline = $comparison['baseline'])
                @php($flow = $comparison['scenario']['cashFlow'])
                <div class="mt-4 rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-900 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-100">Hypothetical result only. Persisted actual records were not modified.</div>
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    @foreach (['Baseline forecast' => $baseline, 'Scenario forecast' => $flow] as $label => $summary)
                        <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-700" aria-labelledby="{{ str($label)->slug() }}">
                            <flux:heading id="{{ str($label)->slug() }}" level="3">{{ $label }}</flux:heading>
                            <dl class="mt-3 space-y-2 text-sm"><div class="flex justify-between gap-3"><dt class="agos-muted">Projected cash</dt><dd class="font-semibold">{{ $summary['currentCash'] === null ? 'Unavailable' : 'PHP '.$summary['daily'][count($summary['daily']) - 1]['projectedCash'] }}</dd></div><div class="flex justify-between gap-3"><dt class="agos-muted">Cash gap</dt><dd class="font-semibold">{{ $summary['currentCash'] === null ? 'Unavailable' : 'PHP '.$summary['cashGap'] }}</dd></div><div class="flex justify-between gap-3"><dt class="agos-muted">Earliest shortfall</dt><dd class="font-semibold">{{ $summary['earliestShortfallDate'] ?? ($summary['currentCash'] === null ? 'Unavailable' : 'None detected') }}</dd></div></dl>
                        </section>
                    @endforeach
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2"><div><dt class="agos-muted text-sm">Projected cash impact</dt><dd class="text-xl font-semibold">{{ $comparison['impact']['projectedCashDelta'] === null ? 'Unavailable' : 'PHP '.$comparison['impact']['projectedCashDelta'] }}</dd></div><div><dt class="agos-muted text-sm">Cash-gap impact</dt><dd class="text-xl font-semibold">{{ $comparison['impact']['cashGapDelta'] === null ? 'Unavailable' : 'PHP '.$comparison['impact']['cashGapDelta'] }}</dd></div></dl>
                <div class="mt-6">
                    <flux:heading level="3">Hypothetical inputs</flux:heading>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">@foreach ($comparison['inputs'] as $key => $value)
                            @if ($key !== 'type' && $key !== 'forecastDays')<li>{{ str($key)->headline() }}: {{ is_array($value) ? json_encode($value) : $value }}</li>@endif
                        @endforeach</ul>
                </div>
                @php($comparisonPayload = ['baseline' => ['daily' => $baseline['daily']], 'scenario' => ['daily' => $flow['daily']], 'threshold' => $baseline['threshold']])
                <div data-scenario-comparison-chart class="mt-6"><div data-scenario-chart-loading class="agos-muted flex h-64 items-center justify-center text-sm" role="status">Loading comparison chart…</div><div data-scenario-chart-error class="hidden rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-700 dark:bg-red-950/30 dark:text-red-100" role="alert">The comparison chart could not be rendered. The summary above remains available.</div><div class="hidden h-64 md:block"><canvas aria-label="Baseline and scenario projected cash comparison" role="img"></canvas></div><script type="application/json" data-scenario-comparison-data>@json($comparisonPayload)</script></div>
                @if (count($comparison['limitations']))
                    <div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100"><p class="font-semibold">Limitations</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($comparison['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul></div>
                @endif
            @else
                <div class="mt-4 rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-900 dark:border-brand-700 dark:bg-brand-950/30 dark:text-brand-100">Purchase affordability is hypothetical only. Each alternative is calculated independently and does not change actual records.</div>
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    @foreach ($purchaseAnalysis['alternatives'] as $alternative)
                        @php($comparison = $alternative['comparison'])
                        <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><flux:heading level="3">{{ $alternative['label'] }}</flux:heading><p class="agos-muted mt-1 text-sm">{{ $alternative['affordability']['reason'] }}</p><ul class="mt-3 list-disc space-y-1 pl-5 text-xs">@foreach ($alternative['assumptions'] as $key => $value)<li>{{ str($key)->headline() }}: {{ $value }}</li>@endforeach</ul><dl class="mt-3 space-y-2 text-sm"><div class="flex justify-between gap-3"><dt class="agos-muted">Projected cash</dt><dd class="font-semibold">{{ $comparison['scenario']['cashFlow']['currentCash'] === null ? 'Unavailable' : 'PHP '.$comparison['scenario']['cashFlow']['daily'][count($comparison['scenario']['cashFlow']['daily']) - 1]['projectedCash'] }}</dd></div><div class="flex justify-between gap-3"><dt class="agos-muted">Earliest shortfall</dt><dd class="font-semibold">{{ $comparison['scenario']['cashFlow']['earliestShortfallDate'] ?? ($comparison['scenario']['cashFlow']['currentCash'] === null ? 'Unavailable' : 'None detected') }}</dd></div><div class="flex justify-between gap-3"><dt class="agos-muted">Cash gap</dt><dd class="font-semibold">{{ $comparison['scenario']['cashFlow']['currentCash'] === null ? 'Unavailable' : 'PHP '.$comparison['scenario']['cashFlow']['cashGap'] }}</dd></div></dl>@if (count($comparison['limitations']))<ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-amber-800 dark:text-amber-200">@foreach ($comparison['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul>@endif</section>
                    @endforeach
                </div>
                @php($purchaseChartPayload = ['alternatives' => collect($purchaseAnalysis['alternatives'])->map(fn (array $alternative): array => ['label' => $alternative['label'], 'daily' => $alternative['comparison']['scenario']['cashFlow']['daily']])->values(), 'threshold' => $purchaseAnalysis['alternatives'][0]['comparison']['baseline']['threshold']])
                <div data-scenario-comparison-chart class="mt-6"><div data-scenario-chart-loading class="agos-muted flex h-64 items-center justify-center text-sm" role="status">Loading affordability comparison chart…</div><div data-scenario-chart-error class="hidden rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-700 dark:bg-red-950/30 dark:text-red-100" role="alert">The affordability chart could not be rendered. The alternative summaries above remain available.</div><div class="hidden h-64 md:block"><canvas aria-label="Purchase alternative projected cash comparison" role="img"></canvas></div><script type="application/json" data-scenario-comparison-data>@json($purchaseChartPayload)</script></div>
                @if (count($purchaseAnalysis['limitations']))<div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100"><p class="font-semibold">Limitations</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($purchaseAnalysis['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul></div>@endif
            @endif
        </section>
    </div>
</section>
