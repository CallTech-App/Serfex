<x-layouts.app title="Genel Bakış · Servik">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">Genel Bakış</h1>
        <p class="mt-1 text-[13px] text-muted">Sunucunun ve sitelerin durumu.</p>
    </div>

    <section class="divide-y divide-line rounded-xl border border-line bg-surface text-[13.5px]">
        <div class="flex items-center gap-3 px-5 py-4">
            <livewire:agent-status />
        </div>
        <a href="{{ route('sites.index') }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
            <x-icon name="globe" class="text-muted" />
            <span>Siteler</span>
            <span class="ml-auto text-muted num">{{ \App\Models\Site::count() }} site</span>
            <x-icon name="chevron-right" class="text-muted" />
        </a>
    </section>
</x-layouts.app>
