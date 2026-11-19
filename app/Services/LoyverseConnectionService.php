<?php

namespace App\Services;

use App\Models\LoyverseConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoyverseConnectionService
{
    /** @return array{token: string|null, merchant: string|null, store: string|null, collected_methods: list<string>} */
    public function resolve(Team $team): array
    {
        $stored = $team->loyverseConnection;
        if ($stored) {
            return [
                'token' => $stored->access_token,
                'merchant' => $stored->merchant_id,
                'store' => $stored->store_id,
                'collected_methods' => $stored->collected_method_ids ?? [],
            ];
        }

        return config("loyverse.connections.{$team->id}", [
            'token' => null, 'merchant' => null, 'store' => null, 'collected_methods' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{token: string, merchant: string, store: string, collected_methods: list<string>}
     */
    public function normalize(array $input, ?string $existingToken = null): array
    {
        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '' && $existingToken !== null) {
            $token = $existingToken;
        }

        return [
            'token' => $token,
            'merchant' => trim((string) ($input['merchant'] ?? '')),
            'store' => trim((string) ($input['store'] ?? '')),
            'collected_methods' => array_values(array_filter(array_map(
                static fn (string $method): string => trim($method),
                explode(',', (string) ($input['collected_methods'] ?? '')),
            ), static fn (string $method): bool => $method !== '')),
        ];
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, Team $team, array $input): LoyverseConnection
    {
        Gate::forUser($actor)->authorize('syncLoyverse', $team);
        $data = $this->normalize($input, $this->resolve($team)['token'] ?? null);
        if ($data['token'] === '') {
            throw ValidationException::withMessages(['accessToken' => 'An access token is required for this business.']);
        }

        /** @var LoyverseConnection $connection */
        $connection = $team->loyverseConnection()->updateOrCreate([], [
            'access_token' => $data['token'], 'merchant_id' => $data['merchant'],
            'store_id' => $data['store'], 'collected_method_ids' => $data['collected_methods'],
            'verified_at' => null,
        ]);
        app(AuditEventService::class)->record($actor, $team, 'loyverse.connection.saved', $connection, metadata: [
            'merchant_id' => $data['merchant'], 'store_id' => $data['store'],
        ]);

        return $connection;
    }

    public function clear(User $actor, Team $team): void
    {
        Gate::forUser($actor)->authorize('syncLoyverse', $team);
        $connection = $team->loyverseConnection;
        $team->loyverseConnection()->delete();
        if ($connection) {
            app(AuditEventService::class)->record($actor, $team, 'loyverse.connection.cleared', $connection, metadata: [
                'merchant_id' => $connection->merchant_id, 'store_id' => $connection->store_id,
            ]);
        }
    }

    public function maskedToken(Team $team): ?string
    {
        $connection = $team->loyverseConnection;

        return $connection ? '••••••••'.Str::substr($connection->access_token, -4) : null;
    }
}
