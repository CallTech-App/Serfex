@php
    use App\Services\Monitor\IpBlocks;
    use App\Support\Load;
    use App\Support\Num;
    use App\Support\When;
    $n = $allMatching ? $matching : count($selected);
    $sortHead = fn (string $key) => $sort === $key ? 'inline-flex items-center gap-1 text-fg uppercase' : 'inline-flex items-center gap-1 uppercase hover:text-fg';
@endphp
<div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
    <button type="button" wire:click="openPaste" class="{{ $primary }}"><x-icon name="ban" />{{ __('IP engelle') }}</button>
    <button type="button" wire:click="unblockSelected" wire:loading.attr="disabled" @disabled($n === 0) class="{{ $btn }}">
        {{ __('Engeli kaldır') }}@if ($n > 0) <span class="num">({{ Num::format($n) }})</span>@endif
    </button>
    <button type="button" wire:click="allowSelected" wire:loading.attr="disabled" @disabled($n === 0) class="{{ $btn }}"
            wire:confirm="{{ __('Seçili adresler izinlilere eklensin mi? Engelleri kalkar ve bir daha engellenmezler.') }}">{{ __('İzinlilere ekle') }}</button>
    <div class="relative" x-data="{ open: false }">
        <button type="button" @click="open = !open" @disabled($n === 0) class="{{ $btn }}" aria-haspopup="true" :aria-expanded="open">{{ __('Süreyi değiştir') }}<x-icon name="chevron-down" class="size-3.5 text-muted" /></button>
        <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full left-0 z-20 mt-1 w-44 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
            @foreach (IpBlocks::DURATIONS as $m => $label)
                <button type="button" wire:click="changeSelected({{ $m }})" @click="open = false" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ $label }}</button>
            @endforeach
        </div>
    </div>
    <div class="flex w-full gap-2 sm:ml-auto sm:w-auto">
        <select wire:model.live="source" aria-label="{{ __('Kaynak') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px] outline-none focus:border-accent">
            <option value="">{{ __('Tüm kaynaklar') }}</option>
            @foreach ($this::SOURCES as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <label class="relative min-w-0 flex-1 sm:w-64 sm:flex-none">
            <span class="sr-only">{{ __('Adres, ülke ya da neden ara') }}</span>
            <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Adres, ülke ya da neden ara') }}" spellcheck="false"
                   class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
        </label>
    </div>
</div>

@if ($n > 0)
    <p class="border-b border-line bg-accent-soft/40 px-5 py-2 text-[13px]">
        @if ($allMatching)
            {{ __('Listedeki :count adresin tümü seçildi.', ['count' => Num::format($matching)]) }}
            <button type="button" wire:click="clearSelection" class="text-accent hover:underline">{{ __('Seçimi temizle') }}</button>
        @else
            {{ trans_choice(':count adres seçildi.', count($selected), ['count' => Num::format(count($selected))]) }}
            @if ($matching > count($selected))
                <button type="button" wire:click="selectAllMatching" class="text-accent hover:underline">{{ __('Listedeki :count adresin tümünü seç', ['count' => Num::format($matching)]) }}</button>
            @endif
        @endif
    </p>
@endif

<div class="relative overflow-x-auto">
    <table class="w-full text-[13.5px] sm:min-w-[1000px]">
        <thead>
            <tr class="{{ $thead }}">
                <th class="w-10 py-2.5 pl-5">
                    <input type="checkbox" class="size-4 accent-accent" aria-label="{{ __('Sayfadaki adreslerin tümünü seç') }}" @checked($pageTicked) @disabled($rows->isEmpty())
                           x-on:change="$wire.selectPage($event.target.checked)" wire:key="page-box-{{ $rows->currentPage() }}-{{ $pageTicked ? 1 : 0 }}">
                </th>
                <th class="{{ $th }}">{{ __('Adres') }}</th>
                <th class="hidden sm:table-cell {{ $th }}">{{ __('Ülke') }}</th>
                <th class="hidden sm:table-cell {{ $th }}">{{ __('Neden') }}</th>
                <th class="hidden sm:table-cell {{ $th }}">
                    <button type="button" wire:click="$set('sort', 'new')" class="{{ $sortHead('new') }}" title="{{ __('Son engellenen üstte') }}">{{ __('Engelleyen') }}@if ($sort === 'new')<x-icon name="arrow-down" class="size-3" />@endif</button>
                </th>
                <th class="py-2.5 pr-5 pl-3 text-right font-medium sm:px-3 sm:text-left">
                    <button type="button" wire:click="$set('sort', 'ending')" class="{{ $sortHead('ending') }}" title="{{ __('Süresi ilk dolacak olan üstte') }}">{{ __('Kalan süre') }}@if ($sort === 'ending')<x-icon name="arrow-down" class="size-3" />@endif</button>
                </th>
                <th class="hidden w-32 sm:table-cell"><span class="sr-only">{{ __('İşlemler') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse ($rows as $block)
                @php $on = $allMatching || in_array((string) $block->id, $ticked, true); @endphp
                <tr wire:key="block-{{ $block->id }}" @class(['bg-accent-soft/40' => $on, 'hover:bg-surface-2/60' => ! $on])>
                    <td class="py-3 pl-5 align-top sm:align-middle">
                        @if ($allMatching)
                            <input type="checkbox" checked disabled class="size-4 accent-accent" aria-label="{{ __(':address seç', ['address' => $block->address]) }}">
                        @else
                            <input type="checkbox" wire:model.live="selected" value="{{ $block->id }}" class="size-4 accent-accent" aria-label="{{ __(':address seç', ['address' => $block->address]) }}">
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        <a href="{{ route('security.blocks.address', $block->address) }}" wire:navigate class="font-mono text-[13px] font-medium break-all hover:text-accent sm:whitespace-nowrap">{{ $block->address }}</a>
                        <span class="mt-1 block text-[13px] sm:hidden">{{ $block->reason ?: $block->reach() }}</span>
                        <span class="mt-0.5 block text-2xs text-muted sm:hidden">@if (\App\Services\Security\AddressGeo::name($block->country) !== '')<span class="font-mono">{{ $block->country }}</span> · @endif{{ $block->byLabel() }} · {{ When::short($block->created_at) }}</span>
                    </td>
                    <td class="hidden px-3 py-3 sm:table-cell"><x-country :code="$block->country" /></td>
                    <td class="hidden px-3 py-3 sm:table-cell">
                        {{ $block->reason ?: '—' }}
                        <span @class(['block text-2xs text-muted', 'font-mono' => $block->source !== 'fail2ban' && $block->site !== null])>{{ $block->reach() }}</span>
                    </td>
                    <td class="hidden px-3 py-3 whitespace-nowrap sm:table-cell">{{ $block->byLabel() }}<span class="num block text-2xs text-muted">{{ When::short($block->created_at) }}</span></td>
                    <td class="num py-3 pr-5 pl-3 text-right align-top whitespace-nowrap sm:px-3 sm:text-left sm:align-middle">{{ Load::remaining($block->expires_at) }}</td>
                    <td class="hidden py-3 pr-5 pl-3 text-right whitespace-nowrap sm:table-cell">
                        <button type="button" wire:click="unblock({{ $block->id }})" wire:loading.attr="disabled" class="{{ $rowAction }}">{{ __('Engeli kaldır') }}</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-5 py-12 text-center">
                        @if ($lookup !== null)
                            <p class="font-medium"><x-t text=":address engelli değil."><x-slot:address><span class="font-mono">{{ $lookup }}</span></x-slot:address></x-t></p>
                            <p class="mt-3 flex flex-wrap justify-center gap-2">
                                <a href="{{ route('security.blocks.address', $lookup) }}" wire:navigate class="{{ $btn }}"><x-icon name="search" />{{ __('Adresi incele') }}</a>
                                <button type="button" wire:click="openPaste(@js($lookup))" class="{{ $btn }}"><x-icon name="ban" />{{ __('Engelle') }}</button>
                            </p>
                        @elseif ($search !== '' || $source !== '')
                            <p class="text-muted">{{ __('Aramanızla eşleşen engel yok.') }}</p>
                        @else
                            <p class="font-medium">{{ __('Engelli adres yok') }}</p>
                            <p class="mx-auto mt-1 max-w-xl text-[13px] text-muted">{{ __('Bir adresi ya da bir listeyi "IP engelle" ile engelleyin. Fail2ban\'ın ve Serfex AI\'ın engelledikleri de burada görünür.') }}</p>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-2xs text-muted">
    <p class="min-w-0 flex-1 basis-full sm:basis-0">{{ __('Engel bütün sunucuyu kapsar: adresten gelen hiçbir bağlantı (web, posta, SSH) kabul edilmez. Fail2ban engelleri yalnızca saldırılan hizmetin portunu kapatır. Süresi dolan engel kendiliğinden kalkar.') }}</p>
    @include('livewire.security.ip-blocks.pager')
</div>
