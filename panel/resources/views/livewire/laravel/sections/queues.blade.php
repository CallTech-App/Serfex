@php
    use App\Support\Badge;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $num = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent num';
    $fields = [
        'timeout' => ['Zaman aşımı', 'saniye; bir iş bundan uzun sürerse durdurulur'],
        'tries' => ['Deneme', 'bir iş başarısız sayılmadan önce'],
        'backoff' => ['Bekleme', 'saniye; yeniden denemeden önce'],
        'sleep' => ['Boşta bekleme', 'saniye; kuyruk boşken'],
        'max_jobs' => ['En çok iş', 'sonra süreç yenilenir (0 sınırsız)'],
        'max_time' => ['En uzun süre', 'saniye; sonra süreç yenilenir (0 sınırsız)'],
        'memory' => ['Bellek sınırı', 'MB'],
    ];
@endphp
<div>
    @if ($syncing)
        <div wire:poll.2s hidden></div>
    @endif
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    @if ($app->processes_state === 'failed')
        <div class="flex flex-wrap items-center gap-3 border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px]">
            <span class="text-crit">İşçi ayarları sunucuya uygulanamadı: <span class="font-mono">{{ $app->processes_error }}</span></span>
            <button type="button" wire:click="retrySync" class="ml-auto text-accent hover:underline">Tekrar dene</button>
        </div>
    @endif

    @if ($horizon)
        <div class="flex flex-wrap items-center gap-3 border-b border-line bg-info-soft/40 px-5 py-2.5 text-[13px]">
            <span>Kuyrukları Horizon işliyor; aşağıdaki işçiler Horizon kapanana kadar çalışmaz.</span>
            <button type="button" wire:click="$dispatch('show-section', { section: 'surecler' })" class="ml-auto text-accent hover:underline">Süreçler</button>
        </div>
    @endif

    @if ($operate)
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <button type="button" wire:click="openWorker" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="plus" />İşçi ekle</button>
        <button type="button" wire:click="restart" @disabled($workers->sum('processes') === 0) class="{{ $btn }} disabled:opacity-40"><x-icon name="rotate-cw" />Yeniden başlat</button>
        <button type="button" wire:click="openFailed" class="{{ $btn }}">Başarısız işler
            @if ($failedCount)<span class="num rounded-full bg-crit-soft px-1.5 text-2xs font-medium text-crit">{{ $failedCount }}</span>@endif
        </button>
        @if ($syncing)
            <span class="ml-auto flex items-center gap-2 text-[13px] text-muted"><x-icon name="loader-circle" class="animate-spin" />Sunucuya uygulanıyor</span>
        @endif
    </div>
    @endif

    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[760px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">İşçi</th>
                    <th class="px-3 py-2.5 font-medium">Kuyruk</th>
                    <th class="px-3 py-2.5 font-medium">Süreç</th>
                    <th class="px-3 py-2.5 font-medium">Bekleyen</th>
                    <th class="px-3 py-2.5 font-medium">Zaman aşımı</th>
                    <th class="px-3 py-2.5 font-medium">Durum</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($workers as $w)
                    @php
                        $active = collect($states[$w->name] ?? [])->where('state', 'active')->count();
                        $waiting = collect($w->queues())->sum(fn ($q) => (int) ($pending[(string) $w->connection.'|'.$q] ?? 0));
                        $badge = match (true) {
                            $w->processes === 0 => new Badge('Durduruldu', 'muted'),
                            $horizon => new Badge('Horizon işliyor', 'muted'),
                            $syncing => new Badge('Uygulanıyor', 'info', true),
                            $active >= $w->processes => new Badge('Çalışıyor', 'ok'),
                            $active > 0 => new Badge($active.' / '.$w->processes.' çalışıyor', 'warn'),
                            default => new Badge('Çalışmıyor', 'crit'),
                        };
                    @endphp
                    <tr wire:key="w-{{ $w->id }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5 font-mono text-[13px]">{{ $w->name }}<div class="font-sans text-2xs text-muted">{{ $w->connection ?: 'varsayılan bağlantı' }}</div></td>
                        <td class="px-3 py-3 font-mono text-[13px]">{{ implode(', ', $w->queues()) }}</td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center rounded-md border border-line">
                                <button type="button" wire:click="scale({{ $w->id }}, -1)" @disabled($w->processes === 0) aria-label="Azalt" class="grid size-7 place-items-center text-muted hover:text-fg disabled:opacity-40"><x-icon name="minus" class="size-3.5" /></button>
                                <span class="num w-7 text-center">{{ $w->processes }}</span>
                                <button type="button" wire:click="scale({{ $w->id }}, 1)" @disabled($w->processes >= \App\Livewire\Laravel\Sections\Queues::MAX_PROCESSES) aria-label="Artır" class="grid size-7 place-items-center text-muted hover:text-fg disabled:opacity-40"><x-icon name="plus" class="size-3.5" /></button>
                            </span>
                        </td>
                        <td class="num px-3 py-3 {{ $waiting > 0 ? '' : 'text-muted' }}">{{ $pending === [] ? '—' : $waiting }}</td>
                        <td class="num px-3 py-3 whitespace-nowrap text-muted">{{ $w->timeout }} sn</td>
                        <td class="px-3 py-3"><x-status-badge :status="$badge" /></td>
                        <td class="space-x-3 px-3 py-3 text-right whitespace-nowrap">
                            <button type="button" wire:click="openWorker({{ $w->id }})" class="text-[13px] text-muted hover:text-fg">Ayarlar</button>
                            <button type="button" wire:click="deleteWorker({{ $w->id }})" class="text-[13px] text-muted hover:text-crit">Sil</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center"><p class="font-medium">Kuyruk işçisi yok</p><p class="mt-1 text-[13px] text-muted">Uygulama işleri kuyruğa atıyorsa onları çalıştıracak bir işçi ekleyin.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted">Her süreç ayrı bir systemd hizmeti olarak sürekli çalışır, çökerse yeniden başlar ve günlüğü <span class="font-mono">/var/log/servik/laravel/{{ $app->site->domain }}/queue.log</span> dosyasına yazılır. Her dağıtımdan ve .env kaydından sonra işçiler yeniden başlar. Uygulamaya ek bir Composer paketi gerekmez.</p>

    {{-- İşçi ayarları --}}
    <x-modal title="İşçi ayarları" show="editing">
        <form wire:submit="saveWorker">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $workerId ? 'İşçi ayarları' : 'İşçi ekle' }}</h2>
            <div class="grid max-h-[60vh] grid-cols-1 gap-x-4 gap-y-3 overflow-y-auto p-5 text-[13.5px] sm:grid-cols-[140px_1fr]">
                <label for="w-name" class="sm:pt-2">Ad</label>
                <div>
                    <input id="w-name" wire:model="form.name" placeholder="default" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.name') ? 'border-crit' : 'border-line' }}">
                    @error('form.name')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
                <label for="w-queue" class="sm:pt-2">Kuyruklar</label>
                <div>
                    <input id="w-queue" wire:model="form.queue" placeholder="high,default" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.queue') ? 'border-crit' : 'border-line' }}">
                    @error('form.queue')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">Virgülle ayırın; öndeki kuyruk önce işlenir.</p>@enderror
                </div>
                <label for="w-conn" class="sm:pt-2">Bağlantı</label>
                <div>
                    <input id="w-conn" wire:model="form.connection" list="w-conns" placeholder="QUEUE_CONNECTION değeri" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.connection') ? 'border-crit' : 'border-line' }}">
                    <datalist id="w-conns"><option value="database"></option><option value="redis"></option><option value="sqs"></option><option value="beanstalkd"></option></datalist>
                    @error('form.connection')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
                <label for="w-proc" class="sm:pt-2">Süreç sayısı</label>
                <div><input id="w-proc" type="number" min="0" max="{{ \App\Livewire\Laravel\Sections\Queues::MAX_PROCESSES }}" wire:model="form.processes" class="{{ $num }} w-28 {{ $errors->has('form.processes') ? 'border-crit' : 'border-line' }}"></div>
                @foreach ($fields as $key => [$label, $hint])
                    <label for="w-{{ $key }}" class="sm:pt-2">{{ $label }}</label>
                    <div>
                        <div class="flex items-center gap-2"><input id="w-{{ $key }}" type="number" min="0" wire:model="form.{{ $key }}" class="{{ $num }} w-28 {{ $errors->has('form.'.$key) ? 'border-crit' : 'border-line' }}"><span class="text-2xs text-muted">{{ $hint }}</span></div>
                        @error('form.'.$key)<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Kaydet</button>
            </div>
        </form>
    </x-modal>

    {{-- Başarısız işler --}}
    <x-modal title="Başarısız işler" show="showingFailed" size="max-w-3xl">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Başarısız işler</h2>
        <div class="scroll-thin max-h-[60vh] overflow-auto">
            <table class="w-full min-w-[640px] text-[13px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">İş</th>
                        <th class="px-3 py-2.5 font-medium">Hata</th>
                        <th class="px-3 py-2.5 font-medium">Zaman</th>
                        <th class="px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($failed ?? [] as $job)
                        <tr wire:key="fj-{{ $job['id'] }}">
                            <td class="py-2.5 pr-3 pl-5"><span class="font-mono">{{ $job['job'] ?: '—' }}</span><div class="text-2xs text-muted">{{ $job['connection'] }} · {{ $job['queue'] }}</div></td>
                            <td class="max-w-xs truncate px-3 py-2.5 text-muted" title="{{ $job['error'] }}">{{ $job['error'] }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap text-muted">{{ $job['failed_at'] }}</td>
                            <td class="space-x-3 px-3 py-2.5 text-right whitespace-nowrap">
                                <button type="button" wire:click="failedAction('retry', @js($job['id']))" class="text-muted hover:text-fg">Yeniden dene</button>
                                <button type="button" wire:click="failedAction('forget', @js($job['id']))" class="text-muted hover:text-crit">Sil</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-8 text-center text-muted">Başarısız iş yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            @if (! empty($failed))
                <button type="button" wire:click="failedAction('retry')" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Tümünü yeniden dene</button>
                <button type="button" wire:click="failedAction('flush')" wire:confirm="Tüm başarısız işler silinsin mi? Bu geri alınamaz." class="h-9 rounded-md border border-line px-3.5 text-[13.5px] text-crit hover:bg-surface-2">Tümünü sil</button>
            @endif
            <button type="button" wire:click="$set('showingFailed', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
        </div>
    </x-modal>
</div>
