@php
    $nav = [
        [null, [
            ['dashboard', 'layout-dashboard', 'Genel Bakış'],
            [null, 'activity', 'Canlı İzleme'],
            [null, 'sparkles', 'Servik AI'],
        ]],
        ['Barındırma', [
            ['sites.index', 'globe', 'Siteler'],
            [null, 'layers', 'Laravel'],
            ['dns.index', 'network', 'Alan Adları ve DNS'],
            [null, 'mail', 'E-posta'],
            [null, 'database', 'Veritabanları'],
            ['ssl.index', 'shield-check', 'SSL Sertifikaları'],
        ]],
        ['Hesaplar', [
            [null, 'users', 'Müşteriler ve Paketler'],
        ]],
        ['Sunucu', [
            [null, 'shield-alert', 'Güvenlik'],
            [null, 'archive', 'Yedekler'],
            [null, 'server-cog', 'Hizmetler'],
            [null, 'package-plus', 'Yazılım Yönetimi'],
            [null, 'settings', 'Araçlar ve Ayarlar'],
        ]],
    ];
    $user = auth()->user();
    // Problem counts shown next to menu items.
    $alerts = $user ? ['ssl.index' => \App\Models\Certificate::attentionCount()] : [];
@endphp
<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>{{ $title ?? 'Servik' }}</title>
        <script>
            try { const t = localStorage.getItem('servik-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen" x-data="{ menu: false }">
        <div class="flex min-h-screen">
            {{-- Sidebar --}}
            <aside :class="menu ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-nav-line bg-nav text-nav-fg transition-transform lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0">
                <div class="flex h-14 items-center gap-2.5 border-b border-nav-line px-5">
                    <x-logo />
                    <span class="text-[17px] font-semibold tracking-tight">servik</span>
                    <span class="ml-auto font-mono text-2xs text-nav-muted">{{ config('servik.version') }}</span>
                </div>

                <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4 text-[13.5px]" aria-label="Ana menü">
                    @foreach ($nav as [$group, $items])
                        <div>
                            @if ($group)
                                <p class="mb-1.5 px-3 text-2xs font-medium tracking-[.08em] text-nav-muted uppercase">{{ $group }}</p>
                            @endif
                            <div class="space-y-0.5">
                                @foreach ($items as [$route, $icon, $label])
                                    @if ($route)
                                        @php $on = request()->routeIs($route) || request()->routeIs(\Illuminate\Support\Str::before($route, '.').'.*'); @endphp
                                        <a href="{{ route($route) }}" wire:navigate @if ($on) aria-current="page" @endif
                                           class="flex h-9 items-center gap-3 rounded-md px-3 {{ $on ? 'bg-nav-active font-medium text-white' : 'text-nav-fg/80 hover:bg-nav-active hover:text-white' }}">
                                            <x-icon :name="$icon" @class(['text-accent' => $on]) />{{ $label }}
                                            @if ($alerts[$route] ?? 0)
                                                <span class="num ml-auto min-w-5 rounded-full bg-warn px-1.5 text-center text-2xs leading-5 font-semibold text-nav" title="İlgilenmeniz gereken {{ $alerts[$route] }} sertifika">{{ $alerts[$route] }}</span>
                                            @endif
                                        </a>
                                    @else
                                        <span aria-disabled="true" title="Sonraki sürümlerde gelecek" class="flex h-9 cursor-not-allowed items-center gap-3 rounded-md px-3 text-nav-muted/70">
                                            <x-icon :name="$icon" />{{ $label }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>

                @if ($user)
                    <div class="flex items-center gap-3 border-t border-nav-line p-4">
                        <div class="grid size-8 place-items-center rounded-full bg-accent/20 text-xs font-semibold text-accent">{{ \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->join('') }}</div>
                        <div class="min-w-0 leading-tight">
                            <p class="truncate text-[13px] font-medium">{{ $user->name }}</p>
                            <p class="text-2xs text-nav-muted">Sunucu yöneticisi</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                            @csrf
                            <button type="submit" class="text-nav-muted hover:text-white" aria-label="Çıkış yap" title="Çıkış yap"><x-icon name="log-out" /></button>
                        </form>
                    </div>
                @endif
            </aside>
            <div x-show="menu" x-cloak @click="menu = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

            {{-- Workspace --}}
            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur lg:px-8">
                    <button type="button" @click="menu = true" class="-ml-1 rounded-md p-2 hover:bg-surface-2 lg:hidden" aria-label="Menüyü aç"><x-icon name="menu" class="size-5" /></button>
                    <span class="hidden h-9 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] sm:flex">
                        <span class="size-2 rounded-full bg-ok"></span>
                        <span class="font-mono">{{ gethostname() }}</span>
                    </span>
                    <div class="ml-auto flex items-center gap-1">
                        <button type="button" onclick="servikToggleTheme()" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" aria-label="Temayı değiştir" title="Temayı değiştir">
                            <x-icon name="sun-moon" class="size-[18px]" />
                        </button>
                    </div>
                </header>

                <main class="mx-auto w-full max-w-[1440px] flex-1 space-y-6 px-4 py-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-toasts />
        @livewireScripts
    </body>
</html>
