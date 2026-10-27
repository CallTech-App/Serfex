@php
    use App\Models\LaravelApp;
    use App\Support\Badge;
    $field = 'rounded-md border bg-surface outline-none focus:border-accent disabled:opacity-60';
    $nginxMode = $mode !== 'app';
@endphp
<form wire:submit="{{ $down ? 'save' : 'down' }}">
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4 text-[13.5px]">
        <x-status-badge :status="$down ? new Badge(__('Bakım modunda'), 'warn') : new Badge(__('Uygulama açık'), 'ok')" />
        <span class="text-[13px] text-muted">
            @if ($down && $nginx)
                {{ __('Ziyaretçiler :maintenance_mode görüyor; uygulama, kuyruklar ve zamanlayıcı çalışmaya devam ediyor.', ['maintenance_mode' => $app->maintenance_mode === 'custom' ? __('özel bakım sayfasını') : __('Serfex bakım sayfasını')]) }}
            @elseif ($down)
                <x-t text="Ziyaretçiler uygulamanın 503 sayfasını görüyor (:part)."><x-slot:part><span class="font-mono">artisan down</span></x-slot:part></x-t>
            @else
                {{ __('Sıfır kesintili dağıtımda bakım moduna gerek yoktur.') }}
            @endif
        </span>
    </div>
    <div class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-[13.5px] md:grid-cols-[230px_1fr]">
        <label for="m-mode" class="font-medium md:pt-2">{{ __('Ziyaretçinin göreceği sayfa') }}</label>
        <div class="max-w-xl">
            <select id="m-mode" wire:model.live="mode" @disabled($down || ! $operate) class="{{ $field }} h-9 w-full border-line px-2.5">
                <option value="serfex">{{ __('Serfex bakım sayfası') }}</option>
                <option value="custom">{{ __('Özel HTML') }}</option>
                <option value="app">{{ __('Uygulamanın kendi sayfası (resources/views/errors/503.blade.php)') }}</option>
            </select>
            <p class="mt-1 text-2xs text-muted">{{ $nginxMode ? __('Sayfayı Nginx gösterir; uygulama durmaz, izinli adresler ve atlama bağlantısı siteyi normal görür.') : __('php artisan down çalışır; kuyruk işçileri de bekler. IP izin listesi bu seçenekte yoktur.') }}</p>
        </div>

        @if ($mode === 'custom')
            <label for="m-html" class="font-medium md:pt-2">{{ __('Özel sayfa') }}</label>
            <div class="max-w-xl">
                <textarea id="m-html" wire:model="html" rows="6" spellcheck="false" @disabled(! $operate) placeholder="{{ __("<!doctype html>\n<title>Bakımdayız</title>\n<h1>Kısa bir bakım yapıyoruz</h1>") }}"
                          class="{{ $field }} w-full px-3 py-2 font-mono text-[12.5px] {{ $errors->has('html') ? 'border-crit' : 'border-line' }}"></textarea>
                @error('html')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Tek dosyalık HTML; görseller ve stiller sayfanın içinde ya da başka bir adreste olmalı. 503 durum koduyla gönderilir.') }}</p>@enderror
            </div>
        @endif

        @if ($nginxMode)
            <label for="m-allow" class="font-medium md:pt-2">{{ __('İzin verilen IP adresleri') }}</label>
            <div class="max-w-xl">
                <textarea id="m-allow" wire:model="allow" rows="3" spellcheck="false" @disabled(! $operate) placeholder="78.186.41.12&#10;10.0.0.0/24"
                          class="{{ $field }} w-full px-3 py-2 font-mono text-[13px] {{ $errors->has('allow') ? 'border-crit' : 'border-line' }}"></textarea>
                @error('allow')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else
                    <p class="mt-1 text-2xs text-muted">{{ __('Her satıra bir adres veya ağ. Bu adreslerden gelenler bakım sırasında siteyi normal görür.') }}
                        @if ($operate)<button type="button" wire:click="addMine" class="text-accent hover:underline">{{ __('Adresimi ekle (:visitor)', ['visitor' => $visitor]) }}</button>@endif
                    </p>
                @enderror
            </div>
        @endif

        <p class="font-medium md:pt-2">{{ __('Atlama bağlantısı') }}</p>
        <div class="max-w-xl" x-data="{ copied: false }">
            <div class="flex gap-2">
                <input readonly value="{{ $link }}" aria-label="{{ __('Atlama bağlantısı') }}" class="h-9 min-w-0 flex-1 rounded-md border border-line bg-surface-2 px-3 font-mono text-[13px]">
                <button type="button" @click="navigator.clipboard.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 2000)" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span></button>
                @if (! $down && $operate)
                    <button type="button" wire:click="regenerate" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">{{ __('Yenile') }}</button>
                @endif
            </div>
            @error('secret')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Bu bağlantıyı açan ekip üyeleri bakım sırasında uygulamayı normal görür (tarayıcıya bir çerez bırakılır).') }}</p>@enderror
        </div>
        <label for="m-retry" class="font-medium md:pt-2">{{ __('Tekrar deneme süresi') }}</label>
        <div class="max-w-xl">
            <div class="flex items-center gap-2"><input id="m-retry" type="number" min="0" wire:model="retry" @disabled($down || ! $operate) class="{{ $field }} num h-9 w-24 px-3 {{ $errors->has('retry') ? 'border-crit' : 'border-line' }}"><span class="text-[13px] text-muted"><x-t text="saniye (:header)"><x-slot:header><span class="font-mono">Retry-After</span></x-slot:header></x-t></span></div>
            @error('retry')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
        </div>
        @if ($operate)
            <div class="flex flex-wrap gap-2 md:col-start-2">
                @if ($down)
                    <button type="button" wire:click="up" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">{{ __('Bakımdan çıkar') }}</button>
                    @if ($nginx)<button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-60">{{ __('Değişiklikleri uygula') }}</button>@endif
                @else
                    <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-warn px-3.5 text-[13.5px] font-medium text-white hover:brightness-110 disabled:opacity-60">{{ __('Bakım moduna al') }}</button>
                @endif
            </div>
        @endif
    </div>
</form>
