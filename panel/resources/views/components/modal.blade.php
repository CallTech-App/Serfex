@props(['title', 'show', 'size' => 'max-w-lg'])
{{-- Small dialog bound to a Livewire boolean property ($show). --}}
<div x-data x-show="$wire.{{ $show }}" x-cloak @keydown.escape.window="$wire.{{ $show }} = false"
     class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
    <div {{ $attributes->class(['w-full rounded-xl border border-line bg-surface shadow-xl', $size]) }}
         role="dialog" aria-modal="true" aria-label="{{ $title }}" @click.outside="$wire.{{ $show }} = false">
        {{ $slot }}
    </div>
</div>
