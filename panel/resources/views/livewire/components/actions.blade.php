{{-- A component row's actions; on phones they sit under the name. --}}
@if ($r['busy'] !== null)
    @if ($r['operation'])
        <button type="button" wire:click="show({{ $r['operation'] }})" class="{{ $link }}">{{ __('İlerlemeyi göster') }}</button>
    @endif
@elseif (! $r['known'])
    <span class="text-2xs text-muted">{{ __('Ajan bu bileşeni tanımıyor') }}</span>
@elseif ($r['later'] ?? false)
    <span class="text-2xs text-muted">{{ $r['refusal'] }}</span>
@elseif ($r['installed'])
    <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1 sm:justify-end">
        @if ($r['id'] === 'modsecurity')
            <button type="button" wire:click="openSettings" @disabled($busy) class="{{ $link }}">{{ __('Ayarlar') }}</button>
        @elseif ($r['mail'] ?? false)
            <a href="{{ route('mail.settings') }}" wire:navigate class="{{ $link }}">{{ __('E-posta ayarları') }}</a>
        @endif
        @if ($r['latest'])
            <button type="button" wire:click="ask('{{ $r['id'] }}', 'update')" @disabled($busy) title="{{ $blocker['text'] ?? '' }}" class="{{ $link }}">{{ __('Güncelle') }}</button>
        @endif
        @if ($r['kept'] ?? null)
            <button type="button" wire:click="ask('{{ $r['id'] }}', 'remove-old')" @disabled($busy) title="{{ $blocker['text'] ?? '' }}" class="text-[13px] text-muted hover:text-crit disabled:opacity-40">{{ __('Eski sürümü kaldır') }}</button>
        @elseif ($r['upgrade'] ?? null)
            <button type="button" wire:click="ask('{{ $r['id'] }}', 'upgrade')" @disabled($busy) title="{{ $blocker['text'] ?? '' }}" class="{{ $link }}">{{ __('Sürümü yükselt') }}</button>
        @endif
        @if ($r['refusal'])
            <span class="cursor-not-allowed text-[13px] text-muted opacity-50" title="{{ $r['refusal'] }}" aria-disabled="true">{{ __('Kaldır') }}</span>
        @else
            <button type="button" wire:click="ask('{{ $r['id'] }}', 'remove')" @disabled($busy) class="text-[13px] text-muted hover:text-crit disabled:opacity-40">{{ __('Kaldır') }}</button>
        @endif
    </span>
    @if ($r['refusal'])
        <span class="mt-1 block text-2xs text-muted">{{ $r['refusal'] }}</span>
    @endif
@elseif ($r['refusal'])
    <span class="text-2xs text-muted">{{ $r['refusal'] }}</span>
    @if ($r['mail'] ?? false)
        <a href="{{ route('mail.index') }}" wire:navigate class="mt-0.5 block text-2xs text-accent hover:underline">{{ __('E-postaya git') }}</a>
    @endif
@else
    <button type="button" wire:click="ask('{{ $r['id'] }}', 'install')" @disabled($busy) title="{{ $blocker['text'] ?? '' }}" class="{{ $link }}">{{ __('Kur') }}</button>
@endif
