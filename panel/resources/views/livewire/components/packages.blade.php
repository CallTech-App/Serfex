{{-- Ubuntu güncellemeleri: Ubuntu's own packages with updates, the restart they ask for and the automatic security updates. Needs $system and $busy. --}}
@php
    use App\Support\Badge;
    use App\Support\When;
    use Illuminate\Support\Carbon;
    $kind = fn (array $r) => match (true) {
        $r['held'] => new Badge(__('Bekletiliyor'), 'muted'),
        $r['phased'] => new Badge(__('Aşamalı'), 'muted'),
        $r['new'] => new Badge(__('Yeni paket'), 'info'),
        $r['security'] => new Badge(__('Güvenlik'), 'warn'),
        default => new Badge(__('Normal'), 'muted'),
    };
    $can = $system['can'] ?? false;
    $disabled = $busy || ! $can;
    $link = 'text-[13px] text-accent hover:underline disabled:opacity-40 disabled:no-underline';
@endphp
@if ($system === null || $system['error'])
    <p class="px-5 py-8 text-center text-[13.5px] text-muted">{{ $system['error'] ?? '' }}</p>
@else
    @php($reboot = $system['reboot'])
    @if (($reboot['required'] ?? false) || ($reboot['scheduled'] ?? null))
        <div class="flex flex-col gap-3 border-b border-line bg-warn-soft px-5 py-3.5 text-[13.5px] sm:flex-row sm:items-center" role="status">
            <x-icon name="power" class="hidden shrink-0 text-warn sm:block" />
            <div class="min-w-0 flex-1">
                @if ($reboot['scheduled'] ?? null)
                    <p class="font-medium">{{ __('Sunucu :time sularında yeniden başlayacak', ['time' => When::time(Carbon::parse($reboot['scheduled']))]) }}</p>
                    <p class="mt-0.5 text-[13px] text-muted">{{ __('Siteler, posta ve panel birkaç dakika ulaşılamaz olur.') }}</p>
                @else
                    <p class="font-medium">{{ __('Sunucunun yeniden başlatılması gerekiyor') }}</p>
                    <p class="mt-0.5 text-[13px] break-words text-muted">
                        {{ __('Yeni sürümler ancak yeniden başlatınca devreye girer: :packages.', ['packages' => implode(', ', array_slice($reboot['packages'] ?? [], 0, 4)) ?: __('çekirdek')]) }}
                        @if ($reboot['since'] ?? null)
                            <span title="{{ When::dateTime(Carbon::parse($reboot['since'])) }}">{{ __('İlk kez :since istendi.', ['since' => Carbon::parse($reboot['since'])->diffForHumans()]) }}</span>
                        @endif
                    </p>
                @endif
            </div>
            @if ($reboot['scheduled'] ?? null)
                <button type="button" wire:click="abortReboot" @disabled(! $can) class="h-9 shrink-0 self-start rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40 sm:self-auto">{{ __('Yeniden başlatmayı iptal et') }}</button>
            @else
                <button type="button" wire:click="askReboot" @disabled($disabled) class="h-9 shrink-0 self-start rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40 sm:self-auto">{{ __('Yeniden başlat') }}</button>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3 px-5 py-3">
        <div class="flex gap-1 rounded-md bg-surface-2 p-0.5 text-[13px]" role="group" aria-label="{{ __('Göster') }}">
            @foreach (['all' => __('Tümü (:count)', ['count' => $system['counts']['all']]), 'security' => __('Güvenlik (:count)', ['count' => $system['counts']['security']])] as $key => $label)
                <button type="button" wire:click="$set('packageFilter', '{{ $key }}')" aria-pressed="{{ $packageFilter === $key ? 'true' : 'false' }}"
                        @class(['h-7 rounded px-2.5', 'bg-surface font-medium shadow-sm' => $packageFilter === $key, 'text-muted hover:text-fg' => $packageFilter !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($selected !== [])
                <button type="button" wire:click="askPackages('selected')" @disabled($disabled) class="h-9 rounded-md border border-line px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40">{{ trans_choice('Seçilenleri güncelle (:count)', count($selected)) }}</button>
            @endif
            <button type="button" wire:click="askPackages('all')" @disabled($disabled || $system['counts']['upgradable'] === 0) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="download" />{{ __('Tümünü güncelle (:count)', ['count' => $system['counts']['upgradable']]) }}
            </button>
        </div>
    </div>

    @if ($system['rows'] === [])
        <div class="flex flex-col items-center gap-2 border-t border-line px-5 py-10 text-center text-[13.5px]">
            <span class="grid size-10 place-items-center rounded-full bg-ok-soft text-ok"><x-icon name="circle-check" class="size-5" /></span>
            <p class="font-medium">{{ $packageFilter === 'security' ? __('Bekleyen güvenlik güncellemesi yok') : __('Bütün paketler güncel') }}</p>
            <p class="text-[13px] text-muted">{{ __('Yeni güncellemeler için "Güncellemeleri denetle"ye basın; Serfex her gece de denetler.') }}</p>
        </div>
    @else
        <div class="relative overflow-x-auto border-t border-line">
            <table class="w-full text-[13.5px] sm:min-w-[720px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="w-10 py-2.5 pl-5">
                            @php($selectable = array_filter($system['rows'], fn ($r) => $r['selectable']))
                            <input type="checkbox" aria-label="{{ __('Hepsini seç') }}" class="size-4 accent-accent" @disabled($disabled || $selectable === [])
                                   @checked($selectable !== [] && count($selected) >= count($selectable))
                                   wire:click="selectAllPackages($event.target.checked)">
                        </th>
                        <th class="py-2.5 pr-3 pl-2 font-medium">{{ __('Ubuntu paketi') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Sürüm') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Kaynak') }}</th>
                        <th class="hidden py-2.5 pr-5 pl-3 sm:table-cell"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($system['rows'] as $r)
                        <tr wire:key="pkg-{{ $r['name'] }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pl-5 align-top">
                                @if ($r['selectable'])
                                    <input type="checkbox" wire:model.live="selected" value="{{ $r['name'] }}" aria-label="{{ __(':name seç', ['name' => $r['name']]) }}" class="mt-0.5 size-4 accent-accent" @disabled($disabled)>
                                @endif
                            </td>
                            <td class="py-3 pr-3 pl-2">
                                <span class="font-mono text-[13px] font-medium break-all">{{ $r['name'] }}</span>
                                @if ($r['component_name'])
                                    <span class="ml-1.5 rounded bg-surface-2 px-1.5 py-0.5 text-2xs whitespace-nowrap text-muted" title="{{ __('Serfex bu bileşeni güncellemeden sonra denetler') }}">{{ $r['component_name'] }}</span>
                                @endif
                                <span class="mt-0.5 block text-2xs text-muted">
                                    @if ($r['held'])
                                        {{ __('apt-mark hold ile bekletiliyor; Serfex güncellemez.') }}
                                    @elseif ($r['phased'])
                                        {{ __('Ubuntu bu güncellemeyi sunuculara sırayla dağıtıyor; sıra gelince seçilebilir.') }}
                                    @elseif ($r['new'])
                                        {{ __('Bir güncellemeyle birlikte kurulur.') }}
                                    @elseif ($r['kept_back'])
                                        {{ __('"Tümünü güncelle" bunu atlar; tek başına güncellenebilir.') }}
                                    @endif
                                </span>
                                <span class="mt-0.5 block font-mono text-2xs text-muted sm:hidden">{{ $r['installed'] ? $r['installed'].' → ' : '' }}{{ $r['version'] }}</span>
                            </td>
                            <td class="hidden px-3 py-3 font-mono text-[12.5px] whitespace-nowrap sm:table-cell">
                                @if ($r['installed'])
                                    <span class="text-muted">{{ $r['installed'] }}</span> <span class="text-muted">→</span>
                                @endif
                                {{ $r['version'] }}
                            </td>
                            <td class="px-3 py-3"><x-status-badge :status="$kind($r)" /></td>
                            <td class="hidden px-3 py-3 text-[13px] text-muted md:table-cell">{{ $r['source'] ?? '—' }}</td>
                            <td class="hidden py-3 pr-5 pl-3 text-right sm:table-cell">
                                @if ($r['selectable'])
                                    <button type="button" wire:click="askPackages('one', @js($r['name']))" @disabled($disabled) class="{{ $link }}">{{ __('Güncelle') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php($auto = $system['auto'])
    @if ($auto !== null)
        <div class="grid grid-cols-1 gap-x-6 gap-y-2 border-t border-line px-5 py-4 text-[13.5px] md:grid-cols-[220px_1fr]">
            <p class="font-medium md:pt-2">{{ __('Otomatik güvenlik güncellemeleri') }}</p>
            <div class="flex flex-wrap items-center gap-3">
                <x-status-badge :status="$auto['enabled'] ? new Badge(__('Açık'), 'ok') : new Badge(__('Kapalı'), 'warn')" />
                <span class="min-w-0 flex-1 text-[13px] text-muted">
                    @if ($auto['enabled'])
                        {{ ($auto['security_only'] ?? true) ? __('Ubuntu her gün güvenlik güncellemelerini kendiliğinden kurar (unattended-upgrades).') : __('Ubuntu her gün güncellemeleri kendiliğinden kurar; ayarlarında güvenlik dışı depolar da açık.') }}
                        @if ($auto['last_run'] ?? null)
                            <span title="{{ When::dateTime(Carbon::parse($auto['last_run'])) }}">{{ __('Son çalışma :when.', ['when' => Carbon::parse($auto['last_run'])->diffForHumans()]) }}</span>
                        @endif
                        {{ ($auto['reboot'] ?? false) ? __('Gerekince sunucuyu kendisi yeniden başlatır.') : __('Sunucuyu kendisi yeniden başlatmaz.') }}
                    @else
                        {{ ($auto['installed'] ?? false) ? __('Güvenlik güncellemeleri ancak buradan kurulunca gelir.') : __('unattended-upgrades kurulu değil; açınca kurulur.') }}
                    @endif
                </span>
                <button type="button" wire:click="setAutoUpdates({{ $auto['enabled'] ? 'false' : 'true' }})" wire:loading.attr="disabled" @disabled($disabled) class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40">{{ $auto['enabled'] ? __('Kapat') : __('Aç') }}</button>
            </div>
        </div>
    @endif
    <p class="border-t border-line px-5 py-3 text-2xs text-muted">
        {{ __('Serfex güncellerken hiçbir paketi kaldırmaz ve sunucuda değiştirilmiş ayar dosyalarını korur. Bir Serfex bileşenine (Nginx, PHP, veritabanı sunucusu...) ait paket güncellenince Serfex onun ayarlarını yeniden yazar, hizmeti yeniden başlatır ve denetler.') }}
        @unless ($can)
            {{ __('Güncellemeleri yalnızca tam yetkili yöneticiler başlatabilir.') }}
        @endunless
    </p>
@endif
