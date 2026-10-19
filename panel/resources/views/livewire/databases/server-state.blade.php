{{-- The database servers' state, shown only when it needs attention. Customers see a short note instead of the setup. --}}
@php
    use App\Services\Databases\DatabaseServers;
    $admin = auth()->user()->is_admin;
    $state = $server['state'];
@endphp
@if ($state === 'installing')
    <div wire:poll.3s="refreshServer" hidden></div>
@endif
@if ($state === 'missing' && $admin)
    <section class="rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-5 py-4">
            <h2 class="text-[15px] font-semibold">Veritabanı sunucusunu kurun</h2>
            <p class="mt-1 text-[13px] text-muted">Müşteri veritabanları bu sunucuda çalışır. Servik seçtiğiniz sunucuyu kurar, yalnızca bu sunucudan bağlantı kabul edecek şekilde ayarlar ve tarayıcıdan yönetim için phpMyAdmin ya da Adminer'ı kendi web sunucusunda açar. Kurulum yarıda kalırsa yapılanlar geri alınır.</p>
        </div>
        <form wire:submit="setupServer" class="space-y-4 p-5 text-[13.5px]">
            <fieldset>
                <legend class="mb-2 font-medium">MySQL uyumlu sunucu</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (['mysql' => ['MySQL', 'Varsayılan. WordPress, Laravel ve çoğu PHP uygulaması için.'], 'mariadb' => ['MariaDB', 'MySQL ile uyumlu topluluk sürümü. İkisi aynı anda kurulamaz.']] as $key => [$label, $hint])
                        <label class="flex cursor-pointer gap-3 rounded-lg border p-3 {{ $setupProduct === $key ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2' }}">
                            <input type="radio" wire:model.live="setupProduct" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                            <span><span class="block font-medium">{{ $label }}</span><span class="mt-0.5 block text-[12.5px] text-muted">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="setupPostgres" class="mt-0.5 size-4 accent-accent">
                <span><span class="block font-medium">PostgreSQL de kurulsun</span><span class="mt-0.5 block text-[12.5px] text-muted">Yanında çalışır; isteyen siteler PostgreSQL veritabanı açabilir. Sonradan da kurulabilir.</span></span>
            </label>
            @error('setupProduct') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
            @if (! empty($server['error']))
                <div class="rounded-lg border border-line bg-crit-soft p-3 text-[13px]">
                    <p class="font-medium text-crit">Önceki kurulum tamamlanamadı; yapılanlar geri alındı.</p>
                    <p class="mt-1 font-mono text-[12.5px] break-words text-muted">{{ $server['error'] }}</p>
                </div>
            @endif
            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                    <x-icon name="download" />Kur
                </button>
            </div>
        </form>
    </section>
@elseif ($state === 'installing')
    @php
        $setup = app(DatabaseServers::class)->setup();
        $steps = [...($setup['products'] ?? []), 'manager'];
        $current = array_search($server['step'] ?? null, $steps, true);
        $names = ['mysql' => 'MySQL kuruluyor', 'mariadb' => 'MariaDB kuruluyor', 'postgresql' => 'PostgreSQL kuruluyor', 'manager' => 'Web yöneticisi (phpMyAdmin, Adminer) hazırlanıyor'];
    @endphp
    <div class="relative overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
        <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
        <p class="font-medium">Veritabanı sunucusu kuruluyor</p>
        <p class="mt-0.5 text-[13px] text-muted">Paketler indirilip ayarlanıyor; bu sayfadan ayrılabilirsiniz.</p>
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
@elseif ($state !== 'ready')
    @php
        [$tone, $title, $text, $action] = match ($state) {
            'missing' => ['warn', 'Veritabanı sunucusu henüz kurulmadı', 'Sunucu yöneticiniz kurduğunda burada veritabanı oluşturabilirsiniz.', null],
            'failed' => ['crit', 'Veritabanı sunucusu kurulamadı', 'Yarıda kalan kurulum geri alındı.', 'Tekrar dene'],
            'stopped' => ['crit', 'Veritabanı sunucusu çalışmıyor', 'Siteler veritabanlarına bağlanamıyor. Servik ayarları denetleyip sunucuyu yeniden başlatabilir.', 'Onar ve başlat'],
            'nomanager' => ['warn', 'Web veritabanı yöneticisi hazır değil', 'Veritabanları çalışıyor ama phpMyAdmin ve Adminer henüz kurulmadı.', 'Kur'],
            default => ['crit', 'Sunucu ajanına ulaşılamıyor', 'Sunucuda "systemctl status servik-agent" ile kontrol edin.', null],
        };
        if (! $admin) {
            $action = null;
            if (in_array($state, ['failed', 'nomanager', 'offline'], true)) {
                [$tone, $title, $text] = ['warn', 'Veritabanı sunucusuna şu an ulaşılamıyor', 'Sunucu yöneticiniz bilgilendirildi; birazdan yeniden deneyin.'];
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
                <p class="mt-1 text-[13px] text-muted">Durmuş: {{ implode(', ', $server['stopped']) }}</p>
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
