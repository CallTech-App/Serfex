@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent';
    $row = 'grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6';
    $uptime = function (?int $s) {
        if ($s === null) {
            return '—';
        }
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);

        return $d > 0 ? $d.' gün '.$h.' saat' : $h.' saat '.intdiv($s % 3600, 60).' dakika';
    };
@endphp
<x-settings-page current="settings.server" title="Sunucu" description="Sunucunun adı, adresleri ve donanımı.">
    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-4 text-[13.5px]"><p class="font-medium text-crit">{{ $agentError }}</p></div>
    @endif

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="{{ $row }}">
                    <label for="server-hostname" class="sm:pt-2">Sunucu adı</label>
                    <div>
                        <input id="server-hostname" wire:model="hostname" spellcheck="false" autocomplete="off" placeholder="srv-ist-01.ornek.com.tr"
                               class="{{ $field }} sm:w-80 {{ $errors->has('hostname') ? 'border-crit' : 'border-line' }}">
                        @error('hostname')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Sunucunun kendi adı (hostname). Panelin ve posta sunucusunun adreslerinden ayrıdır; onlar Genel ve E-posta ayarlarında.</p>
                        @enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <span>IP adresleri</span>
                    <div class="font-mono text-[13px]">
                        @forelse ([...($server['addresses']['ipv4'] ?? []), ...($server['addresses']['ipv6'] ?? [])] as $ip)
                            <span class="block">{{ $ip }}</span>
                        @empty
                            <span class="font-sans text-muted">—</span>
                        @endforelse
                        <p class="mt-1 font-sans text-2xs text-muted">DNS kayıtlarında kullanılan adres <a href="{{ route('dns.settings') }}" wire:navigate class="text-accent hover:underline">Sunucu DNS ayarları</a>'nda seçilir.</p>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <span>İşletim sistemi</span>
                    <span>{{ $server['os'] ?? '—' }}<span class="block text-2xs text-muted">Çekirdek {{ $server['kernel'] ?? '—' }}</span></span>
                </div>
                <div class="{{ $row }}">
                    <span>Donanım</span>
                    <span class="num">{{ isset($server['cpus']) ? $server['cpus'].' çekirdek' : '—' }} · {{ isset($server['memory_bytes']) ? \App\Support\Bytes::format((int) $server['memory_bytes']) : '—' }} bellek</span>
                </div>
                <div class="{{ $row }}">
                    <span>Saat dilimi</span>
                    <span>{{ $server['timezone'] ?? '—' }} <a href="{{ route('settings.general') }}" wire:navigate class="ml-2 text-[13px] text-accent hover:underline">Değiştir</a></span>
                </div>
                <div class="{{ $row }}">
                    <span>Çalışma süresi</span>
                    <span class="num">{{ $uptime(isset($server['uptime_seconds']) ? (int) $server['uptime_seconds'] : null) }}</span>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" wire:loading.attr="disabled" @disabled($agentError) class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">Kaydet</button>
            </div>
        </form>
    </section>
</x-settings-page>
