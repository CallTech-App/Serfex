{{-- The setup wizard's page: no menu, so the first run stays on one job. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>{{ __($title ?? 'Kurulum sihirbazı · Serfex') }}</title>
        <x-favicon />
        <script>
            try { const t = localStorage.getItem('serfex-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex h-14 max-w-5xl items-center gap-2.5 px-4">
                <x-logo class="size-7" />
                <span class="text-[16.5px] font-semibold tracking-[-0.015em]">serfex</span>
                <span class="ml-2 hidden text-[13px] text-muted sm:inline">{{ __('Kurulum sihirbazı') }}</span>
                <a href="{{ route('dashboard') }}" class="ml-auto text-[13px] text-muted hover:text-fg">{{ __('Panele geç') }}</a>
            </div>
        </header>
        <main class="mx-auto w-full max-w-5xl px-4 py-6 lg:py-10">
            {{ $slot }}
        </main>
        <x-toasts />
        <x-connection-lost />
        @livewireScripts
    </body>
</html>
