@props(['customer'])
{{-- Etkin, Askıda, Davet bekliyor or the time a deleted customer goes for good. --}}
@php
    [$tone, $label] = match (true) {
        $customer->trashed() => ['crit', $customer->deleted_at->addSeconds(\App\Services\Customers\Customers::UNDO_SECONDS)->timezone(config('app.timezone'))->format('H:i').' sularında silinecek'],
        $customer->suspended_at !== null => ['warn', 'Askıda'],
        (bool) $customer->user?->isInvited() => ['info', 'Davet bekliyor'],
        default => ['ok', 'Etkin'],
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-2xs font-medium', [
    'ok' => 'bg-ok-soft text-ok', 'warn' => 'bg-warn-soft text-warn', 'crit' => 'bg-crit-soft text-crit', 'info' => 'bg-info-soft text-info',
][$tone]]) }} @if ($customer->suspend_reason) title="{{ $customer->suspend_reason }}" @endif>
    <i class="size-1.5 rounded-full {{ ['ok' => 'bg-ok', 'warn' => 'bg-warn', 'crit' => 'bg-crit', 'info' => 'bg-info'][$tone] }}"></i>{{ $label }}
</span>
