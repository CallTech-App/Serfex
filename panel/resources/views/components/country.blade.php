@props(['code'])
{{-- A country as lists show it: its two-letter code in a chip, then its name. --}}
@php $name = \App\Services\Security\AddressGeo::name($code); @endphp
@if ($name !== '')
    <span {{ $attributes->class('inline-flex items-center gap-2 whitespace-nowrap') }}><span class="w-7 rounded border border-line bg-surface-2 py-px text-center font-mono text-2xs font-medium">{{ $code }}</span>{{ $name }}</span>
@else
    <span class="text-muted">—</span>
@endif
