@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent';
    $readOnly = auth()->user()->isReadOnly();
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('dns.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Alan Adları ve DNS') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Cloudflare') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Cloudflare') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('DNS\'i Cloudflare\'de olan alan adlarının kayıtları Serfex\'ten yönetilir ve Cloudflare\'e yazılır. Her hesap için bir API anahtarı (API token) bağlayın.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3.5">
            <h2 class="mr-auto text-[14.5px] font-semibold">{{ __('Hesaplar') }}</h2>
            @unless ($readOnly)
                <button type="button" wire:click="connect" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />{{ __('Hesap bağla') }}
                </button>
            @endunless
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Ad') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Cloudflare hesabı') }}</th>
                        <th class="px-3 py-2.5 text-right font-medium">{{ __('Alan adları') }}</th>
                        <th class="px-3 py-2.5 text-right font-medium">{{ __('Bu sunucuda') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('İzinler') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                        <th class="py-2.5 pr-5 pl-3"><span class="sr-only">{{ __('İşlemler') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($accounts as $a)
                        <tr wire:key="cf-{{ $a->id }}">
                            <td class="py-3 pr-3 pl-5 font-medium">{{ $a->name }}</td>
                            <td class="px-3 py-3 text-muted">{{ $a->account_name ?? '—' }}</td>
                            <td class="num px-3 py-3 text-right">{{ $a->zones_count }}</td>
                            <td class="num px-3 py-3 text-right">{{ $a->panel_zones }}</td>
                            <td class="px-3 py-3 text-muted">{{ $a->status === \App\Models\CloudflareAccount::OK && $a->abilities() !== [] ? implode(' · ', $a->abilities()) : '—' }}</td>
                            <td class="px-3 py-3">
                                @if ($a->status === \App\Models\CloudflareAccount::OK)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-2xs font-medium text-ok"><span class="size-1.5 rounded-full bg-ok"></span>{{ __('Bağlı') }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-crit-soft px-2 py-0.5 text-2xs font-medium text-crit" title="{{ $a->last_error }}"><span class="size-1.5 rounded-full bg-crit"></span>{{ __('Anahtar geçersiz') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                @unless ($readOnly)
                                    <span class="inline-flex items-center gap-3 text-[13px]">
                                        <button type="button" wire:click="refresh({{ $a->id }})" class="text-muted hover:text-accent">{{ __('Denetle') }}</button>
                                        <button type="button" wire:click="edit({{ $a->id }})" @class(['hover:text-accent', 'text-accent' => $a->status !== \App\Models\CloudflareAccount::OK, 'text-muted' => $a->status === \App\Models\CloudflareAccount::OK])>{{ $a->status === \App\Models\CloudflareAccount::OK ? __('Düzenle') : __('Anahtarı yenile') }}</button>
                                        <button type="button" wire:click="remove({{ $a->id }})" class="text-muted hover:text-crit">{{ __('Kaldır') }}</button>
                                    </span>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center">
                                <p class="font-medium">{{ __('Henüz Cloudflare hesabı bağlanmadı') }}</p>
                                <p class="mt-1 text-[13px] text-muted">{{ __('Bir hesap bağlayınca "Site ekle" alan adını o hesapta arar ve kayıtları Cloudflare\'e yazar.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="saveDefaults" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <h2 class="text-[14.5px] font-semibold">{{ __('Varsayılanlar') }}</h2>
            <div class="mt-2 divide-y divide-line">
                <fieldset class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <legend class="contents"><span class="sm:pt-0.5">{{ __('Cloudflare vekili') }}</span></legend>
                    <div class="space-y-2.5">
                        <label class="flex items-start gap-2.5">
                            <input type="radio" wire:model="proxySites" value="1" @checked($proxySites) class="mt-0.5 size-4 accent-accent">
                            <span><span class="block">{{ __('Site adresleri vekil üzerinden') }}</span>
                                <span class="block text-2xs text-muted">{{ __('Alan adı ve www turuncu bulutla açılır; posta, webmail, FTP ve panel adresleri her zaman yalnızca DNS kalır.') }}</span></span>
                        </label>
                        <label class="flex items-start gap-2.5">
                            <input type="radio" wire:model="proxySites" value="0" @checked(! $proxySites) class="mt-0.5 size-4 accent-accent">
                            <span><span class="block">{{ __('Hiçbiri vekil üzerinden değil') }}</span>
                                <span class="block text-2xs text-muted">{{ __('Kayıtlar gri bulutla yazılır; vekili kayıt başına açarsınız.') }}</span></span>
                        </label>
                    </div>
                </fieldset>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('SSL modu') }}</span>
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" wire:model="strictSsl" class="mt-0.5 size-4 accent-accent">
                        <span><span class="block">{{ __('Cloudflare\'in SSL modunu Full (strict) yap') }}</span>
                            <span class="block text-2xs text-muted">{{ __('Site sertifikasını Serfex Let\'s Encrypt\'ten alır, ardından mod Full (strict) yapılır. Esnek (Flexible) mod Serfex\'in HTTPS yönlendirmesiyle sonsuz döngüye girer.') }}</span></span>
                    </label>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('Laravel dağıtımı') }}</span>
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" wire:model="purgeAfterDeploy" class="mt-0.5 size-4 accent-accent">
                        <span><span class="block">{{ __('Dağıtımdan sonra Cloudflare önbelleğini temizle') }}</span>
                            <span class="block text-2xs text-muted">{{ __('Yalnızca o sitenin adresleri temizlenir. Kapalıyken Site → Önbellek ve hız sayfasından elle temizlenir.') }}</span></span>
                    </label>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('Ziyaretçinin gerçek IP\'si') }}</span>
                    <div>
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-2xs font-medium text-ok"><span class="size-1.5 rounded-full bg-ok"></span>{{ __('Açık') }}</span>
                            {{ __('Cloudflare\'den gelen isteklerde ziyaretçi adresi kullanılır.') }}
                        </p>
                        <p class="mt-1.5 text-2xs text-muted">
                            <x-t text="Günlükler, istatistikler, güvenlik duvarı engelleri, WAF ve Laravel'in :ip değeri gerçek ziyaretçiyi görür. Cloudflare'in adresleri hiçbir zaman engellenmez. Adres listesi haftada bir güncellenir (son: :when)."><x-slot:ip><span class="font-mono">request()->ip()</span></x-slot:ip><x-slot:when>{{ $ipsUpdated ? \App\Support\When::date(\Illuminate\Support\Carbon::parse($ipsUpdated)) : __('henüz yok') }}</x-slot:when></x-t>
                        </p>
                    </div>
                </div>
            </div>
            @unless ($readOnly)
                <button type="submit" class="mt-3 h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            @endunless
        </form>
    </section>

    {{-- Hesap bağla / düzenle --}}
    <x-modal :title="$accountId ? __('Cloudflare hesabını düzenle') : __('Cloudflare hesabı bağla')" show="editing" size="max-w-xl">
        <form wire:submit="save" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $accountId ? __('Cloudflare hesabını düzenle') : __('Cloudflare hesabı bağla') }}</h2>
            <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
                <div>
                    <label for="cf-name" class="mb-1.5 block">{{ __('Ad') }}</label>
                    <input id="cf-name" wire:model="name" required maxlength="60" placeholder="{{ __('Örneğin şirketin adı') }}" autocomplete="off"
                           class="{{ $field }} {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                    @error('name') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="cf-token" class="mb-1.5 block">{{ __('API anahtarı') }}</label>
                    <input id="cf-token" type="password" wire:model.blur="token" @required(! $accountId) autocomplete="off" spellcheck="false"
                           placeholder="{{ $accountId ? __('Değiştirmek için yeni anahtarı yapıştırın') : '' }}"
                           class="{{ $field }} font-mono text-[13px] {{ $errors->has('token') ? 'border-crit' : 'border-line' }}">
                    @error('token')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @enderror
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Cloudflare → My Profile → API Tokens → Create Token → "Edit zone DNS" şablonu, Zone Resources: All zones. Daha fazlası için şu izinleri de ekleyin: Zone Settings: Edit (SSL modu), Cache Purge: Purge, Zone: Edit (yeni alan adı ekleme), Account Settings: Read. Şifreli saklanır, bir daha gösterilmez.') }}</p>
                </div>
                @if ($checked !== null)
                    <div class="rounded-md border border-line">
                        <p class="border-b border-line px-3.5 py-2.5 font-medium">{{ __('Anahtar denetlendi') }}</p>
                        <ul class="space-y-2.5 px-3.5 py-3">
                            <li class="flex items-start gap-2.5">
                                <x-icon name="circle-check" class="mt-0.5 text-ok" />
                                <span><span class="block">{{ __('Anahtar geçerli') }}</span>@if ($checked['account'])<span class="block text-2xs text-muted">{{ $checked['account'] }}</span>@endif</span>
                            </li>
                            @foreach (\App\Models\CloudflareAccount::CHECKS as $key => $label)
                                @php $ok = $checked['checks'][$key] ?? false; @endphp
                                <li class="flex items-start gap-2.5">
                                    <x-icon :name="$ok ? 'circle-check' : 'circle-x'" @class(['mt-0.5', 'text-ok' => $ok, 'text-muted' => ! $ok]) />
                                    <span>
                                        <span class="block">{{ __($label) }}</span>
                                        <span class="block text-2xs text-muted">
                                            @switch($key)
                                                @case('dns') {{ $ok ? trans_choice(':count alan adı görünüyor', $checked['zones'], ['count' => $checked['zones']]) : __('Anahtar hiçbir alan adını görmüyor; Zone Resources ayarını denetleyin.') }} @break
                                                @case('ssl') {{ $ok ? __('SSL modu okunabiliyor; Full (strict) yapmak için Zone Settings: Edit gerekir.') : __('Yok: SSL modunu Cloudflare\'de elle Full (strict) yaparsınız.') }} @break
                                                @default {{ $ok ? __('Hesap görünüyor; Zone: Edit izniyle Cloudflare\'de olmayan alan adları eklenebilir.') : __('Yok: Cloudflare\'de olmayan bir alan adını Serfex ekleyemez; önce Cloudflare\'de eklersiniz.') }}
                                            @endswitch
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            <div class="flex shrink-0 items-center justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="test" wire:loading.attr="disabled" wire:target="test" class="mr-auto h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40">{{ __('Anahtarı denetle') }}</button>
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ $accountId ? __('Kaydet') : __('Bağla') }}</button>
            </div>
        </form>
    </x-modal>
</div>
