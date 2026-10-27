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
            <input type="checkbox" @checked($app->scheduler) wire:click="toggle" @disabled($syncing) class="size-4 accent-[rgb(var(--accent))]">{{ __('Zamanlayıcı') }}
        </label>
        @if ($syncing)
            <x-status-badge :status="new Badge(__('Uygulanıyor'), 'info', true)" />
        @elseif ($late)
            <x-status-badge :status="new Badge($lastRun ? __('Son çalışma :last_run', ['last_run' => When::withTime($lastRun)]) : __('Henüz çalışmadı'), 'warn')" />
        @elseif ($enabled)
            <x-status-badge :status="new Badge(__('Çalışıyor'), 'ok')" />
        @else
            <x-status-badge :status="new Badge(__('Kapalı'), 'muted')" />
        @endif
        <span class="text-[13px] text-muted">@if ($lastRun)<x-t text="Son :part: :last_run" :with="['last_run' => When::withTime($lastRun)]"><x-slot:part><span class="font-mono">schedule:run</span></x-slot:part></x-t>@endif</span>
    </div>
    @if ($late && ! $syncing)
        <p class="border-b border-line bg-warn-soft/40 px-5 py-2.5 text-[13px]"><x-t text="Zamanlayıcı açık ama son :late_minutes dakikada çalışmadı; görevler kaçırılıyor olabilir. Hatayı görmek için :domain dosyasına bakın." :with="['late_minutes' => \App\Livewire\Laravel\Sections\Scheduler::LATE_MINUTES]"><x-slot:domain><span class="font-mono">/var/log/serfex/laravel/{{ $app->site->domain }}/schedule.log</span></x-slot:domain></x-t></p>
    @endif

    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[760px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Komut') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Zamanlama') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Sonraki') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Son çalışma') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($tasks as $i => $t)
                    <tr wire:key="task-{{ $i }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5"><span class="font-mono text-[13px]">{{ $t['command'] }}</span>@if ($t['description'])<div class="text-2xs text-muted">{{ $t['description'] }}</div>@endif</td>
                        <td class="px-3 py-3 font-mono text-[13px] whitespace-nowrap">{{ $t['expression'] }}</td>
                        <td class="px-3 py-3 whitespace-nowrap text-muted">{{ When::withTime(Carbon::parse($t['next'])) }}</td>
                        <td class="px-3 py-3 whitespace-nowrap text-muted">@if ($t['run']){{ When::withTime(Carbon::parse($t['run']['at'], config('serfex.timezone'))) }}@if ($t['run']['duration']) · {{ $t['run']['duration'] }}@endif @else—@endif</td>
                        <td class="px-3 py-3">
                            @if ($t['run'])
                                <x-status-badge :status="$t['run']['ok'] ? new Badge(__('Başarılı'), 'ok') : new Badge(__('Başarısız'), 'crit')" />
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right whitespace-nowrap">
                            @if ($t['artisan'])
                                <button type="button" wire:click="runNow(@js($t['command']))" class="text-[13px] text-muted hover:text-fg">{{ __('Şimdi çalıştır') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-muted"><x-t text="Uygulamada zamanlanmış görev yok. Görevler :part içinde tanımlanır."><x-slot:part><span class="font-mono">routes/console.php</span></x-slot:part></x-t></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted"><x-t text="Görevler uygulamanın kendisinden okunur. Serfex :part komutunu her dakika bir systemd zamanlayıcısıyla, sitenin kullanıcısıyla çalıştırır; çıktısı :part2 dosyasına yazılır. Görev başına aç/kapat sonraki sürümde."><x-slot:part><span class="font-mono">schedule:run</span></x-slot:part><x-slot:part2><span class="font-mono">schedule.log</span></x-slot:part2></x-t></p>
</div>
