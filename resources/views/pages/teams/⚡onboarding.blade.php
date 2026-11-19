<?php

use App\Models\Team;
use App\Services\BusinessProfileService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    public string $countryCode = 'PH';

    public string $currencyCode = 'PHP';

    public string $timezone = 'Asia/Manila';

    public string $cashSafetyThreshold = '';

    public string $openingCashBalance = '';

    public string $openingCashBalanceDate = '';

    public bool $openingCashBalanceVerified = false;

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
        $this->countryCode = $current_team->country_code ?? 'PH';
        $this->currencyCode = $current_team->currency_code ?? 'PHP';
        $this->timezone = $current_team->timezone ?? 'Asia/Manila';
        $this->cashSafetyThreshold = $current_team->cash_safety_threshold ?? '';
        $this->openingCashBalance = $current_team->opening_cash_balance ?? '';
        $this->openingCashBalanceDate = $current_team->opening_cash_balance_date?->toDateString() ?? '';
        $this->openingCashBalanceVerified = $current_team->opening_cash_balance_verified_at !== null;
    }

    public function completeOnboarding(BusinessProfileService $profileService): void
    {
        Gate::authorize('completeOnboarding', $this->team);

        $validated = $this->validate($profileService->rules());

        $this->team = $profileService->completeOnboarding(Auth::user(), $this->team, $validated);

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
            <flux:input wire:model="countryCode" :label="__('Country code')" maxlength="2" required />
            <flux:input wire:model="currencyCode" :label="__('Currency')" maxlength="3" readonly required />
            <flux:input wire:model="timezone" :label="__('Business timezone')" required />
            <flux:input wire:model="cashSafetyThreshold" :label="__('Cash-safety threshold (PHP)')" inputmode="decimal" hint="The minimum projected cash balance AGOS should protect." />
            <div class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <flux:heading level="3" size="sm">Verified opening cash</flux:heading>
                <flux:text class="text-sm">Enter the cash position immediately before the effective date. AGOS will use it as the starting point for absolute cash and forecast calculations.</flux:text>
                <flux:input wire:model="openingCashBalance" :label="__('Opening cash balance (PHP)')" inputmode="decimal" />
                <flux:input wire:model="openingCashBalanceDate" :label="__('Effective date')" type="date" />
                <flux:checkbox wire:model="openingCashBalanceVerified" label="I confirm this opening cash balance is verified." />
            </div>
            <flux:button type="submit" variant="primary">{{ __('Complete onboarding') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
