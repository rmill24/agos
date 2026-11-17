<x-layouts::app :title="__('Dashboard')">
    <div class="agos-page">
        <livewire:pages::teams.pending-invitations-modal />
        <div class="agos-page-header">
            <div>
                <p class="agos-eyebrow">{{ __('Workspace') }}</p>
                <flux:heading size="xl" level="1" class="mt-2">
                    {{ __('Good day, :name', ['name' => auth()->user()->name]) }}</flux:heading>
                <flux:subheading class="mt-2">
                    {{ __('Here is your starting point for keeping business finances organized.') }}</flux:subheading>
            </div>
            <div class="rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-900"><span
                    class="font-semibold">{{ auth()->user()->currentTeam?->name }}</span><span
                    class="mx-1 text-brand-300">·</span>{{ __('Business workspace') }}</div>
        </div>
        <div class="grid gap-5 md:grid-cols-3">
            <article class="agos-card p-6">
                <div class="flex items-center justify-between"><span class="rounded-xl bg-brand-50 p-3 text-brand-600">
                        <flux:icon name="arrows-right-left" class="size-5" />
                    </span><span
                        class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Workspace') }}</span>
                </div>
                <h2 class="mt-5 text-lg font-bold">{{ __('Transactions') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ __('Record and review income and expenses for this business.') }}</p><a
                    class="agos-link mt-5 inline-block text-sm"
                    href="{{ route('transactions.index', ['current_team' => auth()->user()->currentTeam?->slug]) }}"
                    wire:navigate>{{ __('Open transactions') }} →</a>
            </article>
            <article class="agos-card p-6">
                <div class="flex items-center justify-between"><span
                        class="rounded-xl bg-emerald-50 p-3 text-emerald-600">
                        <flux:icon name="tag" class="size-5" />
                    </span><span
                        class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Workspace') }}</span>
                </div>
                <h2 class="mt-5 text-lg font-bold">{{ __('Categories') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ __('Keep your financial activity easy to understand with business categories.') }}</p><a
                    class="agos-link mt-5 inline-block text-sm"
                    href="{{ route('categories.index', ['current_team' => auth()->user()->currentTeam?->slug]) }}"
                    wire:navigate>{{ __('Manage categories') }} →</a>
            </article>
            <article class="agos-card p-6">
                <div class="flex items-center justify-between"><span class="rounded-xl bg-amber-50 p-3 text-amber-600">
                        <flux:icon name="building-office-2" class="size-5" />
                    </span><span
                        class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Business') }}</span>
                </div>
                <h2 class="mt-5 text-lg font-bold">{{ __('Business profile') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ __('Review the details that keep your AGOS workspace grounded in your business.') }}</p><a
                    class="agos-link mt-5 inline-block text-sm" href="{{ route('business.edit') }}"
                    wire:navigate>{{ __('View profile') }} →</a>
            </article>
        </div>
        <section class="agos-card mt-5 overflow-hidden">
            <div
                class="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-bold">{{ __('Your workspace is ready') }}</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ __('AGOS shows what is available today and keeps future capabilities clearly separated.') }}
                    </p>
                </div><span
                    class="inline-flex w-fit items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700"><span
                        class="size-1.5 rounded-full bg-emerald-500"></span>{{ __('Business profile complete') }}</span>
            </div>
            <div class="grid gap-6 px-6 py-6 text-sm text-slate-600 sm:grid-cols-3">
                <div>
                    <p class="font-semibold text-slate-900">{{ __('Start with records') }}</p>
                    <p class="mt-1 leading-6">
                        {{ __('Capture the activity you already know so your view stays useful and evidence-based.') }}
                    </p>
                </div>
                <div>
                    <p class="font-semibold text-slate-900">{{ __('Keep context close') }}</p>
                    <p class="mt-1 leading-6">
                        {{ __('Use categories and references to make every entry easier to review later.') }}</p>
                </div>
                <div>
                    <p class="font-semibold text-slate-900">{{ __('Invite the right people') }}</p>
                    <p class="mt-1 leading-6">
                        {{ __('Manage business access through the team workspace and role permissions.') }}</p>
                </div>
            </div>
        </section>
    </div>
</x-layouts::app>
