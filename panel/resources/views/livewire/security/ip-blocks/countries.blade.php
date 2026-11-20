@php
    use App\Support\Badge;
    use App\Support\Num;
    use App\Support\When;
    $codes = array_map('strval', $selected);
    $tickable = array_values(array_filter($rows->items(), fn ($r) => ! $r->home));
    // The country the reader is connected from has no box, so it does not count.
    $pageTicked = $tickable !== [] && collect($tickable)->every(fn ($r) => in_array($r->code, $codes, true));
    $toLift = count(array_intersect($codes, $blockedCodes));
    $blockedCount = count($blockedCodes);
    $ready = $status['reachable'] && $status['loaded'];
@endphp
<div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
    <button type="button" wire:click="openCountries" wire:loading.attr="disabled" wire:target="openCountries" @disabled(! $ready) class="{{ $primary }}"><x-icon name="globe" />{{ __('Ülke engelle') }}</button>
    <button type="button" wire:click="unblockCountries" wire:loading.attr="disabled" @disabled($toLift === 0) class="{{ $btn }}">
        {{ __('Engeli kaldır') }}@if ($toLift > 0) <span class="num">({{ Num::format($toLift) }})</span>@endif
    </button>
    <label class="relative w-full sm:ml-auto sm:w-64">
        <span class="sr-only">{{ __('Ülke ara') }}</span>
        <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Ülke ara') }}" spellcheck="false"
               class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
    </label>
</div>

@if (! $status['reachable'])
    <p class="flex items-start gap-2.5 border-b border-line bg-warn-soft px-5 py-2.5 text-[13px]" role="status"><x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />{{ __('Sunucu ajanına ulaşılamıyor; ülke engelleri şu an değiştirilemez.') }}</p>
@elseif (! $status['loaded'])
    <p class="flex items-start gap-2.5 border-b border-line bg-warn-soft px-5 py-2.5 text-[13px]" role="status"><x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />{{ __('Ülke tablosu henüz indirilmedi. Sunucu, bölgesel internet kayıt kurumlarının listelerini kurulumdan sonraki ilk dakikalarda indirir; o zamana kadar ülke engellenemez.') }}</p>
@endif

<div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line px-5 py-2.5 text-[13px]">
    @if ($blockedCount > 0)
        <x-status-badge :status="new Badge(trans_choice(':count ülke engelli', $blockedCount, ['count' => Num::format($blockedCount)]), 'info')" />
        <p class="min-w-0 flex-1 basis-full sm:basis-0">
            @if ($scope === 'server')
                {{ __('Kapsam: bütün sunucu. Siteler 403 sayfası gösterir; SSH, FTP, panel ve e-posta girişleri de kapalıdır. Gelen posta (25) ve DNS (53) açık kalır.') }}
            @else
                {{ __('Kapsam: yalnızca web siteleri (80, 443). Engelli ülkeden gelen ziyaretçi 403 sayfası görür; Cloudflare vekili arkasındaki sitelerde de geçerlidir.') }}
            @endif
        </p>
        <button type="button" wire:click="openScope" class="whitespace-nowrap text-accent hover:underline">{{ __('Kapsamı değiştir') }}</button>
    @else
        <p class="text-muted">{{ __('Engelli ülke yok. Bir ülkeyi engellediğinizde oraya kayıtlı adreslerden gelen ziyaretçiler sitelerinizde 403 sayfası görür.') }}</p>
    @endif
</div>

<div class="relative overflow-x-auto">
    <table class="w-full text-[13.5px] sm:min-w-[900px]">
        <thead>
            <tr class="{{ $thead }}">
                <th class="w-10 py-2.5 pl-5">
                    <input type="checkbox" class="size-4 accent-accent" aria-label="{{ __('Sayfadaki ülkelerin tümünü seç') }}" @checked($pageTicked) @disabled($tickable === [])
                           x-on:change="$wire.selectPage($event.target.checked)" wire:key="page-box-{{ $rows->currentPage() }}-{{ $pageTicked ? 1 : 0 }}">
                </th>
                <th class="{{ $th }}">{{ __('Ülke') }}</th>
                <th class="hidden text-right sm:table-cell {{ $th }}">{{ __('İstek (30 gün)') }}</th>
                <th class="hidden text-right sm:table-cell {{ $th }}">{{ __('Ziyaretçi (30 gün)') }}</th>
                <th class="hidden text-right sm:table-cell {{ $th }}">{{ __('Engelli adres') }}</th>
                <th class="py-2.5 pr-5 pl-3 text-right font-medium sm:px-3 sm:pl-8 sm:text-left">{{ __('Durum') }}</th>
                <th class="hidden w-32 sm:table-cell"><span class="sr-only">{{ __('İşlemler') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse ($rows as $row)
                @php $on = in_array($row->code, $codes, true); @endphp
                <tr wire:key="country-{{ $row->code }}" @class(['bg-accent-soft/40' => $on, 'hover:bg-surface-2/60' => ! $on])>
                    <td class="py-3 pl-5 align-top sm:align-middle">
                        @if ($row->home && ! $row->block)
                            <input type="checkbox" disabled class="size-4 opacity-40" aria-label="{{ __(':country seç', ['country' => $row->name]) }}">
                        @else
                            <input type="checkbox" wire:model.live="selected" value="{{ $row->code }}" class="size-4 accent-accent" aria-label="{{ __(':country seç', ['country' => $row->name]) }}">
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        <span class="inline-flex items-center gap-2 font-medium"><span class="w-7 shrink-0 rounded border border-line bg-surface-2 py-px text-center font-mono text-2xs font-medium">{{ $row->code }}</span>{{ $row->name }}</span>
                        <span class="num mt-1 block text-2xs text-muted sm:hidden">{{ __(':requests istek · :visitors ziyaretçi', ['requests' => Num::format($row->requests), 'visitors' => Num::format($row->visitors)]) }}</span>
                    </td>
                    <td class="num hidden px-3 py-3 text-right sm:table-cell">{{ Num::format($row->requests) }}</td>
                    <td class="num hidden px-3 py-3 text-right sm:table-cell">{{ Num::format($row->visitors) }}</td>
                    <td class="num hidden px-3 py-3 text-right sm:table-cell">{{ $row->addresses > 0 ? Num::format($row->addresses) : '—' }}</td>
                    <td class="py-3 pr-5 pl-3 text-right align-top sm:px-3 sm:pl-8 sm:text-left sm:align-middle">
                        @if ($row->block)
                            <span class="inline-flex flex-wrap items-center justify-end gap-x-2.5 gap-y-1 sm:justify-start">
                                <x-status-badge :status="new Badge(__('Engelli'), 'info')" />
                                <span class="num hidden text-2xs whitespace-nowrap text-muted sm:inline">{{ When::short($row->block->created_at) }}@if ($row->block->user) · {{ $row->block->user->name }}@endif</span>
                            </span>
                            @if ($row->home)
                                <span class="mt-1 flex items-center justify-end gap-1.5 text-2xs text-warn sm:justify-start"><x-icon name="triangle-alert" class="size-3.5 shrink-0" />{{ __('Şu an buradan bağlısınız') }}</span>
                            @endif
                            @if ($row->ranges === 0)
                                <span class="mt-1 block text-2xs text-muted">{{ __('Tabloda bu ülkeye kayıtlı adres yok') }}</span>
                            @endif
                        @elseif ($row->home)
                            <span class="inline-flex items-center gap-1.5 text-[13px] text-muted"><x-icon name="lock" class="size-3.5 shrink-0" />{{ __('Şu an buradan bağlısınız') }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="hidden py-3 pr-5 pl-3 text-right whitespace-nowrap sm:table-cell">
                        @if ($row->block)
                            <button type="button" wire:click="unblockCountry('{{ $row->code }}')" wire:loading.attr="disabled" class="{{ $rowAction }}">{{ __('Engeli kaldır') }}</button>
                        @elseif (! $row->home && $ready)
                            <button type="button" wire:click="openCountries('{{ $row->code }}')" class="{{ $rowAction }}">{{ __('Engelle') }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-5 py-12 text-center text-muted">{{ __('Aramanızla eşleşen ülke yok.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-2xs text-muted">
    <p class="min-w-0 flex-1 basis-full sm:basis-0">
        {{ __('Engelliler üstte, sonra en çok istek gelen ülkeler. Sayılar site istatistiklerinden.') }}
        @if ($status['updated'])
            {{ __('Ülke, adresin kayıtlı olduğu ülkedir (bölgesel internet kayıt kurumlarının listeleri, ayda bir güncellenir; son: :date).', ['date' => When::short($status['updated'])]) }}
        @else
            {{ __('Ülke, adresin kayıtlı olduğu ülkedir (bölgesel internet kayıt kurumlarının listeleri, ayda bir güncellenir).') }}
        @endif
        {{ __('İzinli adresler ülke engelinden etkilenmez.') }}
    </p>
    @include('livewire.security.ip-blocks.pager')
</div>
