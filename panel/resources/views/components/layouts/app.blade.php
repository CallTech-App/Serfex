@php
    $nav = [
        [null, [
            ['dashboard', 'layout-dashboard', 'Genel Bakış'],
            ['monitor.index', 'activity', 'Canlı İzleme'],
            ['ai.index', 'sparkles', 'Servik AI'],
        ]],
        ['Barındırma', [
            ['sites.index', 'globe', 'Siteler'],
            ['laravel.index', 'layers', 'Laravel'],
            ['dns.index', 'network', 'Alan Adları ve DNS'],
            ['mail.index', 'mail', 'E-posta'],
            [null, 'database', 'Veritabanları'],
            ['ssl.index', 'shield-check', 'SSL Sertifikaları'],
        ]],
        ['Hesaplar', [
            ['customers.index', 'users', 'Müşteriler ve Paketler'],
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
    if ($user?->isCustomer()) {
        // A customer sees their own hosting, within their package.
        $nav = [
            [null, [['dashboard', 'layout-dashboard', 'Genel Bakış']]],
            ['Barındırma', array_values(array_filter([
                ['sites.index', 'globe', 'Siteler'],
                $user->allows('laravel') ? ['laravel.index', 'layers', 'Laravel'] : null,
                $user->allows('dns') ? ['dns.index', 'network', 'Alan Adları ve DNS'] : null,
                $user->allows('mail') ? ['mail.index', 'mail', 'E-posta'] : null,
                [null, 'database', 'Veritabanları'],
                ['ssl.index', 'shield-check', 'SSL Sertifikaları'],
            ]))],
        ];
    } elseif ($user && ! $user->is_admin) {
        // Members of Laravel applications see only their applications.
        $nav = [['Barındırma', [['laravel.index', 'layers', 'Laravel']]]];
    }
    // Problem counts shown next to menu items.
    $alerts = match (true) {
        (bool) $user?->is_admin => [
            'monitor.index' => [(int) \Illuminate\Support\Facades\Cache::get(\App\Services\Monitor\LiveStats::ATTENTION_KEY, 0), 'site veya saldırı'],
            'ssl.index' => [\App\Models\Certificate::attentionCount(), 'sertifika'],
            'mail.index' => [\App\Models\MailDomain::where('status', \App\Enums\MailDomainStatus::Failed)->count(), 'posta alan adı'],
            'laravel.index' => [\App\Models\LaravelApp::attentionCount(), 'Laravel uygulaması'],
            'customers.index' => [app(\App\Services\Customers\Quotas::class)->attentionCount(), 'müşteri kotası'],
        ],
        (bool) $user?->isCustomer() => [
            'ssl.index' => [\App\Models\Certificate::attentionCount($user), 'sertifika'],
        ],
        default => [],
    };
    $impersonator = \App\Support\Impersonation::administrator();
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
                                            @if ($alerts[$route][0] ?? 0)
                                                <span class="num ml-auto min-w-5 rounded-full bg-warn px-1.5 text-center text-2xs leading-5 font-semibold text-nav" title="İlgilenmeniz gereken {{ $alerts[$route][0] }} {{ $alerts[$route][1] }}">{{ $alerts[$route][0] }}</span>
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
                            <p class="truncate text-2xs text-nav-muted">{{ match (true) { $user->is_admin => 'Sunucu yöneticisi', $user->isCustomer() => 'Müşteri · '.$user->customer?->package?->name, default => 'Laravel ekibi' } }}</p>
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
                        @if ($user?->is_admin)
                            @if (request()->routeIs('ai.*'))
                                <a href="{{ route('ai.index') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md bg-accent-soft px-3 text-[13px] font-medium text-accent hover:bg-accent/15">
                                    <x-icon name="sparkles" /><span class="hidden md:inline">Servik AI</span>
                                </a>
                            @else
                                <button type="button" x-on:click="$dispatch('ai-open')" class="flex h-9 items-center gap-2 rounded-md bg-accent-soft px-3 text-[13px] font-medium text-accent hover:bg-accent/15" aria-label="Servik AI">
                                    <x-icon name="sparkles" /><span class="hidden md:inline">Servik AI</span>
                                </button>
                            @endif
                        @endif
                        <button type="button" onclick="servikToggleTheme()" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" aria-label="Temayı değiştir" title="Temayı değiştir">
                            <x-icon name="sun-moon" class="size-[18px]" />
                        </button>
                    </div>
                </header>

                @if ($impersonator)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-warn/30 bg-warn-soft px-4 py-2.5 text-[13px] lg:px-8">
                        <x-icon name="user-check" class="text-warn" />
                        <span class="min-w-0 flex-1"><b class="font-medium">{{ $user->customer?->displayName() ?? $user->name }}</b> olarak bakıyorsunuz. Müşterinin gördüğü panel budur.</span>
                        <form method="POST" action="{{ route('impersonation.leave') }}">
                            @csrf
                            <button type="submit" class="flex h-8 items-center gap-1.5 rounded-md border border-line bg-surface px-3 font-medium hover:bg-surface-2"><x-icon name="undo-2" />{{ $impersonator->name }} olarak dön</button>
                        </form>
                    </div>
                @endif
                <main class="mx-auto w-full max-w-[1440px] flex-1 space-y-6 px-4 py-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @if ($user?->is_admin && ! request()->routeIs('ai.*'))
            <livewire:ai.ai-drawer />
        @endif
        <x-toasts />
        @livewireScripts
    </body>
</html>
