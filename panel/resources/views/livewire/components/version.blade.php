{{-- A component's Sürüm cell: the installed version and its update, or the version an install would bring (for a database server, the default of the versions offered). --}}
@php($others = array_values(array_filter($r['choices'] ?? [], fn ($c) => ! $c['default'])))
@if ($r['installed'])
    {{ $r['version'] ?: '—' }}
    @if ($r['latest'])
        <span class="block text-2xs text-warn">→ {{ $r['latest'] }}</span>
    @endif
    @if ($r['kept'] ?? null)
        <span class="block font-sans text-2xs">{{ $r['kept']['until'] ? __(':name :date tarihine kadar saklanıyor', ['name' => $r['kept']['name'], 'date' => \App\Support\When::date(\Illuminate\Support\Carbon::parse($r['kept']['until']))]) : __(':name saklanıyor', ['name' => $r['kept']['name']]) }}</span>
    @elseif ($r['upgrade'] ?? null)
        <span class="block font-sans text-2xs">{{ __(':name sürümüne yükseltilebilir', ['name' => $r['upgrade']['name']]) }}</span>
    @endif
@elseif ($r['candidate'] ?? null)
    {{ $r['candidate'] }}
    <span class="block font-sans text-2xs">{{ $r['source'] ? __('kurulacak · :source', ['source' => $r['source']]) : __('kurulacak') }}</span>
    @if ($others !== [])
        <span class="block font-sans text-2xs">{{ __('Kurarken :versions de seçilebilir', ['versions' => implode(', ', array_column($others, 'major'))]) }}</span>
    @endif
@elseif (($r['choices'] ?? []) !== [])
    {{ implode(' · ', array_column($r['choices'], 'major')) }}
    <span class="block font-sans text-2xs">{{ __('kurulabilir sürümler') }}</span>
@else
    —
@endif
