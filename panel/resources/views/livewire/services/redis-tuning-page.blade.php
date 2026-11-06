<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('services.index') }}" wire:navigate class="hover:text-accent">{{ __('Hizmetler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('services.index', ['tab' => 'optimization']) }}" wire:navigate class="hover:text-accent">{{ __('Optimizasyon') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Redis (site önbellekleri)') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Redis (site önbellekleri)') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ $apps->isEmpty() ? __('Redis kullanan Laravel uygulaması yok.') : __('Kendi Redis\'i olan Laravel uygulamaları: :sites', ['sites' => $apps->map(fn ($a) => $a->site?->domain)->filter()->join(', ')]) }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <livewire:services.database-tuning :unit="$unit" key="tuning-redis-sites" />
    </section>
</div>
