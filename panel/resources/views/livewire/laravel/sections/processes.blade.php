@php
    use App\Livewire\Laravel\Sections\Processes;
    use App\Models\LaravelProcess;
    use App\Support\Badge;
    $operate = auth()->user()->canOnApp($app, 'operate');
    $link = 'text-[13px] text-muted hover:text-fg';
    $domain = $app->site->domain;
    $badge = function (?LaravelProcess $p) use ($states, $syncing, $known) {
        if ($p === null || ! $p->enabled) {
            return new Badge(__('Kapalı'), 'muted');
        }
        if ($syncing) {
            return new Badge(__('Uygulanıyor'), 'info', true);
        }
        $state = $states[$p->name]['state'] ?? null;

        return match (true) {
            ! $known => new Badge(__('Bilinmiyor'), 'muted'),
            $state === 'active' => new Badge(__('Çalışıyor'), 'ok'),
            in_array($state, ['activating', 'reloading'], true) => new Badge(__('Başlıyor'), 'info', true),
            default => new Badge(__('Çalışmıyor'), 'crit'),
        };
    };
    $info = [
        'horizon' => ['Horizon', __('Kuyruk panosu ve işçi yönetimi. Açıkken kuyrukları Horizon işler; Kuyruklar bölümündeki işçiler durur.')],
        'octane' => ['Octane', __('Uygulamayı bellekte tutan sunucu. Açıkken Nginx istekleri Octane\'e iletir; statik dosyaları kendisi sunar.')],
        'reverb' => ['Reverb', __('WebSocket sunucusu. Nginx /app ve /apps yollarını Reverb\'e iletir.')],
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
            <span class="text-crit">{{ __('Süreç ayarları sunucuya uygulanamadı:') }} <span class="font-mono">{{ $app->processes_error }}</span></span>
            @if ($operate)<button type="button" wire:click="retrySync" class="ml-auto text-accent hover:underline">{{ __('Tekrar dene') }}</button>@endif
        </div>
    @endif

    @if ($operate)
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="edit" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="plus" />{{ __('Süreç ekle') }}</button>
            @if ($syncing)
                <span class="ml-auto flex items-center gap-2 text-[13px] text-muted"><x-icon name="loader-circle" class="animate-spin" />{{ __('Sunucuya uygulanıyor') }}</span>
            @endif
        </div>
    @endif

    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[720px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Süreç') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Ayrıntı') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
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
                                <x-t text="Sunucu: :octane_servers · :workers işçi · her işçi :max_requests istekte yenilenir · :port" :with="['octane_servers' => LaravelProcess::OCTANE_SERVERS[$p->server] ?? $p->server, 'workers' => $p->workers ?: __('otomatik'), 'max_requests' => $p->max_requests ?: __('sınırsız')]"><x-slot:port><span class="font-mono">127.0.0.1:{{ $p->port }}</span></x-slot:port></x-t>
                            @elseif ($on && $kind === 'reverb')
                                <span class="font-mono">{{ $scheme === 'https' ? 'wss' : 'ws' }}://{{ $domain }}/app</span> · <span class="font-mono">127.0.0.1:{{ $p->port }}</span>
                                <div class="mt-0.5 text-2xs"><x-t text="Uygulamanın .env dosyasında: :domain, :scheme, :scheme"><x-slot:domain><span class="font-mono">REVERB_HOST={{ $domain }}</span></x-slot:domain><x-slot:scheme><span class="font-mono">REVERB_PORT={{ $scheme === 'https' ? 443 : 80 }}</span></x-slot:scheme><x-slot:scheme><span class="font-mono">REVERB_SCHEME={{ $scheme }}</span></x-slot:scheme></x-t></div>
                            @elseif ($on && $kind === 'horizon')
                                <x-t text="Kuyrukları Horizon işliyor. Pano: :link"><x-slot:link><a href="{{ $scheme }}://{{ $domain }}/horizon" target="_blank" rel="noopener" class="font-mono text-accent hover:underline">/horizon</a></x-slot:link></x-t>
                                <div class="mt-0.5 text-2xs"><x-t text="Panoya kimin gireceğini uygulamanın :part kuralı belirler."><x-slot:part><span class="font-mono">viewHorizon</span></x-slot:part></x-t></div>
                            @else
                                {{ $info[$kind][1] }}
                                @unless ($installed)
                                    <div class="mt-0.5 text-2xs"><x-t text="Uygulamada :package yüklü değil. Önce Komutlar'da :package çalıştırıp dağıtın."><x-slot:package><span class="font-mono">{{ $package }}</span></x-slot:package><x-slot:package><span class="font-mono">{{ __('composer require :package', ['package' => $package]) }}</span></x-slot:package></x-t></div>
                                @endunless
                            @endif
                        </td>
                        <td class="px-3 py-3 align-top"><x-status-badge :status="$badge($p)" /></td>
                        <td class="space-x-3 px-3 py-3 text-right align-top whitespace-nowrap">
                            @if ($on)
                                <button type="button" wire:click="openLog('{{ $p->name }}')" class="{{ $link }}">{{ __('Günlük') }}</button>
                                @if ($operate)
                                    <button type="button" wire:click="restart({{ $p->id }})" class="{{ $link }}">{{ __('Yeniden başlat') }}</button>
                                    @if ($kind === 'octane')<button type="button" wire:click="edit({{ $p->id }})" class="{{ $link }}">{{ __('Ayarlar') }}</button>@endif
                                    <button type="button" wire:click="disable({{ $p->id }})" class="{{ $link }} hover:text-crit">{{ __('Kapat') }}</button>
                                @endif
                            @elseif ($operate)
                                <button type="button" wire:click="enable('{{ $kind }}')" @disabled(! $installed && $known) class="text-[13px] text-accent hover:underline disabled:text-muted disabled:no-underline disabled:opacity-60">{{ __('Aç') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @foreach ($custom as $p)
                    <tr wire:key="p-{{ $p->id }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5 align-top font-mono text-[13px] whitespace-nowrap">{{ $p->name }}</td>
                        <td class="px-3 py-3 font-mono text-[12.5px] break-all text-muted">{{ $p->command }}</td>
                        <td class="px-3 py-3 align-top"><x-status-badge :status="$badge($p)" /></td>
                        <td class="space-x-3 px-3 py-3 text-right align-top whitespace-nowrap">
                            <button type="button" wire:click="openLog('{{ $p->name }}')" class="{{ $link }}">{{ __('Günlük') }}</button>
                            @if ($operate)
                                @if ($p->enabled)
                                    <button type="button" wire:click="restart({{ $p->id }})" class="{{ $link }}">{{ __('Yeniden başlat') }}</button>
                                    <button type="button" wire:click="disable({{ $p->id }})" class="{{ $link }}">{{ __('Kapat') }}</button>
                                @else
                                    <button type="button" wire:click="enableAgain({{ $p->id }})" class="text-[13px] text-accent hover:underline">{{ __('Aç') }}</button>
                                @endif
                                <button type="button" wire:click="edit({{ $p->id }})" class="{{ $link }}">{{ __('Ayarlar') }}</button>
                                <button type="button" wire:click="delete({{ $p->id }})" class="{{ $link }} hover:text-crit">{{ __('Sil') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-3 text-2xs text-muted"><x-t text="Süreçler sitenin hesabıyla (:name) systemd hizmeti olarak çalışır, çökerse yeniden başlar ve her dağıtımdan sonra yeni sürümle yeniden başlatılır. Nginx vekil ve WebSocket ayarları otomatik yazılır. Günlükler :domain altında."><x-slot:name><span class="font-mono">{{ $app->site->systemUser->name }}</span></x-slot:name><x-slot:domain><span class="font-mono">/var/log/servik/laravel/{{ $domain }}/</span></x-slot:domain></x-t></p>

    {{-- Ayarlar --}}
    <x-modal title="{{ __('Süreç ayarları') }}" show="editing">
        <form wire:submit="save">
            @php $kind = $form['kind'] ?? 'custom'; @endphp
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $kind === 'octane' ? 'Octane' : ($processId ? __('Süreç ayarları') : __('Süreç ekle')) }}</h2>
            <div class="grid grid-cols-1 gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[130px_1fr]">
                @if ($kind === 'octane')
                    <label for="o-server" class="sm:pt-2">{{ __('Sunucu') }}</label>
                    <div>
                        <select id="o-server" wire:model="form.server" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                            @foreach (LaravelProcess::OCTANE_SERVERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        <p class="mt-1 text-2xs text-muted"><x-t text="FrankenPHP için bir kez :part çalıştırın; Swoole PHP eklentisi ister."><x-slot:part><span class="font-mono">php artisan octane:install --server=frankenphp</span></x-slot:part></x-t></p>
                    </div>
                    <label for="o-workers" class="sm:pt-2">{{ __('İşçi sayısı') }}</label>
                    <div>
                        <div class="flex items-center gap-2"><input id="o-workers" type="number" min="0" wire:model="form.workers" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('form.workers') ? 'border-crit' : 'border-line' }}"><span class="text-2xs text-muted">{{ __('0: işlemci sayısı kadar') }}</span></div>
                        @error('form.workers')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    <label for="o-max" class="sm:pt-2">{{ __('İstek sınırı') }}</label>
                    <div>
                        <div class="flex items-center gap-2"><input id="o-max" type="number" min="0" wire:model="form.max_requests" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('form.max_requests') ? 'border-crit' : 'border-line' }}"><span class="text-2xs text-muted">{{ __('işçi bu kadar istekten sonra yenilenir') }}</span></div>
                        @error('form.max_requests')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                @else
                    <label for="c-name" class="sm:pt-2">{{ __('Ad') }}</label>
                    <div>
                        <input id="c-name" wire:model="form.name" placeholder="{{ __('mqtt') }}" spellcheck="false" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.name') ? 'border-crit' : 'border-line' }}">
                        @error('form.name')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    <label for="c-cmd" class="sm:pt-2">{{ __('Komut') }}</label>
                    <div>
                        <input id="c-cmd" wire:model="form.command" placeholder="{{ __('php artisan mqtt:listen') }}" spellcheck="false" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('form.command') ? 'border-crit' : 'border-line' }}">
                        @error('form.command')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted"><x-t text="Uygulama dizininde çalışır. :part sitenin PHP :php_version sürümüdür." :with="['php_version' => $app->site->php_version]"><x-slot:part><span class="font-mono">php</span></x-slot:part></x-t></p>@enderror
                    </div>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ $kind === 'octane' && ! $processId ? __('Etkinleştir') : __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Günlük --}}
    @if ($viewingLog && $logName)
        <x-modal title="{{ __('Süreç günlüğü') }}" show="viewingLog" size="max-w-3xl">
            <h2 class="flex h-12 items-center border-b border-line px-5 font-mono text-[14px] font-semibold">{{ __(':log_name günlüğü', ['log_name' => $logName]) }}</h2>
            <div class="p-5"><x-run-log :run="$logName" method="readProcessLog" :start="-1" class="h-96" /></div>
            <div class="flex justify-end border-t border-line px-5 py-3"><button type="button" wire:click="closeLog" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button></div>
        </x-modal>
    @endif
</div>
