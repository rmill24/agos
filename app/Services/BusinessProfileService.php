<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use App\Rules\TeamName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BusinessProfileService
{
    /**
     * The shared profile rules used by onboarding and profile editing.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'teamName' => ['required', 'string', 'max:255', new TeamName],
            'legalName' => ['required', 'string', 'max:255'],
            'businessType' => ['required', 'string', 'max:100'],
            'contactEmail' => ['required', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'addressLine1' => ['required', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'postalCode' => ['required', 'string', 'max:20'],
            'countryCode' => ['required', 'string', 'size:2', 'in:PH'],
            'currencyCode' => ['required', 'string', 'size:3', 'in:PHP'],
            'timezone' => ['required', 'timezone'],
        ];
    }

    /**
     * Persist a validated business profile without changing onboarding state.
     *
     * @param  array<string, mixed>  $profile
     */
    public function update(User $actor, Team $team, array $profile): Team
    {
        Gate::forUser($actor)->authorize('updateBusinessProfile', $team);

        return DB::transaction(function () use ($actor, $team, $profile): Team {
            $lockedTeam = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('updateBusinessProfile', $lockedTeam);

            $lockedTeam->update($this->attributes($profile));

            return $lockedTeam->fresh();
        });
    }

    /**
     * Persist a profile and mark a non-personal business as onboarded.
     *
     * @param  array<string, mixed>  $profile
     */
    public function completeOnboarding(User $actor, Team $team, array $profile): Team
    {
        Gate::forUser($actor)->authorize('completeOnboarding', $team);

        return DB::transaction(function () use ($actor, $team, $profile): Team {
            $lockedTeam = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('completeOnboarding', $lockedTeam);

            $lockedTeam->update([
                ...$this->attributes($profile),
                'onboarding_completed_at' => $lockedTeam->onboarding_completed_at ?? now(),
            ]);

            return $lockedTeam->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    protected function attributes(array $profile): array
    {
        return [
            'name' => $profile['teamName'],
            'legal_name' => $profile['legalName'],
            'business_type' => $profile['businessType'],
            'contact_email' => $profile['contactEmail'],
            'contact_phone' => $profile['contactPhone'] ?: null,
            'address_line1' => $profile['addressLine1'],
            'address_line2' => $profile['addressLine2'] ?: null,
            'city' => $profile['city'],
            'province' => $profile['province'],
            'postal_code' => $profile['postalCode'],
            'country_code' => $profile['countryCode'],
            'currency_code' => 'PHP',
            'timezone' => $profile['timezone'],
        ];
    }
}
