@php
    use App\Services\Laravel\LogParser;
    use App\Support\Badge;
    use App\Support\Bytes;
    $labels = ['emergency' => 'Acil', 'alert' => 'Alarm', 'critical' => 'Kritik', 'error' => 'Hata', 'warning' => 'Uyarı', 'notice' => 'Not', 'info' => 'Bilgi', 'debug' => 'Ayıklama'];
@endphp
<div>
    @if ($follow)
        <div wire:poll.5s hidden></div>
    @endif
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <select wire:model.live="file" aria-label="Günlük dosyası" class="h-9 rounded-md border border-line bg-surface px-2.5 font-mono text-[13px]" @disabled($files === [])>
            @forelse ($files as $f)
                <option value="{{ $f['name'] }}">{{ $f['name'] }} · {{ Bytes::format((int) $f['size']) }}</option>
            @empty
                <option value="">Günlük dosyası yok</option>
            @endforelse
        </select>
        <select wire:model.live="level" aria-label="Düzey" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
            <option value="">Tüm düzeyler</option>
            <option value="error">Hata ve üstü</option>
            <option value="warning">Uyarı ve üstü</option>
            <option value="info">Bilgi ve üstü</option>
        </select>
        <label class="ml-1 flex items-center gap-2 text-[13.5px]"><input type="checkbox" wire:model.live="follow" class="size-4 accent-[rgb(var(--accent))]">Canlı takip</label>
        <label class="relative ml-auto w-full sm:w-56">
            <span class="sr-only">Günlükte ara</span>
            <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Günlükte ara" class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
        </label>
    </div>
    <ul class="scroll-thin max-h-[520px] divide-y divide-line overflow-auto text-[13px]">
        @forelse ($entries as $i => $e)
            <li wire:key="log-{{ $i }}-{{ md5($e['at'].$e['message']) }}" x-data="{ open: false }" class="px-5 py-2.5">
                <div class="flex items-start gap-3">
                    <x-status-badge :status="new Badge($labels[$e['level']] ?? $e['level'], LogParser::tone($e['level']))" class="mt-0.5 shrink-0" />
                    <span class="num shrink-0 font-mono text-2xs leading-5 text-muted">{{ $e['at'] }}</span>
                    <button type="button" @click="open = ! open" class="min-w-0 flex-1 text-left font-mono text-[12.5px] break-words {{ $e['detail'] !== '' ? 'hover:text-accent' : 'cursor-default' }}">{{ \Illuminate\Support\Str::limit($e['message'], 400) }}</button>
                </div>
                @if ($e['detail'] !== '')
                    <pre x-show="open" x-cloak class="scroll-thin mt-2 max-h-72 overflow-auto rounded-md bg-surface-2 p-3 font-mono text-[11.5px] whitespace-pre-wrap text-muted">{{ \Illuminate\Support\Str::limit($e['detail'], 20000) }}</pre>
                @endif
            </li>
        @empty
            <li class="px-5 py-10 text-center text-muted">{{ $files === [] ? 'Uygulama henüz günlük yazmadı.' : 'Bu filtreyle eşleşen kayıt yok.' }}</li>
        @endforelse
    </ul>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted">
        <span class="font-mono">storage/logs</span> içindeki dosyalar, en yeni kayıt üstte.@if ($truncated) Dosya büyük olduğu için yalnızca son {{ Bytes::format(\App\Livewire\Laravel\Sections\Logs::BYTES) }} gösteriliyor.@endif
        Yeni kurulumlarda günlük düzeni <span class="font-mono">daily</span>, 14 gün saklanır. Hata düzeyinde bildirim Bildirimler bölümüyle gelecek.
    </p>
</div>
