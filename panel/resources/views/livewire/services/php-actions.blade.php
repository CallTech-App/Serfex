{{-- A PHP version's actions; on phones they sit under the name. --}}
@if ($r['busy'] !== null)
    @if ($r['operation'])
        <button type="button" wire:click="show({{ $r['operation'] }})" class="{{ $link }}">{{ __('İlerlemeyi göster') }}</button>
    @endif
@elseif (! $r['known'])
    <span class="text-2xs text-muted">{{ __('Ajan bu sürümü tanımıyor') }}</span>
@elseif ($r['installed'])
    <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1 sm:justify-end">
        <a href="{{ route('services.php', $r['number']) }}" wire:navigate class="{{ $link }}">{{ __('Ayarlar ve eklentiler') }}</a>
        @if ($r['latest'])
            <button type="button" wire:click="askPhp('{{ $r['id'] }}', 'update')" @disabled($busy) class="{{ $link }}">{{ __('Güncelle') }}</button>
        @endif
        @if ($r['refusal'])
            <span class="cursor-not-allowed text-[13px] text-muted opacity-50" title="{{ $r['refusal'] }}" aria-disabled="true">{{ __('Kaldır') }}</span>
        @else
            <button type="button" wire:click="askPhp('{{ $r['id'] }}', 'remove')" @disabled($busy) class="text-[13px] text-muted hover:text-crit disabled:opacity-40">{{ __('Kaldır') }}</button>
        @endif
    </span>
    @if ($r['refusal'])
        <span class="mt-1 block text-2xs text-muted">{{ $r['refusal'] }}</span>
    @endif
@elseif ($r['refusal'])
    <span class="text-2xs text-muted">{{ $r['refusal'] }}</span>
@else
    <button type="button" wire:click="askPhp('{{ $r['id'] }}', 'install')" @disabled($busy) class="{{ $link }}">{{ __('Kur') }}</button>
@endif
