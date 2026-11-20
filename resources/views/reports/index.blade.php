<x-layouts::app title="Reports">
    <section class="agos-page space-y-6">
        <header class="agos-page-header">
            <div><p class="agos-eyebrow">{{ $team->name }}</p><flux:heading level="1" size="xl">Reports</flux:heading></div>
        </header>
        @if ($errors->any())
            <div class="agos-card p-4" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('reports.store', $team) }}" x-data="{ saving: false }" x-on:submit="saving = true" class="agos-card grid gap-4 p-5 sm:grid-cols-[1fr_auto] sm:items-end">
            @csrf
            <flux:select name="kind" label="Report type">
                @foreach (array_diff_key($titles, array_flip(['invoice', 'scenario'])) as $kind => $title)
                    <option value="{{ $kind }}">{{ $title }}</option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" x-bind:disabled="saving">Save and preview</flux:button>
            <span x-cloak x-show="saving" role="status">Preparing report…</span>
        </form>
        <p class="agos-muted text-sm">For an individual invoice, open its details in <a class="agos-link" href="{{ route('invoices.index', $team) }}">Invoices</a>.
            @can('runScenarios', $team) Save a hypothetical comparison from the <a class="agos-link" href="{{ route('scenarios.index', $team) }}">Scenario Simulator</a>. @endcan
        </p>
        <livewire:reports.pdf-status-list :team="$team" />
    </section>
</x-layouts::app>
