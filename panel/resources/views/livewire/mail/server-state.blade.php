{{-- The mail server's state, shown only when it needs attention. --}}
@if ($server['state'] === 'installing')
    <div wire:poll.3s="refreshServer" hidden></div>
@endif
@if ($server['state'] !== 'ready')
    @php
        [$tone, $title, $text, $action] = match ($server['state']) {
            'installing' => ['info', 'Posta sunucusu kuruluyor', 'Postfix, Dovecot, Rspamd ve web posta kuruluyor. Bitince bekleyen alan adları otomatik uygulanır.', null],
            'nohostname' => ['warn', 'Posta sunucusu kurulu değil', 'Önce posta sunucusunun adını girin, örneğin mail.ornek.com.tr. Posta istemcileri ve web posta bu adı kullanır.', 'Ayarlara git'],
            'missing' => ['warn', 'Posta sunucusu kurulu değil', 'Posta kutularınız panelde duruyor ama bu sunucu henüz posta almıyor. Servik; Postfix, Dovecot, Rspamd ve web postayı kurup yapılandırabilir.', 'Posta sunucusunu kur'],
            'stopped' => ['crit', 'Posta sunucusu çalışmıyor', 'Yapılandırma yerinde ama bazı hizmetler durmuş. Servik yapılandırmayı denetleyip yeniden başlatabilir.', 'Onar ve başlat'],
            'failed' => ['crit', 'Posta sunucusu kurulamadı', 'Önceki ayarlar geri yüklendi.', 'Tekrar dene'],
            default => ['crit', 'Sunucu ajanına ulaşılamıyor', 'Sunucuda "systemctl status servik-agent" ile kontrol edin.', null],
        };
        $stopped = collect($server['services'] ?? [])->reject(fn ($up) => $up)->keys();
    @endphp
    <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
        <span @class(["absolute inset-y-0 left-0 w-1", "bg-info" => $tone === "info", "bg-warn" => $tone === "warn", "bg-crit" => $tone === "crit"])></span>
        @if ($server['state'] === 'installing')
            <x-icon name="loader-circle" class="animate-spin text-info" />
        @else
            <x-icon name="triangle-alert" @class(["text-warn" => $tone === "warn", "text-crit" => $tone === "crit"]) />
        @endif
        <div class="min-w-0 flex-1">
            <p class="font-medium">{{ $title }}</p>
            <p class="mt-0.5 text-[13px] text-muted">{{ $text }}</p>
            @if ($stopped->isNotEmpty())
                <p class="mt-1 text-[13px] text-muted">Durmuş hizmetler: <span class="font-mono">{{ $stopped->join(', ') }}</span></p>
            @endif
            @if (! empty($server['error']))
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
