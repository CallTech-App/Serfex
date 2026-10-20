{{-- Ctrl+K: the search box in the top bar opens this dialog. --}}
<div x-data="{
        open: false,
        active: 0,
        show() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.input?.focus()); },
        hide() { this.open = false; },
        items() { return [...(this.$refs.list?.querySelectorAll('[data-result]') ?? [])]; },
        move(step) { const n = this.items().length; if (n) { this.active = (this.active + step + n) % n; this.items()[this.active].scrollIntoView({ block: 'nearest' }); } },
        go() { const el = this.items()[this.active]; if (el) { this.hide(); Livewire.navigate(el.getAttribute('href')); } },
     }"
     @keydown.window.prevent.ctrl.k="open ? hide() : show()" @keydown.window.prevent.meta.k="open ? hide() : show()"
     class="max-w-xl min-w-0 flex-1">
    <button type="button" @click="show()" class="relative flex h-9 w-full items-center gap-2 rounded-md border border-transparent bg-surface-2 pr-14 pl-3 text-left text-[13.5px] text-muted hover:border-line" aria-label="{{ __('Ara (Ctrl K)') }}">
        <x-icon name="search" />
        <span class="truncate">{{ __('Site, alan adı, e-posta veya sayfa ara') }}</span>
        <kbd class="absolute top-1/2 right-2.5 hidden -translate-y-1/2 rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-2xs text-muted md:block">Ctrl K</kbd>
    </button>

    @teleport('body')
        <div x-show="open" x-cloak class="fixed inset-0 z-[55] flex items-start justify-center bg-black/40 p-4 pt-[12vh]" @keydown.escape.window="hide()">
            <div @click.outside="hide()" role="dialog" aria-modal="true" aria-label="{{ __('Hızlı arama') }}" class="w-full max-w-xl overflow-hidden rounded-xl border border-line bg-surface shadow-xl">
                <label class="flex h-12 items-center gap-3 border-b border-line px-4">
                    <x-icon name="search" class="text-muted" />
                    <span class="sr-only">{{ __('Ara') }}</span>
                    <input x-ref="input" type="search" wire:model.live.debounce.150ms="q" @input="active = 0" autocomplete="off" spellcheck="false"
                           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()"
                           placeholder="{{ __('Site, alan adı, müşteri, posta kutusu, veritabanı ya da sayfa') }}" class="h-full min-w-0 flex-1 bg-transparent text-[14px] outline-none">
                    <kbd class="rounded border border-line px-1.5 py-0.5 font-mono text-2xs text-muted">Esc</kbd>
                </label>
                <div x-ref="list" class="max-h-[60vh] overflow-y-auto p-1.5">
                    @forelse ($groups as $g => $group)
                        <p class="px-2.5 pt-2 pb-1 text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ $group['group'] }}</p>
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['url'] }}" data-result wire:navigate @click="hide()" wire:key="r-{{ $g }}-{{ $loop->index }}"
                               :class="items().indexOf($el) === active ? 'bg-surface-2' : ''" @mouseenter="active = items().indexOf($el)"
                               class="flex h-10 items-center gap-3 rounded-md px-2.5 text-[13.5px]">
                                <x-icon :name="$item['icon']" class="text-muted" />
                                <span @class(['truncate', 'font-mono text-[13px]' => $item['mono'] ?? false])>{{ $item['label'] }}</span>
                                @if ($item['detail'])
                                    <span class="ml-auto truncate text-2xs text-muted">{{ $item['detail'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    @empty
                        <p class="px-3 py-8 text-center text-[13px] text-muted">{{ $q === '' ? __('Aramak için yazmaya başlayın.') : __('Sonuç yok.') }}</p>
                    @endforelse
                </div>
                <p class="flex items-center gap-3 border-t border-line px-4 py-2 text-2xs text-muted">
                    <span class="flex items-center gap-1"><kbd class="rounded border border-line px-1 font-mono">↑</kbd><kbd class="rounded border border-line px-1 font-mono">↓</kbd> {{ __('seç') }}</span>
                    <span class="flex items-center gap-1"><kbd class="rounded border border-line px-1 font-mono">Enter</kbd> {{ __('aç') }}</span>
                    <span class="ml-auto">{{ __('Yalnızca erişebildikleriniz gösterilir') }}</span>
                </p>
            </div>
        </div>
    @endteleport
</div>
