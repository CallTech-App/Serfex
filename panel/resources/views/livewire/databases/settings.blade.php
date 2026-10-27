@php
    use App\Services\Databases\DatabaseServers;
    $rows = [
        DatabaseServers::MYSQL => 'MySQL / MariaDB',
        DatabaseServers::POSTGRESQL => 'PostgreSQL',
    ];
    $busy = $server['state'] === 'installing';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('databases.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Veritabanları') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Veritabanı sunucuları') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Veritabanı sunucuları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Müşteri veritabanlarının çalıştığı sunucular ve tarayıcıdan yönetim. Serfex\'in kendi veritabanı bunlardan ayrıdır.') }}</p>
    </div>

    @include('livewire.databases.server-state')

    @if ($installed !== [])
        <section class="rounded-xl border border-line bg-surface">
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <h2 class="mr-auto text-[14.5px] font-semibold">{{ __('Sunucular') }}</h2>
                <button type="button" wire:click="setupServer" @disabled($busy) class="flex h-9 items-center gap-2 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40">
                    <x-icon name="refresh-cw" />{{ __('Denetle ve onar') }}
                </button>
            </div>
            <div class="divide-y divide-line text-[13.5px]">
                @foreach ($rows as $key => $label)
                    @php $s = $servers[$key] ?? ['installed' => false]; @endphp
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ ($s['installed'] ?? false) ? (DatabaseServers::PRODUCTS[$s['product']][1] ?? $label) : $label }}</p>
                            <p class="mt-0.5 text-[13px] text-muted">
                                @if ($s['installed'] ?? false)
                                    <x-t text=":version · :remote · port :key" :with="['remote' => ($s['remote'] ?? false) ? __('Uzak bağlantılara açık (izin verilen kullanıcılar için)') : __('Yalnızca bu sunucudan bağlantı')]"><x-slot:version><span class="font-mono">{{ preg_replace('/-.*$/', '', (string) ($s['version'] ?? '')) ?: '—' }}</span></x-slot:version><x-slot:key><span class="font-mono">{{ $key === 'postgresql' ? 5432 : 3306 }}</span></x-slot:key></x-t>
                                @elseif ($key === DatabaseServers::POSTGRESQL)
                                    {{ __('Kurulu değil. MySQL ile yan yana çalışır.') }}
                                @else
                                    {{ __('Kurulu değil.') }}
                                @endif
                            </p>
                        </div>
                        @if ($s['installed'] ?? false)
                            <x-status-badge :status="($s['running'] ?? false) ? new \App\Support\Badge(__('Çalışıyor'), 'ok') : new \App\Support\Badge(__('Durdu'), 'crit')" :title="$s['error'] ?? null" />
                        @elseif ($key === DatabaseServers::POSTGRESQL)
                            <button type="button" wire:click="addPostgres" @disabled($busy) class="flex h-8 items-center gap-2 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                                <x-icon name="download" class="size-3.5" />{{ __('PostgreSQL\'i kur') }}
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-line bg-surface">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Web veritabanı yöneticisi') }}</h2>
            <div class="max-w-3xl px-5 py-2 text-[13.5px]">
                <div class="divide-y divide-line">
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span class="sm:pt-0.5">{{ __('Araçlar') }}</span>
                        <p>
                            @if ($manager['configured'] ?? false)
                                {{ collect(['phpMyAdmin' => $manager['phpmyadmin'] ?? false, 'Adminer' => $manager['adminer'] ?? false])->filter()->keys()->join(', ') ?: '—' }}
                                <span class="text-muted">{{ __('· Serfex\'in kendi web sunucusunda, müşteri sitelerinden ayrı') }}</span>
                            @else
                                <span class="text-muted">{{ __('Henüz kurulmadı.') }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span class="sm:pt-0.5">{{ __('Port (HTTPS)') }}</span>
                        <div>
                            <p class="num font-mono">{{ $port }}</p>
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Panelle aynı port. Panelden "phpMyAdmin\'de aç" ya da "Adminer\'da aç" denince tek kullanımlık bir bağlantıyla parola sorulmadan açılır; oturum 4 saat sürer.') }}
                                @if (auth()->user()->isFullAdmin())
                                    <x-t text="Port :link sayfasında değişir."><x-slot:link><a href="{{ route('settings.general') }}" wire:navigate class="text-accent hover:underline">{{ __('Araçlar ve Ayarlar → Genel') }}</a></x-slot:link></x-t>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endif
</div>
