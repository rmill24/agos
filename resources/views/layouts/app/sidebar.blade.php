<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-slate-50 dark:bg-canvas">
        <flux:sidebar sticky collapsible="mobile" class="agos-sidebar border-e border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard', ['current_team' => auth()->user()->currentTeam?->slug]) }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <div class="agos-sidebar-sections">
                <h2 id="workspace-nav-heading" class="agos-nav-label">{{ __('Workspace') }}</h2>
                <flux:sidebar.nav aria-labelledby="workspace-nav-heading" class="agos-sidebar-scroll">
                    <flux:sidebar.item icon="home" :href="route('dashboard', ['current_team' => auth()->user()->currentTeam?->slug])" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('runScenarios', auth()->user()->currentTeam)
                        <flux:sidebar.item icon="beaker" :href="route('scenarios.index', auth()->user()->currentTeam)" :current="request()->routeIs('scenarios.index')" wire:navigate>Scenario Simulator</flux:sidebar.item>
                    @endcan
                    @can('viewRecommendations', auth()->user()->currentTeam)
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('recommendations.index', auth()->user()->currentTeam)" :current="request()->routeIs('recommendations.index')" wire:navigate>Action Center</flux:sidebar.item>
                    @endcan
                    <flux:sidebar.item icon="arrows-right-left" :href="route('transactions.index', ['current_team' => auth()->user()->currentTeam?->slug])" :current="request()->routeIs('transactions.*')" wire:navigate>{{ __('Transactions') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="tag" :href="route('categories.index', ['current_team' => auth()->user()->currentTeam?->slug])" :current="request()->routeIs('categories.*')" wire:navigate>{{ __('Categories') }}</flux:sidebar.item>
                    @if (($activeTeam = auth()->user()->currentTeam) && auth()->user()->can('manageContacts', $activeTeam))
                        <flux:sidebar.item icon="users" :href="route('customers.index', $activeTeam)" :current="request()->routeIs('customers.*')" wire:navigate>{{ __('Customers') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="building-office" :href="route('suppliers.index', $activeTeam)" :current="request()->routeIs('suppliers.*')" wire:navigate>{{ __('Suppliers') }}</flux:sidebar.item>
                        @foreach (['invoices' => ['Invoices', 'document-text'], 'bills' => ['Supplier bills', 'clipboard-document-list']] as $page => [$label, $icon])
                            <flux:sidebar.item :icon="$icon" :href="route($page.'.index', $activeTeam)" :current="request()->routeIs($page.'.index')" wire:navigate>{{ $label }}</flux:sidebar.item>
                        @endforeach
                    @endif
                    @can('syncLoyverse', auth()->user()->currentTeam)
                        <flux:sidebar.item icon="arrow-path" :href="route('loyverse.index', auth()->user()->currentTeam)" :current="request()->routeIs('loyverse.index')" wire:navigate>{{ __('Loyverse sync') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.nav>
                <h2 id="settings-nav-heading" class="agos-nav-label">{{ __('Settings') }}</h2>
                <flux:sidebar.nav aria-labelledby="settings-nav-heading" class="agos-sidebar-scroll">
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('business.edit')" :current="request()->routeIs('business.*')" wire:navigate>{{ __('Business Profile') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('teams.index')" :current="request()->routeIs('teams.*')" wire:navigate>{{ __('Team Management') }}</flux:sidebar.item>
                </flux:sidebar.nav>
            </div>
            <flux:spacer />

            <div class="flex items-center justify-between gap-2">
                <div class="hidden min-w-0 flex-1 lg:block">
                    <x-desktop-user-menu :name="auth()->user()->name" />
                </div>
                <x-appearance-switch compact />
            </div>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
