{{-- A component's Sürüm cell: the installed version and its update, or the version an install would bring. --}}
@if ($r['installed'])
    {{ $r['version'] ?: '—' }}
    @if ($r['latest'])
        <span class="block text-2xs text-warn">→ {{ $r['latest'] }}</span>
    @endif
@elseif ($r['candidate'] ?? null)
    {{ $r['candidate'] }}
    <span class="block font-sans text-2xs">{{ $r['source'] ? __('kurulacak · :source', ['source' => $r['source']]) : __('kurulacak') }}</span>
@else
    —
@endif
