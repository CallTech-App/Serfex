@php
    use App\Support\When;
    $pending = $queues ? collect($queues['sizes'] ?? [])->sum(fn ($s) => (int) ($s['size'] ?? 0)) : null;
    $failed = $queues['failed'] ?? null;
    $processes = $workers->sum('processes');
    $running = collect($status['workers'] ?? [])->where('state', 'active')->count();
    $maintenance = $status['maintenance'] ?? null;
    $schedule = $status['schedule'] ?? [];
    $row = 'flex justify-between gap-3 py-2';
@endphp
<div>
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    <div class="grid grid-cols-2 border-b border-line text-[13px] xl:grid-cols-4 [&>div]:border-line [&>div]:p-4 [&>div:nth-child(-n+2)]:border-b xl:[&>div]:border-b-0 [&>div:nth-child(odd)]:border-r xl:[&>div:not(:last-child)]:border-r">
        <div>
            <p class="text-2xs text-muted">Sağlık (<span class="font-mono">{{ $app->health_path }}</span>)</p>
            @if ($health === null)
                <p class="mt-1 font-medium text-muted">Bilinmiyor</p>
            @elseif ($health['ok'] ?? false)
                <p class="mt-1 font-medium text-ok">{{ $health['status'] }} · {{ $health['ms'] }} ms</p>
            @else
                <p class="mt-1 font-medium text-crit">{{ ($health['status'] ?? 0) ? $health['status'] : ($health['error'] ?? 'Yanıt yok') }}</p>
            @endif
            <button type="button" wire:click="checkHealth" class="text-2xs text-accent hover:underline">Şimdi kontrol et</button>
        </div>
        <div>
            <p class="text-2xs text-muted">Yayındaki sürüm</p>
            @if ($live)
                <p class="mt-1 font-mono font-medium">{{ '#'.$live->number.($live->shortCommit() ? ' · '.$live->shortCommit() : '') }}</p>
                <span class="text-2xs text-muted">{{ When::withTime($live->finished_at) }}</span>
            @elseif ($app->isManaged())
                <p class="mt-1 font-mono font-medium">releases/{{ $app->current_release ?? '—' }}</p>
            @else
                <p class="mt-1 font-medium">Yerinde</p>
                <span class="font-mono text-2xs text-muted">{{ $app->path }}</span>
            @endif
        </div>
        <div>
            <p class="text-2xs text-muted">Kuyruk</p>
            @if ($processes === 0)
                <p class="mt-1 font-medium text-muted">İşçi yok</p>
            @else
                <p class="mt-1 font-medium">{{ $pending ?? '—' }} bekleyen · {{ $processes }} işçi</p>
            @endif
            @if ($failed)
                <button type="button" wire:click="$parent.show('kuyruklar')" class="text-2xs text-crit hover:underline">{{ $failed }} başarısız iş</button>
            @elseif ($processes > 0 && $running < $workers->where('processes', '>', 0)->count())
                <span class="text-2xs text-warn">Bazı işçiler çalışmıyor</span>
            @endif
        </div>
        <div>
            <p class="text-2xs text-muted">Durum</p>
            @if ($maintenance)
                <p class="mt-1 font-medium text-warn">Bakım modunda</p>
                <button type="button" wire:click="$parent.show('bakim')" class="text-2xs text-accent hover:underline">Bakım modu</button>
            @else
                <p class="mt-1 font-medium">Açık</p>
                <span class="text-2xs text-muted">Zamanlayıcı {{ ($schedule['enabled'] ?? false) ? 'açık' : 'kapalı' }}@if ($schedule['last_run'] ?? null) · son çalışma {{ When::withTime(\Illuminate\Support\Carbon::parse($schedule['last_run'])) }}@endif</span>
            @endif
        </div>
    </div>
    <div class="grid grid-cols-1 gap-6 p-5 text-[13.5px] lg:grid-cols-2">
        <div>
            <h2 class="mb-2 font-semibold">Uygulama bilgileri</h2>
            <dl class="divide-y divide-line text-[13px]">
                <div class="{{ $row }}"><dt class="text-muted">Laravel / PHP</dt><dd>{{ $app->laravel_version ?? '—' }} / {{ $site->php_version }}
                    @if ($fits === true)<span class="text-ok">· uyumlu</span>@elseif ($fits === false)<span class="text-crit">· uyumsuz</span>@endif</dd></div>
                <div class="{{ $row }}"><dt class="text-muted">composer.json PHP isteği</dt><dd class="font-mono">{{ $app->php_requirement ?? '—' }}</dd></div>
                <div class="{{ $row }}"><dt class="text-muted">Site kökü</dt><dd class="font-mono">{{ $site->web_root ?? 'public_html' }}</dd></div>
                <div class="{{ $row }}"><dt class="text-muted">Uygulama dizini</dt><dd class="truncate font-mono" title="{{ $app->directory() }}">{{ $app->path }}</dd></div>
                @if ($app->isManaged())
                    <div class="{{ $row }}"><dt class="text-muted">Paylaşılan</dt><dd class="font-mono">storage/, .env</dd></div>
                @endif
                <div class="{{ $row }}"><dt class="text-muted">Paket yöneticisi</dt><dd class="font-mono">{{ $app->package_manager ?? '—' }}</dd></div>
            </dl>
        </div>
        <div>
            <h2 class="mb-2 font-semibold">Uygulamanın ayarları</h2>
            @if ($about)
                <dl class="divide-y divide-line text-[13px]">
                    <div class="{{ $row }}"><dt class="text-muted">Ortam</dt><dd class="font-mono">{{ $about['environment'] ?? '—' }}</dd></div>
                    <div class="{{ $row }}"><dt class="text-muted">Hata ayıklama (APP_DEBUG)</dt>
                        <dd class="{{ ($about['debug'] ?? false) ? 'text-crit' : '' }}">{{ ($about['debug'] ?? false) ? 'Açık, ziyaretçiler hata ayrıntısını görür' : 'Kapalı' }}</dd></div>
                    <div class="{{ $row }}"><dt class="text-muted">Veritabanı</dt><dd class="font-mono">{{ $about['database'] ?? '—' }}@if ($app->database)
                        · @if (auth()->user()->is_admin || auth()->user()->isCustomer())<a href="{{ route('databases.show', $app->database) }}" wire:navigate class="text-accent hover:underline">{{ $app->database->name }}</a>@else{{ $app->database->name }}@endif
                        <span class="font-sans text-muted">({{ \App\Support\Bytes::format($app->database->size_bytes) }})</span>@endif</dd></div>
                    <div class="{{ $row }}"><dt class="text-muted">Önbellek / oturum</dt><dd class="font-mono">{{ $about['cache'] ?? '—' }} / {{ $about['session'] ?? '—' }}</dd></div>
                    <div class="{{ $row }}"><dt class="text-muted">Kuyruk bağlantısı</dt><dd class="font-mono">{{ $about['queue'] ?? '—' }}</dd></div>
                </dl>
                <button type="button" wire:click="$parent.show('ortam')" class="mt-2 text-[13px] text-accent hover:underline">.env dosyasını düzenle</button>
            @else
                <p class="text-[13px] text-muted">Uygulama şu an ayarlarını bildiremedi. Hatayı görmek için <button type="button" wire:click="$parent.show('gunlukler')" class="text-accent hover:underline">günlüklere</button> bakın.</p>
            @endif
        </div>
    </div>
</div>
