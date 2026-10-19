{{-- Every limit of the package with what is used of it. --}}
<div class="divide-y divide-line text-[13.5px]">
    @foreach ($report as $key => $row)
        @php
            $pct = $row['ratio'] === null ? null : min(100, (int) round($row['ratio'] * 100));
            $bar = ['ok' => 'bg-accent', 'warn' => 'bg-warn', 'crit' => 'bg-crit'][$row['level']];
        @endphp
        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 px-5 py-3.5 sm:grid-cols-[12rem_minmax(0,1fr)_11rem]">
            <span>{{ $row['label'] }}@if ($key === 'traffic') <span class="text-muted">(bu ay)</span>@endif</span>
            <span @class(['num text-right sm:order-last', 'font-medium text-warn' => $row['level'] === 'warn', 'font-medium text-crit' => $row['level'] === 'crit'])>
                {{ $row['text'] }}
            </span>
            <div class="col-span-2 sm:col-span-1">
                @if ($row['used'] === null)
                    <span class="text-[12.5px] text-muted">Veritabanı yönetimi geldiğinde sayılır.</span>
                @elseif ($pct === null)
                    <span class="text-[12.5px] text-muted">Sınırsız</span>
                @else
                    <div class="h-1.5 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $row['label'] }}">
                        <div class="h-full rounded-full {{ $bar }}" style="width: {{ max($pct, $row['used'] > 0 ? 2 : 0) }}%"></div>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
