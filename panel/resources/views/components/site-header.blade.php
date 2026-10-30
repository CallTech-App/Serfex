@props(['site', 'active' => 'overview', 'tool' => null])
{{-- The top of every page of one site: where you are, the site itself and
     its sections, so that working on a site never leaves it. --}}
@php
    $user = auth()->user();
    $owner = $user->is_admin ? $site->customer() : null;
    $ssl = $site->sslState();
    // Security shows a dot when the certificate needs a look or the site
    // has none while it is up.
    $sslDot = $ssl->needsAttention() ? $ssl->tone()
        : ($site->status === \App\Enums\SiteStatus::Active && ! $site->hasHttps() && ! $ssl->isBusy() ? 'warn' : null);
    $tabs = array_filter([
        'overview' => ['layout-dashboard', __('Genel bakış'), route('sites.show', $site)],
        'files' => ['folder', __('Dosyalar'), route('sites.files', $site)],
        'databases' => ['database', __('Veritabanları'), route('sites.databases', $site)],
        'mail' => $user->allows('mail') ? ['mail', __('E-posta'), route('sites.mail', $site)] : null,
        'domains' => ['network', __('Alan adları ve DNS'), $user->allows('dns') ? route('sites.dns', $site) : route('sites.domains', $site)],
        'security' => ['shield-check', __('SSL ve güvenlik'), route('sites.ssl', $site)],
        'backups' => $user->allows('backups') ? ['archive', __('Yedekler'), route('sites.backups', $site)] : null,
        'logs' => ['scroll-text', __('Günlükler'), route('sites.logs', $site)],
    ]);
@endphp
<div>
    <nav class="flex min-w-0 flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
        <a href="{{ route('sites.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Siteler') }}</a>
        <x-icon name="chevron-right" class="size-3.5" />
        @if ($tool)
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ $tool }}</span>
        @else
            <span class="font-mono text-fg">{{ $site->domain }}</span>
        @endif
    </nav>
    <div class="mt-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="flex min-w-0 items-center gap-3.5">
            <span class="hidden size-12 shrink-0 place-items-center rounded-xl border border-line bg-surface text-accent sm:grid"><x-icon name="globe" class="size-6" /></span>
            <div class="min-w-0">
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                    @if ($tool)
                        <p class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $site->domain }}</p>
                    @else
                        <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $site->domain }}</h1>
                    @endif
                    <x-status-badge :status="$site->status" />
                    {{ $badges ?? '' }}
                </div>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px] text-muted">
                    @if ($owner)
                        <span>{{ __('Müşteri:') }} <a href="{{ route('customers.show', $owner) }}" wire:navigate class="text-fg hover:text-accent">{{ $owner->displayName() }}</a></span>
                        <span aria-hidden="true">·</span>
                    @endif
                    <span>{{ $site->web_server === \App\Models\Site::APACHE ? 'Nginx + Apache' : 'Nginx' }} · PHP {{ $site->php_version }}</span>
                    @if ($ip = app(\App\Services\Dns\DnsSettings::class)->ipv4())
                        <span aria-hidden="true">·</span>
                        <span class="font-mono">{{ $ip }}</span>
                    @endif
                    @if ($site->provisioned_at)
                        <span aria-hidden="true" class="max-sm:hidden">·</span>
                        <span class="max-sm:hidden">{{ __('Oluşturuldu :date', ['date' => \App\Support\When::date($site->provisioned_at)]) }}</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            {{ $actions ?? '' }}
            @if ($site->status === \App\Enums\SiteStatus::Active)
                <a href="{{ $site->hasHttps() ? 'https' : 'http' }}://{{ $site->primaryHost() }}" target="_blank" rel="noopener" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="external-link" />{{ __('Siteyi aç') }}
                </a>
            @endif
            {{ $menu ?? '' }}
        </div>
    </div>
    <nav class="scroll-thin mt-5 flex gap-1 overflow-x-auto border-b border-line text-[13.5px]" aria-label="{{ __('Site bölümleri') }}">
        @foreach ($tabs as $key => [$icon, $label, $link])
            <a href="{{ $link }}" wire:navigate @if ($active === $key) aria-current="page" @endif
               class="-mb-px flex h-11 shrink-0 items-center gap-2 border-b-2 px-3 whitespace-nowrap {{ $active === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">
                <x-icon :name="$icon" class="{{ $active === $key ? 'text-accent' : '' }}" />{{ $label }}
                @if ($key === 'security' && $sslDot)
                    <i class="size-1.5 rounded-full {{ $sslDot === 'crit' ? 'bg-crit' : 'bg-warn' }}" title="{{ $ssl->label() }}"></i>
                @endif
            </a>
        @endforeach
    </nav>
</div>
