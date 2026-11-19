{{-- "1–25 / 47" and the page buttons under a list ($rows is its paginator). --}}
@if ($rows->total() > 0)
    <p class="num flex items-center gap-2 text-[13px] whitespace-nowrap">
        <span class="mr-1">{{ $rows->firstItem() }}–{{ $rows->lastItem() }} / {{ \App\Support\Num::format($rows->total()) }}</span>
        @if ($rows->hasPages())
            <button type="button" wire:click="previousPage" @disabled($rows->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
            <button type="button" wire:click="nextPage" @disabled(! $rows->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
        @endif
    </p>
@endif
