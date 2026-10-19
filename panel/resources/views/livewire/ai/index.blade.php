@php $at = fn ($d) => $d?->timezone(config('app.timezone')); @endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-semibold tracking-tight">Servik AI</h1>
            <p class="mt-1 text-[13px] text-muted">Sunucunuz hakkında soru sorun; değişiklikleri onayınızla yapar.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('ai.audit') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13.5px] hover:bg-surface-2"><x-icon name="scroll-text" />Kayıt</a>
            <a href="{{ route('ai.settings') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13.5px] hover:bg-surface-2"><x-icon name="settings" />Ayarlar</a>
            <button type="button" wire:click="startNew" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="plus" />Yeni sohbet</button>
        </div>
    </div>

    <section class="grid min-w-0 grid-cols-1 overflow-hidden rounded-xl border border-line bg-surface md:h-[calc(100vh-12.5rem)] md:min-h-[560px] md:grid-cols-[240px_1fr]">
        <nav class="hidden min-h-0 flex-col border-r border-line md:flex" aria-label="Sohbetler">
            <p class="flex h-11 items-center border-b border-line px-4 text-2xs font-medium tracking-[.06em] text-muted uppercase">Sohbetler</p>
            <div class="flex-1 space-y-0.5 overflow-y-auto p-2 text-[13px]">
                @forelse ($conversations as $c)
                    <button type="button" wire:key="c-{{ $c->id }}" wire:click="open({{ $c->id }})"
                            @class(['flex w-full items-center gap-2 truncate rounded-md px-3 py-1.5 text-left',
                                'bg-surface-2 font-medium' => $c->id === $conversation?->id,
                                'text-muted hover:bg-surface-2 hover:text-fg' => $c->id !== $conversation?->id])>
                        <span class="truncate">{{ $c->title }}</span>
                        @if ($c->status === 'running')
                            <x-icon name="loader-circle" class="ml-auto size-3 animate-spin text-accent" />
                        @endif
                    </button>
                @empty
                    <p class="px-3 py-2 text-muted">Henüz sohbet yok.</p>
                @endforelse
            </div>
        </nav>

        <div class="flex min-h-[70vh] min-w-0 flex-col md:min-h-0">
            <div class="flex h-11 items-center gap-2 border-b border-line px-5">
                <h2 class="truncate text-[14px] font-semibold">{{ $conversation?->title ?? 'Yeni sohbet' }}</h2>
                @if ($conversation)
                    <span class="num ml-auto whitespace-nowrap text-2xs text-muted">{{ $at($conversation->created_at)->isToday() ? 'Bugün '.$at($conversation->created_at)->format('H:i') : $at($conversation->created_at)->format('d.m.Y H:i') }}</span>
                    <button type="button" wire:click="delete({{ $conversation->id }})" class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-fg" aria-label="Sohbeti sil" title="Sohbeti sil"><x-icon name="trash-2" class="size-3.5" /></button>
                @endif
            </div>

            @if (! $ready && ! $conversation)
                @include('livewire.ai.partials.not-ready')
            @else
                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5 text-[13.5px] leading-relaxed"
                     x-data="{ end() { this.$el.scrollTop = this.$el.scrollHeight } }" x-init="end(); new MutationObserver(() => end()).observe($el, { childList: true, subtree: true })"
                     @ai-scroll.window="$nextTick(() => end())">
                    @if ($items === [])
                        <div class="mx-auto max-w-md py-10 text-center">
                            <span class="mx-auto grid size-10 place-items-center rounded-full bg-accent-soft text-accent"><x-icon name="sparkles" class="size-5" /></span>
                            <p class="mt-3 font-medium">Size nasıl yardımcı olabilirim?</p>
                            <p class="mt-1 text-[13px] text-muted">Siteleri, DNS'i, sertifikaları, e-postayı, Laravel uygulamalarını ve canlı izlemeyi okuyabilirim. Saldıran adresleri engelleyebilir ve siteleri geçici olarak sınırlayabilirim; gerisini size önerir, onayınızla yaparım.</p>
                        </div>
                    @endif
                    @include('livewire.ai.partials.transcript')
                </div>
                @if ($ready)
                    @include('livewire.ai.partials.compose')
                @else
                    <p class="border-t border-line px-5 py-4 text-[13px] text-muted">Servik AI şu an kullanılamıyor. <a href="{{ route('ai.settings') }}" wire:navigate class="text-accent hover:underline">Ayarlar</a></p>
                @endif
            @endif
        </div>
    </section>
</div>
