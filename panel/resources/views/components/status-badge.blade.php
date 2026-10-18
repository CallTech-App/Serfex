@props(['status'])
@php
    $tone = [
        'ok' => ['bg-ok-soft text-ok', 'bg-ok'],
        'warn' => ['bg-warn-soft text-warn', 'bg-warn'],
        'crit' => ['bg-crit-soft text-crit', 'bg-crit'],
        'info' => ['bg-info-soft text-info', 'bg-info'],
        'muted' => ['bg-surface-2 text-muted', 'bg-muted'],
    ][$status->tone()];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-2xs font-medium', $tone[0]]) }}>
    @if ($status->isBusy())
        <x-icon name="loader-circle" class="size-3 animate-spin" />
    @else
        <i class="size-1.5 rounded-full {{ $tone[1] }}"></i>
    @endif
    {{ $status->label() }}
</span>
