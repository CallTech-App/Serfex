@props(['current'])
{{-- Araçlar ve Ayarlar: the sections, left of the page on wide screens and above it on narrow ones. --}}
@php
    $items = [
        ['settings.general', 'sliders-horizontal', 'Genel'],
        ['settings.server', 'server', __('Sunucu')],
        ['settings.notifications', 'bell', __('Bildirimler')],
        ['settings.admins', 'user-cog', __('Yöneticiler')],
        ['settings.api', 'key-round', __('API anahtarları')],
        ['settings.git', 'git-branch', __('Git sağlayıcıları')],
        ['settings.audit', 'history', __('İşlem günlüğü')],
        ['settings.updates', 'refresh-cw', __('Güncellemeler')],
        ['setup', 'list-checks', __('Kurulum sihirbazı')],
    ];
    $updates = count(app(\App\Services\Updates\Updates::class)->notices());
@endphp
<nav class="scroll-thin flex gap-0.5 overflow-x-auto rounded-xl border border-line bg-surface p-2 text-[13.5px] md:sticky md:top-20 md:flex-col" aria-label="{{ __('Ayar bölümleri') }}">
    @foreach ($items as [$route, $icon, $label])
        @php $on = $current === $route; @endphp
        <a href="{{ route($route) }}" wire:navigate @if ($on) aria-current="page" @endif
           class="flex h-9 shrink-0 items-center gap-2.5 rounded-md px-3 whitespace-nowrap {{ $on ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">
            <x-icon :name="$icon" class="size-4" />{{ __($label) }}
            @if ($route === 'settings.updates' && $updates)
                <span class="num ml-auto min-w-5 rounded-full bg-warn px-1.5 text-center text-2xs leading-5 font-semibold text-nav">{{ $updates }}</span>
            @endif
        </a>
    @endforeach
</nav>
