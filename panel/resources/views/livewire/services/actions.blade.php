{{-- A service row's actions; on phones they sit under the name. --}}
<span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1 sm:justify-end">
    @if (in_array($s['state'], ['activating', 'deactivating'], true))
        <span class="text-2xs text-muted">{{ __('Bekleyin') }}</span>
    @elseif ($running)
        <button type="button" wire:click="act('{{ $s['unit'] }}', 'restart')" wire:loading.attr="disabled" class="{{ $link }}">{{ __('Yeniden başlat') }}</button>
        @if ($s['protected'] ?? null)
            <span class="cursor-not-allowed text-[13px] text-muted opacity-50" title="{{ $s['protected'] }}" aria-disabled="true">{{ __('Durdur') }}</span>
        @else
            <button type="button" wire:click="act('{{ $s['unit'] }}', 'stop')" wire:loading.attr="disabled" class="text-[13px] text-muted hover:text-crit disabled:opacity-40">{{ __('Durdur') }}</button>
        @endif
    @else
        <button type="button" wire:click="act('{{ $s['unit'] }}', 'start')" wire:loading.attr="disabled" class="{{ $link }}">{{ __('Başlat') }}</button>
    @endif
</span>
