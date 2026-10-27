@php
    use App\Models\LaravelBackup;
    use App\Support\Badge;
    use App\Support\Bytes;
    use App\Support\When;
    $check = 'size-4 accent-[rgb(var(--accent))]';
    $link = 'text-[13px] text-muted hover:text-fg';
    $plan = match ($app->backup_schedule) {
        'daily' => __('Her gün :backup_time', ['backup_time' => $app->backup_time]),
        'weekly' => __('Haftada bir, :backup_time', ['backup_time' => $app->backup_time]),
        default => __('Zamanlanmış yedek yok'),
    };
@endphp
<div>
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    @if ($restore)
        @php
            $tone = ['queued' => 'info', 'running' => 'info', 'succeeded' => 'ok', 'failed' => 'crit'][$restore->restore_status];
        @endphp
        <div class="relative flex flex-wrap items-center gap-3 border-b border-line py-2.5 pr-5 pl-6 text-[13px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-{{ $tone }}"></span>
            @if ($tone === 'info')<x-icon name="loader-circle" class="animate-spin text-info" />@endif
            <span class="min-w-0 flex-1">
                @switch($restore->restore_status)
                    @case('succeeded') {{ __(':created_at yedeği geri yüklendi.', ['created_at' => When::withTime($restore->created_at)]) }} @break
                    @case('failed') <x-t text="Geri yükleme başarısız: :restore_error"><x-slot:restore_error><span class="font-mono">{{ $restore->restore_error }}</span></x-slot:restore_error></x-t> @break
                    @default {{ __(':created_at yedeği geri yükleniyor.', ['created_at' => When::withTime($restore->created_at)]) }}
                @endswitch
            </span>
            <button type="button" wire:click="showLog({{ $restore->id }}, true)" class="text-accent hover:underline">{{ __('Günlük') }}</button>
            @if ($canUndoRestore && $manage)
                <button type="button" wire:click="undoRestore({{ $restore->id }})" class="text-accent hover:underline">{{ __('Geri al') }}</button>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <button type="button" wire:click="openCreate" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="archive" />{{ __('Şimdi yedekle') }}</button>
        <span class="ml-auto text-[13px] text-muted">{{ $plan }}@if ($app->backup_schedule !== 'off') {{ __('· son :backup_keep yedek saklanır', ['backup_keep' => $app->backup_keep]) }} @endif · <button type="button" wire:click="openSchedule" class="text-accent hover:underline">{{ __('Plan') }}</button></span>
    </div>

    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[680px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Zaman') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('İçerik') }}</th>
                    <th class="px-3 py-2.5 text-right font-medium">{{ __('Boyut') }}</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($backups as $b)
                    <tr wire:key="b-{{ $b->id }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5 whitespace-nowrap">{{ When::withTime($b->created_at) }}@if ($b->user_name)<div class="text-2xs text-muted">{{ $b->user_name }}</div>@endif</td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ $b->kindLabel() }}@if ($b->kind === 'restore' && $b->note)<div class="text-2xs text-muted">{{ $b->note }}</div>@endif</td>
                        <td class="px-3 py-3 text-muted">
                            @if ($b->isRunning())
                                <x-status-badge :status="new Badge(__('Alınıyor'), 'info', true)" />
                            @elseif ($b->status === 'failed')
                                <x-status-badge :status="new Badge(__('Başarısız'), 'crit')" /> <span class="ml-1 text-2xs break-words">{{ $b->error }}</span>
                            @else
                                {{ $b->partsLabel() ?: __('Boş') }}@if ($b->release) <x-t text="· sürüm :release"><x-slot:release><span class="font-mono">{{ $b->release }}</span></x-slot:release></x-t>@endif
                                @if ($b->error)<div class="text-2xs text-warn">{{ __('Alınmayan: :error', ['error' => $b->error]) }}</div>@endif
                            @endif
                        </td>
                        <td class="num px-3 py-3 text-right whitespace-nowrap">{{ $b->size !== null && $b->status === 'succeeded' ? Bytes::format($b->size) : '—' }}</td>
                        <td class="space-x-3 px-3 py-3 text-right whitespace-nowrap">
                            @if ($b->status === 'succeeded')
                                <a href="{{ route('laravel.backup.download', ['app' => $app->id, 'backup' => $b->id]) }}" class="{{ $link }}">{{ __('İndir') }}</a>
                                @if ($manage)<button type="button" wire:click="openRestore({{ $b->id }})" class="{{ $link }}">{{ __('Geri yükle') }}</button>@endif
                            @else
                                <button type="button" wire:click="showLog({{ $b->id }})" class="{{ $link }}">{{ __('Günlük') }}</button>
                            @endif
                            @unless ($b->isRunning())
                                <button type="button" wire:click="delete({{ $b->id }})" class="{{ $link }} hover:text-crit">{{ __('Sil') }}</button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center"><p class="font-medium">{{ __('Henüz yedek yok') }}</p><p class="mt-1 text-[13px] text-muted">{{ __('"Şimdi yedekle" ile ilk yedeği alın ya da bir plan seçin.') }}</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted"><x-t text="Yedekler sunucuda :domain altında tutulur ve yalnızca root okuyabilir. Bu uygulama, hesabın diğer sitelerine dokunmadan geri yüklenir. Önbellek, oturum ve günlük dosyaları yedeğe girmez."><x-slot:domain><span class="font-mono">/var/lib/serfex/backups/laravel/{{ $app->site->domain }}/</span></x-slot:domain></x-t></p>

    {{-- Şimdi yedekle --}}
    <x-modal :title="__('Şimdi yedekle')" show="creating" size="max-w-md">
        <form wire:submit="create">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Şimdi yedekle') }}</h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                @foreach (LaravelBackup::PARTS as $part => $label)
                    @continue($part === 'code' && ! $app->isManaged())
                    <label class="flex items-start gap-2.5"><input type="checkbox" value="{{ $part }}" wire:model="parts" class="mt-0.5 {{ $check }}">
                        <span>{{ $label }}<span class="block text-2xs text-muted">{{ ['code' => __('yayındaki sürümün kodu, vendor dahil (node_modules hariç)'), 'env' => __('gizli değerler dahil'), 'storage' => __('yüklenen dosyalar; önbellek, oturum ve günlükler hariç'), 'database' => __('mysqldump, pg_dump ya da SQLite dosyası')][$part] }}</span></span>
                    </label>
                @endforeach
                @error('parts')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Yedekle') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Plan --}}
    <x-modal :title="__('Yedekleme planı')" show="scheduling" size="max-w-md">
        <form wire:submit="saveSchedule">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Yedekleme planı') }}</h2>
            <div class="grid grid-cols-1 gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[120px_1fr]">
                <label for="bk-schedule" class="sm:pt-2">{{ __('Sıklık') }}</label>
                <select id="bk-schedule" wire:model.live="schedule" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                    <option value="off">{{ __('Kapalı') }}</option>
                    <option value="daily">{{ __('Her gün') }}</option>
                    <option value="weekly">{{ __('Haftada bir') }}</option>
                </select>
                @if ($schedule !== 'off')
                    <label for="bk-time" class="sm:pt-2">{{ __('Saat') }}</label>
                    <div>
                        <input id="bk-time" type="time" wire:model="time" class="num h-9 w-32 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('time') ? 'border-crit' : 'border-line' }}">
                        @error('time')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Sunucu saatiyle (:config).', ['config' => config('serfex.timezone')]) }}</p>@enderror
                    </div>
                    <label for="bk-keep" class="sm:pt-2">{{ __('Saklanan') }}</label>
                    <div>
                        <div class="flex items-center gap-2"><input id="bk-keep" type="number" min="1" max="90" wire:model="keep" class="num h-9 w-24 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('keep') ? 'border-crit' : 'border-line' }}"><span class="text-2xs text-muted">{{ __('zamanlanmış yedek; eskileri silinir') }}</span></div>
                        @error('keep')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                @endif
                <p class="text-2xs text-muted sm:col-span-2">{{ __('Zamanlanmış yedeğe kod, .env, storage ve veritabanı girer. Elle alınan yedekler siz silene kadar kalır; dağıtım öncesi yedeklerin son :keep_deploy tanesi saklanır.', ['keep_deploy' => \App\Services\Laravel\Backups::KEEP_DEPLOY]) }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('scheduling', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Geri yükle --}}
    <x-modal :title="__('Geri yükle')" show="restoring" size="max-w-md">
        @if ($restoreBackup)
            <form wire:submit="restore">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __(':created_at yedeği geri yüklensin mi?', ['created_at' => When::withTime($restoreBackup->created_at)]) }}</h2>
                <div class="space-y-3 p-5 text-[13.5px]">
                    <p class="text-muted">{{ __('Seçtikleriniz yedekteki hâline döner; hesabın diğer siteleri etkilenmez.') }}</p>
                    @foreach (LaravelBackup::PARTS as $part => $label)
                        @continue(! in_array($part, $restoreBackup->parts ?? [], true) || ($part === 'code' && ! $app->isManaged()))
                        <label class="flex items-center gap-2.5"><input type="checkbox" value="{{ $part }}" wire:model="restoreParts" class="{{ $check }}"><span>{{ $label }}@if ($part === 'code' && $restoreBackup->release) <span class="text-2xs text-muted">{{ __('(sürüm :release)', ['release' => $restoreBackup->release]) }}</span>@endif</span></label>
                    @endforeach
                    @error('restoreParts')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                    <label class="flex items-start gap-2.5 border-t border-line pt-3"><input type="checkbox" wire:model="safety" class="mt-0.5 {{ $check }}"><span>{{ __('Önce şu anki hâlin yedeğini al') }}<span class="block text-2xs text-muted">{{ __('Geri yüklemeyi geri alabilmeniz için önerilir.') }}</span></span></label>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('restoring', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Geri yükle') }}</button>
                </div>
            </form>
        @endif
    </x-modal>

    {{-- Günlük --}}
    @if ($viewingLog && $logRun)
        <x-modal :title="__('Günlük')" show="viewingLog" size="max-w-2xl">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $logTitle }}</h2>
            <div class="p-5"><x-run-log :run="$logRun" /></div>
            <div class="flex justify-end border-t border-line px-5 py-3"><button type="button" wire:click="closeLog" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button></div>
        </x-modal>
    @endif
</div>
