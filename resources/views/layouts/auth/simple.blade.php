<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-slate-50 dark:bg-canvas antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-5 sm:p-8">
            <div class="flex w-full max-w-md flex-col gap-5">
                <a href="{{ route('home') }}" class="flex items-center justify-center gap-2.5 font-bold" wire:navigate>
                    <span class="flex size-10 items-center justify-center rounded-xl bg-brand-600 text-white"><x-app-logo-icon class="size-6" /></span>
                    <span class="text-xl tracking-tight">AGOS</span>
                </a>
                <div class="agos-card p-6 sm:p-8">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
