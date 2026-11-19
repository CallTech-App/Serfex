@php
    use App\Services\Monitor\IpBlocks;
    use App\Support\Num;
    use App\Support\When;
    $n = count($selected);
@endphp
<div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
    <button type="button" wire:click="openAllow" class="{{ $primary }}"><x-icon name="plus" />{{ __('Adres ekle') }}</button>
    <button type="button" wire:click="removeAllows" wire:loading.attr="disabled" @disabled($n === 0) class="{{ $btn }}">
        {{ __('Kaldır') }}@if ($n > 0) <span class="num">({{ Num::format($n) }})</span>@endif
    </button>
    <p class="min-w-0 flex-1 basis-full text-[13px] text-muted sm:ml-2 sm:basis-0">{{ __('Bu adresler hiçbir zaman engellenmez: elle, Fail2ban ya da Serfex AI ile.') }}</p>
    <label class="relative w-full sm:w-56">
        <span class="sr-only">{{ __('Adres ya da not ara') }}</span>
        <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Adres ya da not ara') }}" spellcheck="false"
               class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
    </label>
</div>

<div class="relative overflow-x-auto">
    <table class="w-full min-w-[860px] text-[13.5px]">
        <thead>
            <tr class="{{ $thead }}">
                <th class="w-10 py-2.5 pl-5">
                    <input type="checkbox" class="size-4 accent-accent" aria-label="{{ __('Sayfadaki adreslerin tümünü seç') }}" @checked($pageTicked) @disabled($rows->isEmpty())
                           x-on:change="$wire.selectPage($event.target.checked)" wire:key="page-box-{{ $rows->currentPage() }}-{{ $pageTicked ? 1 : 0 }}">
                </th>
                <th class="{{ $th }}">{{ __('Adres') }}</th>
                <th class="{{ $th }}">{{ __('Ülke') }}</th>
                <th class="{{ $th }}">{{ __('Not') }}</th>
                <th class="{{ $th }}">{{ __('Ekleyen') }}</th>
                <th class="w-24"><span class="sr-only">{{ __('İşlemler') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @foreach ($rows as $allow)
                @php $on = in_array((string) $allow->id, $ticked, true); @endphp
                <tr wire:key="allow-{{ $allow->id }}" @class(['bg-accent-soft/40' => $on, 'hover:bg-surface-2/60' => ! $on])>
                    <td class="py-3 pl-5"><input type="checkbox" wire:model.live="selected" value="{{ $allow->id }}" class="size-4 accent-accent" aria-label="{{ __(':address seç', ['address' => $allow->address]) }}"></td>
                    <td class="px-3 py-3 whitespace-nowrap">
                        <a href="{{ route('security.blocks.address', $allow->address) }}" wire:navigate class="font-mono text-[13px] font-medium hover:text-accent">{{ $allow->address }}</a>
                        @if ($mine !== '' && IpBlocks::contains($allow->address, $mine))
                            <span class="ml-2 rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted">{{ __('Şu anki bağlantınız') }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-3"><x-country :code="$allow->country" /></td>
                    <td class="px-3 py-3">{{ $allow->note ?: '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $allow->user->name ?? '—' }}<span class="num block text-2xs text-muted">{{ When::short($allow->created_at) }}</span></td>
                    <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                        <button type="button" wire:click="removeAllow({{ $allow->id }})" wire:loading.attr="disabled" class="{{ $rowAction }}">{{ __('Kaldır') }}</button>
                    </td>
                </tr>
            @endforeach
            @foreach ($own as $address)
                <tr wire:key="own-{{ $address }}" class="hover:bg-surface-2/60">
                    <td class="py-3 pl-5"><input type="checkbox" disabled class="size-4 opacity-40" aria-label="{{ __(':address seç', ['address' => $address]) }}"></td>
                    <td class="px-3 py-3 font-mono text-[13px] font-medium whitespace-nowrap">{{ $address }}</td>
                    <td class="px-3 py-3"><x-country :code="$ownCountries[$address] ?? null" /></td>
                    <td class="px-3 py-3">{{ __('Sunucunun kendi adresi') }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">Serfex</td>
                    <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap"><span class="inline-flex text-muted" title="{{ __('Her zaman izinlidir; kaldırılamaz') }}"><x-icon name="lock" class="size-3.5" /></span></td>
                </tr>
            @endforeach
            @if ($rows->isEmpty() && $own === [])
                <tr>
                    <td colspan="6" class="px-5 py-12 text-center">
                        @if ($search !== '')
                            <p class="text-muted">{{ __('Aramanızla eşleşen izinli adres yok.') }}</p>
                        @else
                            <p class="font-medium">{{ __('İzinli adres yok') }}</p>
                            <p class="mx-auto mt-1 max-w-xl text-[13px] text-muted">{{ __('Ofisinizin sabit IP\'si ya da bir ödeme sağlayıcısının bildirim adresleri gibi, hiçbir zaman engellenmemesi gereken adresleri ekleyin.') }}</p>
                        @endif
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

<div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-2xs text-muted">
    <p class="min-w-0 flex-1 basis-full sm:basis-0">{{ __('Cloudflare\'in vekil adresleri de her zaman izinlidir ve burada listelenmez. Ev ve mobil bağlantıların adresi değişebilir; sabit IP\'niz yoksa adresinizi eklemek sizi her zaman korumaz.') }}</p>
    {{-- The server's own addresses follow the saved ones, so a count of one page would be short of the rows. --}}
    @if ($rows->hasPages())
        @include('livewire.security.ip-blocks.pager')
    @endif
</div>
