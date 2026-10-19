@props(['points', 'max' => null])
{{-- A small line chart of a series, scaled to its largest value (or $max). --}}
@php
    $points = array_values(array_map('floatval', $points));
    $n = count($points);
    $top = max($max ?? 0, $n ? max($points) : 0, 0.0001);
    $coords = [];
    foreach ($points as $i => $v) {
        $coords[] = round($n > 1 ? $i * 100 / ($n - 1) : 50, 2).','.round(30 - min($v, $top) / $top * 28 - 1, 2);
    }
@endphp
<svg viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" {{ $attributes->class(['block h-10 w-full']) }}>
    @if ($n > 1)
        <polyline points="0,30 {{ implode(' ', $coords) }} 100,30" fill="currentColor" opacity=".08" stroke="none" />
        <polyline points="{{ implode(' ', $coords) }}" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
    @else
        <line x1="0" y1="29" x2="100" y2="29" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" opacity=".3" />
    @endif
</svg>
