@php
    use App\Livewire\Laravel\Sections\Processes;
    use App\Models\LaravelProcess;
    use App\Support\Badge;
    $operate = auth()->user()->canOnApp($app, 'operate');
    $link = 'text-[13px] text-muted hover:text-fg';
    $domain = $app->site->domain;
    $badge = function (?LaravelProcess $p) use ($states, $syncing, $known) {
        if ($p === null || ! $p->enabled) {
            return new Badge('Kapalı', 'muted');
        }
        if ($syncing) {
            return new Badge('Uygulanıyor', 'info', true);
        }
        $state = $states[$p->name]['state'] ?? null;

        return match (true) {
            ! $known => new Badge('Bilinmiyor', 'muted'),
            $state === 'active' => new Badge('Çalışıyor', 'ok'),
            in_array($state, ['activating', 'reloading'], true) => new Badge('Başlıyor', 'info', true),
            default => new Badge('Çalışmıyor', 'crit'),
        };
    };
    $info = [
        'horizon' => ['Horizon', 'Kuyruk panosu ve işçi yönetimi. Açıkken kuyrukları Horizon işler; Kuyruklar bölümündeki işçiler durur.'],
        'octane' => ['Octane', 'Uygulamayı bellekte tutan sunucu. Açıkken Nginx istekleri Octane\'e iletir; statik dosyaları kendisi sunar.'],
        'reverb' => ['Reverb', 'WebSocket sunucusu. Nginx /app ve /apps yollarını Reverb\'e iletir.'],
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
            <span class="text-crit">Süreç ayarları sunucuya uygulanamadı: <span class="font-mono">{{ $app->processes_error }}</span></span>
            @if ($operate)<button type="button" wire:click="retrySync" class="ml-auto text-accent hover:underline">Tekrar dene</button>@endif
        </div>
    @endif

    @if ($operate)
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="edit" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="plus" />Süreç ekle</button>
            @if ($syncing)
                <span class="ml-auto flex items-center gap-2 text-[13px] text-muted"><x-icon name="loader-circle" class="animate-spin" />Sunucuya uygulanıyor</span>
            @endif
        </div>
    @endif

    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[720px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">Süreç</th>
                    <th class="px-3 py-2.5 font-medium">Ayrıntı</th>
                    <th class="px-3 py-2.5 font-medium">Durum</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($builtIn as $kind => $p)
                    @php
                        $package = Processes::PACKAGES[$kind];
                        $installed = $packages[$package] ?? null;
                        $on = $p?->enabled;
                    @endphp
                    <tr wire:key="p-{{ $kind }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5 align-top font-medium">{{ $info[$kind][0] }}@if ($installed)<div class="font-mono text-2xs font-normal text-muted">{{ $installed }}</div>@endif</td>
                        <td class="px-3 py-3 text-muted">
                            @if ($on && $kind === 'octane')
                                Sunucu: {{ LaravelProcess::OCTANE_SERVERS[$p->server] ?? $p->server }} · {{ $p->workers ?: 'otomatik' }} işçi · her işçi {{ $p->max_requests ?: 'sınırsız' }} istekte yenilenir · <span class="font-mono">127.0.0.1:{{ $p->port }}</span>
                            @elseif ($on && $kind === 'reverb')
                                <span class="font-mono">{{ $scheme === 'https' ? 'wss' : 'ws' }}://{{ $domain }}/app</span> · <span class="font-mono">127.0.0.1:{{ $p->port }}</span>
                                <div class="mt-0.5 text-2xs">Uygulamanın .env dosyasında: <span class="font-mono">REVERB_HOST={{ $domain }}</span>, <span class="font-mono">REVERB_PORT={{ $scheme === 'https' ? 443 : 80 }}</span>, <span class="font-mono">REVERB_SCHEME={{ $scheme }}</span></div>
                            @elseif ($on && $kind === 'horizon')
                                Kuyrukları Horizon işliyor. Pano: <a href="{{ $scheme }}://{{ $domain }}/horizon" target="_blank" rel="noopener" class="font-mono text-accent hover:underline">/horizon</a>
                                <div class="mt-0.5 text-2xs">Panoya kimin gireceğini uygulamanın <span class="font-mono">viewHorizon</span> kuralı belirler.</div>
                            @else
                                {{ $info[$kind][1] }}
                                @unless ($installed)
                                    <div class="mt-0.5 text-2xs">Uygulamada <span class="font-mono">{{ $package }}</span> yüklü değil. Önce Komutlar'da <span class="font-mono">composer require {{ $package }}</span> çalıştırıp dağıtın.</div>
                                @endunless
                            @endif
                        </td>
                        <td class="px-3 py-3 align-top"><x-status-badge :status="$badge($p)" /></td>
                        <td class="space-x-3 px-3 py-3 text-right align-top whitespace-nowrap">
                            @if ($on)
                                <button type="button" wire:click="openLog('{{ $p->name }}')" class="{{ $link }}">Günlük</button>
                                @if ($operate)
                                    <button type="button" wire:click="restart({{ $p->id }})" class="{{ $link }}">Yeniden başlat</button>
                                    @if ($kind === 'octane')<button type="button" wire:click="edit({{ $p->id }})" class="{{ $link }}">Ayarlar</button>@endif
                                    <button type="button" wire:click="disable({{ $p->id }})" class="{{ $link }} hover:text-crit">Kapat</button>
                                @endif
                            @elseif ($operate)
                                <button type="button" wire:click="enable('{{ $kind }}')" @disabled(! $installed && $known) class="text-[13px] text-accent hover:underline disabled:text-muted disabled:no-underline disabled:opacity-60">Aç</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @foreach ($custom as $p)
                    <tr wire:key="p-{{ $p->id }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5 align-top font-mono text-[13px]">{{ $p->name }}</td>
                        <td class="px-3 py-3 font-mono text-[12.5px] break-all text-muted">{{ $p->command }}</td>
                        <td class="px-3 py-3 align-top"><x-status-badge :status="$badge($p)" /></td>
                        <td class="space-x-3 px-3 py-3 text-right align-top whitespace-nowrap">
                            <button type="button" wire:click="openLog('{{ $p->name }}')" class="{{ $link }}">Günlük</button>
                            @if ($operate)
                                @if ($p->enabled)
                                    <button type="button" wire:click="restart({{ $p->id }})" class="{{ $link }}">Yeniden başlat</button>
                                    <button type="button" wire:click="disable({{ $p->id }})" class="{{ $link }}">Kapat</button>
                                @else
                                    <button type="button" wire:click="enableAgain({{ $p->id }})" class="text-[13px] text-accent hover:underline">Aç</button>
                                @endif
                                <button type="button" wire:click="edit({{ $p->id }})" class="{{ $link }}">Ayarlar</button>
                                <button type="button" wire:click="delete({{ $p->id }})" class="{{ $link }} hover:text-crit">Sil</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted">Süreçler sitenin hesabıyla (<span class="font-mono">{{ $app->site->systemUser->name }}</span>) systemd hizmeti olarak çalışır, çökerse yeniden başlar ve her dağıtımdan sonra yeni sürümle yeniden başlatılır. Nginx vekil ve WebSocket ayarları otomatik yazılır. Günlükler <span class="font-mono">/var/log/servik/laravel/{{ $domain }}/</span> altında.</p>

    {{-- Ayarlar --}}
    <x-modal title="Süreç ayarları" show="editing">
        <form wire:submit="save">
            @php $kind = $form['kind'] ?? 'custom'; @endphp
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $kind === 'octane' ? 'Octane' : ($processId ? 'Süreç ayarları' : 'Süreç ekle') }}</h2>
            <div class="grid grid-cols-1 gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[130px_1fr]">
                @if ($kind === 'octane')
                    <label for="o-server" class="sm:pt-2">Sunucu</label>
                    <div>
                        <select id="o-server" wire:model="form.server" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                            @foreach (LaravelProcess::OCTANE_SERVERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        <p class="mt-1 text-2xs text-muted">FrankenPHP için bir kez <span class="font-mono">php artisan octane:install --server=frankenphp</span> çalıştırın; Swoole PHP eklentisi ister.</p>
                    </div>
                    <label for="o-workers" class="sm:pt-2">İşçi sayısı</label>
                    <div>
                        <div class="flex items-center gap-2"><input id="o-workers" type="number" min="0" wire:model="form.workers" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('form.workers') ? 'border-crit' : 'border-line' }}"><span class="text-2xs text-muted">0: işlemci sayısı kadar</span></div>
                        @error('form.workers')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    <label for="o-max" class="sm:pt-2">İstek sınırı</label>
                    <div>
                        <div class="flex items-center gap-2"><input id="o-max" type="number" min="0" wire:model="form.max_requests" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('form.max_requests') ? 'border-crit' : 'border-line' }}"><span class="text-2xs text-muted">işçi bu kadar istekten sonra yenilenir</span></div>
                        @error('form.max_requests')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                @else
                    <label for="c-name" class="sm:pt-2">Ad</label>
                    <div>
                        <input id="c-name" wire:model="form.name" placeholder="mqtt" spellcheck="false" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.name') ? 'border-crit' : 'border-line' }}">
                        @error('form.name')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    <label for="c-cmd" class="sm:pt-2">Komut</label>
                    <div>
                        <input id="c-cmd" wire:model="form.command" placeholder="php artisan mqtt:listen" spellcheck="false" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.command') ? 'border-crit' : 'border-line' }}">
                        @error('form.command')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">Uygulama dizininde çalışır. <span class="font-mono">php</span> sitenin PHP {{ $app->site->php_version }} sürümüdür.</p>@enderror
                    </div>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ $kind === 'octane' && ! $processId ? 'Etkinleştir' : 'Kaydet' }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Günlük --}}
    @if ($viewingLog && $logName)
        <x-modal title="Süreç günlüğü" show="viewingLog" size="max-w-3xl">
            <h2 class="flex h-12 items-center border-b border-line px-5 font-mono text-[14px] font-semibold">{{ $logName }} günlüğü</h2>
            <div class="p-5"><x-run-log :run="$logName" method="readProcessLog" :start="-1" class="h-96" /></div>
            <div class="flex justify-end border-t border-line px-5 py-3"><button type="button" wire:click="closeLog" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button></div>
        </x-modal>
    @endif
</div>
