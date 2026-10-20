{{--
    Notifications. Livewire components dispatch a browser event:
        $this->dispatch('toast', message: '...', source: $this->getId(), undo: ['method' => 'undoX', 'args' => [1]]);
    The undo button calls that method on the component that sent the toast.
    A toast flashed to the session (session('toast')) is shown on page load:
    a message, or ['message' => ..., 'undo' => ['name' => component name,
    'method' => ..., 'args' => [...]]] for an Undo after a reload.
--}}
<div
    x-data="{
        toasts: [],
        add(t) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, ...t });
            setTimeout(() => this.remove(id), t.undo ? 8000 : 4000);
        },
        remove(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
        undo(t) {
            this.remove(t.id);
            const c = t.component ? window.Livewire?.find(t.component) : window.Livewire?.all().find(x => x.name === t.undo.name)?.$wire;
            if (c) c.$call(t.undo.method, ...(t.undo.args ?? []));
        },
    }"
    x-init="@if (session('toast')) add(@js(is_array(session('toast')) ? session('toast') : ['message' => session('toast')])) @endif"
    @toast.window="add({ ...$event.detail, component: $event.detail.source ?? $event.target.closest('[wire\\:id]')?.getAttribute('wire:id') })"
    class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4"
    aria-live="polite"
>
    <template x-for="t in toasts" :key="t.id">
        <div class="pointer-events-auto flex max-w-md items-center gap-3 rounded-lg bg-fg px-4 py-2.5 text-[13.5px] text-bg shadow-lg">
            <span x-text="t.message"></span>
            <button type="button" x-show="t.undo" @click="undo(t)" class="ml-2 shrink-0 font-medium whitespace-nowrap text-accent-soft hover:underline">{{ __('Geri al') }}</button>
        </div>
    </template>
</div>
