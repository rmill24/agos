<?php

namespace App\Services;

use App\Contracts\AiExplanationProvider;
use App\Data\RecommendationExplanationContext;
use App\Exceptions\ExplanationUnavailable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class RecommendationExplanationService
{
    private const int COOLDOWN_SECONDS = 60;

    private const int FAILURE_THRESHOLD = 2;

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
        $result = [
            'report' => $context->report,
            'explanation' => null,
            'explanation_scope' => $context->scope(),
            'explanation_status' => 'unavailable',
            'failure_reason' => null,
        ];
        if (! config('ai.enabled')) {
            return [...$result, 'explanation_status' => 'disabled'];
        }
        if ($context->report['recommendations'] === []) {
            return [...$result, 'explanation_status' => 'no_supported_action'];
        }
        if (! $this->mayCallProvider($team)) {
            return [...$result, 'failure_reason' => 'cooldown_active'];
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
            $this->clearFailureState($team);

            return [...$result, 'explanation' => $explanation, 'explanation_status' => 'available'];
        } catch (ExplanationUnavailable $error) {
            $this->recordFailure($team, $error->reason);

            return [...$result, 'failure_reason' => $error->reason];
        }
    }

    private function mayCallProvider(Team $team): bool
    {
        /** @var array{failures?: int, cooldown_until?: int}|null $state */
        $state = Cache::get($this->stateKey($team));
        $cooldownUntil = $state['cooldown_until'] ?? null;
        if (! is_int($cooldownUntil)) {
            return true;
        }
        if ($cooldownUntil > now()->getTimestamp()) {
            return false;
        }

        // The first post-cooldown request is the single recovery probe. The
        // atomic cache add prevents simultaneous browser requests from probing.
        return Cache::add($this->probeKey($team), true, now()->addSeconds(self::COOLDOWN_SECONDS));
    }

    private function recordFailure(Team $team, string $reason): void
    {
        if (! in_array($reason, ['rate_limited', 'network_failure', 'provider_unavailable', 'malformed_response', 'invalid_response'], true)) {
            return;
        }
        /** @var array{failures?: int, cooldown_until?: int}|null $existing */
        $existing = Cache::get($this->stateKey($team));
        $failures = (int) ($existing['failures'] ?? 0) + 1;
        if ($reason === 'rate_limited' || $failures >= self::FAILURE_THRESHOLD) {
            Cache::put($this->stateKey($team), [
                'failures' => $failures,
                'cooldown_until' => now()->addSeconds(self::COOLDOWN_SECONDS)->getTimestamp(),
            ], now()->addMinutes(3));
            Cache::forget($this->probeKey($team));

            return;
        }

        Cache::put($this->stateKey($team), ['failures' => $failures], now()->addMinutes(3));
    }

    private function clearFailureState(Team $team): void
    {
        Cache::forget($this->stateKey($team));
        Cache::forget($this->probeKey($team));
    }

    private function stateKey(Team $team): string
    {
        return 'agos:ai-explanation:failures:team:'.$team->id;
    }

    private function probeKey(Team $team): string
    {
        return 'agos:ai-explanation:recovery-probe:team:'.$team->id;
    }
}
