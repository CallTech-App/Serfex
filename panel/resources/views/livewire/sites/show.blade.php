@php
    $ssl = $site->sslState();
    $user = auth()->user();
    $owner = $site->customer();
    // Tools the customer's package does not have.
    $missing = fn (string $feature) => $user->allows($feature) ? null : __('Paketinizde yok');
    $certificate = $site->certificate;
    $laravel = $site->laravelApp;
    $lastBackup = \App\Models\BackupItem::with('backup')->where('site_id', $site->id)->where('status', 'succeeded')
        ->whereHas('backup', fn ($q) => $q->usable())->latest('id')->first();
    $monthStats = app(\App\Services\Stats\SiteStats::class)->totals([$site->id], 'this-month');
    $diskTotal = app(\App\Services\Stats\DiskBreakdown::class)->site($site)['total'];
    $backupText = $lastBackup ? \App\Support\When::dateTime($lastBackup->backup->created_at) : __('Henüz yok');
    $wordpress = $site->wordpress;
    $cache = \App\Services\Sites\SiteCache::settings($site);
    $copyCount = $site->copies()->count();
    $wordpressTool = ['blocks', 'WordPress', match (true) {
        $wordpress === null => __('Kurulu değil'),
        $wordpress->status === \App\Models\WordPressInstall::INSTALLING => __('Kuruluyor'),
        $wordpress->status === \App\Models\WordPressInstall::FAILED => __('Kurulum başarısız'),
        $wordpress->update_count > 0 => trans_choice(':count güncelleme', $wordpress->update_count),
        default => 'WordPress '.($wordpress->version ?? ''),
    }, route('sites.wordpress', $site)];
    $laravelTool = $missing('laravel') ? ['layers', 'Laravel', $missing('laravel')] : ($laravel
        ? ['layers', 'Laravel', $laravel->status === \App\Enums\LaravelAppStatus::Active ? 'Laravel '.($laravel->laravel_version ? \Illuminate\Support\Str::of($laravel->laravel_version)->explode('.')->take(2)->implode('.') : '') : $laravel->status->label(), route('laravel.show', $laravel)]
        : ['layers', 'Laravel', __('Kurulu değil'), route('laravel.index', ['site' => $site->id])]);
    // Site tools, grouped by job. Only the ones with a link work in this
    // version; the rest show what is coming. [icon, name, status, link, opens in a new tab]
    $groups = [
        [__('Dosya ve veri'), 'folder', [
            ['folder', __('Dosya Yöneticisi'), '', route('sites.files', $site)],
            ['hard-drive-upload', __('FTP hesapları'), $ftpAccounts ? __(':ftp_accounts hesap', ['ftp_accounts' => $ftpAccounts]) : __('Yok'), route('sites.ftp', $site)],
            ['database', __('Veritabanları'), $databases ? __(':databases veritabanı', ['databases' => $databases]) : __('Yok'), route('databases.index', ['site' => $site->id])],
            ['chart-column', __('İstatistikler'), $monthStats['visitors'] ? __('Bu ay :visitors ziyaretçi', ['visitors' => \App\Support\Num::format($monthStats['visitors'], 0)]) : __('Bu ay ziyaret yok'), route('sites.stats', $site)],
            ['hard-drive', __('Disk kullanımı'), $site->disk_bytes === null ? __('Ölçülmedi') : \App\Support\Bytes::format($diskTotal), route('sites.disk', $site)],
            $missing('backups') ? ['archive-restore', __('Yedekler'), $missing('backups')] : ['archive-restore', __('Yedekler'), $lastBackup ? __('Son: :text', ['text' => $backupText]) : __('Henüz yok'), route('backups.index', ['site' => $site->id])],
            ['key-round', __('Bağlantı bilgileri'), __('SFTP, veritabanı, SSH'), route('sites.connection', $site)],
            ['copy', __('Siteyi kopyala'), $site->copy_of_id ? __('Bir sitenin kopyası') : ($copyCount ? trans_choice(':count kopya', $copyCount) : __('Kopya yok')), route('sites.copy', $site)],
        ]],
        [__('Geliştirme'), 'code', [
            ['code', __('PHP ve web sunucusu'), 'PHP '.$site->php_version.' · '.($site->web_server === \App\Models\Site::APACHE ? 'Apache' : 'Nginx'), route('sites.php', $site)],
            ['gauge', __('Önbellek ve hız'), $cache['page'] ? __('Sayfa önbelleği açık') : ($cache['browser'] || $cache['gzip'] ? __('Tarayıcı önbelleği') : __('Kapalı')), route('sites.cache', $site)],
            ...($missing('shell') ? [
                ['package', 'Composer', $missing('shell')],
                ['hexagon', 'Node.js', $missing('shell')],
            ] : [
                ['package', 'Composer', __('Paketler ve komutlar'), route('sites.packages', ['site' => $site, 'tool' => 'composer'])],
                ['hexagon', 'Node.js', $site->node_version ? 'Node.js '.$site->node_version : __('Sunucunun varsayılanı'), route('sites.packages', ['site' => $site, 'tool' => 'node'])],
            ]),
            $laravelTool,
            $missing('ssh') ? ['square-terminal', __('SSH erişimi'), $missing('ssh')] : ['square-terminal', __('SSH erişimi'), __(\App\Services\Files\SshAccess::LABELS[$site->systemUser->ssh_access] ?? 'Kapalı'), route('sites.ssh', $site)],
            ['terminal', __('SSH terminali'), null],
            ['clock', __('Zamanlanmış görevler'), $cronJobs ? __(':cron_jobs görev', ['cron_jobs' => $cronJobs]) : __('Yok'), route('sites.cron', $site)],
            $laravel ? ['git-branch', __('Git dağıtımı'), $laravel->repository ? $laravel->repositoryName() : __('Bağlı değil'), route('laravel.show', [$laravel, 'section' => 'deployments'])]
                : ['git-branch', __('Git dağıtımı'), $site->gitRepository ? $site->gitRepository->repositoryName() : __('Bağlı değil'), route('sites.git', $site)],
            ['file-text', __('Günlükler'), __('Erişim ve hata'), route('sites.logs', $site)],
            ['file-warning', __('Hata sayfaları'), $site->error_pages ? __(':error_pages özel sayfa', ['error_pages' => count($site->error_pages)]) : __('Varsayılan'), route('sites.errors', $site)],
            ...($user->isFullAdmin() ? [['file-cog', __('Web sunucusu yönergeleri'), filled($site->nginx_directives) || filled($site->apache_directives) ? __('Özel satırlar var') : __('Yok'), route('sites.directives', $site)]] : []),
        ]],
        [__('Uygulamalar'), 'blocks', [
            $wordpressTool,
            $laravelTool,
            ...($user->is_admin ? [['download', __('Başka sunucudan taşı'), __('cPanel yedeği'), route('sites.migrate')]] : []),
        ]],
        [__('Alan adı ve DNS'), 'network', [
            $missing('dns') ? ['network', __('DNS kayıtları'), $missing('dns')] : ['network', __('DNS kayıtları'), $zone ? __(':records_count kayıt', ['records_count' => $zone->records_count]) : null, $zone ? route('dns.show', $zone) : null],
            ['globe', __('Alt alan adları'), $subdomainCount ? __(':subdomain_count alt alan adı', ['subdomain_count' => $subdomainCount]) : __('Yok'), route('sites.domains', $site)],
            ['link', __('Ek alan adları'), $site->aliases ? trans_choice(':count ad', count($site->aliases)) : __('Yok'), route('sites.domains', ['site' => $site, 'tab' => 'aliases'])],
            ['signpost', __('Tercih edilen adres'), $site->wwwPair() === null ? __('www. yok') : ($site->preferredName() ?? __('İkisi de açılıyor')), route('sites.domains', ['site' => $site, 'tab' => 'aliases'])],
            ['corner-up-right', __('Yönlendirmeler'), $redirectCount ? __(':redirect_count yönlendirme', ['redirect_count' => $redirectCount]) : __('Yok'), route('sites.domains', ['site' => $site, 'tab' => 'redirects'])],
        ]],
        [__('E-posta'), 'mail', $missing('mail') ? [
            ['mail', __('Posta kutuları'), $missing('mail')],
            ['forward', __('Yönlendiriciler'), $missing('mail')],
            ['inbox', 'Webmail', $missing('mail')],
        ] : ($mail && $mail->status !== \App\Enums\MailDomainStatus::Deleting ? [
            ['mail', __('Posta kutuları'), trans_choice(':count kutu', $mail->mailboxes_count), route('mail.show', $mail)],
            ['forward', __('Yönlendiriciler'), $mail->forwarders_count ?: __('Yok'), route('mail.show', [$mail, 'tab' => 'yonlendiriciler'])],
            ['inbox', 'Webmail', $webmail ? $webmailApp : __('Kapalı'), $webmail, true],
        ] : [
            ['mail', __('Posta kutuları'), __('E-posta kapalı'), route('mail.index', ['enable' => $site->domain])],
            ['forward', __('Yönlendiriciler'), __('E-posta kapalı'), route('mail.index', ['enable' => $site->domain])],
            ['inbox', 'Webmail', __('E-posta kapalı'), route('mail.index', ['enable' => $site->domain])],
        ])],
        [__('Güvenlik'), 'shield', [
            ['shield-check', __('SSL sertifikası'), $ssl->label(), route('sites.ssl', $site)],
            ...($missing('security') ? [
                ['shield', 'WAF', $missing('security')],
                ['scan-search', __('Güvenlik taraması'), $missing('security')],
                ['folder-lock', __('Parola korumalı klasörler'), $missing('security')],
            ] : [
                ['shield', 'WAF', ! $wafInstalled ? __('Kurulu değil') : ($site->waf_mode ? \App\Services\Security\Waf::MODES[$site->waf_mode] : __('Sunucu ayarı')), route('sites.waf', $site)],
                ['scan-search', __('Güvenlik taraması'), $openFindings ? __(':open_findings açık bulgu', ['open_findings' => $openFindings]) : ($scanned ? __('Sorun yok') : __('Taranmadı')), route('sites.scan', $site)],
                ['folder-lock', __('Parola korumalı klasörler'), $protectedDirs ? __(':protected_dirs klasör', ['protected_dirs' => $protectedDirs]) : __('Yok'), route('sites.protect', $site)],
            ]),
        ]],
    ];
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on a site. --}}
    @if ($site->status->isBusy())
        <div wire:poll.2s="refreshStatus" hidden></div>
    @elseif ($ssl->isBusy())
        <div wire:poll.3s hidden></div>
    @endif

    {{-- Title --}}
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="font-mono text-fg">{{ $site->domain }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $site->domain }}</h1>
                <x-status-badge :status="$site->status" />
                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Diğer işlemler') }}"><x-icon name="ellipsis-vertical" /></button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full left-0 z-20 mt-1 w-52 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                        <button type="button" wire:click="openDelete" @click="open = false" @disabled($site->status->isBusy())
                                class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2 disabled:opacity-40">{{ __('Siteyi sil') }}</button>
                    </div>
                </div>
            </div>
            @if ($site->status === \App\Enums\SiteStatus::Active)
                <a href="{{ $site->hasHttps() ? 'https' : 'http' }}://{{ $site->primaryHost() }}" target="_blank" rel="noopener" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                    <x-icon name="external-link" />{{ __('Siteyi aç') }}
                </a>
            @endif
        </div>
    </div>

    @if ($site->isSuspended())
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-warn"></span>
            <x-icon name="circle-pause" class="text-warn" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Site askıda: ziyaretçiler "hizmet dışı" sayfasını görüyor.') }}</p>
                <p class="mt-0.5 text-[13px] text-muted">{{ $user->is_admin ? __('Müşterinin hesabı askıya alındı') : __('Hesabınız askıya alındı') }}{{ $owner->suspend_reason ? ': '.$owner->suspend_reason : '' }}. {{ __('Dosyalar, posta ve veritabanları korunuyor.') }}</p>
            </div>
            @if ($user->is_admin)
                <a href="{{ route('customers.show', $owner) }}" wire:navigate class="flex h-8 items-center rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">{{ __('Müşteriye git') }}</a>
            @endif
        </div>
    @endif

    @if ($backingUp)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
            <x-icon name="archive" class="text-info" />
            <p class="min-w-0 flex-1">{{ __('Silmeden önce sitenin yedeği alınıyor. Yedek bitince site silinir.') }}</p>
        </div>
    @endif

    @if ($site->status === \App\Enums\SiteStatus::Failed)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            @if ($site->failed_action === \App\Models\Site::FAILED_DELETE)
                <div class="min-w-0 flex-1 basis-56">
                    <p class="font-medium">{{ __('Site silinemedi. Silmeyi yeniden başlatabilirsiniz.') }}</p>
                    <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $site->last_error }}</p>
                </div>
                <button type="button" wire:click="openDelete" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] text-crit hover:bg-surface-2">
                    <x-icon name="refresh-cw" class="size-3.5" />{{ __('Silmeyi yeniden dene') }}
                </button>
            @else
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ __('Sunucuda son işlem tamamlanamadı. Önceki ayarlar geri yüklendi.') }}</p>
                    <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $site->last_error }}</p>
                </div>
                <button type="button" wire:click="retry" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                    <x-icon name="refresh-cw" class="size-3.5" />{{ __('Tekrar dene') }}
                </button>
            @endif
        </div>
    @endif

    {{-- Status strip --}}
    <section class="grid grid-cols-1 rounded-xl border border-line bg-surface text-[13px] sm:grid-cols-2 xl:grid-cols-5 [&>*]:border-line [&>*]:p-4 max-sm:[&>*:not(:last-child)]:border-b sm:max-xl:[&>*:nth-child(-n+4)]:border-b sm:max-xl:[&>*:nth-child(odd):not(:last-child)]:border-r xl:[&>*:not(:last-child)]:border-r">
        <a href="{{ route('sites.php', $site) }}" wire:navigate class="block hover:bg-surface-2/60">
            <p class="text-2xs text-muted">{{ __('Web sunucusu') }}</p>
            <p class="mt-1 font-medium">{{ $site->web_server === \App\Models\Site::APACHE ? 'Nginx + Apache' : 'Nginx' }} · PHP {{ $site->php_version }}</p>
            <span class="text-2xs text-muted">{{ __('PHP-FPM, siteye özel havuz') }}</span>
        </a>
        <a href="{{ route('sites.ssl', $site) }}" wire:navigate class="block hover:bg-surface-2/60">
            <p class="text-2xs text-muted">SSL</p>
            <p class="mt-1"><x-status-badge :status="$ssl" /></p>
            <span class="mt-1 block text-2xs text-muted">
                @if ($certificate?->isInstalled())
                    {{ __(':certificate · :not_after tarihine kadar', ['certificate' => $certificate->issuerLabel(), 'not_after' => \App\Support\When::date($certificate->not_after)]) }}
                @elseif ($site->pendingCertificate()?->status === \App\Enums\CertificateStatus::WaitingDns)
                    {{ __('DNS henüz bu sunucuya işaret etmiyor') }}
                @else
                    {{ __('Site şimdilik yalnızca http ile açılır') }}
                @endif
            </span>
        </a>
        <div>
            <p class="text-2xs text-muted">{{ __('Sistem kullanıcısı') }}</p>
            <p class="mt-1 font-mono text-[12.5px]">{{ $site->systemUser->name }}</p>
            <span class="text-2xs text-muted">{{ $otherSites ? __('Bu kullanıcının :other_sites sitesi daha var', ['other_sites' => $otherSites]) : __('Yalnızca bu siteye ait') }}</span>
            @if ($user->is_admin && $owner)
                <a href="{{ route('customers.show', $owner) }}" wire:navigate class="mt-0.5 block truncate text-2xs text-accent hover:underline">{{ __('Müşteri: :owner', ['owner' => $owner->displayName()]) }}</a>
            @endif
        </div>
        <div class="min-w-0">
            <p class="text-2xs text-muted">{{ __('Belge kökü') }}</p>
            <p class="mt-1 truncate font-mono text-[12.5px]" title="{{ $site->document_root }}">{{ $site->document_root ?? '—' }}</p>
            <span class="text-2xs text-muted">{{ $site->provisioned_at ? __('Oluşturuldu: :provisioned_at', ['provisioned_at' => \App\Support\When::dateTime($site->provisioned_at)]) : __('Henüz hazırlanmadı') }}</span>
        </div>
        @php $backupCell = $user->allows('backups') ? route('backups.index', ['site' => $site->id]) : null; @endphp
        <{{ $backupCell ? 'a' : 'div' }} @if ($backupCell) href="{{ $backupCell }}" wire:navigate @endif class="block hover:bg-surface-2/60 sm:col-span-2 xl:col-span-1">
            <p class="text-2xs text-muted">{{ __('Son yedek') }}</p>
            <p class="num mt-1 font-medium">{{ $backupText }}</p>
            <span class="text-2xs text-muted">{{ $lastBackup ? ($lastBackup->backup->scope === 'server' ? __('Tüm sunucu yedeğiyle') : __('Site yedeği')).' · '.\App\Support\Bytes::format($lastBackup->size) : ($owner?->package && ! $owner->package->allows('backups') ? __('Paketi yedekleme içermiyor') : __('Zamanlanmış yedeğe girer')) }}</span>
        </{{ $backupCell ? 'a' : 'div' }}>
    </section>

    {{-- Tools --}}
    <div x-data="{ q: '' }" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-[15px] font-semibold">{{ __('Site araçları') }}</h2>
            <label class="relative w-full sm:w-64">
                <span class="sr-only">{{ __('Araç ara') }}</span>
                <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                <input x-model="q" type="search" placeholder="{{ __('Araç ara') }}" class="h-9 w-full rounded-md border border-line bg-surface pr-3 pl-9 text-[13.5px] outline-none focus:border-accent">
            </label>
        </div>
        <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($groups as [$group, $groupIcon, $tools])
                <section class="rounded-xl border border-line bg-surface"
                         x-show="!q || @js(mb_strtolower($group.' '.collect($tools)->pluck(1)->join(' '))).includes(q.toLocaleLowerCase('tr'))">
                    <h3 class="flex h-11 items-center gap-2 border-b border-line px-4 text-[13.5px] font-semibold"><x-icon :name="$groupIcon" class="text-accent" />{{ $group }}</h3>
                    <ul class="p-1.5">
                        @foreach ($tools as $tool)
                            @php [$icon, $name, $state, $link, $external] = $tool + [3 => null, 4 => false]; @endphp
                            <li x-show="!q || @js(mb_strtolower($group.' '.$name)).includes(q.toLocaleLowerCase('tr'))">
                                @if ($link)
                                    <a href="{{ $link }}" @if ($external) target="_blank" rel="noopener" @else wire:navigate @endif class="flex h-10 items-center gap-3 rounded-md px-2.5 text-[13.5px] hover:bg-surface-2">
                                        <x-icon :name="$icon" class="text-muted" />
                                        <span class="truncate">{{ $name }}</span>
                                        <span class="ml-auto truncate text-2xs whitespace-nowrap text-muted">{{ $state }}</span>
                                        <x-icon :name="$external ? 'external-link' : 'chevron-right'" class="size-3.5 text-muted" />
                                    </a>
                                @else
                                    <div class="flex h-10 items-center gap-3 rounded-md px-2.5 text-[13.5px]">
                                        <x-icon :name="$icon" class="text-muted" />
                                        <span class="truncate {{ $state === null ? 'text-muted' : '' }}">{{ $name }}</span>
                                        <span class="ml-auto truncate text-2xs whitespace-nowrap text-muted">{{ $state ?? __('Yakında') }}</span>
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
    <x-modal :title="__('Siteyi sil')" show="deleting" size="max-w-md">
        <form wire:submit="delete" class="p-5 text-[13.5px]">
            <p class="font-semibold"><x-t text=":domain silinsin mi?"><x-slot:domain><span class="font-mono">{{ $site->domain }}</span></x-slot:domain></x-t></p>
            <p class="mt-1 text-[13px] text-muted"><x-t text="Nginx ve PHP-FPM ayarları ile :document_root içindeki tüm dosyalar silinir."><x-slot:document_root><span class="font-mono">{{ $site->document_root ? dirname($site->document_root) : __('site klasörü') }}</span></x-slot:document_root></x-t></p>
            @if ($noRoom !== null)
                <p class="mt-3 rounded-md bg-warn-soft px-3 py-2 text-[13px] text-warn">{{ $noRoom }} {{ __('Site yedek alınmadan silinir ve geri getirilemez.') }}</p>
            @elseif (auth()->user()->is_admin)
                <label class="mt-3 flex items-start gap-2 text-[13px]">
                    <input type="checkbox" wire:model.live="backup" class="mt-0.5 size-4 shrink-0 accent-accent">
                    <span>{{ __('Silmeden önce yedek al') }}<span class="block text-[12.5px] text-muted">{{ $backup ? __('Dosyalar, veritabanları, e-posta ve DNS :days gün Yedekler\'de saklanır; oradan geri yüklenebilir.', ['days' => $deleteDays]) : __('Yedek alınmazsa site geri getirilemez.') }}</span></span>
                </label>
            @else
                <p class="mt-3 text-[13px] text-muted">{{ __('Silmeden önce sitenin yedeği alınır ve :days gün Yedekler\'de saklanır.', ['days' => $deleteDays]) }}</p>
            @endif
            @if ($otherSites === 0)
                <label class="mt-3 flex items-center gap-2 text-[13px]">
                    <input type="checkbox" wire:model="removeUser" class="size-4 accent-accent">
                    <span>{{ __('Sistem kullanıcısını da sil') }} (<span class="font-mono">{{ $site->systemUser->name }}</span>)</span>
                </label>
            @endif
            @if ($zone)
                <label class="mt-3 flex items-center gap-2 text-[13px]">
                    <input type="checkbox" wire:model="removeZone" class="size-4 accent-accent">
                    <span>{{ __('DNS bölgesini de sil (:records_count kayıt)', ['records_count' => $zone->records_count]) }}</span>
                </label>
            @endif
            @if ($mail && $mail->status !== \App\Enums\MailDomainStatus::Deleting)
                <label class="mt-3 flex items-center gap-2 text-[13px]">
                    <input type="checkbox" wire:model="removeMail" class="size-4 accent-accent">
                    <span>{{ __('E-postayı da kapat (:mailboxes_count posta kutusu ve içindeki postalar silinir)', ['mailboxes_count' => $mail->mailboxes_count]) }}</span>
                </label>
            @endif
            @if ($databases > 0)
                <label class="mt-3 flex items-center gap-2 text-[13px]">
                    <input type="checkbox" wire:model="removeDatabases" class="size-4 accent-accent">
                    <span>{{ __('Veritabanlarını da sil (:databases veritabanı; önce yedek alınır)', ['databases' => $databases]) }}</span>
                </label>
            @endif
            <label for="confirmDomain" class="mt-4 block text-[13px]">{{ __('Onaylamak için alan adını yazın') }}</label>
            <input id="confirmDomain" wire:model.live="confirmDomain" autocomplete="off" spellcheck="false" placeholder="{{ $site->domain }}"
                   class="mt-1.5 h-9 w-full rounded-md border border-line bg-surface px-3 font-mono text-[13px] outline-none focus:border-crit">
            @error('confirmDomain') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deleting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" @disabled($confirmDomain !== $site->domain) class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit disabled:cursor-not-allowed disabled:opacity-40">{{ __('Siteyi sil') }}</button>
            </div>
        </form>
    </x-modal>
</div>
