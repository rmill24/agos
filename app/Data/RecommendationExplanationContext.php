<?php

namespace App\Data;

use App\Models\Team;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Support\Arr;

final readonly class RecommendationExplanationContext
{
    /**
     * Only the authorized factory can mint a provider context. Never accept
     * caller-provided records, evidence or financial snapshots at this boundary.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $payload
     */
    private function __construct(public array $report, private array $payload) {}

    public static function forTeam(User $actor, Team $team, RecommendationService $recommendations, int $days = 30): self
    {
        // RecommendationService reloads the actor and Team and authorizes every read.
        $report = $recommendations->forTeam($actor, $team, $days);
        $actions = [];
        $evidence = [];
        $limitations = $report['limitations'];
        foreach ($report['recommendations'] as $candidate) {
            $action = Arr::only($candidate, ['id', 'rule', 'action', 'priority', 'rationale', 'amount', 'amounts', 'due_date', 'dates', 'evidence_references']);
            $action['explanation_choices'] = [
                'why_this_action' => $candidate['rationale'],
                'cash_treatment' => match ($candidate['rule']) {
                    'collection_review' => 'An outstanding receivable is an uncertain collection, not confirmed cash. Follow-up does not guarantee receipt.',
                    'supplier_obligation' => 'An outstanding supplier bill is an expected outflow. Review its payment timing; no supplier deferral is assumed.',
                    default => 'This risk comes from the recorded cash forecast and its assumptions. Review commitments; no cash benefit from this advice is guaranteed.',
                },
            ];
            $actions[] = $action;
            $limitations = [...$limitations, ...$candidate['limitations']];
            foreach ($candidate['evidence_references'] as $reference) {
                $source = $report['evidence'][$reference];
                if ($source['type'] === 'record') {
                    $evidence[$reference] = Arr::only($source, ['type', 'kind', 'amount', 'due_date', 'calculation']);
                } else {
                    $evidence[$reference] = [
                        'type' => 'calculation', 'service' => $source['service'],
                        'result' => Arr::only($source['result'], ['asOf', 'periodEnd', 'currentCash', 'threshold', 'historyComplete', 'earliestShortfallDate', 'cashGap', 'limitations']),
                    ];
                }
            }
        }
        $limitationMap = [];
        foreach (array_values(array_unique($limitations)) as $index => $limitation) {
            $limitationMap['limitation_'.$index] = $limitation;
        }
        $payload = [
            'schema_version' => '1', 'as_of' => $report['as_of'], 'period_end' => $report['period_end'],
            'currency' => $report['currency'], 'history_complete' => $report['history_complete'],
            'freshness' => $report['freshness'], 'actions' => $actions,
            'evidence' => $evidence, 'limitations' => $limitationMap,
        ];
        $payload['snapshot_id'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return new self($report, $payload);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return array<string, string> */
    public function limitations(): array
    {
        return $this->payload['limitations'];
    }
}
