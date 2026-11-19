<?php

use App\Models\Team;
use App\Services\RecommendationExplanationService;
use App\Services\RecommendationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Action Center')] class extends Component
{
    #[Locked]
    public Team $team;

    #[Locked]
    public bool $refreshed = false;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $aiExplanation = null;

    #[Locked]
    public string $aiExplanationStatus = 'not_requested';

    #[Locked]
    public ?string $aiFailureReason = null;

    /** @var array{selected_action_count: int, total_action_count: int, is_limited: bool}|null */
    #[Locked]
    public ?array $aiExplanationScope = null;

    public function mount(Team $current_team): void
    {
        $this->team = $current_team;
        $this->permit();
    }

    private function permit(): void
    {
        $actor = auth()->user()?->fresh() ?? abort(403);
        $this->team = $this->team->fresh() ?? abort(404);
        Gate::forUser($actor)->authorize('viewRecommendations', $this->team);
    }

    public function refreshRecommendations(): void
    {
        $this->permit();
        $this->refreshed = true;
        $this->aiExplanation = null;
        $this->aiExplanationStatus = 'not_requested';
        $this->aiFailureReason = null;
        $this->aiExplanationScope = null;
    }

    public function requestExplanation(RecommendationExplanationService $service): void
    {
        $this->permit();
        $result = $service->forTeam(auth()->user(), $this->team);
        $this->aiExplanation = $result['explanation'];
        $this->aiExplanationStatus = $result['explanation_status'];
        $this->aiFailureReason = $result['failure_reason'];
        $this->aiExplanationScope = $result['explanation_scope'];
    }

    public function with(RecommendationService $service): array
    {
        $this->permit();
        try {
            // Keep financial results server-side and regenerate on every read.
            $report = $service->forTeam(auth()->user(), $this->team);
            $reference = 'team:'.$this->team->id.':cash_flow:'.$report['as_of'].':'.$report['period_end'];
            $source = $report['evidence'][$reference] ?? null;
            $cashFlow = $source !== null && $source['team_id'] === $this->team->id && $source['type'] === 'calculation'
                ? $source['result'] : null;

            return compact('report', 'cashFlow');
        } catch (QueryException) {
            logger()->warning('Action Center recommendations could not be loaded.', ['team_id' => $this->team->id]);

            return ['report' => null, 'cashFlow' => null];
        }
    }
}; ?>
<section class="agos-page space-y-6">
    <header class="agos-page-header">
        <div class="min-w-0">
            <p class="agos-eyebrow wrap-anywhere">{{ $team->name }}</p>
            <flux:heading level="1" size="xl" class="mt-2">Action Center</flux:heading>
            <flux:subheading>Review cash risks, collections and supplier commitments in priority order.</flux:subheading>
        </div>
        <flux:button icon="arrow-path" wire:click="refreshRecommendations" wire:loading.attr="disabled">Refresh</flux:button>
    </header>

    <p class="agos-muted text-sm">Rule-based guidance · Advisory only. Actions require your review and do not execute payments or collections. All amounts are Philippine pesos (PHP); dates use Asia/Manila.</p>
    <p wire:loading wire:target="refreshRecommendations" role="status" class="text-sm">Updating recommendations…</p>

    <div wire:loading.remove class="space-y-6">
        @if ($report === null)
            <div class="agos-card space-y-3 p-6" role="alert">
                <flux:heading level="2">Recommendations could not be loaded</flux:heading>
                <flux:text>Financial evidence is unavailable right now. Select Refresh to try again.</flux:text>
                <flux:button wire:click="refreshRecommendations" wire:loading.attr="disabled">Retry recommendations</flux:button>
            </div>
        @else
            <div role="status" class="agos-muted text-sm">
                @if ($refreshed) Recommendations refreshed. @endif
                Review period: <time datetime="{{ $report['as_of'] }}">{{ $report['as_of'] }}</time> to <time datetime="{{ $report['period_end'] }}">{{ $report['period_end'] }}</time>.
                Updated {{ \Carbon\CarbonImmutable::parse($report['generated_at'])->setTimezone('Asia/Manila')->format('M j, Y g:i A') }} PHT.
            </div>

            <section class="agos-card space-y-3 p-5 sm:p-6" aria-labelledby="recommendation-data-quality">
                <flux:heading id="recommendation-data-quality" level="2">Data quality and limitations</flux:heading>
                @if (! $report['history_complete'])
                    <p class="font-medium text-amber-800 dark:text-amber-200">Incomplete cash history — verified opening cash is unavailable for this date. Absolute cash and balance-risk assessments are unavailable.</p>
                @endif
                @if ($report['freshness']['latest_sync_status'] !== null && $report['freshness']['latest_sync_status'] !== 'complete')
                    <p class="font-medium text-amber-800 dark:text-amber-200">Stale Loyverse data warning — the latest synchronization is incomplete. Only previously published records support this review.</p>
                @endif
                <p class="agos-muted text-sm">Last successful Loyverse sync:
                    @if ($report['freshness']['last_successful_sync_at'] !== null)
                        <time datetime="{{ $report['freshness']['last_successful_sync_at'] }}">{{ \Carbon\CarbonImmutable::parse($report['freshness']['last_successful_sync_at'])->setTimezone('Asia/Manila')->format('M j, Y g:i A') }} PHT</time>.
                    @else
                        No successful synchronization recorded.
                    @endif
                </p>
                <ul class="agos-muted list-disc space-y-1 ps-5 text-sm">
                    @foreach ($report['limitations'] as $limitation)
                        <li>{{ $limitation }}</li>
                    @endforeach
                </ul>
            </section>

            @if ($report['recommendations'] !== [])
                <section class="agos-card space-y-3 p-5 sm:p-6" aria-labelledby="ai-explanation-heading" aria-live="polite">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <flux:heading id="ai-explanation-heading" level="2">AI explanation</flux:heading>
                            <p class="agos-muted text-sm">Optional clarification of the verified rule-based guidance. It cannot change actions, priorities, values, dates, or evidence.</p>
                        </div>
                        @if ($aiExplanationStatus !== 'available' && $aiExplanationStatus !== 'disabled')
                            <flux:button wire:click="requestExplanation" wire:loading.attr="disabled" wire:target="requestExplanation">{{ $aiExplanationStatus === 'not_requested' ? 'Explain verified recommendations' : 'Retry AI explanation' }}</flux:button>
                        @endif
                    </div>

                    <p wire:loading wire:target="requestExplanation" role="status" class="text-sm">Requesting an AI explanation of verified guidance...</p>

                    <div wire:loading.remove wire:target="requestExplanation">
                        @if ($aiExplanationStatus === 'available')
                            <p class="font-medium text-emerald-800 dark:text-emerald-200">AI-generated explanation available</p>
                            <p class="agos-muted text-sm">The wording below is assembled from approved, evidence-linked statements. Rule-based guidance remains authoritative.</p>
                            @if ($aiExplanationScope !== null && $aiExplanationScope['is_limited'])
                                <p class="agos-muted text-sm">AI explanation covers the highest-priority {{ $aiExplanationScope['selected_action_count'] }} of {{ $aiExplanationScope['total_action_count'] }} recommendations. All remaining cards continue to show deterministic guidance.</p>
                            @endif
                        @elseif ($aiExplanationStatus === 'not_requested')
                            <p class="agos-muted text-sm">Deterministic fallback active. Request an optional explanation when you need clarification; no provider is contacted until then.</p>
                        @elseif ($aiExplanationStatus === 'disabled')
                            <p class="font-medium text-amber-800 dark:text-amber-200">AI explanation unavailable</p>
                            <p class="agos-muted text-sm">AI explanations are disabled. The deterministic recommendations remain available.</p>
                        @elseif ($aiFailureReason === 'invalid_response')
                            <p class="font-medium text-amber-800 dark:text-amber-200">AI explanation rejected for safety</p>
                            <p class="agos-muted text-sm">The provider response did not match the verified evidence contract. Deterministic fallback remains active.</p>
                        @elseif ($aiFailureReason === 'cooldown_active' || $aiFailureReason === 'rate_limited')
                            <p class="font-medium text-amber-800 dark:text-amber-200">AI explanation temporarily unavailable</p>
                            <p class="agos-muted text-sm">To protect this business from repeated provider failures, try again in about a minute. Deterministic fallback remains active.</p>
                        @else
                            <p class="font-medium text-amber-800 dark:text-amber-200">AI explanation unavailable</p>
                            <p class="agos-muted text-sm">The explanation service could not be reached safely. Deterministic fallback remains active; retry when ready.</p>
                        @endif
                    </div>
                </section>
            @endif

            @if ($report['recommendations'] === [])
                <div class="agos-card space-y-2 p-6">
                    <flux:heading level="2">No action identified</flux:heading>
                    <flux:text>{{ $report['message'] }}</flux:text>
                    <flux:text>No invoices or bills need attention under the supported review rules. Missing records, uncertain collections or incomplete history can still hide financial risks.</flux:text>
                </div>
            @else
                <p class="agos-muted text-sm">Ordered by severity, earliest relevant date, then largest supported exposure. Amounts are not promised cash benefits.</p>
                @php($explanationsByAction = collect($aiExplanation['actions'] ?? [])->keyBy('action_id'))
                <ol class="space-y-4" aria-label="Prioritized recommendations">
                    @foreach ($report['recommendations'] as $item)
                        <li wire:key="recommendation-{{ $item['id'] }}" class="agos-card min-w-0 space-y-4 p-5 sm:p-6" data-recommendation-rule="{{ $item['rule'] }}">
                            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,auto)]">
                                <div class="min-w-0 space-y-2">
                                    <div class="flex flex-wrap gap-2">
                                        <flux:badge size="sm" :color="match ($item['priority']) { 'Critical' => 'red', 'High' => 'amber', default => 'zinc' }">{{ $item['priority'] }} priority</flux:badge>
                                        <flux:badge size="sm" color="zinc">{{ match ($item['rule']) { 'collection_review' => 'Uncertain collection', 'supplier_obligation' => 'Expected outflow', default => 'Projected risk' } }}</flux:badge>
                                    </div>
                                    <flux:heading level="2">{{ $item['action'] }}</flux:heading>
                                    <flux:text>{{ $item['rationale'] }}</flux:text>
                                    @if ($aiExplanationStatus === 'available' && $explanationsByAction->has($item['id']))
                                        <p class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100"><span class="font-medium">AI-generated explanation:</span> {{ $explanationsByAction->get($item['id'])['explanation'] }}</p>
                                    @endif
                                    <p class="agos-muted text-sm">{{ isset($item['amounts']['outstanding']) ? 'Due' : 'Risk date' }} <time datetime="{{ $item['due_date'] }}">{{ $item['due_date'] }}</time>.</p>
                                </div>
                                <div class="min-w-0 sm:text-right">
                                    <p class="agos-muted text-sm">{{ match ($item['rule']) { 'negative_cash' => 'First projected deficit', 'threshold_risk' => 'Maximum threshold gap', default => 'Outstanding' } }}</p>
                                    <p class="text-xl font-semibold"><x-php-amount :amount="$item['amount']" /></p>
                                </div>
                            </div>
                            @if (isset($item['amounts']['projected_cash']))
                                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                                    @foreach (['projected_cash' => 'Projected cash on risk date', 'threshold' => 'Cash-safety threshold', 'maximum_threshold_gap' => 'Maximum threshold gap in period'] as $key => $label)
                                        <div class="min-w-0"><dt class="agos-muted">{{ $label }}</dt><dd class="mt-1 font-medium"><x-php-amount :amount="$item['amounts'][$key]" /></dd></div>
                                    @endforeach
                                </dl>
                                @if ($item['dates']['first_threshold_shortfall'] !== null)
                                    <p class="agos-muted text-sm">First threshold shortfall: <time datetime="{{ $item['dates']['first_threshold_shortfall'] }}">{{ $item['dates']['first_threshold_shortfall'] }}</time>. The maximum gap may occur later.</p>
                                @endif
                            @endif
                            <div class="flex flex-wrap gap-x-5 gap-y-3 text-sm" aria-label="Supporting evidence">
                                @foreach ($item['evidence_references'] as $reference)
                                    @php($source = $report['evidence'][$reference] ?? null)
                                    @if ($source !== null && $source['team_id'] === $team->id)
                                        @if ($source['type'] === 'record' && in_array($source['kind'], ['invoice', 'bill'], true))
                                            <a class="agos-link wrap-anywhere" href="{{ route($source['kind'] === 'invoice' ? 'invoices.index' : 'bills.index', ['current_team' => $team, 'document' => $source['record_id']]) }}" wire:navigate>View {{ $source['kind'] }} · {{ $item['document'] }}</a>
                                        @elseif ($source['type'] === 'calculation' && $cashFlow !== null)
                                            <a class="agos-link" href="#cash-flow-evidence">View cash-flow calculation</a>
                                        @endif
                                    @endif
                                @endforeach
                            </div>
                            <details class="text-sm">
                                <summary class="agos-link cursor-pointer py-2">Limitations for this recommendation</summary>
                                <ul class="agos-muted mt-2 list-disc space-y-1 ps-5">
                                    @foreach ($item['limitations'] as $limitation)
                                        <li>{{ $limitation }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($cashFlow !== null)
                <section id="cash-flow-evidence" tabindex="-1" class="agos-card scroll-mt-6 space-y-4 p-5 sm:p-6" aria-labelledby="cash-flow-evidence-heading">
                    <flux:heading id="cash-flow-evidence-heading" level="2">Cash-flow calculation evidence</flux:heading>
                    <flux:text>Calculated from this business's recorded cash movements and obligations for the review period above. Refresh to review updated evidence.</flux:text>
                    <dl class="grid gap-4 sm:grid-cols-3">
                        @foreach (['currentCash' => 'Confirmed current cash', 'threshold' => 'Cash-safety threshold', 'cashGap' => 'Maximum projected threshold gap'] as $key => $label)
                            <div class="min-w-0"><dt class="agos-muted text-sm">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold"><x-php-amount :amount="($key === 'cashGap' && (! $cashFlow['historyComplete'] || $cashFlow['threshold'] === null)) ? null : $cashFlow[$key]" /></dd></div>
                        @endforeach
                    </dl>
                    <dl class="agos-muted grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="font-medium">Confirmed</dt><dd>Recorded cash already received or paid. Outstanding invoices and bills do not change confirmed cash.</dd></div>
                        <div><dt class="font-medium">Expected</dt><dd>Known obligations not yet settled, including supplier outflows.</dd></div>
                        <div><dt class="font-medium">Uncertain</dt><dd>Invoice collections included on forecast dates; receipt and timing are not guaranteed.</dd></div>
                        <div><dt class="font-medium">Optional</dt><dd>Hypothetical or discretionary flows are not actual financial records.</dd></div>
                    </dl>
                    <details>
                        <summary class="agos-link cursor-pointer py-2 text-sm">Inspect daily forecast and classifications</summary>
                        <div class="agos-table-panel mt-3" role="region" aria-label="Daily cash-flow evidence, scroll horizontally for all columns" tabindex="0">
                            <table class="w-full min-w-4xl text-left text-sm">
                                <caption class="agos-muted p-3 text-left">Daily forecast · PHP · Asia/Manila. Future-dated confirmed entries remain forecast inputs, not current cash.</caption>
                                <thead><tr>
                                    @foreach (['Date', 'Confirmed inflow', 'Confirmed outflow', 'Expected inflow', 'Expected outflow', 'Uncertain collection', 'Optional inflow', 'Optional outflow', 'Projected cash'] as $label)
                                        <th scope="col" class="px-3 py-3">{{ $label }}</th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @foreach ($cashFlow['daily'] as $day)
                                        <tr>
                                            <th scope="row" class="whitespace-nowrap px-3 py-3 font-medium"><time datetime="{{ $day['date'] }}">{{ $day['date'] }}</time></th>
                                            @foreach (['confirmedInflow', 'confirmedOutflow', 'expectedInflow', 'expectedOutflow', 'uncertainInflow', 'optionalInflow', 'optionalOutflow', 'projectedCash'] as $key)
                                                <td class="px-3 py-3"><x-php-amount :amount="$day[$key]" /></td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </section>
            @endif
        @endif
    </div>
</section>
