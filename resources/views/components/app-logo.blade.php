@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand aria-label="AGOS" {{ $attributes }}>
        <x-slot name="name"><span class="block text-2xl leading-none font-bold tracking-tight">AGOS</span></x-slot>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center">
            <img src="{{ asset('brand/agos-logo.png') }}" alt="" aria-hidden="true" class="size-10 rounded-xl object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="AGOS" aria-label="AGOS" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-brand-600 text-white">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
