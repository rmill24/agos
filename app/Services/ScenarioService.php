<?php

namespace App\Services;

use App\Models\Obligation;
use App\Models\Team;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScenarioService
{
    private const TYPES = [
        'payment_delay',
        'sales_reduction',
        'expense_increase',
        'supplier_delay',
        'customer_deposit',
        'proposed_purchase',
    ];

    public function __construct(private CashFlowService $cashFlow) {}

    /**
     * Execute a transient, Team-scoped hypothetical scenario.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(User $actor, Team $team, string $type, array $input, ?CarbonImmutable $asOf = null, ?string $openingBalance = null): array
    {
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor)->authorize('runScenarios', $team);
        abort_if($team->is_personal, 403);
        abort_unless(in_array($type, self::TYPES, true), 404);

        $asOf ??= CarbonImmutable::now($team->timezone ?: 'Asia/Manila')->startOfDay();
        $days = $this->days($input);
        $end = $asOf->addDays($days - 1);
        $baseline = $this->cashFlow->forTeam($actor, $team, $openingBalance, $asOf, $days);
        $hypotheticalItems = [];
        $overrides = [];
        $limitations = [];
        $normalized = ['type' => $type, 'forecastDays' => $days];

        switch ($type) {
            case 'payment_delay':
                $obligation = $this->obligation($team, $input, 'invoice');
                $delay = $this->integer($input, 'delay_days', 1, 365);
                $date = CarbonImmutable::parse((string) $obligation->due_date, $team->timezone)->addDays($delay)->toDateString();
                $overrides[$obligation->id] = $date;
                $normalized += ['obligationId' => $obligation->id, 'delayDays' => $delay, 'hypotheticalDate' => $date];
                if ($date > $end->toDateString()) {
                    $limitations[] = 'The delayed collection falls outside the selected forecast period.';
                }
                break;

            case 'supplier_delay':
                $obligation = $this->obligation($team, $input, 'bill');
                $delay = $this->integer($input, 'delay_days', 1, 365);
                $date = CarbonImmutable::parse((string) $obligation->due_date, $team->timezone)->addDays($delay)->toDateString();
                $overrides[$obligation->id] = $date;
                $normalized += ['obligationId' => $obligation->id, 'delayDays' => $delay, 'hypotheticalDate' => $date];
                if ($date > $end->toDateString()) {
                    $limitations[] = 'The delayed supplier payment falls outside the selected forecast period.';
                }
                break;

            case 'sales_reduction':
                $mode = $input['mode'] ?? 'amount';
                Validator::make(['mode' => $mode], ['mode' => ['required', 'in:amount,percentage']])->validate();
                $value = $this->decimal($input, 'value', true);
                $amount = $mode === 'percentage' ? $this->percentageAmount($baseline, $value, $limitations) : $value;
                $date = $asOf->addDay()->toDateString();
                if ($amount->isPositive()) {
                    $hypotheticalItems[] = $this->hypothetical($date, $amount, 'outflow', 'optional_outflow', 'Hypothetical sales reduction');
                }
                $normalized += ['mode' => $mode, 'value' => $this->money($value), 'amountModeled' => $this->money($amount), 'hypotheticalDate' => $date];
                break;

            case 'expense_increase':
                $amount = $this->decimal($input, 'amount');
                $date = $this->futureDate($input, 'date', $asOf, $end);
                $hypotheticalItems[] = $this->hypothetical($date, $amount, 'outflow', 'optional_outflow', 'Hypothetical expense increase');
                $normalized += ['amount' => $this->money($amount), 'hypotheticalDate' => $date];
                break;

            case 'customer_deposit':
                $amount = $this->decimal($input, 'amount');
                $date = $this->futureDate($input, 'date', $asOf, $end);
                $hypotheticalItems[] = $this->hypothetical($date, $amount, 'inflow', 'optional_inflow', 'Hypothetical customer deposit');
                $normalized += ['amount' => $this->money($amount), 'hypotheticalDate' => $date];
                break;

            case 'proposed_purchase':
                $amount = $this->decimal($input, 'amount');
                $date = $this->futureDate($input, 'date', $asOf, $end);
                $paymentDays = $this->integer($input, 'payment_days', 0, 365);
                $relatedIncome = $this->decimal($input, 'related_income');
                $incomeDate = CarbonImmutable::parse($date, $team->timezone)->addDays($paymentDays)->toDateString();
                $hypotheticalItems[] = $this->hypothetical($date, $amount, 'outflow', 'optional_outflow', 'Hypothetical proposed purchase');
                $hypotheticalItems[] = $this->hypothetical($incomeDate, $relatedIncome, 'inflow', 'optional_inflow', 'Hypothetical related income');
                if (isset($input['deposit_amount']) && $input['deposit_amount'] !== '') {
                    $deposit = $this->decimal($input, 'deposit_amount');
                    $hypotheticalItems[] = $this->hypothetical($date, $deposit, 'inflow', 'optional_inflow', 'Hypothetical requested customer deposit');
                    $normalized['depositAmount'] = $this->money($deposit);
                }
                $normalized += ['amount' => $this->money($amount), 'hypotheticalDate' => $date, 'paymentDays' => $paymentDays, 'relatedIncome' => $this->money($relatedIncome), 'relatedIncomeDate' => $incomeDate];
                if ($incomeDate > $end->toDateString()) {
                    $limitations[] = 'The related income falls outside the selected forecast period.';
                }
                break;
        }

        $result = $this->cashFlow->forTeam($actor, $team, $openingBalance, $asOf, $days, $hypotheticalItems, $overrides);

        return [
            'type' => $type,
            'inputs' => $normalized,
            'hypotheticalItems' => $hypotheticalItems,
            'limitations' => array_values(array_unique([...$limitations, ...$result['limitations']])),
            'cashFlow' => $result,
            'persisted' => false,
        ];
    }

    /**
     * Return a baseline and hypothetical result without modifying records.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compare(User $actor, Team $team, string $type, array $input, ?CarbonImmutable $asOf = null, ?string $openingBalance = null): array
    {
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor)->authorize('runScenarios', $team);
        $asOf ??= CarbonImmutable::now($team->timezone ?: 'Asia/Manila')->startOfDay();
        $days = (int) ($input['forecast_days'] ?? 30);
        $baseline = $this->cashFlow->forTeam($actor, $team, $openingBalance, $asOf, $days);
        $scenario = $this->run($actor, $team, $type, $input, $asOf, $openingBalance);
        $scenarioFlow = $scenario['cashFlow'];
        $baselineEnd = $this->endingCash($baseline);
        $scenarioEnd = $this->endingCash($scenarioFlow);
        $gapDelta = $this->delta($baseline['cashGap'], $scenarioFlow['cashGap']);
        $cashDelta = $this->delta($baselineEnd, $scenarioEnd);
        $limitations = array_values(array_unique([...$baseline['limitations'], ...$scenario['limitations']]));

        return [
            'type' => $type,
            'inputs' => $scenario['inputs'],
            'baseline' => $baseline,
            'scenario' => $scenario,
            'impact' => [
                'projectedCashDelta' => $cashDelta,
                'cashGapDelta' => $gapDelta,
                'threshold' => $baseline['threshold'],
                'earliestShortfall' => ['baseline' => $baseline['earliestShortfallDate'], 'scenario' => $scenarioFlow['earliestShortfallDate']],
                'status' => $cashDelta === null || $gapDelta === null ? 'insufficient_data' : 'calculated',
            ],
            'limitations' => $limitations,
            'persisted' => false,
        ];
    }

    /**
     * Analyze the four required purchase alternatives as independent scenarios.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function purchaseAffordability(User $actor, Team $team, array $input, ?CarbonImmutable $asOf = null, ?string $openingBalance = null): array
    {
        $team = $team->fresh() ?? abort(404);
        Gate::forUser($actor)->authorize('runScenarios', $team);
        $asOf ??= CarbonImmutable::now($team->timezone ?: 'Asia/Manila')->startOfDay();
        $days = $this->days($input);
        $end = $asOf->addDays($days - 1);
        $amount = $this->decimal($input, 'amount');
        $date = $this->futureDate($input, 'date', $asOf, $end);
        $paymentDays = $this->integer($input, 'payment_days', 0, 365);
        $relatedIncome = $this->decimal($input, 'related_income');
        $delayDays = $this->integer($input, 'delay_days', 1, 365);
        $reductionPercentage = $this->decimal($input, 'reduction_percentage');
        if (! $reductionPercentage->isLessThan('100.00')) {
            throw ValidationException::withMessages(['reduction_percentage' => 'Use a reduction below 100 percent so a purchase remains modeled.']);
        }
        $deposit = $this->decimal($input, 'deposit_amount');
        $base = [
            'amount' => $this->money($amount),
            'date' => $date,
            'forecast_days' => $days,
            'payment_days' => $paymentDays,
            'related_income' => $this->money($relatedIncome),
        ];
        $delayedDate = CarbonImmutable::parse($date, $team->timezone)->addDays($delayDays)->toDateString();
        if ($delayedDate > $end->toDateString()) {
            throw ValidationException::withMessages(['delay_days' => 'The delayed purchase must remain inside the selected forecast period.']);
        }
        $reducedAmount = $amount->multipliedBy(BigDecimal::of('100.00')->minus($reductionPercentage))->dividedBy('100', 2, RoundingMode::HalfUp)->toScale(2);
        $alternatives = [
            ['key' => 'purchase_now', 'label' => 'Purchase now', 'input' => $base],
            ['key' => 'delay_purchase', 'label' => 'Delay purchase', 'input' => [...$base, 'date' => $delayedDate]],
            ['key' => 'reduce_purchase', 'label' => 'Reduce purchase amount', 'input' => [...$base, 'amount' => $this->money($reducedAmount)]],
            ['key' => 'request_deposit', 'label' => 'Request a customer deposit', 'input' => [...$base, 'deposit_amount' => $this->money($deposit)]],
        ];
        $results = [];
        foreach ($alternatives as $alternative) {
            $comparison = $this->compare($actor, $team, 'proposed_purchase', $alternative['input'], $asOf, $openingBalance);
            $results[] = [
                'key' => $alternative['key'],
                'label' => $alternative['label'],
                'assumptions' => $alternative['input'],
                'comparison' => $comparison,
                'affordability' => $this->affordability($comparison),
            ];
        }

        $limitations = [];
        if ($delayedDate > $end->toDateString()) {
            $limitations[] = 'The delayed purchase date falls outside the selected forecast period.';
        }
        if ($openingBalance === null) {
            $limitations[] = 'Affordability cannot be concluded without a verified opening balance or complete cash history.';
        }
        if ($team->cash_safety_threshold === null) {
            $limitations[] = 'Affordability cannot be compared against a threshold until the business configures one.';
        }

        return [
            'type' => 'purchase_affordability',
            'assumptions' => [
                'forecastDays' => $days,
                'purchaseAmount' => $this->money($amount),
                'purchaseDate' => $date,
                'paymentDays' => $paymentDays,
                'relatedIncome' => $this->money($relatedIncome),
                'delayDays' => $delayDays,
                'reductionPercentage' => $this->money($reductionPercentage),
                'depositAmount' => $this->money($deposit),
            ],
            'alternatives' => $results,
            'limitations' => array_values(array_unique($limitations)),
            'persisted' => false,
        ];
    }

    /** @param array<string, mixed> $input */
    private function obligation(Team $team, array $input, string $kind): Obligation
    {
        Validator::make($input, ['obligation_id' => ['required', 'integer', 'min:1']])->validate();
        $obligation = Obligation::query()->where('team_id', $team->id)->where('kind', $kind)->with('payments.transaction')->whereKey($input['obligation_id'])->first();
        if (! $obligation || ! $obligation->balance()->isPositive()) {
            throw ValidationException::withMessages(['obligation_id' => 'Select an outstanding Team-owned '.($kind === 'invoice' ? 'invoice.' : 'supplier bill.')]);
        }

        return $obligation;
    }

    /** @param array<string, mixed> $input */
    private function days(array $input): int
    {
        $days = (int) ($input['forecast_days'] ?? 30);
        Validator::make(['forecast_days' => $days], ['forecast_days' => ['required', 'integer', 'in:7,14,30,60,90']])->validate();

        return $days;
    }

    /** @param array<string, mixed> $input */
    private function integer(array $input, string $key, int $min, int $max): int
    {
        Validator::make($input, [$key => ['required', 'integer', 'min:'.$min, 'max:'.$max]])->validate();

        return (int) $input[$key];
    }

    /** @param array<string, mixed> $input */
    private function decimal(array $input, string $key, bool $percentage = false): BigDecimal
    {
        $rules = ['required', 'string', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'];
        if ($percentage) {
            $rules[] = 'numeric';
            $rules[] = 'between:0,100';
        }
        Validator::make($input, [$key => $rules])->validate();
        $value = BigDecimal::of((string) $input[$key])->toScale(2);
        if (! $value->isPositive()) {
            throw ValidationException::withMessages([$key => 'Enter a positive amount.']);
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function futureDate(array $input, string $key, CarbonImmutable $asOf, CarbonImmutable $end): string
    {
        Validator::make($input, [$key => ['required', 'date_format:Y-m-d']])->validate();
        $date = CarbonImmutable::parse((string) $input[$key]);
        if ($date->toDateString() <= $asOf->toDateString() || $date->toDateString() > $end->toDateString()) {
            throw ValidationException::withMessages([$key => 'Choose a date inside the selected future forecast period.']);
        }

        return $date->toDateString();
    }

    /** @return array{date: string, amount: string, direction: string, classification: string, description: string} */
    private function hypothetical(string $date, BigDecimal $amount, string $direction, string $classification, string $description): array
    {
        return [
            'date' => $date,
            'amount' => $this->money($amount),
            'direction' => $direction,
            'classification' => $classification,
            'description' => $description,
        ];
    }

    /** @param list<string> $limitations */
    /**
     * @param  array<string, mixed>  $baseline
     * @param  list<string>  $limitations
     */
    private function percentageAmount(array $baseline, BigDecimal $percentage, array &$limitations): BigDecimal
    {
        $sales = BigDecimal::of('0.00');
        foreach ($baseline['daily'] as $day) {
            $sales = $sales->plus($day['confirmedInflow']);
        }
        if (! $sales->isPositive()) {
            $limitations[] = 'No confirmed future sales exist in the selected period; the percentage reduction has no modeled cash amount.';

            return BigDecimal::of('0.00');
        }

        return $sales->multipliedBy($percentage)->dividedBy('100', 2, RoundingMode::HalfUp)->toScale(2);
    }

    private function money(BigDecimal $amount): string
    {
        return $amount->toScale(2)->toString();
    }

    /** @param array<string, mixed> $flow */
    private function endingCash(array $flow): ?string
    {
        return $flow['daily'][count($flow['daily']) - 1]['projectedCash'] ?? null;
    }

    private function delta(?string $baseline, ?string $scenario): ?string
    {
        if ($baseline === null || $scenario === null) {
            return null;
        }

        return $this->money(BigDecimal::of($scenario)->minus($baseline));
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @return array{affordable: bool|null, reason: string}
     */
    private function affordability(array $comparison): array
    {
        if ($comparison['impact']['status'] === 'insufficient_data') {
            return ['affordable' => null, 'reason' => 'Insufficient verified opening history for an affordability conclusion.'];
        }
        if ($comparison['scenario']['cashFlow']['threshold'] === null) {
            return ['affordable' => null, 'reason' => 'No business cash-safety threshold is configured.'];
        }

        $shortfall = $comparison['scenario']['cashFlow']['earliestShortfallDate'] !== null;

        return ['affordable' => ! $shortfall, 'reason' => $shortfall ? 'Projected cash falls below the configured threshold.' : 'No threshold crossing was detected in the selected period.'];
    }
}
