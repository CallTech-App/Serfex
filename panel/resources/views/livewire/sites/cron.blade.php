@php
    use App\Models\CronJob;
    use App\Services\Sites\CronJobs;
    use App\Support\Badge;
    use App\Support\When;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $badge = fn (?string $status) => $status ? new Badge(CronJobs::STATUSES[$status][0] ?? $status, CronJobs::STATUSES[$status][1] ?? 'muted') : null;
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">Zamanlanmış görevler</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">Zamanlanmış görevler</h1>
        <p class="mt-1 text-[13px] text-muted">
            Komutlar belirlediğiniz zamanlarda sitenin klasöründe, <span class="font-mono text-fg">{{ $site->systemUser->name }}</span> kullanıcısıyla ve sitenin PHP {{ $site->php_version }} sürümüyle çalışır.
            Bir görev, önceki çalışması bitmeden yeniden başlamaz. Saatler sunucunun saat dilimine ({{ config('servik.timezone') }}) göredir.
            @if ($site->laravelApp)
                Laravel'in zamanlayıcısı için görev eklemeniz gerekmez: <a href="{{ route('laravel.show', ['app' => $site->laravelApp->id, 'bolum' => 'zamanlayici']) }}" wire:navigate class="text-accent hover:underline">Laravel → Zamanlayıcı</a>.
            @endif
        </p>
    </div>

    @if ($site->isSuspended())
        <p class="flex items-start gap-2 rounded-lg bg-warn-soft p-3 text-[13px]"><x-icon name="pause" class="mt-0.5 shrink-0 text-warn" />Hesap askıda olduğu için görevler çalışmıyor; hesap yeniden etkinleşince kaldıkları yerden devam ederler.</p>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openCreate" @disabled($refusal) title="{{ $refusal }}" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="plus" />Görev ekle
            </button>
            @if ($refusal)
                <span class="text-[13px] text-muted">{{ $refusal }}</span>
            @endif
        </div>
        <div class="relative overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[720px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">Komut</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Zaman</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">Son çalışma</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($tasks as $task)
                        <tr wire:key="cron-{{ $task->id }}" @class(['hover:bg-surface-2/60', 'text-muted' => ! $task->enabled])>
                            <td class="max-w-0 py-3 pr-3 pl-5 sm:w-[45%]">
                                <span class="block truncate font-mono text-[13px]" title="{{ $task->command }}">{{ $task->command }}</span>
                                @if ($task->note)
                                    <span class="mt-0.5 block text-[12.5px] text-muted">{{ $task->note }}</span>
                                @endif
                                <span class="mt-0.5 block text-[12.5px] text-muted sm:hidden">{{ $task->scheduleLabel() }}</span>
                            </td>
                            <td class="hidden px-3 py-3 sm:table-cell">
                                @if ($task->enabled)
                                    <span>{{ $task->scheduleLabel() }}</span>
                                    @if (isset(CronJob::PRESETS[$task->schedule]) === false)
                                        <span class="block text-2xs text-muted">sonraki: {{ When::withTime($task->nextRun()) }}</span>
                                    @endif
                                @else
                                    <x-status-badge :status="new Badge('Duraklatıldı', 'muted')" />
                                @endif
                            </td>
                            <td class="hidden px-3 py-3 whitespace-nowrap md:table-cell">
                                @if ($task->last_run_at)
                                    <x-status-badge :status="$badge($task->last_status)" />
                                    <span class="num ml-1 text-[12.5px] text-muted">{{ When::withTime($task->last_run_at) }}</span>
                                @else
                                    <span class="text-muted">Henüz çalışmadı</span>
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="runNow({{ $task->id }})" class="text-[13px] text-accent hover:underline">Şimdi çalıştır</button>
                                <button type="button" wire:click="openHistory({{ $task->id }})" class="ml-3 text-[13px] text-accent hover:underline">Geçmiş</button>
                                @if ($task->enabled)
                                    <button type="button" wire:click="pause({{ $task->id }})" class="ml-3 text-[13px] text-accent hover:underline">Duraklat</button>
                                @else
                                    <button type="button" wire:click="resume({{ $task->id }})" class="ml-3 text-[13px] text-accent hover:underline">Sürdür</button>
                                @endif
                                <button type="button" wire:click="openEdit({{ $task->id }})" class="ml-3 text-[13px] text-accent hover:underline">Düzenle</button>
                                <button type="button" wire:click="confirmDelete({{ $task->id }})" class="ml-3 text-[13px] text-crit hover:underline">Sil</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center">
                                <p class="font-medium">Henüz zamanlanmış görev yok</p>
                                <p class="mt-1 text-[13px] text-muted">Bir betiği her gece, her saat ya da birkaç dakikada bir çalıştırmak için görev ekleyin.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="num border-t border-line px-5 py-3 text-2xs text-muted">
            {{ $usage ? $usage['text'].' zamanlanmış görev (paket)' : $tasks->count().' görev' }} · Her çalışmanın son 64 KB çıktısı saklanır; görev başına son 20 çalışma.
        </p>
    </section>

    {{-- Görev ekle / düzenle --}}
    <x-modal title="{{ $editId ? 'Görevi düzenle' : 'Görev ekle' }}" show="editing">
        <form wire:submit="save">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $editId ? 'Görevi düzenle' : 'Görev ekle' }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="grid items-start gap-x-4 gap-y-1.5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                    <label for="cron-preset" class="sm:pt-2">Zaman</label>
                    <div>
                        <select id="cron-preset" wire:model.live="preset" class="{{ $field }} border-line">
                            @foreach (CronJob::PRESETS as $expr => $label)
                                <option value="{{ $expr }}">{{ $label }}</option>
                            @endforeach
                            <option value="custom">Özel zaman (cron ifadesi)</option>
                        </select>
                        @if ($preset === 'custom')
                            <input id="cron-schedule" wire:model="schedule" aria-label="Cron ifadesi" spellcheck="false" autocomplete="off" placeholder="30 2 * * *" class="{{ $field }} mt-2 font-mono {{ $errors->has('schedule') ? 'border-crit' : 'border-line' }}">
                            @error('schedule')
                                <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                            @else
                                <p class="mt-1.5 text-2xs text-muted">dakika · saat · ayın günü · ay · haftanın günü. Örnek: <span class="font-mono">30 2 * * 1-5</span> hafta içi her gün 02:30.</p>
                            @enderror
                        @else
                            <p class="mt-1.5 font-mono text-2xs text-muted">{{ $preset }}</p>
                        @endif
                    </div>

                    <label for="cron-command" class="mt-2.5 sm:mt-0 sm:pt-2">Komut</label>
                    <div>
                        <input id="cron-command" wire:model="command" required spellcheck="false" autocomplete="off" placeholder="php public_html/cron.php" class="{{ $field }} font-mono {{ $errors->has('command') ? 'border-crit' : 'border-line' }}">
                        @error('command')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Site klasöründe çalışır; <span class="font-mono">php</span> sitenin PHP sürümüdür.</p>
                        @enderror
                    </div>

                    <label for="cron-timeout" class="mt-2.5 sm:mt-0 sm:pt-2">Süre sınırı</label>
                    <div>
                        <select id="cron-timeout" wire:model="timeout" class="{{ $field }} border-line sm:w-40">
                            @foreach (CronJobs::TIMEOUTS as $seconds => $label)
                                <option value="{{ $seconds }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-2xs text-muted">Bu süreyi aşan çalışma durdurulur.</p>
                    </div>

                    <label for="cron-note" class="mt-2.5 sm:mt-0 sm:pt-2">Not</label>
                    <div>
                        <input id="cron-note" wire:model="note" maxlength="100" placeholder="isteğe bağlı, örneğin Gece raporu" class="{{ $field }} {{ $errors->has('note') ? 'border-crit' : 'border-line' }}">
                        @error('note') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">{{ $editId ? 'Kaydet' : 'Görevi ekle' }}</span>
                    <span wire:loading wire:target="save">Uygulanıyor…</span>
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Silme onayı --}}
    @php $deleting = $deletingId ? $tasks->firstWhere('id', $deletingId) : null; @endphp
    <div x-data x-show="$wire.deletingId !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.deletingId = null">
        <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="Görevi sil">
            <p class="font-semibold">Görev silinsin mi?</p>
            <p class="mt-1 truncate font-mono text-[12.5px]">{{ $deleting?->command }}</p>
            <p class="mt-1 text-[13px] text-muted">Bir daha çalışmaz ve geçmişi silinir. 10 dakika içinde geri alabilirsiniz.</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deletingId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="button" wire:click="delete" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">Görevi sil</button>
            </div>
        </div>
    </div>

    {{-- Geçmiş --}}
    @if ($history !== null)
        @php
            $historyTask = $tasks->firstWhere('id', $historyId);
            $run = collect($history['runs'])->firstWhere('id', $runId);
        @endphp
        <div x-data class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.closeHistory()">
            <div class="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Çalışma geçmişi">
                <div class="flex h-12 shrink-0 items-center gap-3 border-b border-line px-5">
                    <h2 class="text-[14.5px] font-semibold">Çalışma geçmişi</h2>
                    <span class="min-w-0 flex-1 truncate font-mono text-[12.5px] text-muted">{{ $historyTask?->command }}</span>
                    <button type="button" wire:click="openHistory({{ $historyId }})" class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-fg" aria-label="Yenile" title="Yenile"><x-icon name="refresh-cw" class="size-3.5" /></button>
                </div>
                @if ($history['error'])
                    <p class="p-5 text-[13px] text-crit">{{ $history['error'] }}</p>
                @elseif ($history['runs'] === [])
                    <p class="p-5 text-[13px] text-muted">{{ $history['running'] ? 'Görev şu an çalışıyor; bitince çıktısı burada görünür.' : 'Görev henüz çalışmadı.' }}</p>
                @else
                    <div class="grid min-h-0 flex-1 sm:grid-cols-[240px_1fr]">
                        <ul class="max-h-40 divide-y divide-line overflow-y-auto border-b border-line sm:max-h-none sm:border-r sm:border-b-0">
                            @if ($history['running'])
                                <li class="flex items-center gap-2 px-4 py-2.5 text-[13px] text-info"><x-icon name="loader-circle" class="size-3.5 animate-spin" />Şu an çalışıyor</li>
                            @endif
                            @foreach ($history['runs'] as $r)
                                <li>
                                    <button type="button" wire:click="showRun('{{ $r['id'] }}')" @class(['flex w-full items-center gap-2 px-4 py-2.5 text-left text-[13px] hover:bg-surface-2', 'bg-surface-2' => $r['id'] === $runId])>
                                        <x-status-badge :status="$badge($r['status'])" />
                                        <span class="num ml-auto text-[12.5px] text-muted">{{ When::withTime(\Illuminate\Support\Carbon::parse($r['started_at'])) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <div class="flex min-h-0 min-w-0 flex-col">
                            @if ($run)
                                <p class="num flex flex-wrap gap-x-4 gap-y-1 border-b border-line px-4 py-2.5 text-[12.5px] text-muted">
                                    <span>{{ ($run['trigger'] ?? '') === 'manual' ? 'Elle başlatıldı' : 'Zamanında çalıştı' }}</span>
                                    @unless ($run['skipped'] ?? false)
                                        <span>Süre {{ number_format(($run['duration_ms'] ?? 0) / 1000, 1, ',', '.') }} sn</span>
                                        <span>Çıkış kodu <span class="font-mono">{{ $run['exit_code'] }}</span></span>
                                    @endunless
                                    @if ($run['truncated'] ?? false)
                                        <span>Çıktının son 64 KB'ı</span>
                                    @endif
                                </p>
                                <pre class="min-h-48 flex-1 overflow-auto bg-surface-2 p-4 font-mono text-[12px] leading-relaxed whitespace-pre-wrap">{{ ($run['skipped'] ?? false) ? 'Önceki çalışma henüz bitmediği için bu çalışma atlandı.' : (($run['output'] ?? '') !== '' ? $run['output'] : '(çıktı yok)') }}</pre>
                            @endif
                        </div>
                    </div>
                @endif
                <div class="flex shrink-0 justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="closeHistory" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
                </div>
            </div>
        </div>
    @endif
</div>
