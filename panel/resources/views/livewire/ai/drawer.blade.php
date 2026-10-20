<div x-data @keydown.escape.window="$wire.open = false" @ai-open.window="$wire.$set('open', true)">
    <div x-show="$wire.open" x-cloak @click="$wire.open = false" class="fixed inset-0 z-40 bg-black/20"></div>
    <aside x-show="$wire.open" x-cloak x-transition:enter="transition-transform duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
           x-transition:leave="transition-transform duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
           class="fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l border-line bg-surface shadow-2xl sm:w-[420px]" aria-label="Servik AI">
        <div class="flex h-14 items-center gap-2.5 border-b border-line px-4">
            <span class="grid size-7 place-items-center rounded-md bg-accent-soft text-accent"><x-icon name="sparkles" /></span>
            <div class="leading-tight">
                <p class="text-[14px] font-semibold">Servik AI</p>
                <p class="text-2xs text-muted">{{ __('Claude ile çalışır · sunucuya kök erişimi yok') }}</p>
            </div>
            <div class="ml-auto flex items-center">
                @if ($conversation)
                    <button type="button" wire:click="startNew" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Yeni sohbet') }}" title="{{ __('Yeni sohbet') }}"><x-icon name="plus" /></button>
                @endif
                <a href="{{ route('ai.index', $conversation ? ['sohbet' => $conversation->id] : []) }}" wire:navigate class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Tam sayfada aç') }}" title="{{ __('Tam sayfada aç') }}"><x-icon name="maximize-2" /></a>
                <button type="button" @click="$wire.open = false" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
            </div>
        </div>
        @if ($open)
            @if (! $ready && ! $conversation)
                @include('livewire.ai.partials.not-ready')
            @else
                <div class="flex-1 space-y-4 overflow-y-auto p-4 text-[13.5px]"
                     x-data="{ end() { this.$el.scrollTop = this.$el.scrollHeight } }" x-init="end(); new MutationObserver(() => end()).observe($el, { childList: true, subtree: true })"
                     @ai-scroll.window="$nextTick(() => end())">
                    @if ($items === [])
                        <p class="py-6 text-center text-[13px] text-muted">{{ __('Sunucunuz, siteleriniz ya da bir sorun hakkında soru sorun. Önerdiğim değişiklikler onayınızla uygulanır.') }}</p>
                    @endif
                    @include('livewire.ai.partials.transcript', ['compact' => true])
                </div>
                @if ($ready)
                    @include('livewire.ai.partials.compose', ['compact' => true])
                @endif
            @endif
        @endif
    </aside>
</div>
