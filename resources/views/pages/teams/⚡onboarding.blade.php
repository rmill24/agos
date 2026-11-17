<?php

use App\Models\Team;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Business onboarding')] class extends Component
{
    public Team $team;

    public string $teamName = '';

    public string $legalName = '';

    public string $businessType = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $province = '';

    public string $postalCode = '';

    public function mount(Team $current_team): void
    {
        Gate::authorize('completeOnboarding', $current_team);

        $this->team = $current_team;
        $this->teamName = $current_team->name;
        $this->legalName = $current_team->legal_name ?? '';
        $this->businessType = $current_team->business_type ?? '';
        $this->contactEmail = $current_team->contact_email ?? Auth::user()->email;
        $this->contactPhone = $current_team->contact_phone ?? '';
        $this->addressLine1 = $current_team->address_line1 ?? '';
        $this->addressLine2 = $current_team->address_line2 ?? '';
        $this->city = $current_team->city ?? '';
        $this->province = $current_team->province ?? '';
        $this->postalCode = $current_team->postal_code ?? '';
    }

    public function completeOnboarding(): void
    {
        Gate::authorize('completeOnboarding', $this->team);

        $validated = Validator::make([
            'teamName' => $this->teamName,
            'legalName' => $this->legalName,
            'businessType' => $this->businessType,
            'contactEmail' => $this->contactEmail,
            'contactPhone' => $this->contactPhone,
            'addressLine1' => $this->addressLine1,
            'addressLine2' => $this->addressLine2,
            'city' => $this->city,
            'province' => $this->province,
            'postalCode' => $this->postalCode,
        ], [
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
        ])->validate();

        DB::transaction(function () use ($validated): void {
            $team = Team::query()->whereKey($this->team->id)->lockForUpdate()->firstOrFail();

            Gate::forUser(Auth::user())->authorize('completeOnboarding', $team);

            $team->update([
                'name' => $validated['teamName'],
                'legal_name' => $validated['legalName'],
                'business_type' => $validated['businessType'],
                'contact_email' => $validated['contactEmail'],
                'contact_phone' => $validated['contactPhone'] ?: null,
                'address_line1' => $validated['addressLine1'],
                'address_line2' => $validated['addressLine2'] ?: null,
                'city' => $validated['city'],
                'province' => $validated['province'],
                'postal_code' => $validated['postalCode'],
                'onboarding_completed_at' => now(),
            ]);

            $this->team = $team->fresh();
        });

        Flux::toast(variant: 'success', text: __('Business onboarding completed.'));

        $this->redirectRoute('dashboard', ['current_team' => $this->team->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout :heading="__('Business onboarding')" :subheading="__('Complete your business profile before using AGOS business modules.')">
        <form wire:submit="completeOnboarding" class="space-y-6">
            <flux:input wire:model="teamName" :label="__('Business name')" required />
            <flux:input wire:model="legalName" :label="__('Legal name')" required />
            <flux:input wire:model="businessType" :label="__('Business type')" placeholder="Retail, services, food, etc." required />
            <flux:input wire:model="contactEmail" :label="__('Business email')" type="email" required />
            <flux:input wire:model="contactPhone" :label="__('Business phone')" />
            <flux:input wire:model="addressLine1" :label="__('Address line 1')" required />
            <flux:input wire:model="addressLine2" :label="__('Address line 2')" />
            <div class="grid gap-6 sm:grid-cols-3">
                <flux:input wire:model="city" :label="__('City')" required />
                <flux:input wire:model="province" :label="__('Province')" required />
                <flux:input wire:model="postalCode" :label="__('Postal code')" required />
            </div>
            <flux:input :label="__('Currency')" value="PHP" disabled />
            <flux:input :label="__('Business timezone')" value="Asia/Manila" disabled />
            <flux:button type="submit" variant="primary">{{ __('Complete onboarding') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
