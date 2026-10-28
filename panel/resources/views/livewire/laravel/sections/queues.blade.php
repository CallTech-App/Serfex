@php
    use App\Support\Badge;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $num = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent num';
    $fields = [
        'timeout' => [__('Zaman aşımı'), __('saniye; bir iş bundan uzun sürerse durdurulur')],
        'tries' => [__('Deneme'), __('bir iş başarısız sayılmadan önce')],
        'backoff' => [__('Bekleme'), __('saniye; yeniden denemeden önce')],
        'sleep' => [__('Boşta bekleme'), __('saniye; kuyruk boşken')],
        'max_jobs' => [__('En çok iş'), __('sonra süreç yenilenir (0 sınırsız)')],
        'max_time' => [__('En uzun süre'), __('saniye; sonra süreç yenilenir (0 sınırsız)')],
        'memory' => [__('Bellek sınırı'), 'MB'],
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
            <span class="text-crit">{{ __('İşçi ayarları sunucuya uygulanamadı:') }} <span class="font-mono">{{ $app->processes_error }}</span></span>
            <button type="button" wire:click="retrySync" class="ml-auto text-accent hover:underline">{{ __('Tekrar dene') }}</button>
        </div>
    @endif

    @if ($horizon)
        <div class="flex flex-wrap items-center gap-3 border-b border-line bg-info-soft/40 px-5 py-2.5 text-[13px]">
            <span>{{ __('Kuyrukları Horizon işliyor; aşağıdaki işçiler Horizon kapanana kadar çalışmaz.') }}</span>
            <button type="button" wire:click="$dispatch('show-section', { section: 'processes' })" class="ml-auto text-accent hover:underline">{{ __('Süreçler') }}</button>
        </div>
    @endif

    @if ($operate)
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <button type="button" wire:click="openWorker" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="plus" />{{ __('İşçi ekle') }}</button>
        <button type="button" wire:click="restart" @disabled($workers->sum('processes') === 0) class="{{ $btn }} disabled:opacity-40"><x-icon name="rotate-cw" />{{ __('Yeniden başlat') }}</button>
        <button type="button" wire:click="openFailed" class="{{ $btn }}">{{ __('Başarısız işler') }}
            @if ($failedCount)<span class="num rounded-full bg-crit-soft px-1.5 text-2xs font-medium text-crit">{{ $failedCount }}</span>@endif
        </button>
        @if ($syncing)
            <span class="ml-auto flex items-center gap-2 text-[13px] text-muted"><x-icon name="loader-circle" class="animate-spin" />{{ __('Sunucuya uygulanıyor') }}</span>
        @endif
    </div>
    @endif

    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[760px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('İşçi') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Kuyruk') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Süreç') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Bekleyen') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Zaman aşımı') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($workers as $w)
                    @php
                        $active = collect($states[$w->name] ?? [])->where('state', 'active')->count();
                        $waiting = collect($w->queues())->sum(fn ($q) => (int) ($pending[(string) $w->connection.'|'.$q] ?? 0));
                        $problem = $problems[$w->id] ?? null;
                        $badge = match (true) {
                            $w->processes === 0 => new Badge(__('Durduruldu'), 'muted'),
                            $problem !== null && ! $horizon && ! $syncing => new Badge(__('Bağlantı yok'), 'crit'),
                            $horizon => new Badge(__('Horizon işliyor'), 'muted'),
                            $syncing => new Badge(__('Uygulanıyor'), 'info', true),
                            $active >= $w->processes => new Badge(__('Çalışıyor'), 'ok'),
                            $active > 0 => new Badge(__(':active / :processes çalışıyor', ['active' => $active, 'processes' => $w->processes]), 'warn'),
                            default => new Badge(__('Çalışmıyor'), 'crit'),
                        };
                    @endphp
                    <tr wire:key="w-{{ $w->id }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5 font-mono text-[13px]">{{ $w->name }}<div class="font-sans text-2xs {{ $problem ? 'text-crit' : 'text-muted' }}">{{ $w->connection ?: __('varsayılan bağlantı') }}</div></td>
                        <td class="px-3 py-3 font-mono text-[13px]">{{ implode(', ', $w->queues()) }}</td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center rounded-md border border-line">
                                <button type="button" wire:click="scale({{ $w->id }}, -1)" @disabled($w->processes === 0) aria-label="{{ __('Azalt') }}" class="grid size-7 place-items-center text-muted hover:text-fg disabled:opacity-40"><x-icon name="minus" class="size-3.5" /></button>
                                <span class="num w-7 text-center">{{ $w->processes }}</span>
                                <button type="button" wire:click="scale({{ $w->id }}, 1)" @disabled($w->processes >= \App\Livewire\Laravel\Sections\Queues::MAX_PROCESSES) aria-label="{{ __('Artır') }}" class="grid size-7 place-items-center text-muted hover:text-fg disabled:opacity-40"><x-icon name="plus" class="size-3.5" /></button>
                            </span>
                        </td>
                        <td class="num px-3 py-3 {{ $waiting > 0 ? '' : 'text-muted' }}">{{ $pending === [] ? '—' : $waiting }}</td>
                        <td class="num px-3 py-3 whitespace-nowrap text-muted">{{ __(':timeout sn', ['timeout' => $w->timeout]) }}</td>
                        <td class="px-3 py-3"><x-status-badge :status="$badge" /></td>
                        <td class="space-x-3 px-3 py-3 text-right whitespace-nowrap">
                            <button type="button" wire:click="openWorker({{ $w->id }})" class="text-[13px] text-muted hover:text-fg">{{ __('Ayarlar') }}</button>
                            <button type="button" wire:click="deleteWorker({{ $w->id }})" class="text-[13px] text-muted hover:text-crit">{{ __('Sil') }}</button>
                        </td>
                    </tr>
                    @if ($problem !== null && $w->processes > 0 && ! $horizon)
                        <tr wire:key="wp-{{ $w->id }}" class="bg-crit-soft/40">
                            <td colspan="7" class="px-5 py-2.5 text-[13px]">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="text-crit">{{ __('İşçi başlayamıyor:') }} {{ $problem }}</span>
                                    @if ($operate && $w->connection && $defaultWorks)
                                        <button type="button" wire:click="useDefaultConnection({{ $w->id }})" class="ml-auto text-accent hover:underline">{{ __('Varsayılan bağlantıyı kullan') }}</button>
                                    @elseif ($operate)
                                        <button type="button" wire:click="openWorker({{ $w->id }})" class="ml-auto text-accent hover:underline">{{ __('Ayarlar') }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center"><p class="font-medium">{{ __('Kuyruk işçisi yok') }}</p><p class="mt-1 text-[13px] text-muted">{{ __('Uygulama işleri kuyruğa atıyorsa onları çalıştıracak bir işçi ekleyin.') }}</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted"><x-t text="Her süreç ayrı bir systemd hizmeti olarak sürekli çalışır, çökerse yeniden başlar ve günlüğü :domain dosyasına yazılır. Her dağıtımdan ve .env kaydından sonra işçiler yeniden başlar. Uygulamaya ek bir Composer paketi gerekmez."><x-slot:domain><span class="font-mono">/var/log/serfex/laravel/{{ $app->site->domain }}/queue.log</span></x-slot:domain></x-t></p>

    {{-- İşçi ayarları --}}
    <x-modal :title="__('İşçi ayarları')" show="editing">
        <form wire:submit="saveWorker">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $workerId ? __('İşçi ayarları') : __('İşçi ekle') }}</h2>
            <div class="grid max-h-[60vh] grid-cols-1 gap-x-4 gap-y-3 overflow-y-auto p-5 text-[13.5px] sm:grid-cols-[140px_1fr]">
                <label for="w-name" class="sm:pt-2">{{ __('Ad') }}</label>
                <div>
                    <input id="w-name" wire:model="form.name" placeholder="{{ __('default') }}" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.name') ? 'border-crit' : 'border-line' }}">
                    @error('form.name')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
                <label for="w-queue" class="sm:pt-2">{{ __('Kuyruklar') }}</label>
                <div>
                    <input id="w-queue" wire:model="form.queue" placeholder="{{ __('high,default') }}" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.queue') ? 'border-crit' : 'border-line' }}">
                    @error('form.queue')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Virgülle ayırın; öndeki kuyruk önce işlenir.') }}</p>@enderror
                </div>
                <label for="w-conn" class="sm:pt-2">{{ __('Kuyruk bağlantısı') }}</label>
                <div>
                    <input id="w-conn" wire:model="form.connection" list="w-conns" placeholder="{{ $defaultConnection !== '' ? __('Varsayılan (:name)', ['name' => $defaultConnection]) : __('Varsayılan') }}" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.connection') ? 'border-crit' : 'border-line' }}">
                    <datalist id="w-conns">@foreach ($connections ?? ['database', 'redis', 'sqs', 'beanstalkd'] as $c)<option value="{{ $c }}"></option>@endforeach</datalist>
                    @error('form.connection')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('config/queue.php dosyasındaki bağlantı; veritabanı bağlantısı değildir. Boş bırakın: .env dosyasındaki QUEUE_CONNECTION kullanılır.') }}</p>@enderror
                </div>
                <label for="w-proc" class="sm:pt-2">{{ __('Süreç sayısı') }}</label>
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
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Başarısız işler --}}
    <x-modal :title="__('Başarısız işler')" show="showingFailed" size="max-w-3xl">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Başarısız işler') }}</h2>
        <div class="scroll-thin max-h-[60vh] overflow-auto">
            <table class="w-full min-w-[640px] text-[13px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('İş') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Hata') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Zaman') }}</th>
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
                                <button type="button" wire:click="failedAction('retry', @js($job['id']))" class="text-muted hover:text-fg">{{ __('Yeniden dene') }}</button>
                                <button type="button" wire:click="failedAction('forget', @js($job['id']))" class="text-muted hover:text-crit">{{ __('Sil') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-8 text-center text-muted">{{ __('Başarısız iş yok.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            @if (! empty($failed))
                <button type="button" wire:click="failedAction('retry')" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Tümünü yeniden dene') }}</button>
                <button type="button" wire:click="failedAction('flush')" wire:confirm="{{ __('Tüm başarısız işler silinsin mi? Bu geri alınamaz.') }}" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] text-crit hover:bg-surface-2">{{ __('Tümünü sil') }}</button>
            @endif
            <button type="button" wire:click="$set('showingFailed', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
        </div>
    </x-modal>
</div>
