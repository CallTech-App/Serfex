@props(['current', 'title', 'description' => null])
{{-- An Araçlar ve Ayarlar page: breadcrumb, title, the sections menu and the page's own cards. --}}
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('settings.general') }}" wire:navigate class="hover:text-accent">{{ __('Araçlar ve Ayarlar') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ $title }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h1 class="text-[22px] font-semibold tracking-tight">{{ $title }}</h1>
            {{ $actions ?? '' }}
        </div>
        @if ($description)
            <p class="mt-1 text-[13px] text-muted">{{ $description }}</p>
        @endif
    </div>
    <div class="grid items-start gap-6 md:grid-cols-[200px_minmax(0,1fr)]">
        <x-settings-nav :current="$current" />
        <div class="min-w-0 space-y-6">
            {{ $slot }}
        </div>
    </div>
</div>
