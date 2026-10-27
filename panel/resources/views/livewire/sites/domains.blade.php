@php
    use App\Livewire\Sites\SiteDomainList;
    use App\Models\SiteRedirect;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $scheme = $site->hasHttps() ? 'https' : 'http';
    $siteDir = $site->document_root ? dirname($site->document_root) : '/home/'.$site->systemUser->name.'/web/'.$site->domain;
    $zoneLink = function (string $name) use ($zones) {
        $zone = $zones->holding($name);
        return $zone && auth()->user()->allows('dns') ? $zone : null;
    };
@endphp
<div class="space-y-6">
    @if (collect($site->hostnames())->contains(fn ($n) => $state($n)['badge']->isBusy()))
        <div wire:poll.4s hidden></div>
    @endif

    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Alan adları') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Alan adları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">
            <x-t text=":domain sitesinin yanıt verdiği diğer adlar. Her yeni ad için DNS kaydı, bölgesi bu sunucudaysa kendiliğinden yazılır; DNS'i bu sunucuya işaret ettiğinde sitenin Let's Encrypt sertifikasına eklenir."><x-slot:domain><span class="font-mono text-fg">{{ $site->domain }}</span></x-slot:domain></x-t>
        </p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (SiteDomainList::TABS as $key => $label)
                <button type="button" role="tab" wire:click="switchTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'alt')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openSubdomain" @disabled($subdomainRefusal) title="{{ $subdomainRefusal }}" class="{{ $primary }}"><x-icon name="plus" />{{ __('Alt alan adı ekle') }}</button>
                @if ($subdomainRefusal)
                    <span class="text-[13px] text-muted">{{ $subdomainRefusal }}</span>
                @endif
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[680px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Ad') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Belge kökü') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Durum') }}</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($subdomains as $sub)
                            @php $s = $state($sub->name); @endphp
                            <tr wire:key="sub-{{ $sub->id }}" class="hover:bg-surface-2/60">
                                <td class="max-w-0 py-3 pr-3 pl-5 sm:w-[34%]">
                                    <a href="{{ $scheme }}://{{ $sub->name }}" target="_blank" rel="noopener" class="block font-mono break-all sm:truncate sm:break-normal text-[13px] hover:text-accent">{{ $sub->name }}</a>
                                    <span class="mt-0.5 block font-mono break-all sm:truncate sm:break-normal text-2xs text-muted md:hidden">{{ $sub->web_root }}</span>
                                    <span class="mt-1 block sm:hidden"><x-status-badge :status="$s['badge']" /></span>
                                </td>
                                <td class="hidden max-w-0 px-3 py-3 md:table-cell">
                                    <span class="block truncate font-mono text-[12.5px]" title="{{ $siteDir }}/{{ $sub->web_root }}">{{ $sub->web_root }}</span>
                                    <span class="text-2xs text-muted">{{ $sub->insideMainSite() ? __('Ana sitenin içinde') : __('Kendi klasörü') }}</span>
                                </td>
                                <td class="hidden px-3 py-3 sm:table-cell" title="{{ $s['hint'] }}">
                                    <x-status-badge :status="$s['badge']" />
                                </td>
                                <td class="w-px py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    <a href="{{ route('sites.files', ['site' => $site, 'yol' => $sub->web_root]) }}" wire:navigate class="hidden text-[13px] text-accent hover:underline sm:inline">{{ __('Dosyalar') }}</a>
                                    <button type="button" wire:click="confirmRemove('subdomain', {{ $sub->id }})" class="text-[13px] sm:ml-3 text-crit hover:underline">{{ __('Kaldır') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-12 text-center">
                                    <p class="font-medium">{{ __('Henüz alt alan adı yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('blog.:domain gibi bir adı kendi klasöründen ya da ana sitenin bir klasöründen yayınlayın.', ['domain' => $site->domain]) }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="num border-t border-line px-5 py-3 text-2xs text-muted">
                {{ __(':text · Alt alan adları sitenin PHP sürümünü, kullanıcısını ve günlüklerini paylaşır.', ['text' => $subdomainUsage ? __(':text alt alan adı (paket)', ['text' => $subdomainUsage['text']]) : __(':count alt alan adı', ['count' => $subdomains->count()])]) }}
            </p>
        @elseif ($tab === 'ek')
            @php $aliases = $site->aliases ?? []; @endphp
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openAlias" @disabled($aliasRefusal) title="{{ $aliasRefusal }}" class="{{ $primary }}"><x-icon name="plus" />{{ __('Ek alan adı ekle') }}</button>
                @if ($aliasRefusal)
                    <span class="text-[13px] text-muted">{{ $aliasRefusal }}</span>
                @endif
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[680px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Ad') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">DNS</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Durum') }}</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($aliases as $alias)
                            @php $s = $state($alias); $zone = $zoneLink($alias); @endphp
                            <tr wire:key="alias-{{ $alias }}" class="hover:bg-surface-2/60">
                                <td class="max-w-0 py-3 pr-3 pl-5 sm:w-[34%]">
                                    <a href="{{ $scheme }}://{{ $alias }}" target="_blank" rel="noopener" class="block font-mono break-all sm:truncate sm:break-normal text-[13px] hover:text-accent">{{ $alias }}</a>
                                    @if ($alias === 'www.'.$site->domain)
                                        <span class="text-2xs text-muted">{{ __('Paket sınırına sayılmaz') }}</span>
                                    @endif
                                    <span class="mt-1 block sm:hidden"><x-status-badge :status="$s['badge']" /></span>
                                </td>
                                <td class="hidden px-3 py-3 md:table-cell">
                                    @if ($zone)
                                        <a href="{{ route('dns.show', $zone) }}" wire:navigate class="font-mono text-[12.5px] text-accent hover:underline">{{ $zone->name }}</a>
                                        <span class="block text-2xs text-muted">{{ __('Bu sunucuda') }}</span>
                                    @else
                                        <span class="text-muted">{{ __('Başka yerde') }}</span>
                                    @endif
                                </td>
                                <td class="hidden px-3 py-3 sm:table-cell" title="{{ $s['hint'] }}">
                                    <x-status-badge :status="$s['badge']" />
                                </td>
                                <td class="w-px py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="confirmRemove('alias', @js($alias))" class="text-[13px] text-crit hover:underline">{{ __('Kaldır') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-12 text-center">
                                    <p class="font-medium">{{ __('Ek alan adı yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('Başka bir alan adının aynı siteyi açmasını istiyorsanız buraya ekleyin.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="num border-t border-line px-5 py-3 text-2xs text-muted">
                {{ __(':text · Ek alan adları sitenin dosyalarını ve ayarlarını aynen kullanır.', ['text' => $aliasUsage ? __(':text ek alan adı ve yönlendirilen alan adı (paket)', ['text' => $aliasUsage['text']]) : __(':aliases ek alan adı', ['aliases' => count($aliases)])]) }}
            </p>
        @else
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openRedirect('domain')" @disabled($aliasRefusal) title="{{ $aliasRefusal }}" class="{{ $primary }}"><x-icon name="plus" />{{ __('Alan adı yönlendir') }}</button>
                <button type="button" wire:click="openRedirect('path')" class="{{ $btn }}"><x-icon name="corner-up-right" />{{ __('Yol yönlendir') }}</button>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[720px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Kaynak') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Hedef') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Tür') }}</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($redirects as $redirect)
                            <tr wire:key="redirect-{{ $redirect->id }}" class="hover:bg-surface-2/60">
                                <td class="max-w-0 py-3 pr-3 pl-5 sm:w-[32%]">
                                    @if ($redirect->isDomain())
                                        @php $s = $state($redirect->source); @endphp
                                        <span class="block font-mono break-all sm:truncate sm:break-normal text-[13px]">{{ $redirect->source }}</span>
                                        <span class="text-2xs text-muted">{{ $redirect->with_www ? __('www ile birlikte · ') : '' }}</span><span class="text-2xs" title="{{ $s['hint'] }}"><x-status-badge :status="$s['badge']" /></span>
                                    @else
                                        <span class="block font-mono break-all sm:truncate sm:break-normal text-[13px]"><span class="text-muted">{{ $site->domain }}</span>{{ $redirect->source }}</span>
                                        <span class="text-2xs text-muted">{{ __('Sitedeki yol ve altındakiler') }}</span>
                                    @endif
                                    <span class="mt-0.5 block font-mono break-all sm:truncate sm:break-normal text-2xs text-muted sm:hidden">→ {{ $redirect->target }}</span>
                                </td>
                                <td class="hidden max-w-0 px-3 py-3 sm:table-cell sm:w-[34%]">
                                    <span class="block truncate font-mono text-[12.5px]" title="{{ $redirect->target }}">{{ $redirect->target }}</span>
                                </td>
                                <td class="hidden px-3 py-3 whitespace-nowrap md:table-cell">
                                    {{ SiteRedirect::CODES[$redirect->code] ?? $redirect->code }}
                                    <span class="block text-2xs text-muted">{{ $redirect->keep_path ? __('Yolu korur') : __('Hep aynı adrese') }}</span>
                                </td>
                                <td class="w-px py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="openEditRedirect({{ $redirect->id }})" class="text-[13px] text-accent hover:underline">{{ __('Düzenle') }}</button>
                                    <button type="button" wire:click="confirmRemove('redirect', {{ $redirect->id }})" class="ml-3 text-[13px] text-crit hover:underline">{{ __('Kaldır') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-12 text-center">
                                    <p class="font-medium">{{ __('Yönlendirme yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('Eski bir alan adını bu siteye, ya da sitedeki bir yolu başka bir adrese gönderin.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Kalıcı (301) yönlendirmeyi tarayıcılar ve arama motorları hatırlar; deneme yaparken Geçici (302) seçin.') }}</p>
        @endif
    </section>

    @if ($certificateBehind)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface px-5 py-3 text-[13px]">
            <x-icon name="shield-check" class="text-muted" />
            <span class="min-w-0 flex-1 basis-64 text-muted">{{ __('Sitenin sertifikası bazı adları henüz kapsamıyor. Serfex, DNS\'leri bu sunucuya işaret edince her saat yeniden dener.') }}</span>
            <button type="button" wire:click="updateCertificate" class="{{ $btn }}"><x-icon name="refresh-cw" class="size-3.5" />{{ __('Sertifikayı şimdi güncelle') }}</button>
        </div>
    @endif

    {{-- Alt alan adı ekle --}}
    <x-modal :title="__('Alt alan adı ekle')" show="adding === 'subdomain'" close="$wire.adding = null">
        <form wire:submit="saveSubdomain">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Alt alan adı ekle') }}</h2>
            <div class="grid items-start gap-x-4 gap-y-1.5 p-5 text-[13.5px] sm:grid-cols-[7rem_minmax(0,1fr)]">
                <label for="sub-label" class="sm:pt-2">{{ __('Ad') }}</label>
                <div>
                    <div class="flex items-center rounded-md border bg-surface focus-within:border-accent {{ $errors->has('label') ? 'border-crit' : 'border-line' }}">
                        <input id="sub-label" wire:model.live.debounce.300ms="label" required spellcheck="false" autocomplete="off" placeholder="{{ __('blog') }}" class="h-9 min-w-0 flex-1 bg-transparent px-3 font-mono text-[13px] outline-none">
                        <span class="truncate pr-3 font-mono text-[13px] text-muted">.{{ $site->domain }}</span>
                    </div>
                    @error('label') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>

                <span class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Belge kökü') }}</span>
                <div class="space-y-2" role="radiogroup">
                    @foreach (['own' => [__('Kendi klasörü'), __('Ana siteden ayrı dosyalar')], 'inside' => [__('Ana sitenin içinde'), __('Ana sitenin bir klasörünü yayınlar')], 'custom' => [__('Başka bir klasör'), __('Site klasöründe bir yol yazın')]] as $mode => [$name, $hint])
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-md border px-3 py-2 {{ $rootMode === $mode ? 'border-accent bg-accent-soft/40' : 'border-line hover:bg-surface-2' }}">
                            <input type="radio" wire:model.live="rootMode" value="{{ $mode }}" class="mt-0.5 size-4 accent-accent">
                            <span><span class="font-medium">{{ $name }}</span><span class="block text-2xs text-muted">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                    @if ($rootMode === 'custom')
                        <input wire:model.live.debounce.300ms="customRoot" aria-label="{{ __('Klasör') }}" spellcheck="false" autocomplete="off" placeholder="public_html/magaza" class="{{ $field }} font-mono {{ $errors->has('customRoot') ? 'border-crit' : 'border-line' }}">
                    @endif
                    @error('customRoot')
                        <p class="text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="font-mono text-2xs break-all text-muted">{{ $siteDir }}/{{ $previewRoot }}</p>
                    @enderror
                </div>
            </div>
            <p class="px-5 pb-1 text-2xs text-muted">{{ __('Klasör yoksa oluşturulur ve içine bir karşılama sayfası konur. Alt alan adı sitenin PHP sürümü ve kullanıcısıyla çalışır.') }}</p>
            <div class="mt-3 flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="closeForm" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="{{ $primary }}">{{ __('Ekle') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Ek alan adı ekle --}}
    <x-modal :title="__('Ek alan adı ekle')" show="adding === 'alias'" close="$wire.adding = null">
        <form wire:submit="saveAlias">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Ek alan adı ekle') }}</h2>
            <div class="grid items-start gap-x-4 gap-y-1.5 p-5 text-[13.5px] sm:grid-cols-[7rem_minmax(0,1fr)]">
                <label for="alias-name" class="sm:pt-2">{{ __('Alan adı') }}</label>
                <div>
                    <input id="alias-name" wire:model.live.debounce.400ms="aliasName" required spellcheck="false" autocomplete="off" placeholder="{{ __('ornek.com') }}" class="{{ $field }} font-mono {{ $errors->has('aliasName') ? 'border-crit' : 'border-line' }}">
                    @error('aliasName')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-2xs text-muted"><x-t text="Bu ad :domain ile aynı dosyaları açar."><x-slot:domain><span class="font-mono">{{ $site->domain }}</span></x-slot:domain></x-t></p>
                    @enderror
                </div>

                <span class="mt-2.5 sm:mt-0 sm:pt-2">DNS</span>
                <div class="text-[13px] sm:pt-2">
                    @if ($aliasZone)
                        <p><x-t text="Adres kayıtları :name bölgesine yazılır."><x-slot:name><span class="font-mono">{{ $aliasZone->name }}</span></x-slot:name></x-t></p>
                    @elseif ($canDns)
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="createZone" class="mt-0.5 size-4 accent-accent">
                            <span>{{ __('Bu alan adı için DNS bölgesi oluştur') }}<span class="block text-2xs text-muted">{{ __('Alan adının ad sunucularını bu sunucuya yönlendirdiğinizde çalışır.') }}</span></span>
                        </label>
                    @else
                        <p class="text-muted">{{ __('Alan adının A kaydını bu sunucunun adresine yönlendirin.') }}</p>
                    @endif
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="closeForm" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="{{ $primary }}">{{ __('Ekle') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Yönlendirme ekle / düzenle --}}
    <x-modal :title="__('Yönlendirme')" show="adding === 'redirect'" close="$wire.adding = null">
        <form wire:submit="saveRedirect">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $editRedirectId ? __('Yönlendirmeyi düzenle') : ($redirectKind === 'domain' ? __('Alan adı yönlendir') : __('Yol yönlendir')) }}</h2>
            <div class="grid items-start gap-x-4 gap-y-1.5 p-5 text-[13.5px] sm:grid-cols-[7rem_minmax(0,1fr)]">
                <label for="redirect-source" class="sm:pt-2">{{ $redirectKind === 'domain' ? __('Alan adı') : __('Yol') }}</label>
                <div>
                    @if ($redirectKind === 'domain')
                        <input id="redirect-source" wire:model.live.debounce.400ms="source" @disabled($editRedirectId) required spellcheck="false" autocomplete="off" placeholder="{{ __('eski-alanadi.com') }}" class="{{ $field }} font-mono disabled:bg-surface-2 {{ $errors->has('source') ? 'border-crit' : 'border-line' }}">
                        @unless ($editRedirectId)
                            <label class="mt-2 flex items-center gap-2 text-[13px]">
                                <input type="checkbox" wire:model="withWww" class="size-4 accent-accent">
                                <span>{{ __('www ile başlayan adı da yönlendir') }}</span>
                            </label>
                        @endunless
                    @else
                        <div class="flex items-center rounded-md border bg-surface focus-within:border-accent {{ $errors->has('source') ? 'border-crit' : 'border-line' }} {{ $editRedirectId ? 'bg-surface-2' : '' }}">
                            <span class="truncate pl-3 font-mono text-[13px] text-muted">{{ $site->domain }}</span>
                            <input id="redirect-source" wire:model="source" @disabled($editRedirectId) required spellcheck="false" autocomplete="off" placeholder="{{ __('/eski-sayfa') }}" class="h-9 min-w-0 flex-1 bg-transparent pr-3 font-mono text-[13px] outline-none">
                        </div>
                    @endif
                    @error('source')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        @if ($redirectKind === 'path')
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Altındaki adresler de yönlendirilir (/eski-sayfa/alt gibi). Sitenin tüm adlarında geçerlidir.') }}</p>
                        @elseif (! $editRedirectId && $redirectZone)
                            <p class="mt-1.5 text-2xs text-muted"><x-t text="Adres kayıtları :name bölgesine yazılır."><x-slot:name><span class="font-mono">{{ $redirectZone->name }}</span></x-slot:name></x-t></p>
                        @elseif (! $editRedirectId && $canDns)
                            <label class="mt-2 flex items-start gap-2 text-[13px]">
                                <input type="checkbox" wire:model="createZone" class="mt-0.5 size-4 accent-accent">
                                <span>{{ __('Bu alan adı için DNS bölgesi oluştur') }}</span>
                            </label>
                        @endif
                    @enderror
                </div>

                <label for="redirect-target" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Hedef') }}</label>
                <div>
                    <input id="redirect-target" wire:model="target" required spellcheck="false" autocomplete="off" placeholder="{{ $redirectKind === 'domain' ? 'https://'.$site->domain : __('/yeni-sayfa veya https://…') }}" class="{{ $field }} font-mono {{ $errors->has('target') ? 'border-crit' : 'border-line' }}">
                    @error('target') <p class="mt-1.5 text-[13px] break-words text-crit">{{ $message }}</p> @enderror
                </div>

                <label for="redirect-code" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Tür') }}</label>
                <div>
                    <select id="redirect-code" wire:model="code" class="{{ $field }} border-line sm:w-48">
                        @foreach (SiteRedirect::CODES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <span class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Adresin devamı') }}</span>
                <label class="flex items-start gap-2 text-[13px] sm:pt-2">
                    <input type="checkbox" wire:model="keepPath" class="mt-0.5 size-4 accent-accent">
                    <span>{{ __('İstenen yolu hedefin sonuna ekle') }}
                        <span class="block text-2xs text-muted">{{ $redirectKind === 'domain' ? __('eski-alanadi.com/urun/5 → hedef/urun/5') : __('/eski-sayfa/alt → hedef/alt') }}</span>
                    </span>
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="closeForm" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="{{ $primary }}">{{ $editRedirectId ? __('Kaydet') : __('Ekle') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Kaldırma onayı --}}
    <x-modal :title="__('Kaldır')" show="removing" close="$wire.removing = null" size="max-w-md">
        @if ($removing)
            @php
                [$rk, $rkey] = $removing;
                $rname = match ($rk) {
                    'subdomain' => $subdomains->find($rkey)?->name,
                    'alias' => $rkey,
                    default => $redirects->find($rkey)?->source,
                };
            @endphp
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold"><x-t text=":rname kaldırılsın mı?"><x-slot:rname><span class="font-mono">{{ $rname }}</span></x-slot:rname></x-t></p>
                <p class="mt-1 text-[13px] text-muted">
                    @if ($rk === 'subdomain')
                        {{ __('Ad artık yanıt vermez. Klasörü ve dosyaları silinmez.') }}
                    @elseif ($rk === 'alias')
                        {{ __('Bu ad artık siteyi açmaz. Bu sunucuya yazılan adres kayıtları da kaldırılır; alan adının kendi DNS bölgesi durur.') }}
                    @else
                        {{ __('Yönlendirme durur.') }}
                    @endif
                    {{ __('10 dakika içinde geri alabilirsiniz.') }}
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('removing', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="remove" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Kaldır') }}</button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
