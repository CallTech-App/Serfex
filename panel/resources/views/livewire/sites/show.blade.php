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
    $httpOnly = $site->status === \App\Enums\SiteStatus::Active && ! $site->hasHttps() && ! $ssl->isBusy();
    $mailOn = $mail && $mail->status !== \App\Enums\MailDomainStatus::Deleting;
    // The document root from the site's home folder on, the whole path on hover.
    $home = $site->systemUser->home;
    $docRoot = $site->document_root === null ? null
        : ($home && str_starts_with($site->document_root, rtrim($home, '/').'/') ? '~/'.substr($site->document_root, strlen(rtrim($home, '/')) + 1) : $site->document_root);
    $wordpressTool = ['blocks', 'WordPress', match (true) {
        $wordpress === null => __('Kurulu değil'),
        $wordpress->status === \App\Models\WordPressInstall::INSTALLING => __('Kuruluyor'),
        $wordpress->status === \App\Models\WordPressInstall::FAILED => __('Kurulum başarısız'),
        $wordpress->update_count > 0 => trans_choice(':count güncelleme', $wordpress->update_count),
        default => 'WordPress '.($wordpress->version ?? ''),
    }, route('sites.wordpress', $site), match (true) {
        $wordpress === null => 'muted',
        $wordpress->status === \App\Models\WordPressInstall::FAILED => 'crit',
        $wordpress->update_count > 0 => 'warn',
        default => null,
    }];
    $laravelTool = $missing('laravel') ? ['layers', 'Laravel', $missing('laravel'), null, 'muted'] : ($laravel
        ? ['layers', 'Laravel', $laravel->status === \App\Enums\LaravelAppStatus::Active ? 'Laravel '.($laravel->laravel_version ? \Illuminate\Support\Str::of($laravel->laravel_version)->explode('.')->take(2)->implode('.') : '') : $laravel->status->label(), route('laravel.show', $laravel)]
        : ['layers', 'Laravel', __('Kurulu değil'), route('laravel.index', ['site' => $site->id]), 'muted']);
    // Site tools, grouped by job: [key, name, icon, section page, tools].
    // A tool is [icon, name, status, link, tone, opens in a new tab, hint]; tone
    // colours the status (warn, crit, ok), "muted" marks nothing set up yet
    // and "off" a tool that needs something turned on first.
    $groups = [
        ['files', __('Dosyalar ve veritabanları'), 'folder', route('sites.files', $site), [
            ['folder', __('Dosya Yöneticisi'), $docRoot ?? __('Dosyalara göz atın'), route('sites.files', $site), null, false, $site->document_root],
            ['hard-drive-upload', __('FTP hesapları'), $ftpAccounts ? __(':ftp_accounts hesap', ['ftp_accounts' => $ftpAccounts]) : __('Yok'), route('sites.ftp', $site), $ftpAccounts ? null : 'muted'],
            ['database', __('Veritabanları'), $databases ? __(':databases veritabanı', ['databases' => $databases]) : __('Yok'), route('sites.databases', $site), $databases ? null : 'muted'],
            ['key-round', __('Bağlantı bilgileri'), __('SFTP, veritabanı, SSH'), route('sites.connection', $site)],
            ['hard-drive', __('Disk kullanımı'), $site->disk_bytes === null ? __('Ölçülmedi') : \App\Support\Bytes::format($diskTotal), route('sites.disk', $site)],
            ['copy', __('Siteyi kopyala'), $site->copy_of_id ? __('Bir sitenin kopyası') : ($copyCount ? trans_choice(':count kopya', $copyCount) : __('Kopya yok')), route('sites.copy', $site), $site->copy_of_id || $copyCount ? null : 'muted'],
        ]],
        ['mail', __('E-posta'), 'mail', $missing('mail') ? null : route('sites.mail', $site), $missing('mail') ? [
            ['mail', __('Posta kutuları'), $missing('mail'), null, 'muted'],
            ['forward', __('Yönlendiriciler'), $missing('mail'), null, 'muted'],
            ['inbox', 'Webmail', $missing('mail'), null, 'muted'],
        ] : ($mailOn ? [
            ['mail', __('Posta kutuları'), trans_choice(':count kutu', $mail->mailboxes_count), route('sites.mail', $site), $mail->status === \App\Enums\MailDomainStatus::Failed ? 'crit' : null],
            ['forward', __('Yönlendiriciler'), $mail->forwarders_count ?: __('Yok'), route('sites.mail', ['site' => $site, 'tab' => 'forwarders']), $mail->forwarders_count ? null : 'muted'],
            ['inbox', 'Webmail', $webmail ? $webmailApp : __('Kapalı'), $webmail, $webmail ? null : 'muted', true],
        ] : [
            ['mail', __('Posta kutuları'), __('E-posta kapalı'), route('sites.mail', $site), 'off'],
            ['forward', __('Yönlendiriciler'), __('E-posta kapalı'), route('sites.mail', $site), 'off'],
            ['inbox', 'Webmail', __('E-posta kapalı'), route('sites.mail', $site), 'off'],
        ])],
        ['domains', __('Alan adları ve DNS'), 'network', $missing('dns') ? route('sites.domains', $site) : route('sites.dns', $site), [
            $missing('dns') ? ['network', __('DNS kayıtları'), $missing('dns'), null, 'muted'] : ($recordsZone
                ? ['network', __('DNS kayıtları'), __(':records_count kayıt', ['records_count' => $recordsZone->records_count]), route('sites.dns', $site)]
                : ['network', __('DNS kayıtları'), __('Bölge bu sunucuda değil'), route('sites.dns', $site), 'muted']),
            ['globe', __('Alt alan adları'), $subdomainCount ? __(':subdomain_count alt alan adı', ['subdomain_count' => $subdomainCount]) : __('Yok'), route('sites.domains', $site), $subdomainCount ? null : 'muted'],
            ['link', __('Ek alan adları'), $site->aliases ? trans_choice(':count ad', count($site->aliases)) : __('Yok'), route('sites.domains', ['site' => $site, 'tab' => 'aliases']), $site->aliases ? null : 'muted'],
            ['signpost', __('Tercih edilen adres'), $site->wwwPair() === null ? __('www. yok') : ($site->preferredName() ?? __('İkisi de açılıyor')), route('sites.domains', ['site' => $site, 'tab' => 'aliases'])],
            ['corner-up-right', __('Yönlendirmeler'), $redirectCount ? __(':redirect_count yönlendirme', ['redirect_count' => $redirectCount]) : __('Yok'), route('sites.domains', ['site' => $site, 'tab' => 'redirects']), $redirectCount ? null : 'muted'],
        ]],
        ['dev', __('Web sunucusu ve geliştirme'), 'code', null, [
            ['code', __('PHP ve web sunucusu'), 'PHP '.$site->php_version.' · '.($site->web_server === \App\Models\Site::APACHE ? 'Apache' : 'Nginx'), route('sites.php', $site)],
            ['gauge', __('Önbellek ve hız'), $cache['page'] ? __('Sayfa önbelleği açık') : ($cache['browser'] || $cache['gzip'] ? __('Tarayıcı önbelleği') : __('Kapalı')), route('sites.cache', $site), $cache['page'] || $cache['browser'] || $cache['gzip'] ? null : 'muted'],
            ...($missing('shell') ? [
                ['package', 'Composer', $missing('shell'), null, 'muted'],
                ['hexagon', 'Node.js', $missing('shell'), null, 'muted'],
            ] : [
                ['package', 'Composer', __('Paketler ve komutlar'), route('sites.packages', ['site' => $site, 'tool' => 'composer'])],
                ['hexagon', 'Node.js', $site->node_version ? 'Node.js '.$site->node_version : __('Sunucunun varsayılanı'), route('sites.packages', ['site' => $site, 'tool' => 'node'])],
            ]),
            $missing('ssh') ? ['square-terminal', __('SSH erişimi'), $missing('ssh'), null, 'muted'] : ['square-terminal', __('SSH erişimi'), __(\App\Services\Files\SshAccess::LABELS[$site->systemUser->ssh_access] ?? 'Kapalı'), route('sites.ssh', $site), in_array($site->systemUser->ssh_access, [null, \App\Services\Files\SshAccess::OFF], true) ? 'muted' : null],
            ['clock', __('Zamanlanmış görevler'), $cronJobs ? __(':cron_jobs görev', ['cron_jobs' => $cronJobs]) : __('Yok'), route('sites.cron', $site), $cronJobs ? null : 'muted'],
            $laravel ? ['git-branch', __('Git dağıtımı'), $laravel->repository ? $laravel->repositoryName() : __('Bağlı değil'), route('laravel.show', [$laravel, 'section' => 'deployments']), $laravel->repository ? null : 'muted']
                : ['git-branch', __('Git dağıtımı'), $site->gitRepository ? $site->gitRepository->repositoryName() : __('Bağlı değil'), route('sites.git', $site), $site->gitRepository ? null : 'muted'],
            ['file-warning', __('Hata sayfaları'), $site->error_pages ? __(':error_pages özel sayfa', ['error_pages' => count($site->error_pages)]) : __('Varsayılan'), route('sites.errors', $site)],
            ...($user->isFullAdmin() ? [['file-cog', __('Web sunucusu yönergeleri'), filled($site->nginx_directives) || filled($site->apache_directives) ? __('Özel satırlar var') : __('Yok'), route('sites.directives', $site), filled($site->nginx_directives) || filled($site->apache_directives) ? null : 'muted']] : []),
            ...($user->isFullAdmin() ? [$missing('ssh') ? ['terminal', __('SSH terminali'), $missing('ssh'), null, 'muted']
                : ($site->systemUser->ssh_access === \App\Services\Files\SshAccess::SHELL
                    ? ['terminal', __('SSH terminali'), __('Tarayıcıda kabuk'), route('sites.terminal', $site)]
                    : ['terminal', __('SSH terminali'), __('Kabuk erişimi kapalı'), route('sites.terminal', $site), 'muted'])] : []),
        ]],
        ['apps', __('Uygulamalar'), 'blocks', null, [
            $wordpressTool,
            $laravelTool,
            ...($user->is_admin ? [['download', __('Başka sunucudan taşı'), __('cPanel veya Plesk'), route('sites.migrate')]] : []),
        ]],
        ['security', __('SSL ve güvenlik'), 'shield-check', route('sites.ssl', $site), [
            ['shield-check', __('SSL sertifikası'), $ssl->label(), route('sites.ssl', $site), $ssl->needsAttention() ? $ssl->tone() : ($httpOnly ? 'warn' : ($site->hasHttps() ? 'ok' : null))],
            ...($missing('security') ? [
                ['shield', 'WAF', $missing('security'), null, 'muted'],
                ['scan-search', __('Güvenlik taraması'), $missing('security'), null, 'muted'],
                ['folder-lock', __('Parola korumalı klasörler'), $missing('security'), null, 'muted'],
            ] : [
                ['shield', 'WAF', ! $wafInstalled ? __('Kurulu değil') : ($site->waf_mode ? \App\Services\Security\Waf::MODES[$site->waf_mode] : __('Sunucu ayarı')), route('sites.waf', $site), $wafInstalled ? null : 'muted'],
                ['scan-search', __('Güvenlik taraması'), $openFindings ? __(':open_findings açık bulgu', ['open_findings' => $openFindings]) : ($scanned ? __('Sorun yok') : __('Taranmadı')), route('sites.scan', $site), $openFindings ? 'warn' : ($scanned ? 'ok' : 'muted')],
                ['folder-lock', __('Parola korumalı klasörler'), $protectedDirs ? __(':protected_dirs klasör', ['protected_dirs' => $protectedDirs]) : __('Yok'), route('sites.protect', $site), $protectedDirs ? null : 'muted'],
            ]),
        ]],
        ['backups', __('Yedekler, günlükler ve istatistik'), 'archive', null, [
            $missing('backups') ? ['archive-restore', __('Yedekler'), $missing('backups'), null, 'muted'] : ['archive-restore', __('Yedekler'), $lastBackup ? __('Son: :text', ['text' => $backupText]) : __('Henüz yok'), route('sites.backups', $site), $lastBackup ? 'ok' : 'muted'],
            ['file-text', __('Günlükler'), __('Erişim ve hata'), route('sites.logs', $site)],
            ['chart-column', __('İstatistikler'), $monthStats['visitors'] ? __('Bu ay :visitors ziyaretçi', ['visitors' => \App\Support\Num::format($monthStats['visitors'], 0)]) : __('Bu ay ziyaret yok'), route('sites.stats', $site), $monthStats['visitors'] ? null : 'muted'],
        ]],
    ];
    $dot = ['ok' => 'bg-ok', 'warn' => 'bg-warn', 'crit' => 'bg-crit'];
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on a site. --}}
    @if ($site->status->isBusy())
        <div wire:poll.2s="refreshStatus" hidden></div>
    @elseif ($ssl->isBusy())
        <div wire:poll.3s hidden></div>
    @endif

    <x-site-header :site="$site" active="overview">
        <x-slot:menu>
            <div class="relative" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="grid size-9 place-items-center rounded-md border border-line bg-surface text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Diğer işlemler') }}"><x-icon name="ellipsis-vertical" /></button>
                <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full right-0 z-20 mt-1 w-52 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                    <button type="button" wire:click="openRename" @click="open = false" @disabled($site->status->isBusy())
                            class="w-full px-3 py-2 text-left hover:bg-surface-2 disabled:opacity-40">{{ __('Alan adını değiştir') }}</button>
                    <button type="button" wire:click="openDelete" @click="open = false" @disabled($site->status->isBusy())
                            class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2 disabled:opacity-40">{{ __('Siteyi sil') }}</button>
                </div>
            </div>
        </x-slot:menu>
    </x-site-header>

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

    {{-- Alan adını değiştir: in progress, done (with the way back) or failed --}}
    @if ($rename && ($rename->isRunning() || $renameUndo || ($rename->status === 'failed' && $rename->finished_at?->gt(now()->subDay()))))
        @php
            $renameTone = $rename->isRunning() ? 'info' : ($rename->status === 'failed' ? 'crit' : 'ok');
            $renameAttention = collect($rename->notes ?? [])->map(fn ($n) => \App\Services\Sites\SiteRenames::noteText($n))->filter(fn ($t) => $t[1])->count();
        @endphp
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-{{ $renameTone }}"></span>
            <x-icon :name="$rename->isRunning() ? 'loader-circle' : ($rename->status === 'failed' ? 'triangle-alert' : 'circle-check')" class="text-{{ $renameTone }} {{ $rename->isRunning() ? 'animate-spin' : '' }}" />
            <div class="min-w-0 flex-1 basis-60">
                <p class="font-medium">
                    @if ($rename->isRunning())
                        <x-t text="Alan adı değiştiriliyor: :from → :to"><x-slot:from><span class="font-mono">{{ $rename->from_domain }}</span></x-slot:from><x-slot:to><span class="font-mono">{{ $rename->to_domain }}</span></x-slot:to></x-t>
                    @elseif ($rename->status === 'failed')
                        {{ __('Alan adı değiştirilemedi; site eski adıyla çalışıyor.') }}
                    @else
                        <x-t text="Alan adı değiştirildi: :from → :to"><x-slot:from><span class="font-mono">{{ $rename->from_domain }}</span></x-slot:from><x-slot:to><span class="font-mono">{{ $rename->to_domain }}</span></x-slot:to></x-t>
                    @endif
                </p>
                <p class="mt-0.5 text-[13px] break-words text-muted">
                    @if ($rename->isRunning())
                        {{ ($rename->options['backup'] ?? false) && $rename->backup_id === null ? __('Önce sitenin yedeği alınıyor; ardından site yeni adına taşınır.') : __('Site birkaç saniye içinde yeni adıyla açılır.') }}
                    @elseif ($rename->status === 'failed')
                        {{ $rename->error }}
                    @else
                        {{ $renameAttention ? trans_choice(':count konuya bakmanız gerekiyor.', $renameAttention) : __('Her şey yeni ada taşındı.') }}
                        {{ __(':date tarihine kadar eski ada geri dönebilirsiniz.', ['date' => \App\Support\When::date($renameUndo)]) }}
                    @endif
                </p>
            </div>
            <button type="button" wire:click="$set('renameDetails', true)" class="flex h-8 items-center rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">{{ __('Ayrıntılar') }}</button>
            @if ($renameUndo && ! $rename->isRunning())
                <button type="button" wire:click="undoRename({{ $rename->id }})" wire:confirm="{{ __('Site :from adına geri taşınsın mı?', ['from' => $rename->from_domain]) }}" @disabled($site->status->isBusy())
                        class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2 disabled:opacity-40">
                    <x-icon name="undo-2" class="size-3.5" />{{ __('Eski ada geri dön') }}
                </button>
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

    {{-- What needs a look --}}
    @if ($ssl->needsAttention() || $httpOnly)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 {{ $ssl->tone() === 'crit' ? 'bg-crit' : 'bg-warn' }}"></span>
            <x-icon name="shield-alert" class="{{ $ssl->tone() === 'crit' ? 'text-crit' : 'text-warn' }}" />
            <div class="min-w-0 flex-1 basis-60">
                @if ($ssl->needsAttention())
                    <p class="font-medium">{{ __('SSL sertifikası: :state', ['state' => $ssl->label()]) }}</p>
                    <p class="mt-0.5 text-[13px] text-muted">{{ __('Sertifika sayfasında nedenini ve nasıl düzeleceğini görebilirsiniz.') }}</p>
                @else
                    <p class="font-medium">{{ __('Site yalnızca http ile açılıyor') }}</p>
                    <p class="mt-0.5 text-[13px] text-muted">
                        @if ($site->pendingCertificate()?->status === \App\Enums\CertificateStatus::WaitingDns)
                            {{ __('DNS henüz bu sunucuya işaret etmiyor; işaret edince Let\'s Encrypt sertifikası kendiliğinden alınır.') }}
                        @else
                            {{ __('Ücretsiz Let\'s Encrypt sertifikasıyla tarayıcılar "güvenli değil" uyarısını göstermeyi bırakır.') }}
                        @endif
                    </p>
                @endif
            </div>
            <a href="{{ route('sites.ssl', $site) }}" wire:navigate class="flex h-8 items-center gap-2 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110">{{ $ssl->needsAttention() ? __('Sertifikaya bak') : __('Sertifika al') }}</a>
        </div>
    @endif

    {{-- Summary --}}
    @php $backupCell = $user->allows('backups') ? route('sites.backups', $site) : null; @endphp
    <section class="grid grid-cols-2 overflow-hidden rounded-xl border border-line bg-surface text-[13px] xl:grid-cols-4 [&>*]:min-w-0 [&>*]:border-line [&>*]:p-4 [&>*:nth-child(odd)]:border-r max-xl:[&>*:nth-child(-n+2)]:border-b xl:[&>*:not(:last-child)]:border-r">
        <a href="{{ route('sites.php', $site) }}" wire:navigate class="block hover:bg-surface-2/60">
            <p class="flex items-center gap-1.5 text-2xs font-medium tracking-[.06em] text-muted uppercase"><x-icon name="server" class="size-3.5" />{{ __('Web sunucusu') }}</p>
            <p class="mt-2 text-[15px] font-semibold">PHP {{ $site->php_version }} <span class="font-normal text-muted">· {{ $site->web_server === \App\Models\Site::APACHE ? 'Nginx + Apache' : 'Nginx' }}</span></p>
            <p class="mt-0.5 truncate text-2xs text-muted">{{ __('PHP-FPM, siteye özel havuz') }}</p>
        </a>
        <a href="{{ route('sites.ssl', $site) }}" wire:navigate class="block hover:bg-surface-2/60">
            <p class="flex items-center gap-1.5 text-2xs font-medium tracking-[.06em] text-muted uppercase"><x-icon name="lock" class="size-3.5" />SSL</p>
            <p class="mt-2"><x-status-badge :status="$ssl" /></p>
            <p class="mt-1 truncate text-2xs text-muted">
                @if ($certificate?->isInstalled())
                    {{ __(':certificate · :not_after tarihine kadar', ['certificate' => $certificate->issuerLabel(), 'not_after' => \App\Support\When::date($certificate->not_after)]) }}
                @elseif ($site->pendingCertificate()?->status === \App\Enums\CertificateStatus::WaitingDns)
                    {{ __('DNS henüz bu sunucuya işaret etmiyor') }}
                @else
                    {{ __('Site şimdilik yalnızca http ile açılır') }}
                @endif
            </p>
        </a>
        <a href="{{ route('sites.disk', $site) }}" wire:navigate class="block hover:bg-surface-2/60">
            <p class="flex items-center gap-1.5 text-2xs font-medium tracking-[.06em] text-muted uppercase"><x-icon name="hard-drive" class="size-3.5" />{{ __('Disk') }}</p>
            <p class="num mt-2 text-[15px] font-semibold">{{ $site->disk_bytes === null ? __('Ölçülmedi') : \App\Support\Bytes::format($diskTotal) }}</p>
            <p class="mt-0.5 truncate text-2xs text-muted">{{ __('Dosyalar, e-posta ve veritabanları') }}</p>
        </a>
        <{{ $backupCell ? 'a' : 'div' }} @if ($backupCell) href="{{ $backupCell }}" wire:navigate @endif class="block hover:bg-surface-2/60">
            <p class="flex items-center gap-1.5 text-2xs font-medium tracking-[.06em] text-muted uppercase"><x-icon name="archive" class="size-3.5" />{{ __('Son yedek') }}</p>
            <p class="num mt-2 text-[15px] font-semibold">{{ $backupText }}</p>
            <p class="mt-0.5 truncate text-2xs text-muted">{{ $lastBackup ? ($lastBackup->backup->scope === 'server' ? __('Tüm sunucu yedeğiyle') : __('Site yedeği')).' · '.\App\Support\Bytes::format($lastBackup->size) : ($owner?->package && ! $owner->package->allows('backups') ? __('Paketi yedekleme içermiyor') : __('Zamanlanmış yedeğe girer')) }}</p>
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
        <div class="gap-4 lg:columns-2">
            @foreach ($groups as [$key, $group, $groupIcon, $groupLink, $tools])
                <section class="mb-4 min-w-0 break-inside-avoid rounded-xl border border-line bg-surface"
                         x-show="!q || @js(mb_strtolower($group.' '.collect($tools)->pluck(1)->join(' '))).includes(q.toLocaleLowerCase('tr'))">
                    <div class="flex h-12 items-center gap-2.5 border-b border-line px-4">
                        <x-icon :name="$groupIcon" class="text-muted" />
                        <h3 class="text-[14px] font-semibold">{{ $group }}</h3>
                        @if ($groupLink)
                            <a href="{{ $groupLink }}" wire:navigate class="ml-auto flex items-center gap-1 text-[13px] text-accent hover:underline">{{ __('Tümü') }}<x-icon name="chevron-right" class="size-3.5" /></a>
                        @endif
                    </div>
                    @if ($key === 'mail' && ! $mailOn && ! $missing('mail'))
                        <div class="flex flex-wrap items-center gap-3 border-b border-line bg-surface-2/50 px-4 py-3 text-[13px]">
                            <p class="min-w-0 flex-1 basis-52 text-muted"><x-t text="Bu alan adında e-posta kapalı. Açınca :address gibi posta kutuları buradan oluşturulur."><x-slot:address><span class="font-mono text-fg">info{{ '@'.$site->domain }}</span></x-slot:address></x-t></p>
                            <a href="{{ route('sites.mail', $site) }}" wire:navigate class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] font-medium hover:bg-surface-2"><x-icon name="mail" class="size-3.5" />{{ __('E-postayı aç') }}</a>
                        </div>
                    @endif
                    <ul class="grid grid-cols-1 gap-1 p-2 sm:grid-cols-2">
                        @foreach ($tools as $tool)
                            @php [$icon, $name, $state, $link, $tone, $external, $hint] = $tool + [3 => null, 4 => null, 5 => false, 6 => null]; @endphp
                            <li class="min-w-0" x-show="!q || @js(mb_strtolower($group.' '.$name)).includes(q.toLocaleLowerCase('tr'))">
                                <{{ $link ? 'a' : 'div' }} @if ($link) href="{{ $link }}" @if ($external) target="_blank" rel="noopener" @else wire:navigate @endif @endif
                                    class="group flex items-center gap-3 rounded-lg p-2 {{ $link ? 'hover:bg-surface-2' : '' }} {{ $tone === 'off' || ! $link ? 'opacity-60' : '' }}">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg border border-line bg-surface-2/60 text-fg {{ $link ? 'group-hover:border-accent/40 group-hover:text-accent' : '' }}"><x-icon :name="$icon" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-1.5 text-[13.5px] font-medium"><span class="truncate">{{ $name }}</span>@if ($external)<x-icon name="external-link" class="size-3 text-muted" />@endif</span>
                                        <span class="flex items-center gap-1.5 text-2xs {{ in_array($tone, ['warn', 'crit'], true) ? 'text-'.$tone : 'text-muted' }}">
                                            @isset($dot[$tone])<i class="size-1.5 shrink-0 rounded-full {{ $dot[$tone] }}"></i>@endisset<span class="truncate {{ $hint ? 'font-mono' : '' }}" @if ($hint) title="{{ $hint }}" @endif>{{ $state }}</span>
                                        </span>
                                    </span>
                                </{{ $link ? 'a' : 'div' }}>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </div>

    {{-- Alan adını değiştir --}}
    <x-modal :title="__('Alan adını değiştir')" show="renaming" size="max-w-3xl">
        <form wire:submit="rename">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Alan adını değiştir') }}</h2>
            <div class="grid items-start gap-x-4 gap-y-1.5 p-5 text-[13.5px] sm:grid-cols-[9rem_minmax(0,1fr)]">
                <span class="sm:pt-2">{{ __('Şu anki ad') }}</span>
                <p class="font-mono text-[13px] sm:pt-2">{{ $site->domain }}</p>

                <label for="rename-domain" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Yeni alan adı') }}</label>
                <div>
                    <input id="rename-domain" wire:model.live.debounce.400ms="newDomain" required spellcheck="false" autocomplete="off" placeholder="{{ __('ornek.com') }}"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('newDomain') || $renameError ? 'border-crit' : 'border-line' }}">
                    @error('newDomain')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        @if ($renameError)
                            <p class="mt-1.5 text-[13px] text-crit">{{ $renameError }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Başka bir alan adı (ornek.com) ya da bu sunucudaki bir alan adının alt adı (guncelleme.ornek.com) olabilir.') }}</p>
                        @endif
                    @enderror
                    @if (! str_starts_with($newDomain, 'www.'))
                        <label class="mt-2 flex items-center gap-2 text-[13px]">
                            <input type="checkbox" wire:model.live="renameWww" class="size-4 shrink-0 accent-accent">
                            <span><x-t text=":name adı da açılsın"><x-slot:name><span class="font-mono">www.{{ $newDomain !== '' ? $newDomain : __('yeni-ad') }}</span></x-slot:name></x-t></span>
                        </label>
                    @endif
                </div>

                <span class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Eski ad') }}</span>
                <div class="space-y-2" role="radiogroup">
                    @foreach ([
                        \App\Models\SiteRename::OLD_REDIRECT => [__('Yeni ada yönlendirilsin (301)'), __('Ziyaretçiler ve arama motorları yeni adrese gider; yol ve sorgu korunur.')],
                        \App\Models\SiteRename::OLD_ALIAS => [__('Siteyi açmaya devam etsin'), __('Ek alan adı olur. Eski adresi çağıran uygulamalar ve API istemcileri için en güvenlisi.')],
                        \App\Models\SiteRename::OLD_NONE => [__('Kaldırılsın'), __('Eski ad artık bu siteyi açmaz.')],
                    ] as $mode => [$name, $hint])
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-md border px-3 py-2 {{ $renameOld === $mode ? 'border-accent bg-accent-soft/40' : 'border-line hover:bg-surface-2' }}">
                            <input type="radio" wire:model.live="renameOld" value="{{ $mode }}" class="mt-0.5 size-4 shrink-0 accent-accent">
                            <span><span class="font-medium">{{ $name }}</span><span class="block text-2xs text-muted">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                    @error('renameOld') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>

                <span class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Yedek') }}</span>
                <div class="text-[13px] sm:pt-2">
                    @if ($renameNoRoom !== null)
                        <p class="text-warn">{{ $renameNoRoom }} {{ __('Değişiklik yedeksiz yapılır.') }}</p>
                    @elseif (auth()->user()->is_admin)
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model.live="renameBackup" class="mt-0.5 size-4 shrink-0 accent-accent">
                            <span>{{ __('Önce sitenin yedeğini al') }}<span class="block text-2xs text-muted">{{ __('Dosyalar, veritabanları, e-posta ve DNS. Yedek alınamazsa hiçbir şey değişmez.') }}</span></span>
                        </label>
                    @else
                        <p class="text-muted">{{ __('Önce sitenin yedeği alınır; yedek alınamazsa hiçbir şey değişmez.') }}</p>
                    @endif
                </div>
            </div>

            @if ($renamePlan)
                @php
                    $p = $renamePlan;
                    $ip = implode(', ', $p['addresses']) ?: __('bu sunucu');
                    $mono = fn ($v) => '<span class="font-mono">'.e($v).'</span>';
                @endphp
                <div class="mx-5 mb-1 rounded-lg border border-line bg-surface-2/50 px-4 py-3">
                    <p class="text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Neler olacak') }}</p>
                    <ul class="mt-2 space-y-1.5 text-[13px]">
                        <li class="flex gap-2"><x-icon name="folder" class="mt-0.5 shrink-0 text-muted" /><span>{!! __('Klasör :from → :to olur; eski yol yeni klasöre bağlantı olarak kalır, dosya izinleri değişmez.', ['from' => $mono($p['folder_from']), 'to' => $mono($p['folder_to'])]) !!}</span></li>
                        <li class="flex gap-2"><x-icon name="user" class="mt-0.5 shrink-0 text-muted" /><span>{!! __('Sistem hesabı :account aynı kalır; veritabanları, SSH anahtarları ve kota ona bağlı.', ['account' => $mono($p['account'])]) !!}</span></li>
                        <li class="flex gap-2"><x-icon name="server" class="mt-0.5 shrink-0 text-muted" /><span>{{ __('Nginx ve PHP-FPM yeni adla yazılır, tek seferde yeniden yüklenir. Bir adım olmazsa her şey eski haline döner.') }}</span></li>
                        <li class="flex gap-2"><x-icon name="network" class="mt-0.5 shrink-0 text-muted" /><span>
                            @if ($p['dns']['kind'] === 'parent')
                                {!! __(':zone bölgesine :label kaydı eklenir (bu sunucunun adresi).', ['zone' => $mono($p['dns']['zone']), 'label' => $mono($p['dns']['label'])]) !!}
                            @elseif ($p['dns']['kind'] === 'own')
                                {!! __(':zone bölgesi bu sunucuda; siteye bağlanır.', ['zone' => $mono($p['dns']['zone'])]) !!}
                            @else
                                {!! __(':zone için DNS bölgesi oluşturulur. DNS başka yerdeyse (kayıt firması, Cloudflare) A kaydını :ip adresine yönlendirin.', ['zone' => $mono($p['dns']['zone']), 'ip' => $mono($ip)]) !!}
                            @endif
                        </span></li>
                        <li class="flex gap-2"><x-icon name="shield-check" class="mt-0.5 shrink-0 {{ in_array($p['certificate'], ['keep', 'issue'], true) ? 'text-ok' : 'text-warn' }}" /><span>
                            @switch($p['certificate'])
                                @case('keep') {{ __('Mevcut sertifika yeni adı da kapsıyor; HTTPS kesilmez.') }} @break
                                @case('issue') {!! __(':to bu sunucuya işaret ediyor: Let\'s Encrypt sertifikası değişiklikten önce alınır, HTTPS kesilmez.', ['to' => $mono($p['to'])]) !!} @break
                                @case('later') {!! __(':to henüz bu sunucuya işaret etmiyor (:found). Site yeni adla önce http ile açılır; DNS gelince sertifika kendiliğinden alınır. Kesintisiz geçiş için önce DNS\'i yönlendirin.', ['to' => $mono($p['to']), 'found' => e($p['pointing']['found'] === [] ? __('kayıt yok') : implode(', ', $p['pointing']['found']))]) !!} @break
                                @default {{ __('Site sertifikasız; yeni ad http ile açılır.') }}
                            @endswitch
                        </span></li>
                        @if ($p['laravel'] || $p['wordpress'])
                            <li class="flex gap-2"><x-icon name="file-cog" class="mt-0.5 shrink-0 text-muted" /><span>{{ $p['wordpress'] ? __('wp-config.php ve WordPress veritabanındaki adresler yeni ada çevrilir.') : __('.env dosyasındaki adresler (APP_URL) ve klasör yolu güncellenir, yapılandırma önbelleği yenilenir.') }}</span></li>
                        @else
                            <li class="flex gap-2"><x-icon name="file-cog" class="mt-0.5 shrink-0 text-muted" /><span>{{ __('Ayar dosyalarındaki (.env, config.php…) eski adres ve klasör yolu güncellenir.') }}</span></li>
                        @endif
                        @if ($p['workers'] || $p['redis'] || $p['cron'] || $p['ftp'])
                            <li class="flex gap-2"><x-icon name="refresh-cw" class="mt-0.5 shrink-0 text-muted" /><span>{{ __('Yeni adla yeniden başlatılır:') }} {{ collect([$p['workers'] ? trans_choice(':count kuyruk işçisi/süreç', $p['workers']) : null, $p['redis'] ? 'Redis' : null, $p['cron'] ? trans_choice(':count zamanlanmış görev', $p['cron']) : null, $p['ftp'] ? trans_choice(':count SFTP hesabı', $p['ftp']) : null])->filter()->implode(', ') }}</span></li>
                        @endif
                        @if ($p['subdomains'])
                            <li class="flex gap-2"><x-icon name="globe" class="mt-0.5 shrink-0 text-muted" /><span>{!! __('Alt alan adları da taşınır: :names', ['names' => collect($p['subdomains'])->map($mono)->implode(', ')]) !!}</span></li>
                        @endif
                        @if ($p['mail'])
                            <li class="flex gap-2"><x-icon name="mail" class="mt-0.5 shrink-0 text-muted" /><span>{!! $p['mail']['moves'] ? __('E-posta (posta kutusu yok) yeni ada taşınır.') : trans_choice(':count posta kutusu :from adında kalır ve posta almaya devam eder.', $p['mail']['mailboxes'], ['from' => $mono($p['from'])]) !!}</span></li>
                        @endif
                        <li class="flex gap-2"><x-icon name="undo-2" class="mt-0.5 shrink-0 text-muted" /><span>{{ __(':days gün içinde tek tıkla eski ada geri dönebilirsiniz.', ['days' => \App\Models\SiteRename::UNDO_DAYS]) }}</span></li>
                    </ul>
                </div>
            @endif
            <div class="mt-4 flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('renaming', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" @disabled(! $renamePlan) class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Alan adını değiştir') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- The latest rename: what it did, with its log --}}
    @if ($rename)
        <x-modal :title="__('Alan adı değişikliği')" show="renameDetails" size="max-w-3xl">
            <div>
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold"><span class="truncate"><x-t text="Alan adı değişikliği: :from → :to"><x-slot:from><span class="font-mono">{{ $rename->from_domain }}</span></x-slot:from><x-slot:to><span class="font-mono">{{ $rename->to_domain }}</span></x-slot:to></x-t></span></h2>
                <div class="space-y-2 p-5 text-[13px]">
                    @if ($rename->error)
                        <p class="flex gap-2 text-crit"><x-icon name="triangle-alert" class="mt-0.5 shrink-0" />{{ $rename->error }}</p>
                    @endif
                    @foreach ($rename->notes ?? [] as $n)
                        @php [$text, $look] = \App\Services\Sites\SiteRenames::noteText($n); @endphp
                        <p class="flex gap-2 {{ $look ? 'text-warn' : '' }}"><x-icon :name="$look ? 'info' : 'check'" class="mt-0.5 shrink-0 {{ $look ? '' : 'text-ok' }}" />{{ $text }}</p>
                    @endforeach
                    @if ($rename->backup_id)
                        <p class="flex gap-2"><x-icon name="archive" class="mt-0.5 shrink-0 text-muted" />{{ __('Değişiklikten önce alınan yedek Yedekler\'de.') }}</p>
                    @endif
                    <x-run-log :run="$rename->run" class="h-56" />
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('renameDetails', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- Delete confirmation --}}
    <x-modal :title="__('Siteyi sil')" show="deleting" size="max-w-md">
        <form wire:submit="delete" class="p-5 text-[13.5px]">
            <p class="font-semibold"><x-t text=":domain silinsin mi?"><x-slot:domain><span class="font-mono">{{ $site->domain }}</span></x-slot:domain></x-t></p>
            <p class="mt-1 text-[13px] text-muted"><x-t text="Nginx ve PHP-FPM ayarları ile :document_root içindeki tüm dosyalar silinir."><x-slot:document_root><span class="font-mono">{{ $site->document_root ? dirname($site->document_root) : __('site klasörü') }}</span></x-slot:document_root></x-t></p>
            @if ($accountGone && $noRoom === null)
                <p class="mt-3 rounded-md bg-warn-soft px-3 py-2 text-[13px] text-warn"><x-t text="Sistem hesabı (:user) sunucuda yok. Yedekte dosyalar olmaz; veritabanları, e-posta, DNS ve sertifikalar yedeklenir."><x-slot:user><span class="font-mono">{{ $site->systemUser->name }}</span></x-slot:user></x-t></p>
            @endif
            @if ($noRoom !== null)
                <p class="mt-3 rounded-md bg-warn-soft px-3 py-2 text-[13px] text-warn">{{ $noRoom }} {{ __('Site yedek alınmadan silinir ve geri getirilemez.') }}</p>
            @elseif (auth()->user()->is_admin)
                <label class="mt-3 flex items-start gap-2 text-[13px]">
                    <input type="checkbox" wire:model.live="backup" class="mt-0.5 size-4 shrink-0 accent-accent">
                    <span>{{ __('Silmeden önce yedek al') }}<span class="block text-[12.5px] text-muted">{{ $backup ? ($accountGone ? __('Veritabanları, e-posta ve DNS :days gün Yedekler\'de saklanır; oradan geri yüklenebilir.', ['days' => $deleteDays]) : __('Dosyalar, veritabanları, e-posta ve DNS :days gün Yedekler\'de saklanır; oradan geri yüklenebilir.', ['days' => $deleteDays])) : __('Yedek alınmazsa site geri getirilemez.') }}</span></span>
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
