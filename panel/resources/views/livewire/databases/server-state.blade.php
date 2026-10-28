{{-- The database servers' state, shown only when it needs attention. Customers see a short note instead of the setup. --}}
@php
    use App\Services\Databases\DatabaseServers;
    $admin = auth()->user()->is_admin;
    $state = $server['state'];
@endphp
@if ($state === 'installing')
    <div wire:poll.3s="refreshServer" hidden></div>
@elseif ($state === 'upgrading')
    <div wire:poll.15s="refreshServer" hidden></div>
@endif
@if ($state === 'missing' && $admin)
    @php
        $components = app(\App\Services\Components\Components::class);
        $candidates = ['mysql' => $components->candidate('mysql'), 'mariadb' => $components->candidate('mariadb'), 'postgresql' => $components->candidate('postgresql')];
        $option = fn (array $c) => $c['name'].($c['version'] ? ' · '.$c['version'] : '').($c['source'] ? ' · '.$c['source'] : '');
    @endphp
    <section class="rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-5 py-4">
            <h2 class="text-[15px] font-semibold">{{ __('Veritabanı sunucusunu kurun') }}</h2>
            <p class="mt-1 text-[13px] text-muted">{{ __('Müşteri veritabanları bu sunucuda çalışır. Serfex seçtiğiniz sunucuyu kurar, yalnızca bu sunucudan bağlantı kabul edecek şekilde ayarlar ve tarayıcıdan yönetim için phpMyAdmin ya da Adminer\'ı kendi web sunucusunda açar. Kurulum yarıda kalırsa yapılanlar geri alınır.') }}</p>
        </div>
        <form wire:submit="setupServer" class="space-y-4 p-5 text-[13.5px]">
            <fieldset>
                <legend class="mb-2 font-medium">{{ __('MySQL uyumlu sunucu') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (['mysql' => ['MySQL', __('Varsayılan. WordPress, Laravel ve çoğu PHP uygulaması için.')], 'mariadb' => ['MariaDB', __('MySQL ile uyumlu topluluk sürümü. İkisi aynı anda kurulamaz.')]] as $key => [$label, $hint])
                        <label class="flex cursor-pointer gap-3 rounded-lg border p-3 {{ $setupProduct === $key ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2' }}">
                            <input type="radio" wire:model.live="setupProduct" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                            <span>
                                <span class="block font-medium">{{ $label }}</span><span class="mt-0.5 block text-[12.5px] text-muted">{{ $hint }}</span>
                                @if ($candidates[$key]['choices'] ?? [])
                                    <span class="mt-1 block text-2xs text-muted">{{ implode(' · ', array_column($candidates[$key]['choices'], 'name')) }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @if ($candidates[$setupProduct]['choices'] ?? [])
                <div>
                    <label for="setup-version" class="mb-1.5 block font-medium">{{ __('Kurulacak sürüm') }}</label>
                    <select id="setup-version" wire:model="setupVersions.{{ $setupProduct }}" wire:key="setup-version-{{ $setupProduct }}" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-auto">
                        @foreach ($candidates[$setupProduct]['choices'] as $c)
                            <option value="{{ $c['major'] }}">{{ $option($c) }}</option>
                        @endforeach
                    </select>
                    @error('setupVersions') <p class="mt-1 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
            @endif
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="setupPostgres" class="mt-0.5 size-4 accent-accent">
                <span>
                    <span class="block font-medium">{{ __('PostgreSQL de kurulsun') }}</span><span class="mt-0.5 block text-[12.5px] text-muted">{{ __('Yanında çalışır; isteyen siteler PostgreSQL veritabanı açabilir. Sonradan da kurulabilir.') }}</span>
                    @foreach ($candidates['postgresql']['choices'] ?? [] as $c)
                        @if ($c['default'])
                            <span class="mt-1 block text-2xs text-muted">{{ __('Kurulacak sürüm:') }} {{ $option($c) }}</span>
                        @endif
                    @endforeach
                </span>
            </label>
            @error('setupProduct') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
            @if (! empty($server['error']))
                <div class="rounded-lg border border-line bg-crit-soft p-3 text-[13px]">
                    <p class="font-medium text-crit">{{ __('Önceki kurulum tamamlanamadı; yapılanlar geri alındı.') }}</p>
                    <p class="mt-1 font-mono text-[12.5px] break-words text-muted">{{ $server['error'] }}</p>
                </div>
            @endif
            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                    <x-icon name="download" />{{ __('Kur') }}
                </button>
            </div>
        </form>
    </section>
@elseif ($state === 'installing')
    @php
        $setup = app(DatabaseServers::class)->setup();
        $steps = [...($setup['products'] ?? []), 'manager'];
        $current = array_search($server['step'] ?? null, $steps, true);
        $names = ['mysql' => __('MySQL kuruluyor'), 'mariadb' => __('MariaDB kuruluyor'), 'postgresql' => __('PostgreSQL kuruluyor'), 'manager' => __('Web yöneticisi (phpMyAdmin, Adminer) hazırlanıyor')];
    @endphp
    <div class="relative overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
        <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
        <p class="font-medium">{{ __('Veritabanı sunucusu kuruluyor') }}</p>
        <p class="mt-0.5 text-[13px] text-muted">{{ __('Paketler indirilip ayarlanıyor; bu sayfadan ayrılabilirsiniz.') }}</p>
        <ol class="mt-3 space-y-1.5 text-[13px]">
            @foreach ($steps as $i => $step)
                <li class="flex items-center gap-2 {{ $current !== false && $i > $current ? 'text-muted' : '' }}">
                    @if ($current !== false && $i < $current)
                        <x-icon name="check" class="size-4 text-ok" />
                    @elseif ($i === $current)
                        <x-icon name="loader-circle" class="size-4 animate-spin text-info" />
                    @else
                        <span class="grid size-4 place-items-center"><i class="size-1.5 rounded-full bg-muted"></i></span>
                    @endif
                    {{ $names[$step] ?? $step }}
                </li>
            @endforeach
        </ol>
    </div>
@elseif ($state === 'upgrading')
    <div class="relative flex items-start gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]" role="status">
        <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
        <x-icon name="loader-circle" class="mt-0.5 size-4 shrink-0 animate-spin text-info" />
        <div class="min-w-0 flex-1">
            <p class="font-medium">{{ __(':names bakımda: yeni sürüme yükseltiliyor', ['names' => implode(', ', $server['upgrading'] ?? [])]) }}</p>
            <p class="mt-0.5 text-[13px] text-muted">{{ __('Bu sırada siteler veritabanlarına bağlanamayabilir ya da yalnızca okuyabilir; yeni veritabanı ve kullanıcı açılamaz. Yükseltme bitince her şey kendiliğinden açılır, verileriniz korunur.') }}</p>
        </div>
    </div>
@elseif ($state !== 'ready')
    @php
        [$tone, $title, $text, $action] = match ($state) {
            'missing' => ['warn', __('Veritabanı sunucusu henüz kurulmadı'), __('Sunucu yöneticiniz kurduğunda burada veritabanı oluşturabilirsiniz.'), null],
            'failed' => ['crit', __('Veritabanı sunucusu kurulamadı'), __('Yarıda kalan kurulum geri alındı.'), __('Tekrar dene')],
            'stopped' => ['crit', __('Veritabanı sunucusu çalışmıyor'), __('Siteler veritabanlarına bağlanamıyor. Serfex ayarları denetleyip sunucuyu yeniden başlatabilir.'), __('Onar ve başlat')],
            'nomanager' => ['warn', __('Web veritabanı yöneticisi hazır değil'), __('Veritabanları çalışıyor ama phpMyAdmin ve Adminer henüz kurulmadı.'), __('Kur')],
            default => ['crit', __('Sunucu ajanına ulaşılamıyor'), __('Sunucuda "systemctl status serfex-agent" ile kontrol edin.'), null],
        };
        if (! $admin) {
            $action = null;
            if (in_array($state, ['failed', 'nomanager', 'offline'], true)) {
                [$tone, $title, $text] = ['warn', __('Veritabanı sunucusuna şu an ulaşılamıyor'), __('Sunucu yöneticiniz bilgilendirildi; birazdan yeniden deneyin.')];
            }
        }
    @endphp
    <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
        <span @class(['absolute inset-y-0 left-0 w-1', 'bg-warn' => $tone === 'warn', 'bg-crit' => $tone === 'crit'])></span>
        <x-icon name="triangle-alert" @class(['text-warn' => $tone === 'warn', 'text-crit' => $tone === 'crit']) />
        <div class="min-w-0 flex-1">
            <p class="font-medium">{{ $title }}</p>
            <p class="mt-0.5 text-[13px] text-muted">{{ $text }}</p>
            @if (! empty($server['stopped']))
                <p class="mt-1 text-[13px] text-muted">{{ __('Durmuş: :stopped', ['stopped' => implode(', ', $server['stopped'])]) }}</p>
            @endif
            @if ($admin && ! empty($server['error']))
                <p class="mt-1 font-mono text-[12.5px] break-words text-muted">{{ $server['error'] }}</p>
            @endif
        </div>
        @if ($action)
            <button type="button" wire:click="setupServer" class="flex h-8 items-center gap-2 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110">
                {{ $action }}
            </button>
        @endif
    </div>
@endif
