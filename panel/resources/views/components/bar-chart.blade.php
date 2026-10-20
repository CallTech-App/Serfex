@props(['points', 'unit' => null])
{{--
    A plain bar chart: one bar per point, [label, value, tooltip]. Labels
    under the bars are thinned out to about eight.
--}}
@php
    $points = array_values($points);
    $n = count($points);
    $top = max(1, ...array_map(fn ($p) => (float) $p[1], $points ?: [[null, 0]]));
    $every = max(1, (int) ceil($n / 8));
@endphp
<figure {{ $attributes->class(['min-w-0']) }}>
    <div class="flex h-40 items-end gap-px sm:gap-0.5" role="img" aria-label="{{ $unit ?? 'Grafik' }}">
        @foreach ($points as $i => [$label, $value, $tip])
            <div class="group relative flex h-full min-w-0 flex-1 items-end" title="{{ $tip }}">
                <div class="w-full rounded-t-sm bg-muted/35 group-hover:bg-muted" style="height: {{ $value > 0 ? max(1.5, round($value / $top * 100, 2)) : 0 }}%"></div>
            </div>
        @endforeach
    </div>
    <div class="mt-1.5 flex gap-px text-2xs text-muted sm:gap-0.5" aria-hidden="true">
        @foreach ($points as $i => [$label])
            <span class="num min-w-0 flex-1 overflow-visible whitespace-nowrap">{{ $i % $every === 0 ? $label : '' }}</span>
        @endforeach
    </div>
</figure>
