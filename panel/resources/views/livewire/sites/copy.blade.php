@php
    use App\Models\SiteCopy;
    use App\Support\Badge;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $state = fn (SiteCopy $r) => match ($r->status) {
        'queued' => new Badge(__('Sırada'), 'info', true),
        'running' => new Badge(__('Sürüyor'), 'info', true),
        'succeeded' => new Badge(__('Bitti'), 'ok'),
        'undone' => $r->restore?->isRunning() ? new Badge(__('Geri alınıyor'), 'info', true) : new Badge(__('Geri alındı'), 'muted'),
        default => new Badge(__('Başarısız'), 'crit'),
    };
    $note = fn (array $n) => match ($n['code'] ?? '') {
        'db_not_in_config' => __(':subject veritabanının adı ayar dosyalarında bulunamadı; uygulamanın ayarlarına kopyanın veritabanını elle yazın.', ['subject' => $n['subject']]),
        'config_cache' => __('Laravel\'in önbelleğe alınmış ayarları silindi (:subject); uygulama ayarlarını .env dosyasından okur.', ['subject' => $n['subject'] === '.' ? __('site klasörü') : $n['subject']]),
        'links' => __(':subject sembolik bağlantı yeni siteye yönlendirildi.', ['subject' => $n['subject']]),
        'changed_while_reading' => __('Bazı dosyalar kopyalanırken değişti; gerekirse yeniden kopyalayın.'),
        default => $n['code'] ?? '',
    };
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
@endphp
<div class="space-y-6">
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Siteyi kopyala') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Siteyi kopyala') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">
            @if ($isCopy)
                <x-t text="Bu site :source sitesinin kopyası. Değişiklikleri burada deneyin; hazır olunca canlı siteye aktarın."><x-slot:source><a href="{{ route('sites.show', $source) }}" wire:navigate class="font-mono text-fg hover:text-accent">{{ $source->domain }}</a></x-slot:source></x-t>
            @else
                {{ __('Sitenin dosyalarını ve veritabanlarını ayrı bir adrese kopyalayın. Eklentileri, güncellemeleri ve tasarım değişikliklerini orada deneyin, hazır olunca canlı siteye aktarın.') }}
            @endif
        </p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        @if ($isCopy)
            <div class="divide-y divide-line px-5 text-[13.5px]">
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Kaynaktan yenile') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('Canlı siteden bu kopyaya') }}</p>
                    </div>
                    <div class="space-y-2">
                        <p class="text-[13px] text-muted"><x-t text="Bu kopyanın dosyaları ve veritabanları :source sitesinin şimdiki hâliyle değiştirilir. Kopyada yaptığınız değişiklikler gider."><x-slot:source><span class="font-mono text-fg">{{ $source->domain }}</span></x-slot:source></x-t></p>
                        @if ($refreshRefusal)
                            <p class="text-[13px] text-warn">{{ $refreshRefusal }}</p>
                        @endif
                        <button type="button" wire:click="openRun('refresh')" @disabled($refreshRefusal) class="{{ $btn }}"><x-icon name="refresh-cw" />{{ __('Kaynaktan yenile') }}</button>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Canlı siteye aktar') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('Bu kopyadan canlı siteye') }}</p>
                    </div>
                    <div class="space-y-2">
                        <p class="text-[13px] text-muted"><x-t text=":source sitesinin dosyaları ve veritabanları bu kopyayla değiştirilir. Önce canlı sitenin yedeği alınır; :days gün içinde tek tıkla geri alabilirsiniz."><x-slot:source><span class="font-mono text-fg">{{ $source->domain }}</span></x-slot:source><x-slot:days>{{ $safetyDays }}</x-slot:days></x-t></p>
                        @if ($publishRefusal)
                            <p class="text-[13px] text-warn">{{ $publishRefusal }}</p>
                        @endif
                        <button type="button" wire:click="openRun('publish')" @disabled($publishRefusal) class="{{ $btn }}"><x-icon name="upload" />{{ __('Canlı siteye aktar') }}</button>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Arama motorları') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('Kopyanın Google\'da görünmesini önler') }}</p>
                    </div>
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" wire:model="noindex" wire:change="saveNoindex" class="mt-0.5 size-4 accent-accent">
                        <span>{{ __('Arama motorlarından gizle') }}
                            <span class="block text-[12.5px] text-muted">{{ __('Her yanıta X-Robots-Tag: noindex eklenir; WordPress\'te "Arama motorlarını engelle" ayarı da açılır. Canlı siteye aktarırken canlı sitenin kendi ayarı korunur.') }}</span>
                        </span>
                    </label>
                </div>
            </div>
        @else
            <form wire:submit="create" class="divide-y divide-line px-5 text-[13.5px]">
                <div class="{{ $row }}">
                    <label for="copy-domain">
                        <span class="block font-medium">{{ __('Kopyanın adresi') }}</span>
                        <span class="mt-0.5 block text-2xs text-muted">{{ __('Ayrı bir site olarak açılır') }}</span>
                    </label>
                    <div>
                        <input id="copy-domain" wire:model="domain" autocomplete="off" spellcheck="false" class="h-9 w-full max-w-md rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('domain') ? 'border-crit' : 'border-line' }}">
                        @error('domain')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">
                                @if ($ip)
                                    <x-t text="Alan adının DNS'i bu sunucudaysa kayıt kendiliğinden eklenir. Başka yerdeyse bu ad için :ip adresine bir A kaydı ekleyin; SSL sertifikası kayıt yayılınca alınır."><x-slot:ip><span class="font-mono">{{ $ip }}</span></x-slot:ip></x-t>
                                @else
                                    {{ __('Alan adının DNS\'i bu sunucudaysa kayıt kendiliğinden eklenir; başka yerdeyse bu ad için sunucuya bir A kaydı ekleyin.') }}
                                @endif
                            </p>
                        @enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Kopyalananlar') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('Aynı sistem kullanıcısı ve PHP sürümü') }}</p>
                    </div>
                    <div class="space-y-2.5">
                        <label class="flex items-start gap-2.5 opacity-80">
                            <input type="checkbox" checked disabled class="mt-0.5 size-4 accent-accent">
                            <span>{{ __('Dosyalar') }}<span class="block text-[12.5px] text-muted">{{ __('Site klasörünün tamamı, günlükler hariç. Ayar dosyalarındaki adres ve veritabanı adları kopyaya göre düzeltilir.') }}</span></span>
                        </label>
                        <label @class(['flex items-start gap-2.5', 'opacity-50' => $databases->isEmpty()])>
                            <input type="checkbox" wire:model="withDatabases" @disabled($databases->isEmpty()) class="mt-0.5 size-4 accent-accent">
                            <span>{{ __('Veritabanları') }}
                                <span class="block text-[12.5px] text-muted">
                                    @if ($databases->isEmpty())
                                        {{ __('Bu sitenin veritabanı yok.') }}
                                    @else
                                        {{ __('Her biri _copy ekiyle yeni bir veritabanına kopyalanır; aynı veritabanı kullanıcıları aynı yetkiyle erişir:') }}
                                        <span class="font-mono text-fg">{{ $databases->pluck('name')->map(fn ($n) => $n.' → '.$n.'_copy')->join(', ') }}</span>
                                    @endif
                                </span>
                            </span>
                        </label>
                        <p class="text-2xs text-muted">{{ __('E-posta, zamanlanmış görevler, FTP hesapları ve kuyruk işçileri kopyalanmaz.') }}</p>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Arama motorları') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('Kopyanın Google\'da görünmesini önler') }}</p>
                    </div>
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" wire:model="noindex" class="mt-0.5 size-4 accent-accent">
                        <span>{{ __('Arama motorlarından gizle') }}<span class="block text-[12.5px] text-muted">{{ __('Önerilir: kopya canlı siteyle aynı içeriği taşır.') }}</span></span>
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-3 py-4 sm:pl-[244px]">
                    <button type="submit" wire:loading.attr="disabled" @disabled($createRefusal) class="{{ $primary }}">
                        <x-icon name="copy" />
                        <span wire:loading.remove wire:target="create">{{ __('Kopyayı oluştur') }}</span>
                        <span wire:loading wire:target="create">{{ __('Hazırlanıyor…') }}</span>
                    </button>
                    @if ($createRefusal)
                        <p class="text-[13px] text-warn">{{ $createRefusal }}</p>
                    @endif
                </div>
            </form>
        @endif

        @if (! $isCopy && $copies->isNotEmpty())
            <div class="border-t border-line">
                <p class="px-5 pt-3 text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Kopyalar') }}</p>
                <ul class="divide-y divide-line px-5 pb-2 text-[13px]">
                    @foreach ($copies as $c)
                        <li wire:key="copy-{{ $c->id }}" class="flex items-center gap-3 py-2">
                            <a href="{{ route('sites.copy', $c) }}" wire:navigate class="min-w-0 truncate font-mono hover:text-accent">{{ $c->domain }}</a>
                            <x-status-badge :status="$c->status" class="shrink-0" />
                            <span class="ml-auto shrink-0 text-2xs text-muted">{{ __('Oluşturuldu: :when', ['when' => \App\Support\When::withTime($c->created_at)]) }}</span>
                            <a href="{{ route('sites.copy', $c) }}" wire:navigate class="shrink-0 text-accent hover:underline">{{ __('Yenile ya da aktar') }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($shown)
            <div class="space-y-3 border-t border-line p-5 text-[13.5px]">
                <div class="flex flex-wrap items-center gap-2 text-[13px]">
                    <span class="font-medium">{{ $shown->label() }}</span>
                    <span class="font-mono text-muted">{{ $shown->domain }}</span>
                    <x-status-badge :status="$state($shown)" />
                </div>
                @if ($shown->error)
                    <p class="rounded-lg bg-crit-soft px-3 py-2 text-[13px] text-crit">{{ $shown->error }}</p>
                @endif
                @foreach ($shown->notes ?? [] as $n)
                    <p class="flex gap-2 text-[13px] text-warn"><x-icon name="info" class="mt-0.5 shrink-0" />{{ $note($n) }}</p>
                @endforeach
                <x-run-log :run="$shown->run" class="h-64" />
            </div>
        @endif

        @if ($runs->isNotEmpty())
            <div class="border-t border-line">
                <p class="px-5 pt-3 text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Son işlemler') }}</p>
                <ul class="divide-y divide-line px-5 pb-2 text-[13px]">
                    @foreach ($runs as $r)
                        <li wire:key="run-{{ $r->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <button type="button" wire:click="show({{ $r->id }})" class="min-w-0 truncate text-left {{ $shown?->id === $r->id ? 'text-accent' : 'hover:text-accent' }}">{{ $r->label() }} <span class="font-mono text-muted">{{ $r->domain }}</span></button>
                            <span class="ml-auto shrink-0 text-2xs text-muted">{{ \App\Support\When::withTime($r->created_at) }}</span>
                            @if ($undoable($r))
                                <button type="button" wire:click="undo({{ $r->id }})" wire:confirm="{{ __('Canlı site bu aktarmadan önceki yedeğinden geri yüklensin mi?') }}" class="shrink-0 text-accent hover:underline">{{ __('Geri al') }}</button>
                            @endif
                            <x-status-badge :status="$state($r)" class="shrink-0" />
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    @if ($isCopy)
        <x-modal :title="$confirming === 'publish' ? __('Canlı siteye aktar') : __('Kaynaktan yenile')" show="confirming" close="$wire.confirming = null">
            <form wire:submit="start">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $confirming === 'publish' ? __('Canlı siteye aktar') : __('Kaynaktan yenile') }}</h2>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p>
                        @if ($confirming === 'publish')
                            <x-t text=":from içeriği :to sitesine yazılır."><x-slot:from><span class="font-mono">{{ $site->domain }}</span></x-slot:from><x-slot:to><span class="font-mono font-semibold">{{ $source->domain }}</span></x-slot:to></x-t>
                        @else
                            <x-t text=":from içeriği bu kopyaya (:to) yazılır."><x-slot:from><span class="font-mono">{{ $source->domain }}</span></x-slot:from><x-slot:to><span class="font-mono font-semibold">{{ $site->domain }}</span></x-slot:to></x-t>
                        @endif
                    </p>
                    <div class="space-y-2.5">
                        <label class="flex items-start gap-2.5">
                            <input type="checkbox" wire:model="parts" value="files" class="mt-0.5 size-4 accent-accent">
                            <span>{{ __('Dosyalar') }}<span class="block text-[12.5px] text-muted">{{ __('Klasör tamamen değişir; günlükler kalır.') }}</span></span>
                        </label>
                        <label class="flex items-start gap-2.5">
                            <input type="checkbox" wire:model="parts" value="databases" class="mt-0.5 size-4 accent-accent">
                            <span>{{ __('Veritabanları') }}<span class="block text-[12.5px] text-muted">{{ $confirming === 'publish' ? __('Canlı veritabanları kopyadakilerle değişir; o arada gelen siparişler ve yorumlar gider.') : __('Kopyanın veritabanları canlı sitedekilerle değişir.') }}</span></span>
                        </label>
                        @error('parts')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    @if ($confirming === 'publish')
                        <p class="flex gap-2 rounded-lg bg-warn-soft p-3 text-[13px]">
                            <x-icon name="archive-restore" class="mt-0.5 shrink-0 text-warn" />
                            <span>{{ __('Önce canlı sitenin yedeği alınır. Bir sorun çıkarsa site kendiliğinden eski hâline döner; sonradan vazgeçerseniz :days gün içinde Geri al ile dönebilirsiniz.', ['days' => $safetyDays]) }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="submit" wire:loading.attr="disabled" class="{{ $primary }}">{{ $confirming === 'publish' ? __('Canlı siteye aktar') : __('Kaynaktan yenile') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
