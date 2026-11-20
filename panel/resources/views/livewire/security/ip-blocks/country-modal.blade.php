@php
    use App\Support\Countries;
    use App\Support\Num;
    $n = count($chosen);
    $blockedCount = count($blockedCodes);
    $title = $scopeOnly ? __('Kapsamı değiştir') : __('Ülke engelle');
    $scopes = [
        'web' => [__('Yalnızca web siteleri (80, 443)'), __('Ziyaretçi 403 sayfası görür. Posta, SSH, FTP ve panel etkilenmez; SSL sertifikası yenilemeleri çalışmaya devam eder.')],
        'server' => [__('Bütün sunucu'), __('Web sitelerine ek olarak SSH, FTP, panel ve e-posta girişleri (IMAP, POP3, SMTP) de kapanır. Gelen posta (25) ve DNS (53) açık kalır; o ülkelerdeki posta sunucularından gelen iletiler kaybolmaz. Webmail sayfası açılmaya devam eder.')],
    ];
@endphp
{{-- Ülke engelle / Kapsamı değiştir: the countries, and what is closed to them. --}}
<x-modal :title="$title" show="blockingCountries" size="max-w-2xl">
    <form wire:submit="saveCountries" class="flex max-h-[calc(100vh-2rem)] flex-col">
        <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $title }}</h2>
        <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
            @unless ($scopeOnly)
                <div class="grid gap-2 sm:grid-cols-[110px_1fr] sm:items-start">
                    <label for="country-query" class="sm:pt-2.5">{{ __('Ülkeler') }}</label>
                    <div>
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                            <div @class(['flex min-h-10 flex-wrap items-center gap-1.5 rounded-md border bg-surface px-1.5 py-1.5 focus-within:border-accent', 'border-crit' => $errors->has('countryCodes'), 'border-line' => ! $errors->has('countryCodes')])>
                                @foreach ($chosen as $code)
                                    <span wire:key="chosen-{{ $code }}" class="inline-flex h-7 items-center gap-1.5 rounded border border-line bg-surface-2 pr-1 pl-2 text-[13px]">
                                        <span class="font-mono text-2xs font-medium text-muted">{{ $code }}</span>{{ Countries::name($code) }}
                                        <button type="button" wire:click="dropCountry('{{ $code }}')" class="grid size-5 place-items-center rounded text-muted hover:bg-surface hover:text-fg" aria-label="{{ __(':country listeden çıkar', ['country' => Countries::name($code)]) }}"><x-icon name="x" class="size-3.5" /></button>
                                    </span>
                                @endforeach
                                <input id="country-query" type="text" wire:model.live.debounce.200ms="countryQuery" placeholder="{{ __('Ülke ekle…') }}" spellcheck="false" autocomplete="off"
                                       @focus="open = true" @input="open = true" @keydown.escape.stop="open = false"
                                       @if ($suggestions !== []) @keydown.enter.prevent="$wire.addCountry('{{ $suggestions[0] }}')" @else @keydown.enter.prevent @endif
                                       class="h-7 min-w-32 flex-1 bg-transparent px-1.5 text-[13.5px] outline-none">
                            </div>
                            @if ($suggestions !== [])
                                <ul x-show="open" x-cloak class="absolute top-full right-0 left-0 z-20 mt-1 max-h-56 overflow-y-auto rounded-lg border border-line bg-surface py-1 shadow-lg" role="listbox">
                                    @foreach ($suggestions as $code)
                                        <li wire:key="suggest-{{ $code }}">
                                            <button type="button" wire:click="addCountry('{{ $code }}')" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-surface-2" role="option">
                                                <span class="w-7 shrink-0 rounded border border-line bg-surface-2 py-px text-center font-mono text-2xs font-medium">{{ $code }}</span>{{ Countries::name($code) }}
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (trim($countryQuery) !== '')
                                <p x-show="open" x-cloak class="absolute top-full right-0 left-0 z-20 mt-1 rounded-lg border border-line bg-surface px-3 py-2 text-[13px] text-muted shadow-lg">{{ __('Eklenebilecek böyle bir ülke yok.') }}</p>
                            @endif
                        </div>
                        @error('countryCodes') <p class="mt-1.5 text-[13px] break-words text-crit" role="alert">{{ $message }}</p> @enderror
                        @if ($n > 0)
                            <p class="num mt-1.5 text-2xs text-muted">{{ trans_choice('Son 30 günde bu :count ülkeden :requests istek, :visitors ziyaretçi geldi.', $n, ['count' => Num::format($n), 'requests' => Num::format($chosenTraffic['requests']), 'visitors' => Num::format($chosenTraffic['visitors'])]) }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Adını ya da iki harfli kodunu yazın; listeden işaretleyip de gelebilirsiniz.') }}</p>
                        @endif
                    </div>
                </div>
            @endunless

            <div class="grid gap-2 sm:grid-cols-[110px_1fr] sm:items-start">
                <span id="country-scope">{{ __('Kapsam') }}</span>
                <div role="radiogroup" aria-labelledby="country-scope">
                    <div class="space-y-3">
                        @foreach ($scopes as $key => [$label, $help])
                            <label class="flex cursor-pointer items-start gap-2.5">
                                <input type="radio" wire:model.live="countryScope" value="{{ $key }}" class="mt-0.5 size-4 shrink-0 accent-accent">
                                <span><span class="font-medium">{{ $label }}</span><span class="mt-0.5 block text-2xs text-muted">{{ $help }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @error('countryScope') <p class="mt-1.5 text-[13px] break-words text-crit" role="alert">{{ $message }}</p> @enderror
                    @if ($blockedCount > 0)
                        <p class="mt-3 text-2xs text-muted">{{ trans_choice('Kapsam engelli ülkelerin tümü için ortaktır; şu an engelli :count ülke de bu seçime uyar.', $blockedCount, ['count' => Num::format($blockedCount)]) }}</p>
                    @else
                        <p class="mt-3 text-2xs text-muted">{{ __('Kapsam engelli ülkelerin tümü için ortaktır.') }}</p>
                    @endif
                </div>
            </div>

            @if (in_array('US', $chosen, true))
                <div class="flex items-start gap-3 rounded-lg border border-line border-l-4 border-l-warn bg-warn-soft px-4 py-3" role="alert">
                    <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
                    <p><span class="font-medium">{{ __('ABD engellenirse arama motorları sitelerinizi göremez.') }}</span> {{ __('Google, Bing ve çoğu ödeme, kargo, izleme servisi ABD adreslerinden bağlanır. Tek tek gerekenleri İzinli adresler\'e ekleyebilirsiniz.') }}</p>
                </div>
            @endif

            <ul class="space-y-1.5 text-[13px] text-muted">
                <li class="flex items-start gap-2.5"><x-icon name="check" class="mt-0.5 shrink-0 text-ok" />{{ __('Her zaman geçenler: İzinli adresler (:count), sunucunun kendi adresleri ve Cloudflare\'in vekil adresleri.', ['count' => Num::format($allowedCount)]) }}</li>
                @if ($home !== null)
                    <li class="flex items-start gap-2.5"><x-icon name="check" class="mt-0.5 shrink-0 text-ok" />{{ __('Bağlı olduğunuz ülke (:country) engellenemez.', ['country' => Countries::name($home)]) }}</li>
                @endif
                <li class="flex items-start gap-2.5"><x-icon name="check" class="mt-0.5 shrink-0 text-ok" /><span><x-t text="Kendinizi dışarıda bırakırsanız sunucuda :command engellerin tümünü kaldırır."><x-slot:command><span class="font-mono text-[12.5px] text-fg">serfex firewall countries off</span></x-slot:command></x-t></span></li>
            </ul>
        </div>
        <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('blockingCountries', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
            @if ($scopeOnly)
                <button type="submit" wire:loading.attr="disabled" wire:target="saveCountries" class="{{ $primary }}">{{ __('Kaydet') }}</button>
            @else
                <button type="submit" wire:loading.attr="disabled" wire:target="saveCountries" @disabled($n === 0) class="{{ $danger }}"><x-icon name="ban" />{{ trans_choice(':count ülkeyi engelle', max($n, 1), ['count' => Num::format(max($n, 1))]) }}</button>
            @endif
        </div>
    </form>
</x-modal>
