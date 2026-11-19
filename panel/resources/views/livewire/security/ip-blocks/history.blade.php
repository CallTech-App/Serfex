@php
    use App\Support\Num;
    use App\Support\When;
    $n = count($selected);
@endphp
<div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
    <button type="button" wire:click="blockAgain" wire:loading.attr="disabled" @disabled($n === 0) class="{{ $btn }}">
        {{ __('Yeniden engelle') }}@if ($n > 0) <span class="num">({{ Num::format($n) }})</span>@endif
    </button>
    <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
        <select wire:model.live="days" aria-label="{{ __('Dönem') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px] outline-none focus:border-accent">
            @foreach ($this::PERIODS as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="source" aria-label="{{ __('Kaynak') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px] outline-none focus:border-accent">
            <option value="">{{ __('Tüm kaynaklar') }}</option>
            @foreach ($this::SOURCES as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <label class="relative min-w-0 flex-1 basis-full sm:w-64 sm:flex-none sm:basis-auto">
            <span class="sr-only">{{ __('Adres, ülke ya da neden ara') }}</span>
            <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Adres, ülke ya da neden ara') }}" spellcheck="false"
                   class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
        </label>
    </div>
</div>

<div class="relative overflow-x-auto">
    <table class="w-full min-w-[940px] text-[13.5px]">
        <thead>
            <tr class="{{ $thead }}">
                <th class="w-10 py-2.5 pl-5"><span class="sr-only">{{ __('Seç') }}</span></th>
                <th class="{{ $th }}">{{ __('Adres') }}</th>
                <th class="{{ $th }}">{{ __('Ülke') }}</th>
                <th class="{{ $th }}">{{ __('Neden · engelleyen') }}</th>
                <th class="{{ $th }}"><span class="inline-flex items-center gap-1 text-fg">{{ __('Nasıl bitti') }}<x-icon name="arrow-down" class="size-3" /></span></th>
                <th class="w-36"><span class="sr-only">{{ __('İşlemler') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse ($rows as $block)
                @php
                    $on = in_array((string) $block->id, $ticked, true);
                    $state = isset($blockedNow[$block->address]) ? 'blocked' : (isset($allowedNow[$block->address]) ? 'allowed' : null);
                @endphp
                <tr wire:key="ended-{{ $block->id }}" @class(['bg-accent-soft/40' => $on, 'hover:bg-surface-2/60' => ! $on])>
                    <td class="py-3 pl-5">
                        @if ($state === null)
                            <input type="checkbox" wire:model.live="selected" value="{{ $block->id }}" class="size-4 accent-accent" aria-label="{{ __(':address seç', ['address' => $block->address]) }}">
                        @endif
                    </td>
                    <td class="px-3 py-3 whitespace-nowrap"><a href="{{ route('security.blocks.address', $block->address) }}" wire:navigate class="font-mono text-[13px] font-medium hover:text-accent">{{ $block->address }}</a></td>
                    <td class="px-3 py-3"><x-country :code="$block->country" /></td>
                    <td class="px-3 py-3">{{ $block->reason ?: '—' }}<span class="num block text-2xs text-muted">{{ $block->byLabel() }} · {{ When::short($block->created_at) }}</span></td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $block->endedLabel() }}<span class="num block text-2xs text-muted">{{ When::withTime($block->endedAt()) }}</span></td>
                    <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                        @if ($state === 'blocked')
                            <span class="text-2xs text-muted">{{ __('Şu an engelli') }}</span>
                        @elseif ($state === 'allowed')
                            <span class="text-2xs text-muted">{{ __('İzinli') }}</span>
                        @else
                            <button type="button" wire:click="blockAgain({{ $block->id }})" wire:loading.attr="disabled" class="{{ $rowAction }}">{{ __('Yeniden engelle') }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-5 py-12 text-center">
                        @if ($search !== '' || $source !== '')
                            <p class="text-muted">{{ __('Aramanızla eşleşen engel yok.') }}</p>
                        @else
                            <p class="font-medium">{{ __('Bu dönemde biten engel yok') }}</p>
                            <p class="mx-auto mt-1 max-w-xl text-[13px] text-muted">{{ __('Süresi dolan ve kaldırılan engeller burada görünür; aynı adresi tek tıkla yeniden engelleyebilirsiniz.') }}</p>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-2xs text-muted">
    <p class="min-w-0 flex-1 basis-full sm:basis-0">{{ __('Biten ve kaldırılan engeller 90 gün burada kalır. Yeniden engellenen adres, önceki süresi kadar engellenir.') }}</p>
    @include('livewire.security.ip-blocks.pager')
</div>
