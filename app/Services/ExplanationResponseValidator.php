<?php

namespace App\Services;

use App\Data\RecommendationExplanationContext;
use App\Exceptions\ExplanationUnavailable;
use Illuminate\Support\Facades\Validator;
use JsonException;

final class ExplanationResponseValidator
{
    /** @return array<string, mixed> */
    public function validate(string $response, RecommendationExplanationContext $context): array
    {
        try {
            $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ExplanationUnavailable('invalid_response');
        }
        $input = $context->payload();
        $valid = Validator::make(['response' => $data], [
            'response' => 'required|array:schema_version,snapshot_id,summary_action_ids,actions,limitation_ids',
            'response.schema_version' => 'required|string', 'response.snapshot_id' => 'required|string',
            'response.summary_action_ids' => 'required|array|list|min:1|max:3',
            'response.summary_action_ids.*' => 'required|string|distinct:strict',
            'response.actions' => 'required|array|list|min:1|max:10',
            'response.actions.*' => 'required|array:action_id,explanation_codes,evidence_references',
            'response.actions.*.action_id' => 'required|string|distinct:strict',
            'response.actions.*.explanation_codes' => 'required|array|list|min:1|max:2',
            'response.actions.*.explanation_codes.*' => 'required|string',
            'response.actions.*.evidence_references' => 'required|array|list|min:1|max:10',
            'response.actions.*.evidence_references.*' => 'required|string',
            'response.limitation_ids' => 'required|array|list|min:1|max:100',
            'response.limitation_ids.*' => 'required|string|distinct:strict',
        ])->passes();
        if (! $valid || $data['schema_version'] !== '1' || $data['snapshot_id'] !== $input['snapshot_id']) {
            throw new ExplanationUnavailable('invalid_response');
        }
        $candidates = array_column($input['actions'], null, 'id');
        $actionIds = array_column($input['actions'], 'id');
        $limitations = $context->limitations();
        if (! $this->sameSet(array_column($data['actions'], 'action_id'), $actionIds)
            || array_diff($data['summary_action_ids'], $actionIds) !== []
            || ! $this->sameSet($data['limitation_ids'], array_keys($limitations))) {
            throw new ExplanationUnavailable('invalid_response');
        }
        $explanations = [];
        foreach ($data['actions'] as $action) {
            $candidate = $candidates[$action['action_id']];
            if (! $this->sameSet($action['evidence_references'], $candidate['evidence_references'])
                || array_diff($action['evidence_references'], array_keys($input['evidence'])) !== []
                || count(array_unique($action['explanation_codes'])) !== count($action['explanation_codes'])
                || array_diff($action['explanation_codes'], array_keys($candidate['explanation_choices'])) !== []) {
                throw new ExplanationUnavailable('invalid_response');
            }
            $sentences = [];
            foreach ($action['explanation_codes'] as $code) {
                $sentences[] = $candidate['explanation_choices'][$code];
            }
            $explanations[] = [
                'action_id' => $action['action_id'], 'explanation' => implode(' ', $sentences),
                'evidence_references' => $candidate['evidence_references'],
            ];
        }

        return [
            'snapshot_id' => $input['snapshot_id'], 'summary_action_ids' => $data['summary_action_ids'],
            'suggested_action_order' => array_column($data['actions'], 'action_id'),
            'actions' => $explanations, 'limitations' => array_values($limitations),
        ];
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     */
    private function sameSet(array $left, array $right): bool
    {
        return count($left) === count($right) && array_diff($left, $right) === [] && array_diff($right, $left) === [];
    }
}
