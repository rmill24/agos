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

    public ?array $result = null;

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
        $this->failure = '';
    }

    public function runScenario(ScenarioService $service): void
    {
        $this->permit();
        $this->resetValidation();
        $this->failure = '';

        try {
            $this->result = $service->run(auth()->user(), $this->team, $this->scenarioType, $this->input());
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
            @elseif ($scenarioType === 'proposed_purchase')
                <flux:input wire:model="amount" label="Purchase amount (PHP)" inputmode="decimal" required />
                <flux:input wire:model="date" label="Proposed purchase date" type="date" required />
                <flux:input wire:model="paymentDays" label="Payment terms (days)" type="number" min="0" max="365" required />
                <flux:input wire:model="relatedIncome" label="Expected related income (PHP)" inputmode="decimal" required />
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
            @if ($result === null)
                <div class="agos-card-muted mt-4 p-5">
                    <flux:text>Choose a hypothetical event and run it to see its isolated forecast. No actual records will be changed.</flux:text>
                </div>
            @else
                @php($flow = $result['cashFlow'])
                <div class="mt-4 rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-950 dark:border-brand-700 dark:bg-brand-950/30 dark:text-brand-100">Hypothetical result only. Persisted actual records were not modified.</div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div><dt class="agos-muted text-sm">Confirmed movement</dt><dd class="text-xl font-semibold">PHP {{ $flow['confirmed']['netMovement'] }}</dd></div>
                    <div><dt class="agos-muted text-sm">Projected cash</dt><dd class="text-xl font-semibold">{{ $flow['currentCash'] === null ? 'Unavailable' : 'PHP '.($flow['daily'][count($flow['daily']) - 1]['projectedCash'] ?? 'Unavailable') }}</dd></div>
                    <div><dt class="agos-muted text-sm">Earliest shortfall</dt><dd class="text-xl font-semibold">{{ $flow['earliestShortfallDate'] ?? ($flow['currentCash'] === null ? 'Unavailable' : 'None detected') }}</dd></div>
                    <div><dt class="agos-muted text-sm">Cash gap</dt><dd class="text-xl font-semibold">{{ $flow['currentCash'] === null ? 'Unavailable' : 'PHP '.$flow['cashGap'] }}</dd></div>
                </dl>
                <div class="mt-6">
                    <flux:heading level="3">Hypothetical inputs</flux:heading>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($result['inputs'] as $key => $value)
                            @if ($key !== 'type' && $key !== 'forecastDays')<li>{{ str($key)->headline() }}: {{ is_array($value) ? json_encode($value) : $value }}</li>@endif
                        @endforeach
                    </ul>
                </div>
                @if (count($result['limitations']))
                    <div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100"><p class="font-semibold">Limitations</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($result['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul></div>
                @endif
            @endif
        </section>
    </div>
</section>
