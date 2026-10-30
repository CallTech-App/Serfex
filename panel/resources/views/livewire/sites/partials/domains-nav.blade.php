{{-- Alan adları ve DNS of a site has two pages: its DNS records and the
     names it answers to. --}}
@php
    $links = array_filter([
        'dns' => auth()->user()->allows('dns') ? [__('DNS kayıtları'), route('sites.dns', $site)] : null,
        'names' => [__('Alt alan adları, ek adlar ve yönlendirmeler'), route('sites.domains', $site)],
    ]);
@endphp
@if (count($links) > 1)
    <nav class="flex flex-wrap gap-1.5 text-[13px]" aria-label="{{ __('Alan adları ve DNS') }}">
        @foreach ($links as $key => [$label, $link])
            <a href="{{ $link }}" wire:navigate @if ($current === $key) aria-current="page" @endif
               class="flex h-8 items-center rounded-full border px-3.5 {{ $current === $key ? 'border-accent bg-accent-soft font-medium text-accent' : 'border-line bg-surface text-muted hover:text-fg' }}">{{ $label }}</a>
        @endforeach
    </nav>
@endif
