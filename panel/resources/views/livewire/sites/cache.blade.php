@php
    use App\Services\Sites\SiteCache;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $select = 'h-9 rounded-md border border-line bg-surface px-2 text-[13px] outline-none focus:border-accent disabled:opacity-50';
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
@endphp
<div class="space-y-6">
    <div>
        <x-site-header :site="$site" active="overview" :tool="__('Önbellek ve hız')" />
        <div class="mt-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h1 class="text-[18px] font-semibold tracking-tight">{{ __('Önbellek ve hız') }}</h1>
            @if ($saved['page'])
                <button type="button" wire:click="purge" wire:loading.attr="disabled" class="{{ $btn }}">
                    <x-icon name="trash-2" />
                    <span wire:loading.remove wire:target="purge">{{ __('Önbelleği temizle') }}</span>
                    <span wire:loading wire:target="purge">{{ __('Temizleniyor…') }}</span>
                </button>
            @endif
        </div>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Sayfaları PHP\'yi çalıştırmadan gönderin, resimleri ve betikleri ziyaretçinin tarayıcısında tutun, metinleri sıkıştırın. Ayarlar kaydedilmeden önce web sunucusunda denenir.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="divide-y divide-line px-5 text-[13.5px]">
            {{-- Sayfa önbelleği --}}
            <div class="{{ $row }}">
                <div>
                    <p class="font-medium">{{ __('Sayfa önbelleği') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ __('Nginx, PHP\'nin ürettiği sayfayı saklar ve tekrar gönderir') }}</p>
                </div>
                <div class="space-y-3">
                    <label @class(['flex items-start gap-2.5', 'opacity-50' => $blocked])>
                        <input type="checkbox" wire:model="page" @disabled($blocked && ! $saved['page']) class="mt-0.5 size-4 accent-accent">
                        <span>{{ __('Sayfaları önbelleğe al') }}
                            <span class="block text-[12.5px] text-muted">{{ $blocked ?? __('Yalnızca oturum açmamış ziyaretçilerin GET istekleri. Çerez kuran ya da uygulamanın "private" dediği yanıtlar, giriş, yönetim, sepet, ödeme ve API sayfaları hiç saklanmaz.') }}</span>
                        </span>
                    </label>
                    @error('page')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                    <div x-show="$wire.page" x-cloak class="space-y-3 border-l-2 border-line pl-4">
                        <label class="flex flex-wrap items-center gap-3">
                            <span class="w-40 text-muted">{{ __('Saklama süresi') }}</span>
                            <select wire:model="pageMinutes" class="{{ $select }}">
                                @foreach (SiteCache::PAGE_MINUTES as $minutes => $label)
                                    <option value="{{ $minutes }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div>
                            <label for="cache-exclude" class="block text-muted">{{ __('Önbelleğe alınmayacak yollar') }}</label>
                            <textarea id="cache-exclude" wire:model="exclude" rows="3" spellcheck="false" placeholder="/magaza/sepet&#10;/uye" class="mt-1.5 w-full max-w-md rounded-md border bg-surface px-3 py-2 font-mono text-[12.5px] outline-none focus:border-accent {{ $errors->has('exclude') ? 'border-crit' : 'border-line' }}"></textarea>
                            @error('exclude')
                                <p class="mt-1 text-[13px] text-crit">{{ $message }}</p>
                            @else
                                <p class="mt-1 text-2xs text-muted"><x-t text="Her satıra bir yol; o yolla başlayan adresler saklanmaz. :paths ve benzerleri zaten hariç."><x-slot:paths><span class="font-mono">/wp-admin/ /login /cart /checkout /api/</span></x-slot:paths></x-t></p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- Tarayıcı önbelleği --}}
            <div class="{{ $row }}">
                <div>
                    <p class="font-medium">{{ __('Tarayıcı önbelleği') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ __('Resim, CSS, JavaScript, yazı tipi ve video') }}</p>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" wire:model="browser" class="mt-0.5 size-4 accent-accent">
                        <span>{{ __('Tarayıcılar bu dosyaları saklasın') }}
                            <span class="block text-[12.5px] text-muted">{{ __('Tekrar gelen ziyaretçi bunları yeniden indirmez. Dosyayı değiştirdiğinizde adını ya da sürüm ekini (?v=2) değiştirin.') }}</span>
                        </span>
                    </label>
                    <label x-show="$wire.browser" x-cloak class="flex flex-wrap items-center gap-3 border-l-2 border-line pl-4">
                        <span class="w-40 text-muted">{{ __('Süre') }}</span>
                        <select wire:model="browserDays" class="{{ $select }}">
                            @foreach (SiteCache::BROWSER_DAYS as $days => $label)
                                <option value="{{ $days }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>

            {{-- Sıkıştırma --}}
            <div class="{{ $row }}">
                <div>
                    <p class="font-medium">{{ __('Sıkıştırma') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">gzip</p>
                </div>
                <label class="flex items-start gap-2.5">
                    <input type="checkbox" wire:model="gzip" class="mt-0.5 size-4 accent-accent">
                    <span>{{ __('Metinleri sıkıştırarak gönder') }}
                        <span class="block text-[12.5px] text-muted">{{ __('HTML, CSS, JavaScript, JSON, SVG ve yazı tipleri genellikle üçte bir boyuta iner.') }}</span>
                    </span>
                </label>
            </div>

            {{-- Durum --}}
            @if ($saved['page'])
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Önbellekteki sayfalar') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('En çok 256 MB; az kullanılanlar önce silinir') }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <p>
                            @if ($usage === null)
                                <span class="text-muted">{{ __('Sunucu ajanına ulaşılamıyor.') }}</span>
                            @elseif ($usage['files'] === 0)
                                <span class="text-muted">{{ __('Henüz sayfa yok; ilk ziyaretlerle dolar.') }}</span>
                            @else
                                {{ trans_choice(':count sayfa', $usage['files'], ['count' => \App\Support\Num::format($usage['files'], 0)]) }} · {{ \App\Support\Bytes::format($usage['bytes']) }}
                            @endif
                        </p>
                        <p class="text-2xs text-muted"><x-t text="Bir sayfanın önbellekten gelip gelmediğini :header yanıt başlığı söyler: HIT önbellekten, MISS yeni üretildi, BYPASS hiç saklanmaz."><x-slot:header><span class="font-mono">X-Serfex-Cache</span></x-slot:header></x-t></p>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 py-4 sm:pl-[244px]">
                <button type="submit" wire:loading.attr="disabled" class="{{ $primary }}">
                    <span wire:loading.remove wire:target="save">{{ __('Kaydet') }}</span>
                    <span wire:loading wire:target="save">{{ __('Uygulanıyor…') }}</span>
                </button>
                <p class="text-2xs text-muted">{{ __('İçeriği değiştirdikten sonra hemen görünmesi için önbelleği temizleyin; yoksa süre dolunca kendiliğinden yenilenir.') }}</p>
            </div>
        </form>
    </section>
</div>
