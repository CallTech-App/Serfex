{{-- Progress of a component operation: needs $operation, $operationName and the ShowsComponentOperations trait. --}}
@if ($operation)
    @php
        $verb = ['install' => 'kuruluyor', 'update' => __('güncelleniyor'), 'remove' => __('kaldırılıyor'), 'ext-install' => 'kuruluyor', 'ext-remove' => __('kaldırılıyor'), 'upgrade' => __('sürümüne yükseltiliyor'), 'remove-old' => __('kaldırılıyor')][$operation->action] ?? '';
        $doneText = ['install' => 'kuruldu', 'update' => __('güncellendi'), 'remove' => __('kaldırıldı'), 'ext-install' => 'kuruldu', 'ext-remove' => __('kaldırıldı'), 'upgrade' => __('sürümüne yükseltildi'), 'remove-old' => __('kaldırıldı')][$operation->action] ?? '';
    @endphp
    <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" wire:key="op-{{ $operation->id }}">
        <div class="max-h-full w-full max-w-3xl overflow-y-auto rounded-xl border border-line bg-surface shadow-xl" wire:ignore role="dialog" aria-modal="true" aria-label="{{ $operationName }} {{ $verb }}"
             x-data="{
                offset: 0, steps: [], log: '', rollback: false, status: @js($operation->status), error: @js($operation->error), timer: null, final: false,
                parse(text) {
                    for (const line of text.split('\n')) {
                        if (line === '') continue;
                        this.log += line + '\n';
                        if (line.startsWith('▸ ')) {
                            this.steps.forEach(s => { if (s.state === 'run') s.state = 'ok'; });
                            this.steps.push({ text: line.slice(2), state: 'run' });
                        } else if (line.startsWith('↺ ')) {
                            this.rollback = true;
                        }
                    }
                },
                async tick() {
                    let r;
                    try { r = await $wire.readRun(@js($operation->run), this.offset); } catch (e) { this.timer = setTimeout(() => this.tick(), 3000); return; }
                    if (r === null) return;
                    if (r.data) { this.parse(r.data); this.$nextTick(() => { const el = this.$refs.log; if (el) el.scrollTop = el.scrollHeight; }); }
                    this.offset = r.offset;
                    if (r.more) { this.timer = setTimeout(() => this.tick(), 0); return; }
                    if (r.done) {
                        if (! this.final) { this.final = true; this.timer = setTimeout(() => this.tick(), 300); return; }
                        this.status = r.status; this.error = r.error;
                        this.steps.forEach(s => { if (s.state === 'run') s.state = r.status === 'succeeded' ? 'ok' : 'fail'; });
                        $wire.$refresh();
                        return;
                    }
                    this.timer = setTimeout(() => this.tick(), r.data ? 500 : 1200);
                },
                destroy() { clearTimeout(this.timer); },
             }" x-init="tick()">
            <div class="flex h-12 items-center border-b border-line px-5">
                <h2 class="text-[14.5px] font-semibold">
                    <span x-show="status === 'queued' || status === 'running'">{{ $operationName }} {{ $verb }}</span>
                    <span x-show="status === 'succeeded'" x-cloak>{{ $operationName }} {{ $doneText }}</span>
                    <span x-show="status === 'failed'" x-cloak>{{ __(':operation_name: işlem tamamlanamadı', ['operation_name' => $operationName]) }}</span>
                </h2>
            </div>
            <div class="space-y-3 p-5 text-[13.5px]">
                <p x-show="status === 'queued' && steps.length === 0" class="flex items-center gap-2.5 text-[13px] text-muted"><x-icon name="loader-circle" class="size-4 animate-spin text-accent" />{{ __('Sırada bekliyor') }}</p>
                <ol class="space-y-2 text-[13px]">
                    <template x-for="(s, i) in steps" :key="i">
                        <li class="flex items-start gap-2.5">
                            <span class="mt-px shrink-0">
                                <x-icon name="check" class="size-4 text-ok" x-show="s.state === 'ok'" />
                                <x-icon name="loader-circle" class="size-4 animate-spin text-accent" x-show="s.state === 'run'" />
                                <x-icon name="x" class="size-4 text-crit" x-show="s.state === 'fail'" />
                            </span>
                            <span x-text="s.text" class="break-words"></span>
                        </li>
                    </template>
                </ol>
                <div x-show="status === 'failed'" x-cloak class="rounded-lg border border-line bg-crit-soft p-3 text-[13px]">
                    <p class="font-medium text-crit" x-text="rollback ? @js(__('Bir adım başarısız oldu; yapılan değişiklikler geri alındı.')) : @js(__('İşlem tamamlanamadı; sunucuda bir şey değişmedi.'))"></p>
                    <p class="mt-1 font-mono text-[12.5px] break-words text-muted" x-text="error"></p>
                </div>
                <details class="text-[13px]" @if ($operation->status === 'failed') open @endif>
                    <summary class="cursor-pointer text-muted hover:text-fg">{{ __('Ayrıntılı günlük') }}</summary>
                    <pre x-ref="log" x-text="log" class="scroll-thin mt-2 h-80 max-h-[50vh] overflow-auto rounded-md bg-[#0f1a1c] p-3 font-mono text-[12px] whitespace-pre-wrap text-[#d7e4e4]"></pre>
                </details>
            </div>
            <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
                <p class="text-2xs text-muted" x-show="status === 'queued' || status === 'running'">{{ __('Bu pencereyi kapatabilirsiniz; işlem arka planda sürer.') }}</p>
                <span x-show="status !== 'queued' && status !== 'running'"></span>
                <button type="button" wire:click="closeOperation" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
            </div>
        </div>
    </div>
@endif
