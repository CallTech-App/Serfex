@php
    // Site tools, grouped by job. Only the ones with a link work in this
    // version; the rest show what is coming. [icon, name, status, link]
    $groups = [
        ['Dosya ve veri', 'folder', [
            ['folder', 'Dosya Yöneticisi', null],
            ['hard-drive-upload', 'FTP hesapları', null],
            ['database', 'Veritabanları', null],
            ['archive-restore', 'Yedekler', null],
            ['key-round', 'Bağlantı bilgileri', null],
        ]],
        ['Geliştirme', 'code', [
            ['code', 'PHP ve web sunucusu', 'PHP '.$site->php_version.' · Nginx'],
            ['terminal', 'SSH terminali', null],
            ['clock', 'Zamanlanmış görevler', null],
            ['git-branch', 'Git dağıtımı', null],
            ['file-text', 'Günlükler', null],
        ]],
        ['Uygulamalar', 'blocks', [
            ['blocks', 'WordPress', null],
            ['layers', 'Laravel', 'Kurulu değil'],
            ['download', 'Başka sunucudan taşı', null],
        ]],
        ['Alan adı ve DNS', 'network', [
            ['network', 'DNS kayıtları', $zone ? $zone->records_count.' kayıt' : null, $zone ? route('dns.show', $zone) : null],
            ['globe', 'Alt alan adları', null],
            ['link', 'Ek alan adları', $site->aliases ? implode(', ', $site->aliases) : 'Yok'],
            ['corner-up-right', 'Yönlendirmeler', null],
        ]],
        ['E-posta', 'mail', [
            ['mail', 'Posta kutuları', null],
            ['forward', 'Yönlendiriciler', null],
            ['inbox', 'Webmail', null],
        ]],
        ['Güvenlik', 'shield', [
            ['shield-check', 'SSL sertifikası', 'Yok'],
            ['folder-lock', 'Parola korumalı dizinler', null],
            ['scan-search', 'Kötü amaçlı yazılım taraması', null],
        ]],
    ];
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on a site. --}}
    @if ($site->status->isBusy())
        <div wire:poll.2s="refreshStatus" hidden></div>
    @endif

    {{-- Title --}}
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="font-mono text-fg">{{ $site->domain }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $site->domain }}</h1>
                <x-status-badge :status="$site->status" />
                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="Diğer işlemler"><x-icon name="ellipsis-vertical" /></button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full left-0 z-20 mt-1 w-52 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                        <button type="button" wire:click="openDelete" @click="open = false" @disabled($site->status->isBusy())
                                class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2 disabled:opacity-40">Siteyi sil</button>
                    </div>
                </div>
            </div>
            @if ($site->status === \App\Enums\SiteStatus::Active)
                <a href="http://{{ $site->domain }}" target="_blank" rel="noopener" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                    <x-icon name="external-link" />Siteyi aç
                </a>
            @endif
        </div>
    </div>

    @if ($site->status === \App\Enums\SiteStatus::Failed)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Sunucuda son işlem tamamlanamadı. Önceki ayarlar geri yüklendi.</p>
                <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $site->last_error }}</p>
            </div>
            <button type="button" wire:click="retry" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="refresh-cw" class="size-3.5" />Tekrar dene
            </button>
        </div>
    @endif

    {{-- Status strip --}}
    <section class="grid grid-cols-1 rounded-xl border border-line bg-surface text-[13px] sm:grid-cols-2 xl:grid-cols-4 [&>div]:border-line [&>div]:p-4 max-sm:[&>div:not(:last-child)]:border-b sm:max-xl:[&>div:nth-child(-n+2)]:border-b sm:max-xl:[&>div:nth-child(odd)]:border-r xl:[&>div:not(:last-child)]:border-r">
        <div>
            <p class="text-2xs text-muted">Web sunucusu</p>
            <p class="mt-1 font-medium">Nginx · PHP {{ $site->php_version }}</p>
            <span class="text-2xs text-muted">PHP-FPM, siteye özel havuz</span>
        </div>
        <div>
            <p class="text-2xs text-muted">SSL</p>
            <p class="mt-1 font-medium text-muted">Sertifika yok</p>
            <span class="text-2xs text-muted">Site şimdilik yalnızca http ile açılır</span>
        </div>
        <div>
            <p class="text-2xs text-muted">Sistem kullanıcısı</p>
            <p class="mt-1 font-mono text-[12.5px]">{{ $site->systemUser->name }}</p>
            <span class="text-2xs text-muted">{{ $otherSites ? "Bu kullanıcının {$otherSites} sitesi daha var" : 'Yalnızca bu siteye ait' }}</span>
        </div>
        <div class="min-w-0">
            <p class="text-2xs text-muted">Belge kökü</p>
            <p class="mt-1 truncate font-mono text-[12.5px]" title="{{ $site->document_root }}">{{ $site->document_root ?? '—' }}</p>
            <span class="text-2xs text-muted">{{ $site->provisioned_at ? 'Oluşturuldu: '.$site->provisioned_at->format('d.m.Y H:i') : 'Henüz hazırlanmadı' }}</span>
        </div>
    </section>

    {{-- Tools --}}
    <div x-data="{ q: '' }" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-[15px] font-semibold">Site araçları</h2>
            <label class="relative w-full sm:w-64">
                <span class="sr-only">Araç ara</span>
                <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                <input x-model="q" type="search" placeholder="Araç ara" class="h-9 w-full rounded-md border border-line bg-surface pr-3 pl-9 text-[13.5px] outline-none focus:border-accent">
            </label>
        </div>
        <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($groups as [$group, $groupIcon, $tools])
                <section class="rounded-xl border border-line bg-surface"
                         x-show="!q || @js(mb_strtolower($group.' '.collect($tools)->pluck(1)->join(' '))).includes(q.toLocaleLowerCase('tr'))">
                    <h3 class="flex h-11 items-center gap-2 border-b border-line px-4 text-[13.5px] font-semibold"><x-icon :name="$groupIcon" class="text-accent" />{{ $group }}</h3>
                    <ul class="p-1.5">
                        @foreach ($tools as $tool)
                            @php [$icon, $name, $state, $link] = $tool + [3 => null]; @endphp
                            <li x-show="!q || @js(mb_strtolower($group.' '.$name)).includes(q.toLocaleLowerCase('tr'))">
                                @if ($link)
                                    <a href="{{ $link }}" wire:navigate class="flex h-10 items-center gap-3 rounded-md px-2.5 text-[13.5px] hover:bg-surface-2">
                                        <x-icon :name="$icon" class="text-muted" />
                                        <span class="truncate">{{ $name }}</span>
                                        <span class="ml-auto truncate text-2xs whitespace-nowrap text-muted">{{ $state }}</span>
                                        <x-icon name="chevron-right" class="size-3.5 text-muted" />
                                    </a>
                                @else
                                    <div class="flex h-10 items-center gap-3 rounded-md px-2.5 text-[13.5px]">
                                        <x-icon :name="$icon" class="text-muted" />
                                        <span class="truncate {{ $state === null ? 'text-muted' : '' }}">{{ $name }}</span>
                                        <span class="ml-auto truncate text-2xs whitespace-nowrap text-muted">{{ $state ?? 'Yakında' }}</span>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </div>

    {{-- Delete confirmation --}}
    <x-modal title="Siteyi sil" show="deleting" size="max-w-md">
        <form wire:submit="delete" class="p-5 text-[13.5px]">
            <p class="font-semibold"><span class="font-mono">{{ $site->domain }}</span> silinsin mi?</p>
            <p class="mt-1 text-[13px] text-muted">Nginx ve PHP-FPM ayarları ile <span class="font-mono">{{ $site->document_root ? dirname($site->document_root) : 'site klasörü' }}</span> içindeki tüm dosyalar silinir. Bu işlem geri alınamaz.</p>
            @if ($otherSites === 0)
                <label class="mt-3 flex items-center gap-2 text-[13px]">
                    <input type="checkbox" wire:model="removeUser" class="size-4 accent-accent">
                    <span>Sistem kullanıcısını da sil (<span class="font-mono">{{ $site->systemUser->name }}</span>)</span>
                </label>
            @endif
            @if ($zone)
                <label class="mt-3 flex items-center gap-2 text-[13px]">
                    <input type="checkbox" wire:model="removeZone" class="size-4 accent-accent">
                    <span>DNS bölgesini de sil ({{ $zone->records_count }} kayıt)</span>
                </label>
            @endif
            <label for="confirmDomain" class="mt-4 block text-[13px]">Onaylamak için alan adını yazın</label>
            <input id="confirmDomain" wire:model.live="confirmDomain" autocomplete="off" spellcheck="false" placeholder="{{ $site->domain }}"
                   class="mt-1.5 h-9 w-full rounded-md border border-line bg-surface px-3 font-mono text-[13px] outline-none focus:border-crit">
            @error('confirmDomain') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deleting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" @disabled($confirmDomain !== $site->domain) class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit disabled:cursor-not-allowed disabled:opacity-40">Siteyi sil</button>
            </div>
        </form>
    </x-modal>
</div>
