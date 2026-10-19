@php
    use App\Support\Badge;
    use App\Support\When;
    use Illuminate\Support\Carbon;
@endphp
<div>
    @if ($syncing)
        <div wire:poll.2s hidden></div>
    @endif
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-[13.5px]">
        <label class="flex items-center gap-2.5 font-medium">
            <input type="checkbox" @checked($app->scheduler) wire:click="toggle" @disabled($syncing) class="size-4 accent-[rgb(var(--accent))]">Zamanlayıcı
        </label>
        @if ($syncing)
            <x-status-badge :status="new Badge('Uygulanıyor', 'info', true)" />
        @elseif ($late)
            <x-status-badge :status="new Badge($lastRun ? 'Son çalışma '.When::withTime($lastRun) : 'Henüz çalışmadı', 'warn')" />
        @elseif ($enabled)
            <x-status-badge :status="new Badge('Çalışıyor', 'ok')" />
        @else
            <x-status-badge :status="new Badge('Kapalı', 'muted')" />
        @endif
        <span class="text-[13px] text-muted">@if ($lastRun)Son <span class="font-mono">schedule:run</span>: {{ When::withTime($lastRun) }}@endif</span>
    </div>
    @if ($late && ! $syncing)
        <p class="border-b border-line bg-warn-soft/40 px-5 py-2.5 text-[13px]">Zamanlayıcı açık ama son {{ \App\Livewire\Laravel\Sections\Scheduler::LATE_MINUTES }} dakikada çalışmadı; görevler kaçırılıyor olabilir. Hatayı görmek için <span class="font-mono">/var/log/servik/laravel/{{ $app->site->domain }}/schedule.log</span> dosyasına bakın.</p>
    @endif

    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[760px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">Komut</th>
                    <th class="px-3 py-2.5 font-medium">Zamanlama</th>
                    <th class="px-3 py-2.5 font-medium">Sonraki</th>
                    <th class="px-3 py-2.5 font-medium">Son çalışma</th>
                    <th class="px-3 py-2.5 font-medium">Durum</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($tasks as $i => $t)
                    <tr wire:key="task-{{ $i }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5"><span class="font-mono text-[13px]">{{ $t['command'] }}</span>@if ($t['description'])<div class="text-2xs text-muted">{{ $t['description'] }}</div>@endif</td>
                        <td class="px-3 py-3 font-mono text-[13px] whitespace-nowrap">{{ $t['expression'] }}</td>
                        <td class="px-3 py-3 whitespace-nowrap text-muted">{{ When::withTime(Carbon::parse($t['next'])) }}</td>
                        <td class="px-3 py-3 whitespace-nowrap text-muted">@if ($t['run']){{ When::withTime(Carbon::parse($t['run']['at'], config('servik.timezone'))) }}@if ($t['run']['duration']) · {{ $t['run']['duration'] }}@endif @else—@endif</td>
                        <td class="px-3 py-3">
                            @if ($t['run'])
                                <x-status-badge :status="$t['run']['ok'] ? new Badge('Başarılı', 'ok') : new Badge('Başarısız', 'crit')" />
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right whitespace-nowrap">
                            @if ($t['artisan'])
                                <button type="button" wire:click="runNow(@js($t['command']))" class="text-[13px] text-muted hover:text-fg">Şimdi çalıştır</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-muted">Uygulamada zamanlanmış görev yok. Görevler <span class="font-mono">routes/console.php</span> içinde tanımlanır.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted">Görevler uygulamanın kendisinden okunur. Servik <span class="font-mono">schedule:run</span> komutunu her dakika bir systemd zamanlayıcısıyla, sitenin kullanıcısıyla çalıştırır; çıktısı <span class="font-mono">schedule.log</span> dosyasına yazılır. Görev başına aç/kapat sonraki sürümde.</p>
</div>
