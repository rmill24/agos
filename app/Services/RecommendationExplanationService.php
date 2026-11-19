<?php

namespace App\Services;

use App\Contracts\AiExplanationProvider;
use App\Data\RecommendationExplanationContext;
use App\Exceptions\ExplanationUnavailable;
use App\Models\Team;
use App\Models\User;

final class RecommendationExplanationService
{
    public function __construct(
        private RecommendationService $recommendations,
        private AiExplanationProvider $provider,
        private ExplanationResponseValidator $validator,
    ) {}

    /** @return array<string, mixed> */
    public function forTeam(User $actor, Team $team, int $days = 30): array
    {
        $started = hrtime(true);
        $context = RecommendationExplanationContext::forTeam($actor, $team, $this->recommendations, $days);
        $result = ['report' => $context->report, 'explanation' => null, 'explanation_status' => 'unavailable', 'failure_reason' => null];
        if (! config('ai.enabled')) {
            return [...$result, 'explanation_status' => 'disabled'];
        }
        if ($context->report['recommendations'] === []) {
            return [...$result, 'explanation_status' => 'no_supported_action'];
        }
        try {
            if (config('ai.provider') !== 'groq') {
                throw new ExplanationUnavailable('unsupported_provider');
            }
            $remaining = 15.0 - (hrtime(true) - $started) / 1_000_000_000;
            $response = $this->provider->explain($context, $remaining);
            $explanation = $this->validator->validate($response, $context);
            if ((hrtime(true) - $started) / 1_000_000_000 > 15.0) {
                throw new ExplanationUnavailable('deadline_exceeded');
            }

            return [...$result, 'explanation' => $explanation, 'explanation_status' => 'available'];
        } catch (ExplanationUnavailable $error) {
            return [...$result, 'failure_reason' => $error->reason];
        }
    }
}
