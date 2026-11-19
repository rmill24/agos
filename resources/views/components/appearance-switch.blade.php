@props(['compact' => false])
<div x-data role="group" aria-label="Appearance" @class(['agos-appearance-switch', 'agos-appearance-switch-compact' => $compact])>
    @foreach (['system' => 'computer-desktop', 'light' => 'sun', 'dark' => 'moon'] as $mode => $icon)
        <button type="button" class="agos-appearance-option" aria-label="{{ ucfirst($mode) }} appearance"
            title="{{ ucfirst($mode) }} appearance" :aria-pressed="$flux.appearance === '{{ $mode }}'"
            @click="window.agosChangeAppearance('{{ $mode }}', $el, mode => $flux.appearance = mode)">
            <flux:icon :name="$icon" variant="micro" />
            @unless ($compact)<span>{{ ucfirst($mode) }}</span>@endunless
        </button>
    @endforeach
</div>
