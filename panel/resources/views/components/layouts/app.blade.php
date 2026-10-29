@php
    $nav = [
        [null, [
            ['dashboard', 'layout-dashboard', __('Genel Bakış')],
            ['monitor.index', 'activity', __('Canlı İzleme')],
            ['ai.index', 'sparkles', 'Serfex AI'],
        ]],
        [__('Barındırma'), [
            ['sites.index', 'globe', __('Siteler')],
            ['laravel.index', 'layers', 'Laravel'],
            ['dns.index', 'network', __('Alan Adları ve DNS')],
            ['mail.index', 'mail', __('E-posta')],
            ['databases.index', 'database', __('Veritabanları')],
            ['ssl.index', 'shield-check', __('SSL Sertifikaları')],
        ]],
        [__('Hesaplar'), [
            ['customers.index', 'users', __('Müşteriler ve Paketler')],
        ]],
        [__('Sunucu'), [
            ['security.index', 'shield-alert', __('Güvenlik')],
            ['backups.index', 'archive', __('Yedekler')],
            ['services.index', 'server-cog', __('Hizmetler')],
            ['components.index', 'package-plus', __('Yazılım Yönetimi')],
            ['settings.general', 'settings', __('Araçlar ve Ayarlar')],
        ]],
    ];
    $user = auth()->user();
    if ($user?->is_admin && ! $user->isFullAdmin()) {
        // Araçlar ve Ayarlar is for full administrators; Salt okunur
        // accounts do not use Serfex AI either.
        $nav[3][1] = array_values(array_filter($nav[3][1], fn ($i) => $i[0] !== 'settings.general'));
        if ($user->isReadOnly()) {
            $nav[0][1] = array_values(array_filter($nav[0][1], fn ($i) => $i[0] !== 'ai.index'));
        }
    }
    if ($user?->isCustomer()) {
        // A customer sees their own hosting, within their package.
        $nav = [
            [null, [['dashboard', 'layout-dashboard', __('Genel Bakış')], ['usage', 'chart-column', __('Kullanım')]]],
            [__('Barındırma'), array_values(array_filter([
                ['sites.index', 'globe', __('Siteler')],
                $user->allows('laravel') ? ['laravel.index', 'layers', 'Laravel'] : null,
                $user->allows('dns') ? ['dns.index', 'network', __('Alan Adları ve DNS')] : null,
                $user->allows('mail') ? ['mail.index', 'mail', __('E-posta')] : null,
                ['databases.index', 'database', __('Veritabanları')],
                ['ssl.index', 'shield-check', __('SSL Sertifikaları')],
                $user->allows('backups') ? ['backups.index', 'archive', __('Yedekler')] : null,
            ]))],
        ];
    } elseif ($user && ! $user->is_admin) {
        // Members of Laravel applications see only their applications.
        $nav = [[__('Barındırma'), [['laravel.index', 'layers', 'Laravel']]]];
    }
    // Problem counts shown next to menu items.
    $alerts = match (true) {
        (bool) $user?->is_admin => [
            'monitor.index' => [(int) \Illuminate\Support\Facades\Cache::get(\App\Services\Monitor\LiveStats::ATTENTION_KEY, 0), __('İlgilenmeniz gereken :count site veya saldırı')],
            'security.index' => [\App\Services\Security\Scans::attentionCount(), __('İlgilenmeniz gereken :count ciddi güvenlik bulgusu')],
            'ssl.index' => [\App\Models\Certificate::attentionCount(), __('İlgilenmeniz gereken :count sertifika')],
            'mail.index' => [\App\Models\MailDomain::where('status', \App\Enums\MailDomainStatus::Failed)->count(), __('İlgilenmeniz gereken :count posta alan adı')],
            'laravel.index' => [\App\Models\LaravelApp::attentionCount(), __('İlgilenmeniz gereken :count Laravel uygulaması')],
            'customers.index' => [app(\App\Services\Customers\Quotas::class)->attentionCount(), __('İlgilenmeniz gereken :count müşteri kotası')],
            'backups.index' => [count(app(\App\Services\Backups\Backups::class)->problems()), __('İlgilenmeniz gereken :count yedekleme sorunu')],
            'services.index' => [count(\App\Services\System\SystemServices::problems()), __('İlgilenmeniz gereken :count durmuş hizmet')],
            'settings.general' => [count(app(\App\Services\Updates\Updates::class)->notices()), __('İlgilenmeniz gereken :count güncelleme bildirimi')],
        ],
        (bool) $user?->isCustomer() => [
            'ssl.index' => [\App\Models\Certificate::attentionCount($user), __('İlgilenmeniz gereken :count sertifika')],
        ],
        default => [],
    };
    $impersonator = \App\Support\Impersonation::administrator();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>{{ __($title ?? 'Serfex') }}</title>
        <x-favicon />
        <script>
            try { const t = localStorage.getItem('serfex-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen" x-data="{ menu: false }">
        <div class="flex min-h-screen">
            {{-- Sidebar --}}
            <aside :class="menu ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col border-r border-nav-line bg-nav text-nav-fg transition-transform lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0">
                <div class="flex h-14 shrink-0 items-center px-4">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2.5 rounded-md" aria-label="{{ __('Genel Bakış') }}">
                        <x-logo class="size-7" />
                        <span class="text-[16.5px] font-semibold tracking-[-0.015em] text-nav-strong">serfex</span>
                    </a>
                </div>

                <nav class="flex-1 overflow-y-auto px-3 pt-1 pb-4 text-[13.5px]" aria-label="{{ __('Ana menü') }}">
                    @foreach ($nav as [$group, $items])
                        <div class="mt-5 first:mt-1">
                            @if ($group)
                                <p class="mb-1 px-2.5 text-xs font-medium text-nav-muted">{{ __($group) }}</p>
                            @endif
                            <div class="space-y-px">
                                @foreach ($items as [$route, $icon, $label])
                                    @if ($route)
                                        @php $on = request()->routeIs($route) || request()->routeIs(\Illuminate\Support\Str::before($route, '.').'.*'); @endphp
                                        <a href="{{ route($route) }}" wire:navigate @if ($on) aria-current="page" @endif
                                           class="relative flex h-8 items-center gap-2.5 rounded-md px-2.5 {{ $on ? 'bg-nav-active font-medium text-nav-strong before:absolute before:inset-y-1.5 before:-left-3 before:w-[3px] before:rounded-r-full before:bg-accent' : 'hover:bg-nav-active hover:text-nav-strong' }}">
                                            {{-- Teal is for the brand and Serfex AI only. --}}
                                            <x-icon :name="$icon" stroke-width="1.75" @class(['text-accent' => $icon === 'sparkles', 'text-nav-strong' => $on && $icon !== 'sparkles', 'text-nav-muted' => ! $on && $icon !== 'sparkles']) />{{ __($label) }}
                                            @if ($alerts[$route][0] ?? 0)
                                                <span class="num ml-auto rounded bg-warn/15 px-1.5 text-2xs leading-[18px] font-semibold text-warn" title="{{ trans_choice($alerts[$route][1], $alerts[$route][0]) }}">{{ $alerts[$route][0] }}</span>
                                            @endif
                                        </a>
                                    @else
                                        <span aria-disabled="true" title="{{ __('Sonraki sürümlerde gelecek') }}" class="flex h-8 cursor-not-allowed items-center gap-2.5 rounded-md px-2.5 text-nav-muted/70">
                                            <x-icon :name="$icon" stroke-width="1.75" />{{ __($label) }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>

                @if ($user)
                    <div class="border-t border-nav-line p-3">
                        <div class="flex items-center gap-2.5 px-1.5 py-1">
                            <a href="{{ route('account.profile') }}" wire:navigate class="group flex min-w-0 items-center gap-2.5" title="{{ __('Hesap bilgileri') }}">
                                <div class="grid size-8 shrink-0 place-items-center rounded-full bg-nav-active text-xs font-semibold text-nav-strong">{{ \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->join('') }}</div>
                                <div class="min-w-0 leading-tight">
                                    <p class="truncate text-[13px] font-medium text-nav-strong group-hover:underline">{{ $user->name }}</p>
                                    <p class="truncate text-2xs text-nav-muted">{{ match (true) { $user->is_admin => $user->roleName(), $user->isCustomer() => __('Müşteri · :package', ['package' => $user->customer?->package?->name]), default => __('Laravel ekibi') } }}</p>
                                </div>
                            </a>
                            <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                                @csrf
                                <button type="submit" class="rounded-md p-1.5 text-nav-muted hover:bg-nav-active hover:text-nav-strong" aria-label="{{ __('Çıkış yap') }}" title="{{ __('Çıkış yap') }}"><x-icon name="log-out" stroke-width="1.75" /></button>
                            </form>
                        </div>
                        @if ($user->is_admin)
                            <p class="mt-2 px-1.5 font-mono text-2xs text-nav-muted">Serfex {{ config('serfex.version') }}</p>
                        @endif
                    </div>
                @endif
            </aside>
            <div x-show="menu" x-cloak @click="menu = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

            {{-- Workspace --}}
            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur lg:px-8">
                    <button type="button" @click="menu = true" class="-ml-1 rounded-md p-2 hover:bg-surface-2 lg:hidden" aria-label="{{ __('Menüyü aç') }}"><x-icon name="menu" class="size-5" /></button>
                    <span class="hidden h-9 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] sm:flex">
                        <span class="size-2 rounded-full bg-ok"></span>
                        <span class="font-mono">{{ \App\Models\Setting::get('server.hostname') ?: gethostname() }}</span>
                    </span>
                    @if ($user)
                        <livewire:quick-search />
                    @endif
                    <div class="ml-auto flex items-center gap-1">
                        @if ($user?->is_admin && ! $user->isReadOnly())
                            @if (request()->routeIs('ai.*'))
                                <a href="{{ route('ai.index') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md bg-accent-soft px-3 text-[13px] font-medium text-accent hover:bg-accent/15">
                                    <x-icon name="sparkles" /><span class="hidden md:inline">Serfex AI</span>
                                </a>
                            @else
                                <button type="button" x-on:click="$dispatch('ai-open')" class="flex h-9 items-center gap-2 rounded-md bg-accent-soft px-3 text-[13px] font-medium text-accent hover:bg-accent/15" aria-label="Serfex AI">
                                    <x-icon name="sparkles" /><span class="hidden md:inline">Serfex AI</span>
                                </button>
                            @endif
                        @endif
                        <button type="button" onclick="serfexToggleTheme()" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Temayı değiştir') }}" title="{{ __('Temayı değiştir') }}">
                            <x-icon name="sun-moon" class="size-[18px]" />
                        </button>
                    </div>
                </header>

                @if ($user?->isReadOnly())
                    <div class="flex items-center gap-3 border-b border-info/30 bg-info-soft px-4 py-2.5 text-[13px] lg:px-8">
                        <x-icon name="eye" class="text-info" />
                        <span>{{ __('Salt okunur hesapla bakıyorsunuz: her şeyi görebilir, hiçbir şeyi değiştiremezsiniz.') }}</span>
                    </div>
                @endif
                @if ($impersonator)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-warn/30 bg-warn-soft px-4 py-2.5 text-[13px] lg:px-8">
                        <x-icon name="user-check" class="text-warn" />
                        <span class="min-w-0 flex-1"><x-t text=":name olarak bakıyorsunuz. Müşterinin gördüğü panel budur."><x-slot:name><b class="font-medium">{{ $user->customer?->displayName() ?? $user->name }}</b></x-slot:name></x-t></span>
                        <form method="POST" action="{{ route('impersonation.leave') }}">
                            @csrf
                            <button type="submit" class="flex h-8 items-center gap-1.5 rounded-md border border-line bg-surface px-3 font-medium hover:bg-surface-2"><x-icon name="undo-2" />{{ __(':name olarak dön', ['name' => $impersonator->name]) }}</button>
                        </form>
                    </div>
                @endif
                <main class="mx-auto w-full max-w-[1440px] flex-1 space-y-6 px-4 py-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @if ($user?->is_admin && ! $user->isReadOnly() && ! request()->routeIs('ai.*'))
            <livewire:ai.ai-drawer />
        @endif
        <x-toasts />
        <x-connection-lost />
        @livewireScripts
    </body>
</html>
