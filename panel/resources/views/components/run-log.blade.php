@props(['run', 'class' => 'h-80', 'method' => 'readRun', 'start' => 0])
{{--
    Output of a deploy or command, read from the agent as it is written.
    The Livewire component it sits in must use App\Livewire\Laravel\ReadsRunLogs
    (or offer another reader as method, such as a process log's, read from
    start; -1 starts at the last lines).
    When the run ends, the component is refreshed so statuses update.
--}}
<div wire:key="run-log-{{ $run }}" wire:ignore
     x-data="{
        offset: @js((int) $start),
        timer: null,
        done: false,
        error: '',
        async tick() {
            let r;
            try {
                r = await $wire[@js($method)](@js($run), this.offset);
            } catch (e) {
                this.timer = setTimeout(() => this.tick(), 3000);
                return;
            }
            if (r === null) { this.done = true; return; }
            this.error = r.error ?? '';
            const el = this.$refs.out;
            if (r.data) {
                const atEnd = el.scrollTop + el.clientHeight >= el.scrollHeight - 16;
                el.append(r.data.replace(/\x1b\[[0-9;?]*[A-Za-z]/g, ''));
                if (atEnd) el.scrollTop = el.scrollHeight;
            }
            this.offset = r.offset;
            if (r.more) { this.timer = setTimeout(() => this.tick(), 0); return; }
            if (r.done) {
                // One last read picks up lines written just before the end.
                if (! this.done) { this.done = true; this.timer = setTimeout(() => this.tick(), 300); }
                else $wire.$refresh();
                return;
            }
            this.timer = setTimeout(() => this.tick(), r.data ? 400 : 1000);
        },
        destroy() { clearTimeout(this.timer); },
     }"
     x-init="tick()"
     {{ $attributes }}>
    <pre x-ref="out" class="{{ $class }} scroll-thin overflow-auto rounded-md bg-[#0f1a1c] p-3 font-mono text-[12.5px] whitespace-pre-wrap text-[#d7e4e4]"></pre>
    <p x-show="error" x-text="error" x-cloak class="mt-1 text-2xs text-crit"></p>
</div>
