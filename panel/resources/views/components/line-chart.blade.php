@props(['series', 'max' => null, 'labels' => []])
{{--
    A plain line chart of one or two series (the second dashed), scaled to
    the largest value or $max. $labels are shown under it, spread evenly.
--}}
@php
    $series = array_values(array_map(fn ($s) => array_values(array_map('floatval', $s)), $series));
    $all = array_merge(...($series ?: [[]]));
    $top = max($max ?? 0, $all ? max($all) : 0, 0.0001);
    $line = function (array $points) use ($top) {
        $n = count($points);
        $out = [];
        foreach ($points as $i => $v) {
            $out[] = round($n > 1 ? $i * 100 / ($n - 1) : 50, 2).','.round(40 - min($v, $top) / $top * 38 - 1, 2);
        }

        return implode(' ', $out);
    };
@endphp
<figure {{ $attributes->class(['min-w-0']) }}>
    <svg viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true" class="block h-24 w-full text-fg/70">
        <line x1="0" y1="39.5" x2="100" y2="39.5" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" opacity=".15" />
        @foreach ($series as $i => $points)
            @if (count($points) > 1)
                @if ($i === 0)
                    <polyline points="0,40 {{ $line($points) }} 100,40" fill="currentColor" opacity=".07" stroke="none" />
                @endif
                <polyline points="{{ $line($points) }}" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round"
                          @if ($i > 0) stroke-dasharray="4 3" opacity=".6" @endif />
            @endif
        @endforeach
    </svg>
    @if ($labels)
        <div class="num mt-1 flex justify-between text-2xs text-muted">
            @foreach ($labels as $label)
                <span>{{ $label }}</span>
            @endforeach
        </div>
    @endif
</figure>
