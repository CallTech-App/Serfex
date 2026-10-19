@php
    use App\Models\Site;
    use App\Services\Php\PhpVersions;
    $busy = $site->status->isBusy();
    $input = 'h-9 rounded-md border border-line bg-surface px-2.5 font-mono text-[13px] disabled:opacity-60';
    $readonly = $busy || ! $canEdit;
    $hints = [
        Site::NGINX => 'Nginx statik dosyaları sunar, PHP isteklerini sitenin kendi PHP-FPM havuzuna gönderir. .htaccess dosyaları okunmaz.',
        Site::APACHE => 'Nginx önde durur ve statik dosyaları sunar; diğer istekler Apache\'ye gider. .htaccess dosyaları (yönlendirme, erişim kuralları) çalışır. PHP yine sitenin kendi PHP-FPM havuzunda çalışır.',
    ];
@endphp
<div class="space-y-6">
    @if ($busy)
        <div wire:poll.2s hidden></div>
    @endif

    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">PHP ve Web Sunucusu</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-[22px] font-semibold tracking-tight">PHP ve Web Sunucusu</h1>
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="arrow-left" />Siteye dön</a>
        </div>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">Bu ayarlar yalnızca <span class="font-mono text-fg">{{ $site->domain }}</span> için geçerlidir. Kaydettiğinizde hemen uygulanır; sunucu kabul etmezse site önceki ayarlarıyla çalışmaya devam eder.</p>
    </div>

    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-3 text-[13.5px] text-crit" role="alert">{{ $agentError }}</div>
    @endif

    <form wire:submit="save" class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="grid grid-cols-1 gap-x-6 gap-y-5 p-5 text-[13.5px] md:grid-cols-[220px_1fr]">
            <label for="php-version" class="font-medium md:pt-2">PHP sürümü</label>
            <div class="max-w-xl">
                <select id="php-version" wire:model.live="phpVersion" @disabled($busy) class="h-9 w-56 rounded-md border border-line bg-surface px-2.5">
                    @foreach ($versions as $v)
                        <option value="{{ $v }}">PHP {{ $v }}</option>
                    @endforeach
                </select>
                @error('phpVersion') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                @if (auth()->user()->is_admin)
                    <p class="mt-1.5 text-[13px] text-muted">Başka bir sürüm için <a href="{{ route('services.index', ['sekme' => 'php']) }}" wire:navigate class="text-accent hover:underline">Hizmetler → PHP sürümleri</a>'nden kurun.</p>
                @endif
            </div>

            <span class="font-medium md:pt-2" id="stack-label">Web sunucusu</span>
            <div class="max-w-xl" role="radiogroup" aria-labelledby="stack-label">
                <div class="grid gap-2">
                    @foreach (Site::STACKS as $key => $label)
                        @php $disabled = $key === Site::APACHE && ! $apacheInstalled && $site->web_server !== Site::APACHE; @endphp
                        <label @class(['flex gap-3 rounded-lg border p-3', 'border-accent bg-accent-soft' => $webServer === $key, 'border-line hover:bg-surface-2 cursor-pointer' => $webServer !== $key && ! $disabled, 'border-line opacity-60 cursor-not-allowed' => $disabled])>
                            <input type="radio" wire:model.live="webServer" value="{{ $key }}" @disabled($disabled || $busy) class="mt-0.5 size-4 accent-accent">
                            <span>
                                <span class="block font-medium">{{ $label }}{{ $key === Site::NGINX ? ' (önerilen)' : '' }}</span>
                                <span class="mt-0.5 block text-[12.5px] text-muted">{{ $hints[$key] }}</span>
                                @if ($disabled)
                                    <span class="mt-1 block text-[12.5px] text-muted">Sunucuda Apache kurulu değil.@if (auth()->user()->is_admin) <a href="{{ route('components.index') }}" wire:navigate class="text-accent hover:underline">Yazılım Yönetimi'nden kurun</a>.@endif</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('webServer') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                @if ($octane)
                    <p class="mt-2 text-[13px] text-muted">Bu sitede Laravel Octane açık; dinamik istekler uygulama sunucusuna gider.</p>
                @endif
            </div>
        </div>
        <div class="border-t border-line">
            <h2 class="px-5 pt-4 text-[14.5px] font-semibold">PHP ayarları</h2>
            @if (! $canEdit)
                <p class="px-5 pt-1 text-[13px] text-muted">Paketiniz PHP ayarlarını değiştirmeye izin vermiyor; site sunucunun varsayılanlarıyla çalışır.</p>
            @else
                <p class="px-5 pt-1 text-[13px] text-muted">Boş bırakılan alan PHP {{ $phpVersion }} için sunucunun değerini kullanır (gri yazı).</p>
            @endif
            <div class="grid grid-cols-1 gap-x-6 gap-y-5 p-5 text-[13.5px] md:grid-cols-[220px_1fr]">
                @foreach (PhpVersions::SITE_INI as $key => [$label, $type])
                    <label for="ini-{{ $key }}" class="font-medium md:pt-2">{{ $label }}</label>
                    <div class="max-w-xl">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($type === 'onoff')
                                <select id="ini-{{ $key }}" wire:model="ini.{{ $key }}" @disabled($readonly) class="{{ $input }} w-48">
                                    <option value="">Sunucunun ({{ ($serverValues[$key] ?? '') === 'On' || ($serverValues[$key] ?? '') === '1' ? 'açık' : 'kapalı' }})</option>
                                    <option value="On">Açık</option>
                                    <option value="Off">Kapalı</option>
                                </select>
                            @else
                                <input id="ini-{{ $key }}" type="text" wire:model="ini.{{ $key }}" placeholder="{{ $serverValues[$key] ?? '' }}" @disabled($readonly) class="{{ $input }} w-40">
                            @endif
                            <span class="font-mono text-2xs text-muted">{{ $key }}</span>
                        </div>
                        @error('ini.'.$key) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                        @if ($key === 'memory_limit' && $memoryCap)
                            <p class="mt-1.5 text-[13px] text-muted">Paket sınırı {{ $memoryCap }}M; site kodu bunu aşamaz.</p>
                        @elseif ($key === 'post_max_size')
                            <p class="mt-1.5 text-[13px] text-muted">Nginx de bu siteye bu boyuta kadar istek kabul eder.</p>
                        @endif
                    </div>
                @endforeach

                <label for="php-custom" class="font-medium md:pt-2">Özel yönergeler</label>
                <div class="max-w-xl">
                    <textarea id="php-custom" wire:model="custom" rows="3" @disabled($readonly) placeholder="session.cookie_secure = 1" class="w-full rounded-md border border-line bg-surface px-2.5 py-2 font-mono text-[13px] disabled:opacity-60"></textarea>
                    @error('custom') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-[13px] text-muted">Her satıra bir php.ini ayarı: <span class="font-mono">ad = değer</span>. Yalıtım ayarları (open_basedir, disable_functions…) değiştirilemez.</p>
                </div>

                @if ($customerSite && auth()->user()->is_admin)
                    <span class="font-medium">Komut çalıştırma</span>
                    <label class="flex max-w-xl items-start gap-2.5">
                        <input type="checkbox" wire:model="allowFunctions" @disabled($busy) class="mt-0.5 size-4 accent-accent">
                        <span>Bu site exec, shell_exec, proc_open gibi fonksiyonları kullanabilsin<span class="mt-0.5 block text-[13px] text-muted">Müşteri sitelerinde bu fonksiyonlar kapalıdır. Yalnızca güvendiğiniz ve buna ihtiyaç duyan sitelerde açın.</span></span>
                    </label>
                @endif
            </div>
        </div>

        <div class="border-t border-line">
            <h2 class="px-5 pt-4 text-[14.5px] font-semibold">PHP-FPM havuzu</h2>
            <div class="grid grid-cols-1 gap-x-6 gap-y-5 p-5 text-[13.5px] md:grid-cols-[220px_1fr]">
                <span class="font-medium md:pt-2" id="pm-label">İşçi yönetimi</span>
                <div class="max-w-xl" role="radiogroup" aria-labelledby="pm-label">
                    <div class="grid gap-2">
                        @foreach (PhpVersions::PM as $key => [$label, $hint])
                            <label @class(['flex gap-3 rounded-lg border p-3', 'border-accent bg-accent-soft' => $pm === $key, 'border-line hover:bg-surface-2 cursor-pointer' => $pm !== $key && ! $readonly, 'border-line opacity-60' => $pm !== $key && $readonly])>
                                <input type="radio" wire:model.live="pm" value="{{ $key }}" @disabled($readonly) class="mt-0.5 size-4 accent-accent">
                                <span><span class="block font-medium">{{ $label }}</span><span class="mt-0.5 block text-[12.5px] text-muted">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @error('pm') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>

                @foreach ([
                    'maxChildren' => ['En fazla işçi', PhpVersions::DEFAULT_MAX_CHILDREN, 'Aynı anda yanıtlanan PHP isteği. Her işçi bellek sınırı kadar bellek kullanabilir'.($maxChildrenCap < 1000 ? "; en fazla {$maxChildrenCap}." : '.')],
                    'maxRequests' => ['İşçi yenileme', 500, 'Bir işçi bu kadar istekten sonra yenilenir; bellek sızıntılarını önler.'],
                    'requestTimeout' => ['İstek zaman aşımı', '', 'Saniye. Bu süreyi aşan istek sonlandırılır; boşsa yalnızca en uzun çalışma süresi geçerli.'],
                ] as $field => [$label, $placeholder, $help])
                    <label for="pool-{{ $field }}" class="font-medium md:pt-2">{{ $label }}</label>
                    <div class="max-w-xl">
                        <input id="pool-{{ $field }}" type="text" inputmode="numeric" wire:model="{{ $field }}" placeholder="{{ $placeholder }}" @disabled($readonly) class="{{ $input }} w-40">
                        @error($field) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-[13px] text-muted">{{ $help }}</p>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-b-xl border-t border-line px-5 py-3">
            <button type="submit" wire:loading.attr="disabled" wire:target="save" @disabled($busy) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="save" />Kaydet
            </button>
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="grid h-9 place-items-center rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</a>
            <button type="button" wire:click="restartFpm" wire:loading.attr="disabled" wire:target="restartFpm" @disabled($busy) class="flex h-9 items-center gap-2 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40 sm:ml-auto">
                <x-icon name="rotate-ccw" wire:loading.class="animate-spin" wire:target="restartFpm" />PHP-FPM'i yeniden başlat
            </button>
            @if ($busy)
                <span class="text-[13px] text-muted">Site güncelleniyor, bitince değiştirebilirsiniz.</span>
            @endif
        </div>
    </form>
</div>
