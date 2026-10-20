@props(['title', 'show', 'size' => 'max-w-lg', 'close' => null])
{{-- Small dialog bound to a Livewire boolean property ($show), or to an
     expression on $wire with a $close statement that hides it. --}}
@php $close ??= '$wire.'.$show.' = false'; @endphp
<div x-data x-show="$wire.{{ $show }}" x-cloak @keydown.escape.window="if ($wire.{{ $show }}) {{ $close }}"
     class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
    <div {{ $attributes->class(['w-full rounded-xl border border-line bg-surface shadow-xl', $size]) }}
         role="dialog" aria-modal="true" aria-label="{{ $title }}" @click.outside="if ($wire.{{ $show }}) {{ $close }}">
        {{ $slot }}
    </div>
</div>
