@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="AGOS" aria-label="AGOS" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-brand-600 text-white">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="AGOS" aria-label="AGOS" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-brand-600 text-white">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
