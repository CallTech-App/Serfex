@php
    use App\Enums\CertificateStatus;
    $date = fn ($d) => $d?->timezone(config('servik.timezone'))->format('d.m.Y');
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40';
    $siteBusy = $site->status->isBusy();
@endphp
<div class="space-y-6">
    @if ($busy)
        <div wire:poll.2s hidden></div>
    @endif

    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">SSL sertifikası</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">SSL sertifikası</h1>
        <p class="mt-1 text-[13px] text-muted">
            <span class="font-mono">{{ $site->domain }}</span> için HTTPS sertifikası.
            Let's Encrypt sertifikaları bitişten {{ config('servik.ssl.renew_days') }} gün önce otomatik yenilenir.
        </p>
    </div>

    {{-- A request that has not produced a certificate yet --}}
    @if ($pending)
        @php
            [$tone, $title] = match ($pending->status) {
                CertificateStatus::Issuing => ['info', "Let's Encrypt sertifikası alınıyor"],
                CertificateStatus::WaitingDns => ['warn', 'Sertifika DNS bekliyor'],
                default => ['crit', 'Sertifika alınamadı'],
            };
        @endphp
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span @class(['absolute inset-y-0 left-0 w-1', 'bg-info' => $tone === 'info', 'bg-warn' => $tone === 'warn', 'bg-crit' => $tone === 'crit'])></span>
            @if ($tone === 'info')
                <x-icon name="loader-circle" class="animate-spin text-info" />
            @else
                <x-icon name="triangle-alert" @class(['text-warn' => $tone === 'warn', 'text-crit' => $tone === 'crit']) />
            @endif
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ $title }}</p>
                @if ($pending->status === CertificateStatus::Issuing)
                    <p class="mt-0.5 text-[13px] text-muted">
                        <span class="font-mono">{{ implode(', ', $pending->domains) }}</span>
                        {{ $pending->challenge === 'dns-01' ? 'DNS kaydıyla' : 'web sunucusu üzerinden' }} doğrulanıyor. Bu birkaç saniye ile bir dakika sürer.
                    </p>
                @elseif ($pending->last_error)
                    <p class="mt-0.5 text-[13px] break-words text-muted">{{ $pending->last_error }}</p>
                @endif
            </div>
            @if ($pending->status !== CertificateStatus::Issuing)
                <button type="button" wire:click="openRequest" @disabled($siteBusy) class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2 disabled:opacity-40">
                    <x-icon name="refresh-cw" class="size-3.5" />Tekrar dene
                </button>
            @endif
        </div>
    @endif

    @if ($certificate?->renew_error)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-warn"></span>
            <x-icon name="triangle-alert" class="text-warn" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Sertifika yenilenemedi. Mevcut sertifika {{ $date($certificate->not_after) }} tarihine kadar çalışmaya devam ediyor.</p>
                <p class="mt-0.5 text-[13px] break-words text-muted">{{ $certificate->renew_error }}</p>
            </div>
            <button type="button" wire:click="renew" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="refresh-cw" class="size-3.5" />Tekrar dene
            </button>
        </div>
    @endif

    @if ($site->status === \App\Enums\SiteStatus::Failed)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">HTTPS ayarları web sunucusuna uygulanamadı. Önceki ayarlar geri yüklendi.</p>
                <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $site->last_error }}</p>
            </div>
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">Siteye git</a>
        </div>
    @endif

    {{-- Certificate --}}
    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Sertifika</h2>
        @if ($certificate && $certificate->isInstalled())
            <dl class="divide-y divide-line px-5 text-[13.5px]">
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Durum</dt>
                    <dd><x-status-badge :status="$certificate->state()" /></dd>
                </div>
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Kapsadığı adlar</dt>
                    <dd class="font-mono text-[13px] break-all">{{ implode(', ', $certificate->domains) }}</dd>
                </div>
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Veren</dt>
                    <dd>{{ $certificate->issuerLabel() }}@if (! $certificate->isLetsEncrypt()) <span class="text-muted">· yüklendi</span>@endif</dd>
                </div>
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Geçerlilik</dt>
                    <dd class="num">{{ $date($certificate->not_before) }} – {{ $date($certificate->not_after) }}
                        <span class="text-muted">· {{ $certificate->isExpired() ? 'süresi doldu' : $certificate->daysLeft().' gün kaldı' }}</span></dd>
                </div>
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Otomatik yenileme</dt>
                    <dd>
                        @if ($certificate->isLetsEncrypt())
                            <label class="flex items-center gap-2">
                                <input type="checkbox" @checked($certificate->auto_renew) wire:change="setAutoRenew($event.target.checked)" class="size-4 accent-accent">
                                Bitişten {{ config('servik.ssl.renew_days') }} gün önce yenile
                            </label>
                        @else
                            <span class="text-muted">Yüklenen sertifikalar elle yenilenir: yenisini aldığınızda tekrar yükleyin.</span>
                        @endif
                    </dd>
                </div>
            </dl>
            <div class="flex flex-wrap gap-2 border-t border-line px-5 py-3">
                @if ($certificate->isLetsEncrypt())
                    <button type="button" wire:click="renew" @disabled($busy) class="{{ $btn }}"><x-icon name="refresh-cw" />Şimdi yenile</button>
                @else
                    <button type="button" wire:click="openRequest" @disabled($busy) class="{{ $btn }}"><x-icon name="shield-check" />Let's Encrypt'e geç</button>
                @endif
                <button type="button" wire:click="openUpload" @disabled($busy) class="{{ $btn }}"><x-icon name="hard-drive-upload" />Sertifika yükle</button>
                @if ($certificate->isLetsEncrypt())
                    <button type="button" wire:click="openRequest" @disabled($busy) class="{{ $btn }}">Kapsamı değiştir</button>
                @endif
                <button type="button" wire:click="$set('confirmingRemove', true)" @disabled($busy) class="ml-auto h-9 rounded-md px-3.5 text-[13.5px] text-crit hover:bg-surface-2 disabled:opacity-40">Sertifikayı kaldır</button>
            </div>
        @else
            <div class="px-5 py-10 text-center">
                <p class="font-medium">Bu sitenin sertifikası yok</p>
                <p class="mt-1 text-[13px] text-muted">Site şimdilik yalnızca <span class="font-mono">http://</span> ile açılıyor. Let's Encrypt sertifikası ücretsizdir ve otomatik yenilenir.</p>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <button type="button" wire:click="openRequest" @disabled($busy) class="{{ $primary }}"><x-icon name="shield-check" />Let's Encrypt sertifikası al</button>
                    <button type="button" wire:click="openUpload" @disabled($busy) class="{{ $btn }}"><x-icon name="hard-drive-upload" />Sertifika yükle</button>
                </div>
            </div>
        @endif
    </section>

    {{-- HTTPS settings --}}
    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">HTTPS ayarları</h2>
        <div class="grid items-start gap-2 px-5 py-4 text-[13.5px] sm:grid-cols-[220px_1fr] sm:gap-6">
            <span>HTTP yönlendirmesi</span>
            <div>
                <label class="flex items-center gap-2.5">
                    <input type="checkbox" @checked($site->https_redirect) @disabled($siteBusy) wire:change="setRedirect($event.target.checked)" class="size-4 accent-accent">
                    HTTP isteklerini HTTPS'e kalıcı olarak yönlendir (301)
                </label>
                <p class="mt-1 ml-[26px] text-2xs text-muted">
                    @if ($site->hasHttps())
                        Let's Encrypt doğrulama istekleri yönlendirilmez, böylece yenileme her zaman çalışır.
                    @else
                        Sertifika kurulunca uygulanır.
                    @endif
                </p>
            </div>
        </div>
    </section>

    {{-- Let's Encrypt --}}
    <x-modal title="Let's Encrypt sertifikası al" show="requesting">
        <form wire:submit="requestCertificate">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Let's Encrypt sertifikası al</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <p class="text-muted">Alan adı: <span class="font-mono text-fg">{{ $site->domain }}</span></p>
                @if ($otherNames = array_slice($site->hostnames(), 1))
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" wire:model="includeAliases" class="mt-0.5 size-4 accent-accent">
                        <span>Ek adları da kapsa: <span class="font-mono">{{ implode(', ', $otherNames) }}</span></span>
                    </label>
                @endif
                <div>
                    <label @class(['flex items-center gap-2.5', 'opacity-50' => ! $zone])>
                        <input type="checkbox" wire:model="wildcard" @disabled(! $zone) class="size-4 accent-accent">
                        Joker sertifika (<span class="font-mono">*.{{ $site->domain }}</span>)
                    </label>
                    <p class="mt-1 ml-[26px] text-2xs text-muted">
                        @if ($zone)
                            Tüm alt alan adlarını kapsar; bu sunucudaki <span class="font-mono">{{ $zone->name }}</span> DNS bölgesine geçici bir kayıt yazılarak doğrulanır.
                        @else
                            Joker sertifika DNS kaydıyla doğrulanır; bu alan adının DNS'i bu sunucuda olmadığı için kullanılamaz.
                        @endif
                    </p>
                </div>
                <p class="text-2xs text-muted">Alan adının DNS'i bu sunucuyu göstermiyorsa Servik Let's Encrypt'e başvurmadan bekler ve her saat yeniden dener.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('requesting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Sertifikayı al</button>
            </div>
        </form>
    </x-modal>

    {{-- Upload --}}
    <x-modal title="Sertifika yükle" show="uploading" size="max-w-2xl">
        <form wire:submit="upload">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Sertifika yükle</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <p class="text-muted">Sertifika sağlayıcınızdan aldığınız dosyaları PEM biçiminde yapıştırın. Servik, anahtarın sertifikaya ait olduğunu ve <span class="font-mono">{{ $site->domain }}</span> adını kapsadığını denetler.</p>
                @foreach ([['certificatePem', 'Sertifika (CRT)', '-----BEGIN CERTIFICATE-----', 6], ['privateKeyPem', 'Özel anahtar (KEY)', '-----BEGIN PRIVATE KEY-----', 6], ['chainPem', 'Ara sertifikalar (CA paketi, isteğe bağlı)', '-----BEGIN CERTIFICATE-----', 4]] as [$field, $label, $hint, $rows])
                    <div>
                        <label for="{{ $field }}" class="mb-1.5 block">{{ $label }}</label>
                        <textarea id="{{ $field }}" wire:model="{{ $field }}" rows="{{ $rows }}" spellcheck="false" autocomplete="off" placeholder="{{ $hint }}"
                                  class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[12px] outline-none focus:border-accent {{ $errors->has($field) ? 'border-crit' : 'border-line' }}"></textarea>
                        @error($field) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('uploading', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="upload" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Yükle ve kullan</button>
            </div>
        </form>
    </x-modal>

    {{-- Remove --}}
    <x-modal title="Sertifikayı kaldır" show="confirmingRemove" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">Sertifika kaldırılsın mı?</p>
            <p class="mt-1 text-[13px] text-muted"><span class="font-mono">{{ $site->domain }}</span> yalnızca <span class="font-mono">http://</span> ile açılır; HTTPS ile gelen ziyaretçiler hata görür. 10 dakika içinde geri alabilirsiniz.</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmingRemove', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="button" wire:click="remove" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">Kaldır</button>
            </div>
        </div>
    </x-modal>
</div>
