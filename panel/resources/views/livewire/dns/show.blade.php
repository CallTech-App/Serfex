@php
    use App\Support\DnsRecords;
    $nameservers = $zone->nameservers();
    $cf = $zone->atCloudflare();
    $cfInfo = $zone->cloudflare_info ?? [];
    $cfPending = $zone->cloudflareStatus() === 'pending';
    $isAdmin = auth()->user()->is_admin;
    $ds = $zone->dsRecord();
    $dnskey = $ds ? trim(implode(' ', array_slice(preg_split('/\s+/', (string) ($zone->dnssec_info['dnskey'] ?? '')), 3))) : null;
    $ttlChoices = DnsRecords::TTLS;
    if (! isset($ttlChoices[$defaultTtl])) {
        $ttlChoices = [$defaultTtl => DnsRecords::ttlLabel($defaultTtl)] + $ttlChoices;
    }
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on the zone. --}}
    @if ($zone->status->isBusy())
        <div wire:poll.2s="refreshStatus" hidden></div>
    @endif

    {{-- Title; inside a site, the site's header stands above instead. --}}
    @if ($siteId === null)
        <div>
            <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
                <a href="{{ route('dns.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Alan Adları ve DNS') }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <span class="font-mono text-fg">{{ $zone->name }}</span>
            </nav>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                <div class="flex min-w-0 items-center gap-3">
                    <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $zone->name }}</h1>
                    @if ($cfPending)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-warn-soft px-2 py-0.5 text-2xs font-medium text-warn"><span class="size-1.5 rounded-full bg-warn"></span>{{ __('Ad sunucusu bekleniyor') }}</span>
                    @else
                        <x-status-badge :status="$zone->status" />
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($cf)
                        <button type="button" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40">
                            <x-icon name="refresh-cw" @class(['animate-spin' => $zone->status->isBusy()]) />{{ __('Şimdi eşitle') }}
                        </button>
                    @elseif ($canConnect)
                        <button type="button" wire:click="openConnect" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                            <x-icon name="cloud" />{{ __('DNS\'i Cloudflare\'e bağla') }}
                        </button>
                    @endif
                    @if ($zone->site)
                        <a href="{{ route('sites.show', $zone->site) }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                            <x-icon name="globe" />{{ __('Siteye git') }}
                        </a>
                    @endif
                </div>
            </div>
            <p class="mt-1 text-[13px] text-muted">
                @if ($cf)
                    {{ __('DNS Cloudflare\'de. Kayıtları burada ya da Cloudflare panelinde değiştirebilirsiniz; Serfex 5 dakikada bir eşitler.') }}
                @else
                {{ __('Bu alan adının DNS kayıtlarını ve bölge ayarlarını yönetin.') }}
                @endif
                @if (! $cf && $nameservers)
                    {{ __('Ad sunucuları:') }}
                    @foreach ($nameservers as $ns)
                        <span class="font-mono">{{ rtrim($ns, '.') }}</span>{{ $loop->last ? '' : ',' }}
                    @endforeach
                @endif
            </p>
        </div>
    @endif

    @if ($siteId !== null && ($cf || $canConnect))
        <div class="flex flex-wrap items-center gap-3 text-[13px] text-muted">
            <span class="mr-auto">{{ $cf ? __('DNS Cloudflare\'de. Kayıtları burada ya da Cloudflare panelinde değiştirebilirsiniz; Serfex 5 dakikada bir eşitler.') : __('DNS bu sunucuda.') }}</span>
            @if ($cf)
                <button type="button" wire:click="syncNow" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-fg hover:bg-surface-2"><x-icon name="refresh-cw" class="size-3.5" />{{ __('Şimdi eşitle') }}</button>
            @else
                <button type="button" wire:click="openConnect" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-fg hover:bg-surface-2"><x-icon name="cloud" class="size-3.5" />{{ __('DNS\'i Cloudflare\'e bağla') }}</button>
            @endif
        </div>
    @endif

    @if ($cfPending && ($cfInfo['name_servers'] ?? []) !== [])
        @php $cfNs = $cfInfo['name_servers']; @endphp
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]" x-data="{ copied: false }">
            <span class="absolute inset-y-0 left-0 w-1 bg-warn"></span>
            <x-icon name="triangle-alert" class="text-warn" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Cloudflare bu alan adını bekliyor') }}</p>
                <p class="mt-0.5 text-[13px] text-muted">
                    <x-t text="Alan adının kayıt firmasında ad sunucularını :ns yapın. Değişiklik görülünce Serfex sertifikayı alır ve site açılır; Serfex 5 dakikada bir denetler."><x-slot:ns><span class="font-mono text-fg">{{ implode(', ', $cfNs) }}</span></x-slot:ns></x-t>
                </p>
            </div>
            <button type="button" @click="navigator.clipboard.writeText(@js(implode("\n", $cfNs))); copied = true; setTimeout(() => copied = false, 2000)" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="copy" class="size-3.5" /><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span>
            </button>
        </div>
    @endif
    @if ($cf && $isAdmin && ($cfInfo['ssl'] ?? null) === 'flexible')
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="shield-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Cloudflare SSL modu Esnek (Flexible)') }}</p>
                <p class="mt-0.5 text-[13px] text-muted">{{ __('Ziyaretçiler sonsuz yönlendirmeye düşer: Cloudflare sunucuya HTTP ile gelir, Serfex HTTPS\'e yönlendirir. Sitenin sertifikası hazır olduğunda Full (strict) güvenlidir.') }}</p>
            </div>
            <button type="button" wire:click="strictSsl" class="h-8 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110">{{ __('Full (strict) yap') }}</button>
        </div>
    @endif

    @if ($zone->status === \App\Enums\ZoneStatus::Failed)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ $cf ? __('Son değişiklik Cloudflare\'e yazılamadı. Cloudflare önceki kayıtları yayınlamaya devam ediyor.') : __('Son değişiklik DNS sunucusuna uygulanamadı. Sunucu önceki kayıtları yayınlamaya devam ediyor.') }}</p>
                <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $zone->last_error }}</p>
            </div>
            <button type="button" wire:click="retry" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="refresh-cw" class="size-3.5" />{{ __('Tekrar dene') }}
            </button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (['records' => __('Kayıtlar'), 'settings' => __('Ayarlar')] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'records')
            @if ($cf)
                @php
                    $ssl = $cfInfo['ssl'] ?? null;
                    $dnssecState = $cfInfo['dnssec']['status'] ?? null;
                    $syncedAt = $zone->cloudflare_synced_at;
                @endphp
                <dl class="grid border-b border-line text-[13px] sm:grid-cols-2 lg:grid-cols-4 [&>div]:border-line [&>div]:px-5 [&>div]:py-3.5 max-lg:[&>div]:border-b lg:[&>div:not(:last-child)]:border-r">
                    <div>
                        <dt class="text-2xs text-muted">{{ __('Cloudflare') }}</dt>
                        <dd class="mt-1.5">
                            @if ($cfPending)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-warn-soft px-2 py-0.5 text-2xs font-medium text-warn"><span class="size-1.5 rounded-full bg-warn"></span>{{ __('Bekliyor') }}</span>
                            @elseif (($cfInfo['status'] ?? null) === 'active')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-2xs font-medium text-ok"><span class="size-1.5 rounded-full bg-ok"></span>{{ __('Etkin') }}</span>
                            @else
                                <span class="text-muted">{{ $cfInfo['status'] ?? '—' }}</span>
                            @endif
                        </dd>
                        <dd class="mt-1 text-2xs text-muted">
                            @if ($isAdmin && $zone->cloudflareAccount){{ __(':account hesabı', ['account' => $zone->cloudflareAccount->name]) }} · @endif
                            {{ $syncedAt ? __('son eşitleme :when', ['when' => $syncedAt->diffForHumans()]) : __('henüz eşitlenmedi') }}
                        </dd>
                        @php $lc = $cfInfo['last_change'] ?? null; $parts = []; @endphp
                        @if ($lc)
                            @php
                                foreach (['created' => __(':n eklendi'), 'updated' => __(':n güncellendi'), 'deleted' => __(':n silindi'), 'imported' => __(':n Cloudflare\'den alındı'), 'removed' => __(':n Cloudflare\'de silinmiş')] as $k => $label) {
                                    if (($lc[$k] ?? 0) > 0) { $parts[] = str_replace(':n', (string) $lc[$k], $label); }
                                }
                            @endphp
                            @if ($parts)<dd class="mt-0.5 text-2xs text-muted" title="{{ $lc['at'] ?? '' }}">{{ __('Son değişiklik: :what', ['what' => implode(', ', $parts)]) }}</dd>@endif
                        @endif
                    </div>
                    <div>
                        <dt class="text-2xs text-muted">{{ __('Ad sunucuları') }}</dt>
                        @if ($cfPending && ($cfInfo['original_name_servers'] ?? []) !== [])
                            <dd class="mt-1.5 font-mono leading-6">@foreach ($cfInfo['original_name_servers'] as $ns){{ $ns }}<br>@endforeach</dd>
                            <dd class="mt-0.5 text-2xs text-muted">{{ __('Şu an kayıt firmasında') }}</dd>
                        @else
                            <dd class="mt-1.5 font-mono leading-6">@forelse ($cfInfo['name_servers'] ?? [] as $ns){{ $ns }}<br>@empty — @endforelse</dd>
                            <dd class="mt-0.5 text-2xs text-muted">{{ $cfPending ? __('Kayıt firmasında bunlar olmalı') : __('Kayıt firmasında doğru') }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-2xs text-muted">{{ __('SSL modu') }}</dt>
                        <dd @class(['mt-1.5 text-[13.5px]', 'text-crit' => in_array($ssl, ['flexible', 'off'], true)])>{{ \App\Livewire\Dns\ZoneShow::sslLabel($ssl) }}</dd>
                        <dd class="mt-0.5 text-2xs text-muted">
                            @if ($ssl === 'strict')
                                {{ __('Sertifika: Let\'s Encrypt, DNS doğrulaması') }}
                            @elseif (isset($cfInfo['ssl_error']))
                                <span title="{{ $cfInfo['ssl_error'] }}">{{ __('Okunamadı: anahtarda Zone Settings izni yok') }}</span>
                            @else
                                {{ __('Full (strict) olmalı') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        @if ($certificate !== null && $certificate->status !== \App\Enums\CertificateStatus::Active)
                            <dt class="text-2xs text-muted">{{ __('Sertifika') }}</dt>
                            <dd class="mt-1.5">
                                @if ($certificate->status === \App\Enums\CertificateStatus::WaitingDns)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-warn-soft px-2 py-0.5 text-2xs font-medium text-warn"><span class="size-1.5 rounded-full bg-warn"></span>{{ __('DNS bekleniyor') }}</span>
                                @else
                                    <x-status-badge :status="$certificate->status" />
                                @endif
                            </dd>
                            <dd class="mt-1 text-2xs text-muted">{{ $cfPending ? __('Ad sunucuları değişince alınır') : ($certificate->last_error ?? '') }}</dd>
                        @else
                            <dt class="text-2xs text-muted">{{ __('DNSSEC') }}</dt>
                            <dd class="mt-1.5 text-[13.5px]">{{ match ($dnssecState) { 'active' => __('Açık'), 'pending' => __('Kayıt firmasında DS bekleniyor'), null => '—', default => __('Kapalı') } }}</dd>
                            <dd class="mt-0.5 text-2xs text-muted">{{ __('Cloudflare\'de; Ayarlar sekmesinden açılır') }}</dd>
                        @endif
                    </div>
                </dl>
            @endif
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openAdd" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />{{ __('Kayıt ekle') }}
                </button>
                <button type="button" wire:click="deleteSelected" @disabled($selected === [])
                        class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                    {{ __('Sil') }}{{ $selected ? ' ('.count($selected).')' : '' }}
                </button>
                <button type="button" wire:click="$set('confirmingReset', true)" class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Varsayılana dön') }}</button>
                <div class="ml-auto flex w-full gap-2 sm:w-auto">
                    <select wire:model.live="type" aria-label="{{ __('Tür') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                        <option value="">{{ __('Tüm türler') }}</option>
                        @foreach (DnsRecords::TYPES as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                    <label class="relative flex-1 sm:w-56 sm:flex-none">
                        <span class="sr-only">{{ __('Kayıt ara') }}</span>
                        <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Ara') }}"
                               class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                    </label>
                </div>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[720px] table-fixed text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="w-10 py-2.5 pl-5"><span class="sr-only">{{ __('Seç') }}</span></th>
                            <th class="w-[30%] px-3 py-2.5 font-medium">{{ __('Ana makine') }}</th>
                            <th class="w-20 px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Değer') }}</th>
                            @if ($cf)<th class="w-36 px-3 py-2.5 font-medium">{{ __('Cloudflare') }}</th>@endif
                            <th class="w-24 px-3 py-2.5 font-medium">{{ __('TTL') }}</th>
                            <th class="w-24 px-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($records as $r)
                            @php $fqdn = DnsRecords::fqdn($r->name, $zone->name); @endphp
                            <tr wire:key="rec-{{ $r->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pl-5"><input type="checkbox" wire:model.live="selected" value="{{ $r->id }}" aria-label="{{ __(':fqdn :type seç', ['fqdn' => $fqdn, 'type' => $r->type]) }}" class="size-4 accent-accent"></td>
                                <td class="truncate px-3 py-3 font-mono text-[13px]" title="{{ $fqdn }}">
                                    @if ($r->name === '@')
                                        {{ $zone->name }}
                                    @else
                                        {{ $r->name }}<span class="text-muted">.{{ $zone->name }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3"><span class="rounded border border-line bg-surface-2 px-2 py-0.5 font-mono text-2xs font-medium">{{ $r->type }}</span></td>
                                <td class="px-3 py-3">
                                    <span class="block truncate font-mono text-[13px]" title="{{ $r->content }}">
                                        @if ($r->priority !== null)<span class="text-muted">{{ $r->priority }}</span> @endif{{ $r->content }}
                                    </span>
                                </td>
                                @if ($cf)
                                    <td class="px-3 py-3">
                                        @if ($r->canBeProxied())
                                            <button type="button" wire:click="toggleProxy({{ $r->id }})" title="{{ $r->proxied ? __('Vekili kapat') : __('Vekili aç') }}"
                                                    @class(['inline-flex items-center gap-1.5 text-[13px] hover:text-accent', 'text-muted' => ! $r->proxied])>
                                                <x-icon name="cloud" @class(['size-4', 'text-[#f38020]' => $r->proxied]) />{{ $r->proxied ? __('Vekil') : __('Yalnızca DNS') }}
                                            </button>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="num px-3 py-3 text-muted">{{ $cf && $r->canBeProxied() && $r->proxied ? __('Otomatik') : DnsRecords::ttlLabel($r->ttl) }}</td>
                                <td class="px-3 py-3 text-right"><button type="button" wire:click="openEdit({{ $r->id }})" class="text-[13px] text-muted hover:text-fg">{{ __('Düzenle') }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $cf ? 7 : 6 }}" class="px-5 py-8 text-center text-muted">{{ __('Eşleşen kayıt yok.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="num border-t border-line px-5 py-3 text-2xs text-muted">
                {{ __(':records / :records2 kayıt · son değişiklik :updated_at', ['records' => $records->count(), 'records2' => $zone->records->count(), 'updated_at' => \App\Support\When::dateTime($zone->updated_at)]) }}
                @if ($cf) · {{ __('Cloudflare\'in kendi yönettiği NS ve SOA kayıtları gösterilmez') }} @elseif ($zone->serial) {{ __('· seri :serial', ['serial' => $zone->serial]) }} @endif
            </p>
        @else
            <form wire:submit="saveSettings" class="max-w-3xl px-5 py-5 text-[13.5px]">
                <div class="divide-y divide-line">
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>{{ __('Ad sunucuları') }}</span>
                        <p class="font-mono text-[13px] leading-6">
                            @forelse ($zone->publicNameservers() as $ns) {{ $ns }}<br> @empty <span class="text-muted">{{ __('NS kaydı yok') }}</span> @endforelse
                        </p>
                    </div>
                    @if ($cf && $isAdmin)
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span class="sm:pt-0.5">{{ __('Cloudflare hesabı') }}</span>
                            <div>
                                <p>{{ $zone->cloudflareAccount?->name ?? '—' }}@if ($cfInfo['account_name'] ?? null) <span class="text-muted">· {{ $cfInfo['account_name'] }}</span>@endif</p>
                                <p class="mt-1 text-2xs text-muted">{{ __('Kayıtlar bu hesabın anahtarıyla yazılır. Anahtarı Alan Adları ve DNS → Cloudflare sayfasında yenileyebilirsiniz.') }}</p>
                            </div>
                        </div>
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <label for="cf-ssl" class="sm:pt-2">{{ __('SSL modu') }}</label>
                            <div>
                                <select id="cf-ssl" wire:change="setSslMode($event.target.value)" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-64">
                                    @if (! in_array($cfInfo['ssl'] ?? null, ['off', 'flexible', 'full', 'strict'], true))<option value="" selected>—</option>@endif
                                    @foreach (['strict', 'full', 'flexible', 'off'] as $m)
                                        <option value="{{ $m }}" @selected(($cfInfo['ssl'] ?? null) === $m)>{{ \App\Livewire\Dns\ZoneShow::sslLabel($m) }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-2xs text-muted">{{ __('Full (strict): Cloudflare sunucuya HTTPS ile gelir ve sertifikayı doğrular. Serfex, sertifika hazır olunca bunu kendisi seçer.') }}</p>
                            </div>
                        </div>
                    @endif
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="defaultTtl">{{ __('Varsayılan TTL') }}</label>
                        <div>
                            <select id="defaultTtl" wire:model.number="defaultTtl" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-64">
                                @foreach ($ttlChoices as $seconds => $label)
                                    <option value="{{ $seconds }}">{{ $label }} ({{ $seconds }})</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-2xs text-muted">{{ __('Yeni kayıtların ve SOA kaydının TTL\'si.') }}</p>
                        </div>
                    </div>
                    @if ($cf)
                        @php $cfDs = $cfInfo['dnssec']['ds'] ?? null; $cfDnssec = $cfInfo['dnssec']['status'] ?? null; @endphp
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span class="sm:pt-0.5">{{ __('DNSSEC') }}</span>
                            <div class="min-w-0">
                                <p>{{ match ($cfDnssec) { 'active' => __('Açık'), 'pending' => __('Açık; kayıt firmasında DS kaydı bekleniyor'), null => '—', default => __('Kapalı') } }}</p>
                                @if ($cfDs && in_array($cfDnssec, ['active', 'pending'], true))
                                    <div class="mt-2 flex items-start gap-3 rounded-md border border-line px-3 py-2" x-data="{ copied: false }">
                                        <span class="min-w-0 font-mono text-[12.5px] break-all">{{ $cfDs }}</span>
                                        <button type="button" @click="navigator.clipboard.writeText(@js($cfDs)); copied = true; setTimeout(() => copied = false, 2000)" class="shrink-0 text-[13px] text-accent hover:underline"><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span></button>
                                    </div>
                                    <p class="mt-1 text-2xs text-muted">{{ __('Bu DS kaydını alan adı kayıt firmanıza ekleyin. Kapatmadan önce kayıt firmasından silin.') }}</p>
                                @endif
                                @if ($isAdmin)
                                    <button type="button" wire:click="setCloudflareDnssec({{ in_array($cfDnssec, ['active', 'pending'], true) ? 'false' : 'true' }})" wire:confirm="{{ in_array($cfDnssec, ['active', 'pending'], true) ? __('Kayıt firmasındaki DS kaydını sildiniz mi? Silmeden kapatırsanız alan adı birçok çözümleyicide bulunamaz.') : '' }}"
                                            class="mt-2 h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">{{ in_array($cfDnssec, ['active', 'pending'], true) ? __('Cloudflare\'de DNSSEC\'i kapat') : __('Cloudflare\'de DNSSEC\'i aç') }}</button>
                                @endif
                            </div>
                        </div>
                    @else
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span class="sm:pt-0.5">{{ __('DNSSEC') }}</span>
                        <div class="min-w-0">
                            <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="dnssec" class="size-4 accent-accent">{{ __('Bölgeyi imzala') }}</label>
                            @if (! $zone->dnssec)
                                <p class="mt-1 text-2xs text-muted">{{ __('Yanıtlar imzalanır; sahte DNS yanıtları doğrulayan çözümleyicilerde reddedilir. Açtıktan sonra DS kaydını alan adı kayıt firmanıza eklemeniz gerekir.') }}</p>
                            @elseif ($ds === null)
                                <p class="mt-1 flex items-center gap-1.5 text-2xs text-muted"><x-icon name="loader-circle" class="size-3.5 animate-spin" />{{ __('Anahtar oluşturuluyor; DS kaydı birazdan burada görünecek.') }}</p>
                            @else
                                <div class="mt-3 rounded-lg border border-line" x-data="{ copied: null }">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-line px-3.5 py-2.5">
                                        <p class="mr-auto font-medium">{{ __('Kayıt firmasına eklenecek DS kaydı') }}</p>
                                        <button type="button" @click="navigator.clipboard.writeText(@js($ds['record'])); copied = 'ds'; setTimeout(() => copied = null, 2000)" class="text-[13px] text-accent hover:underline"><span x-show="copied !== 'ds'">{{ __('Kaydı kopyala') }}</span><span x-show="copied === 'ds'" x-cloak>{{ __('Kopyalandı') }}</span></button>
                                    </div>
                                    <dl class="divide-y divide-line text-[13px]">
                                        <div class="grid gap-1 px-3.5 py-2 sm:grid-cols-[150px_1fr] sm:gap-3"><dt class="text-muted">{{ __('Anahtar etiketi') }}</dt><dd class="num font-mono">{{ $ds['key_tag'] }}</dd></div>
                                        <div class="grid gap-1 px-3.5 py-2 sm:grid-cols-[150px_1fr] sm:gap-3"><dt class="text-muted">{{ __('Algoritma') }}</dt><dd><span class="font-mono">{{ $ds['algorithm'] }}</span> <span class="text-muted">(ECDSAP256SHA256)</span></dd></div>
                                        <div class="grid gap-1 px-3.5 py-2 sm:grid-cols-[150px_1fr] sm:gap-3"><dt class="text-muted">{{ __('Özet türü') }}</dt><dd><span class="font-mono">2</span> <span class="text-muted">(SHA-256)</span></dd></div>
                                        <div class="grid gap-1 px-3.5 py-2 sm:grid-cols-[150px_1fr] sm:gap-3">
                                            <dt class="text-muted">{{ __('Özet') }}</dt>
                                            <dd class="flex min-w-0 items-start gap-3">
                                                <span class="min-w-0 font-mono break-all">{{ $ds['digest'] }}</span>
                                                <button type="button" @click="navigator.clipboard.writeText(@js($ds['digest'])); copied = 'digest'; setTimeout(() => copied = null, 2000)" class="shrink-0 text-accent hover:underline"><span x-show="copied !== 'digest'">{{ __('Kopyala') }}</span><span x-show="copied === 'digest'" x-cloak>{{ __('Kopyalandı') }}</span></button>
                                            </dd>
                                        </div>
                                    </dl>
                                    @if ($dnskey)
                                        <details class="border-t border-line px-3.5 py-2 text-[13px]">
                                            <summary class="cursor-pointer text-accent">{{ __('Kayıt firması açık anahtar (DNSKEY) istiyorsa') }}</summary>
                                            <dl class="mt-2 space-y-1.5">
                                                <div class="grid gap-1 sm:grid-cols-[150px_1fr] sm:gap-3"><dt class="text-muted">{{ __('Bayraklar / Protokol') }}</dt><dd class="font-mono">257 / 3</dd></div>
                                                <div class="grid gap-1 sm:grid-cols-[150px_1fr] sm:gap-3">
                                                    <dt class="text-muted">{{ __('Açık anahtar') }}</dt>
                                                    <dd class="flex min-w-0 items-start gap-3">
                                                        <span class="min-w-0 font-mono break-all">{{ $dnskey }}</span>
                                                        <button type="button" @click="navigator.clipboard.writeText(@js($dnskey)); copied = 'key'; setTimeout(() => copied = null, 2000)" class="shrink-0 text-accent hover:underline"><span x-show="copied !== 'key'">{{ __('Kopyala') }}</span><span x-show="copied === 'key'" x-cloak>{{ __('Kopyalandı') }}</span></button>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </details>
                                    @endif
                                </div>
                                <p class="mt-2 text-2xs text-muted">{{ __('Kayıt firmanızın DNSSEC bölümüne bu değerleri girin; kayıt firması yayınlayınca alan adı doğrulanır. DNSSEC\'i kapatmadan önce DS kaydını kayıt firmasından silin.') }}</p>
                            @endif
                        </div>
                    </div>
                    @endif
                    @if ($cf && $isAdmin)
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span class="sm:pt-0.5">{{ __('Bu sunucuya geri al') }}</span>
                            <div>
                                <button type="button" wire:click="$set('confirmingDetach', true)" class="h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">{{ __('DNS\'i bu sunucuya geri al') }}</button>
                                <p class="mt-1 text-2xs text-muted">{{ __('Bölge yeniden bu sunucunun DNS\'inden yayınlanır; Cloudflare\'deki kayıtlara dokunulmaz.') }}</p>
                            </div>
                        </div>
                    @endif
                </div>
                @unless ($cf)
                <details class="mt-2" @if ($errors->hasAny(['hostmaster', 'refresh', 'retry', 'expire', 'minimum'])) open @endif>
                    <summary class="cursor-pointer py-2 text-[13px] text-accent">{{ __('Gelişmiş (SOA)') }}</summary>
                    <div class="divide-y divide-line">
                        <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>{{ __('Birincil ad sunucusu') }}</span>
                            <span class="font-mono text-[13px]">{{ $nameservers[0] ?? '—' }}</span>
                        </div>
                        <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <label for="hostmaster">{{ __('Yönetici e-postası') }}</label>
                            <div>
                                <input id="hostmaster" wire:model="hostmaster" spellcheck="false" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent sm:w-80 {{ $errors->has('hostmaster') ? 'border-crit' : 'border-line' }}">
                                @error('hostmaster') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>{{ __('Seri numarası') }}</span>
                            <span class="num font-mono text-[13px]">{{ $zone->serial ?? '—' }}</span>
                        </div>
                        @foreach ([['refresh', 'retry', __('Yenileme / Yeniden deneme')], ['expire', 'minimum', __('Sona erme / Negatif TTL')]] as [$a, $b, $label])
                            <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                                <label for="{{ $a }}">{{ $label }}</label>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <input id="{{ $a }}" wire:model.number="{{ $a }}" inputmode="numeric" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has($a) ? 'border-crit' : 'border-line' }}">
                                        <span class="text-muted">/</span>
                                        <input aria-label="{{ explode(' / ', $label)[1] }}" wire:model.number="{{ $b }}" inputmode="numeric" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has($b) ? 'border-crit' : 'border-line' }}">
                                        <span class="text-[13px] text-muted">{{ __('sn') }}</span>
                                    </div>
                                    @error($a) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                                    @error($b) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
                @endunless
                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
                    <button type="button" wire:click="export" class="flex h-9 items-center gap-2 rounded-md border border-line px-3.5 hover:bg-surface-2 sm:ml-auto">
                        <x-icon name="download" />{{ __('Bölge dosyasını dışa aktar') }}
                    </button>
                </div>
            </form>
        @endif
    </section>

    @include('livewire.dns.record-modal', ['suffix' => '.'.$zone->name, 'proxiable' => $cf])

    {{-- DNS'i Cloudflare'e bağla --}}
    @if ($canConnect)
    <x-modal :title="__(':zone DNS\'ini Cloudflare\'e bağla', ['zone' => $zone->name])" show="connecting" size="max-w-4xl">
        <form wire:submit="connectCloudflare" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __(':zone DNS\'ini Cloudflare\'e bağla', ['zone' => $zone->name]) }}</h2>
            <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
                @if ($connectError)
                    <p class="relative overflow-hidden rounded-md bg-crit-soft py-2.5 pr-3 pl-4 text-[13px] text-crit"><span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>{{ $connectError }}</p>
                @endif
                @if ($connectPlan)
                    <div class="grid items-center gap-2 sm:grid-cols-[180px_1fr] sm:gap-6">
                        <span>{{ __('Cloudflare hesabı') }}</span>
                        <p class="rounded-md border border-line px-3 py-2">{{ $connectPlan['account'] }} <span class="text-muted">· {{ ($connectPlan['zone']['status'] ?? '') === 'active' ? __(':zone burada bulundu, etkin', ['zone' => $zone->name]) : __(':zone burada bulundu, ad sunucuları bekleniyor', ['zone' => $zone->name]) }}</span></p>
                    </div>
                    @if ($connectPlan['rows'] === [])
                        <p>{{ __('İki taraftaki kayıtlar karşılaştırıldı; :count kaydın hepsi aynı.', ['count' => $connectPlan['same']]) }}</p>
                    @else
                        <p>{{ __('İki taraftaki kayıtlar karşılaştırıldı. :count kayıt aynı. Farklı olanlar:', ['count' => $connectPlan['same']]) }}</p>
                        <div class="overflow-x-auto rounded-md border border-line">
                            <table class="w-full min-w-[720px] table-fixed text-[13px]">
                                <thead>
                                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                        <th class="w-[22%] py-2.5 pr-3 pl-4 font-medium">{{ __('Ana makine') }}</th>
                                        <th class="w-20 px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                                        <th class="px-3 py-2.5 font-medium">{{ __('Cloudflare\'de') }}</th>
                                        <th class="px-3 py-2.5 font-medium">{{ __('Serfex\'te') }}</th>
                                        <th class="w-48 py-2.5 pr-4 pl-3 font-medium">{{ __('Ne olsun') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @foreach ($connectPlan['rows'] as $row)
                                        <tr wire:key="plan-{{ $row['key'] }}">
                                            <td class="truncate py-2.5 pr-3 pl-4 font-mono" title="{{ DnsRecords::fqdn($row['name'], $zone->name) }}">{{ $row['name'] === '@' ? $zone->name : $row['name'] }}</td>
                                            <td class="px-3 py-2.5 font-mono text-2xs">{{ $row['type'] }}</td>
                                            <td class="px-3 py-2.5 font-mono">@forelse ($row['cloudflare'] as $v)<span class="block truncate" title="{{ $v }}">{{ $v }}</span>@empty<span class="text-muted">{{ __('yok') }}</span>@endforelse</td>
                                            <td class="px-3 py-2.5 font-mono">@forelse ($row['serfex'] as $v)<span class="block truncate" title="{{ $v }}">{{ $v }}</span>@empty<span class="text-muted">{{ __('yok') }}</span>@endforelse</td>
                                            <td class="py-2 pr-4 pl-3">
                                                <select wire:model="connectChoices.{{ $row['key'] }}" aria-label="{{ __('Ne olsun') }}" class="h-8 w-full rounded-md border border-line bg-surface px-2 text-[13px]">
                                                    @foreach (\App\Services\Cloudflare\CloudflareZones::CHOICES[$row['kind']] as $c)
                                                        <option value="{{ $c }}">{{ match ($c) { 'serfex' => __('Serfex\'teki yazılsın'), 'cloudflare' => __('Cloudflare\'deki kalsın'), 'import' => __('Serfex\'e alınsın'), 'delete' => __('Cloudflare\'den silinsin'), 'push' => __('Cloudflare\'e eklensin'), default => __('Serfex\'ten silinsin') } }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <ul class="list-disc space-y-1 pl-5 text-[13px] text-muted">
                        <li>{{ __('Bağlandıktan sonra kayıtları Serfex\'ten ya da Cloudflare panelinden değiştirebilirsiniz; Serfex 5 dakikada bir eşitler. Aynı kayıt iki yerde birden değişirse Serfex\'teki geçerli olur.') }}</li>
                        <li>{{ __('Bu sunucunun DNS\'i (PowerDNS) :zone bölgesini yayınlamayı bırakır. Ayarlar → "DNS\'i bu sunucuya geri al" ile istediğiniz zaman dönebilirsiniz; Cloudflare\'deki kayıtlara dokunulmaz.', ['zone' => $zone->name]) }}</li>
                        <li>{{ __('Sertifika yenilemeleri bundan sonra DNS doğrulamasıyla (Cloudflare) yapılır; joker sertifika da alınabilir.') }}</li>
                    </ul>
                @elseif (! $connectError)
                    <p class="flex items-center gap-2 text-muted"><x-icon name="loader-circle" class="animate-spin" />{{ __('Cloudflare\'de aranıyor…') }}</p>
                @endif
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('connecting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" @disabled(! $connectPlan) wire:loading.attr="disabled" wire:target="connectCloudflare" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Bağla ve eşitle') }}</button>
            </div>
        </form>
    </x-modal>

    @endif

    {{-- Bu sunucuya geri al --}}
    @if ($cf && $isAdmin)
    <x-modal :title="__('DNS\'i bu sunucuya geri al')" show="confirmingDetach" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ __(':zone yeniden bu sunucudan yayınlansın mı?', ['zone' => $zone->name]) }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Panelin kayıtları bu sunucunun DNS\'ine yazılır ve ad sunucusu kayıtları eklenir. Cloudflare\'deki bölge ve kayıtlar olduğu gibi kalır, ama artık eşitlenmez. Ziyaretçiler, kayıt firmasında ad sunucuları bu sunucununkiler yapılınca buraya gelir.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmingDetach', false)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="detachCloudflare" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Geri al') }}</button>
            </div>
        </div>
    </x-modal>
    @endif

    {{-- DNSSEC'i kapat --}}
    <x-modal :title="__('DNSSEC\'i kapat')" show="confirmingDnssecOff" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ __('Kayıt firmasındaki DS kaydını sildiniz mi?') }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Kayıt firmasında DS kaydı dururken imza kalkarsa, imzayı denetleyen çözümleyiciler (Google, Cloudflare ve birçok internet sağlayıcısı) bu alan adını bulamaz. Önce DS kaydını silin ve bir gün bekleyin. Anahtar saklanır; yeniden açtığınızda aynı DS kaydı geçerli olur.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="keepDnssec" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="confirmDnssecOff" class="h-9 rounded-md bg-crit px-3.5 font-medium text-on-crit">{{ __('DNSSEC\'i kapat') }}</button>
            </div>
        </div>
    </x-modal>

    {{-- Varsayılana dön --}}
    <x-modal :title="__('Varsayılana dön')" show="confirmingReset" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ __('DNS bölgesi varsayılana dönsün mü?') }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Elle eklenen ve değiştirilen tüm kayıtlar silinir; sunucu şablonundaki kayıtlar yeniden yazılır. Hemen ardından "Geri al" ile eski kayıtlara dönebilirsiniz.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmingReset', false)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="resetToTemplate" class="h-9 rounded-md bg-crit px-3.5 font-medium text-on-crit">{{ __('Varsayılana dön') }}</button>
            </div>
        </div>
    </x-modal>
</div>
