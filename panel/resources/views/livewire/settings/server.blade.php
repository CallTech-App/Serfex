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
<x-settings-page current="settings.server" :title="__('Sunucu')" :description="__('Sunucunun adı, günlükleri, disk kotası, adresleri ve donanımı.')">
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
                @php
                    $qs = $quota['status'];
                    $qState = $quota['enabling'] ? 'enabling' : ($quota['broken'] ? 'broken' : (($qs['state'] ?? 'unknown') === 'on' ? ($quota['wanted'] ? 'on' : 'warn_only') : ($qs['state'] ?? 'unknown')));
                    [$qTone, $qLabel] = match ($qState) {
                        'enabling' => ['info', __('Açılıyor')],
                        'on' => ['ok', __('Uygulanıyor')],
                        'warn_only' => ['muted', __('Yalnızca uyarı')],
                        'broken' => ['crit', __('Çalışmıyor')],
                        'unsupported' => ['warn', __('Bu sunucuda kullanılamıyor')],
                        'off' => ['muted', __('Kapalı')],
                        default => ['muted', __('Denetlenmedi')],
                    };
                    $qWhere = isset($qs['mount']) ? $qs['mount'].' · '.($qs['fstype'] ?? '').' · '.($qs['device'] ?? '') : null;
                    $qButton = 'flex h-8 items-center gap-1.5 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2';
                @endphp
                <div class="{{ $row }}">
                    <span class="sm:pt-1">{{ __('Disk kotası') }}</span>
                    <div @if ($qState === 'enabling') wire:poll.3s @endif>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span @class(['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-medium', 'bg-ok-soft text-ok' => $qTone === 'ok', 'bg-warn-soft text-warn' => $qTone === 'warn', 'bg-crit-soft text-crit' => $qTone === 'crit', 'bg-info-soft text-info' => $qTone === 'info', 'bg-surface-2 text-muted' => $qTone === 'muted'])>
                                @if ($qState === 'enabling')<x-icon name="loader-circle" class="size-3 animate-spin" />@else<i @class(['size-1.5 rounded-full', 'bg-ok' => $qTone === 'ok', 'bg-warn' => $qTone === 'warn', 'bg-crit' => $qTone === 'crit', 'bg-muted' => $qTone === 'muted', 'bg-info' => $qTone === 'info'])></i>@endif{{ $qLabel }}
                            </span>
                            @if ($qWhere && in_array($qState, ['on', 'warn_only', 'off', 'broken'], true))
                                <span class="font-mono text-[12.5px] text-muted">{{ $qWhere }}</span>
                            @endif
                            <span class="ml-auto flex flex-wrap gap-2">
                                @switch($qState)
                                    @case('on')
                                        <button type="button" wire:click="enforceQuota(false)" class="{{ $qButton }}">{{ __('Yalnızca uyarı ver') }}</button>
                                        @break
                                    @case('warn_only')
                                        <button type="button" wire:click="enforceQuota(true)" class="{{ $qButton }}">{{ __('Uygula') }}</button>
                                        @break
                                    @case('off')
                                        <button type="button" wire:click="openQuota" class="flex h-8 items-center gap-1.5 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110">{{ __('Disk kotalarını aç') }}</button>
                                        @break
                                    @case('broken')
                                        <button type="button" wire:click="enableQuota" class="{{ $qButton }}">{{ __('Şimdi yeniden aç') }}</button>
                                        @break
                                    @case('unsupported')
                                        @if (($qs['reason'] ?? '') !== 'fstype')
                                            <button type="button" wire:click="openQuota" class="{{ $qButton }}">{{ __('Yeniden dene') }}</button>
                                        @endif
                                        @break
                                    @case('unknown')
                                        <button type="button" wire:click="checkQuota" class="{{ $qButton }}">{{ __('Denetle') }}</button>
                                        @break
                                @endswitch
                            </span>
                        </div>
                        <p class="mt-1.5 text-2xs text-muted">
                            @switch($qState)
                                @case('enabling')
                                    {{ __('Kota araçları kuruluyor ve diskteki bütün dosyalar sayılıyor. Büyük disklerde birkaç dakika sürebilir; sayfadan ayrılabilirsiniz.') }}
                                    @break
                                @case('on')
                                    {{ __('Müşteriler paketlerindeki disk alanını aşamaz: dolunca dosya yöneticisi, FTP ve siteler dosya yazamaz, gelen posta bekletilir. Müşterisi olmayan sitelerde sınır yoktur.') }}
                                    @break
                                @case('warn_only')
                                    {{ __('Dosya sisteminde kotalar açık ama sınırlar uygulanmıyor: disk dolunca yalnızca uyarılır ve yeni site, posta kutusu açılamaz; yazma engellenmez.') }}
                                    @break
                                @case('broken')
                                    {{ __('Kotalar açıktı ama şimdi kapalı (örneğin sunucu kota modülü olmayan yeni bir çekirdekle başladı). Serfex 5 dakikada bir yeniden açmayı dener; o zamana kadar sınırlar yalnızca uyarıdır.') }}
                                    @break
                                @case('unsupported')
                                    {{ match ($qs['reason'] ?? '') {
                                        'fstype' => __(':fstype dosya sisteminde kullanıcı kotası yok; disk sınırı yalnızca uyarı olarak kalır.', ['fstype' => $qs['fstype'] ?? '?']),
                                        'xfs_mount' => __('XFS kotaları yalnızca disk bağlanırken açılabilir: /etc/fstab\'a uquota seçeneği (kök disk için çekirdek satırına rootflags=uquota) eklenip sunucu yeniden başlatılmalı. O zamana kadar disk sınırı yalnızca uyarıdır.'),
                                        'no_fstab' => __('/etc/fstab\'da :mount için satır yok, bu yüzden kota seçenekleri yeniden başlatmada kaybolur. Disk sınırı yalnızca uyarı olarak kalır.', ['mount' => $qs['mount'] ?? '/']),
                                        'kernel' => __('Çalışan çekirdekte kota modülü (quota_v2) yok ve kurulamadı. Çekirdek güncellenip sunucu yeniden başladıktan sonra yeniden deneyin. O zamana kadar disk sınırı yalnızca uyarıdır.'),
                                        default => $qs['detail'] ?? '',
                                    } }}
                                    @break
                                @case('off')
                                    {{ __('Dosya sisteminde kota açık değil, bu yüzden disk sınırı yalnızca uyarıdır. Açınca müşteriler paketlerindeki disk alanını aşamaz.') }}
                                    @break
                                @default
                                    {{ __('Sunucunun disk kotası desteği henüz denetlenmedi.') }}
                            @endswitch
                        </p>
                        @if ($quota['error'] && ! $quota['enabling'])
                            <p class="mt-1.5 text-[13px] text-crit">{{ __('Son deneme başarısız oldu: :error', ['error' => $quota['error']]) }}</p>
                        @endif
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

    <x-modal :title="__('Disk kotalarını aç')" show="confirmingQuota" size="max-w-xl">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ __('Disk kotaları açılsın mı?') }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Bundan sonra müşteriler paketlerindeki disk alanını aşamaz. Serfex şunları yapar:') }}</p>
            <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[13px]">
                <li>{{ __('quota paketini, çekirdekte kota modülü yoksa çekirdeğin ek modüllerini (linux-modules-extra) kurar.') }}</li>
                <li><x-t text=":fstab dosyasında diskin satırına kota seçeneklerini ekler; önceki hali :backup olarak saklanır."><x-slot:fstab><span class="font-mono text-[12.5px]">/etc/fstab</span></x-slot:fstab><x-slot:backup><span class="font-mono text-[12.5px]">/etc/fstab.before-serfex-quota</span></x-slot:backup></x-t></li>
                <li>{{ __('Diski yeniden başlatmadan yeniden bağlar ve bütün dosyaları sayar; büyük disklerde birkaç dakika sürebilir.') }}</li>
            </ul>
            <p class="mt-3 text-[13px] text-muted">{{ __('Bir adım başarısız olursa önceki adımlar geri alınır ve disk sınırı yalnızca uyarı olarak kalır. Siteler bu sırada çalışmaya devam eder.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmingQuota', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="enableQuota" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Aç') }}</button>
            </div>
        </div>
    </x-modal>
</x-settings-page>
