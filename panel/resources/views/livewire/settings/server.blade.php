@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent';
    $row = 'grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6';
    $uptime = function (?int $s) {
        if ($s === null) {
            return '—';
        }
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);

        return $d > 0 ? __(':d gün :h saat', ['d' => $d, 'h' => $h]) : __(':h saat :s dakika', ['h' => $h, 's' => intdiv($s % 3600, 60)]);
    };
@endphp
<x-settings-page current="settings.server" :title="__('Sunucu')" :description="__('Sunucunun adı, günlükleri, adresleri ve donanımı.')">
    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-4 text-[13.5px]"><p class="font-medium text-crit">{{ $agentError }}</p></div>
    @endif

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="{{ $row }}">
                    <label for="server-hostname" class="sm:pt-2">{{ __('Sunucu adı') }}</label>
                    <div>
                        <input id="server-hostname" wire:model="hostname" spellcheck="false" autocomplete="off" placeholder="{{ __('srv-ist-01.ornek.com.tr') }}"
                               class="{{ $field }} sm:w-80 {{ $errors->has('hostname') ? 'border-crit' : 'border-line' }}">
                        @error('hostname')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Sunucunun kendi adı (hostname). Panelin ve posta sunucusunun adreslerinden ayrıdır; onlar Genel ve E-posta ayarlarında.') }}</p>
                        @enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="server-log-days" class="sm:pt-2">{{ __('Günlükleri sakla') }}</label>
                    <div>
                        <select id="server-log-days" wire:model.number="logDays" class="{{ str_replace('font-mono', '', $field) }} sm:w-48 {{ $errors->has('logDays') ? 'border-crit' : 'border-line' }}">
                            @foreach ($logDayOptions as $days)
                                <option value="{{ $days }}">{{ trans_choice(':count gün', $days) }}</option>
                            @endforeach
                        </select>
                        @error('logDays')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Sitelerin erişim, hata ve PHP günlükleri ile panelin ve web postanın günlükleri her gece döndürülür ve sıkıştırılır. Bu süreden eski günlükler silinir.') }}</p>
                        @enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <span>{{ __('IP adresleri') }}</span>
                    <div class="font-mono text-[13px]">
                        @forelse ([...($server['addresses']['ipv4'] ?? []), ...($server['addresses']['ipv6'] ?? [])] as $ip)
                            <span class="block">{{ $ip }}</span>
                        @empty
                            <span class="font-sans text-muted">—</span>
                        @endforelse
                        <p class="mt-1 font-sans text-2xs text-muted"><x-t text="DNS kayıtlarında kullanılan adres :link'nda seçilir."><x-slot:link><a href="{{ route('dns.settings') }}" wire:navigate class="text-accent hover:underline">{{ __('Sunucu DNS ayarları') }}</a></x-slot:link></x-t></p>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <span>{{ __('İşletim sistemi') }}</span>
                    <span>{{ $server['os'] ?? '—' }}<span class="block text-2xs text-muted">{{ __('Çekirdek :kernel', ['kernel' => $server['kernel'] ?? '—']) }}</span></span>
                </div>
                <div class="{{ $row }}">
                    <span>{{ __('Donanım') }}</span>
                    <span class="num">{{ __(':cpus · :memory_bytes bellek', ['cpus' => isset($server['cpus']) ? __(':cpus çekirdek', ['cpus' => $server['cpus']]) : '—', 'memory_bytes' => isset($server['memory_bytes']) ? \App\Support\Bytes::format((int) $server['memory_bytes']) : '—']) }}</span>
                </div>
                <div class="{{ $row }}">
                    <span>{{ __('Saat dilimi') }}</span>
                    <span>{{ $server['timezone'] ?? '—' }} <a href="{{ route('settings.general') }}" wire:navigate class="ml-2 text-[13px] text-accent hover:underline">{{ __('Değiştir') }}</a></span>
                </div>
                <div class="{{ $row }}">
                    <span>{{ __('Çalışma süresi') }}</span>
                    <span class="num">{{ $uptime(isset($server['uptime_seconds']) ? (int) $server['uptime_seconds'] : null) }}</span>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" wire:loading.attr="disabled" @disabled($agentError) class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </section>
</x-settings-page>
