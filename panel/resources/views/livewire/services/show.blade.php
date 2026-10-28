@php
    use App\Livewire\Services\ServiceIndex;
    use App\Livewire\Services\ServiceShow;
    use App\Services\System\SystemServices;
    use App\Support\Bytes;
    $running = $service ? SystemServices::running($service) : false;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('services.index') }}" wire:navigate class="hover:text-accent">{{ __('Hizmetler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ $service['name'] ?? $unit }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-[22px] font-semibold tracking-tight">{{ $service['name'] ?? $unit }}</h1>
                @if ($service)
                    <x-status-badge :status="SystemServices::badge($service)" />
                @endif
            </div>
            @if ($service)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($running)
                        @if ($service['can_reload'])
                            <button type="button" wire:click="act('reload')" wire:loading.attr="disabled" class="{{ $btn }}" title="{{ __('Ayarları kesinti olmadan yeniden okur') }}"><x-icon name="refresh-cw" />{{ __('Yeniden yükle') }}</button>
                        @endif
                        <button type="button" wire:click="act('restart')" wire:loading.attr="disabled" class="{{ $btn }}"><x-icon name="rotate-ccw" />{{ __('Yeniden başlat') }}</button>
                        @if ($service['protected'] ?? null)
                            <span class="{{ $btn }} cursor-not-allowed opacity-50" title="{{ $service['protected'] }}" aria-disabled="true"><x-icon name="square" />{{ __('Durdur') }}</span>
                        @else
                            <button type="button" wire:click="act('stop')" wire:loading.attr="disabled" class="{{ $btn }} hover:text-crit"><x-icon name="square" />{{ __('Durdur') }}</button>
                        @endif
                    @elseif (! in_array($service['state'], ['activating', 'deactivating'], true))
                        <button type="button" wire:click="act('start')" wire:loading.attr="disabled" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40"><x-icon name="play" />{{ __('Başlat') }}</button>
                    @endif
                </div>
            @endif
        </div>
        <p class="mt-1 font-mono text-[13px] text-muted">{{ $unit }}.service</p>
    </div>

    @if ($service && in_array($service['state'], ['activating', 'deactivating'], true))
        <div wire:poll.2s hidden></div>
    @endif

    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-4 text-[13.5px]"><p class="font-medium text-crit">{{ $agentError }}</p></div>
    @endif
    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="flex-1">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif

    @if ($service)
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 p-5 text-[13.5px] md:grid-cols-[220px_1fr]">
                <dt class="text-muted">{{ $running ? __('Çalışma süresi') : __('Ne zamandır duruyor') }}</dt>
                <dd>{{ ServiceIndex::uptime($service['since'] ?? null) }}@if (($service['restarts'] ?? 0) > 0)<span class="text-muted"> {{ __('· kendiliğinden :restarts kez yeniden başladı', ['restarts' => $service['restarts']]) }}</span>@endif</dd>
                @if ($running)
                    <dt class="text-muted">{{ __('İşlemci ve bellek') }}</dt>
                    <dd class="font-mono text-[13px]">{{ \App\Support\Num::format((float) $service['cpu'], 1) }} % · {{ $service['memory'] ? Bytes::format((int) $service['memory']) : '—' }}@if ($service['pid'] ?? null)<span class="text-muted"> {{ __('· PID :pid', ['pid' => $service['pid']]) }}</span>@endif</dd>
                @endif
                <dt class="text-muted md:pt-1">{{ __('Sunucu açılınca başlasın') }}</dt>
                <dd>
                    @if (in_array($service['enabled'], ['enabled', 'disabled'], true))
                        @php $on = $service['enabled'] === 'enabled'; $locked = $on && ($service['protected'] ?? null); @endphp
                        <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" wire:click="act('{{ $on ? 'disable' : 'enable' }}')" @disabled($locked)
                                @if ($locked) title="{{ $service['protected'] }}" @endif
                                @class(['relative inline-flex h-6 w-10 shrink-0 items-center rounded-full transition disabled:opacity-50', 'bg-accent' => $on, 'bg-line' => ! $on])>
                            <span @class(['inline-block size-5 rounded-full bg-surface shadow transition', 'translate-x-[18px]' => $on, 'translate-x-0.5' => ! $on])></span>
                        </button>
                    @else
                        <span class="text-muted">{{ __('Başka bir hizmetle birlikte başlar') }}</span>
                    @endif
                </dd>
                @if ($affects)
                    <dt class="text-muted">{{ __('Durursa') }}</dt>
                    <dd>{{ $affects }}</dd>
                @endif
                @if ($service['protected'] ?? null)
                    <dt class="text-muted">{{ __('Durdurulamaz') }}</dt>
                    <dd>{{ $service['protected'] }}</dd>
                @endif
            </dl>

            @if ($fields !== [])
                <div class="flex gap-5 overflow-x-auto border-t border-b border-line px-5 text-[13.5px]" role="tablist">
                    @foreach (ServiceShow::TABS as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                                @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @else
                <h2 class="flex h-12 items-center border-t border-line px-5 text-[14.5px] font-semibold">{{ __('Günlük') }}</h2>
            @endif

            @if ($tab === 'settings' && $fields !== [])
                <form wire:submit="saveSettings">
                    @if ($settingsError)
                        <p class="mx-5 mt-4 rounded-lg bg-crit-soft px-3 py-2 text-[13px] text-crit" role="alert">{{ $settingsError }}</p>
                    @endif
                    @if (! $running)
                        <p class="mx-5 mt-4 rounded-lg bg-warn-soft px-3 py-2 text-[13px]">{{ __('Ayarlar hizmet çalışırken değiştirilebilir; önce başlatın.') }}</p>
                    @endif
                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 p-5 text-[13.5px] md:grid-cols-[220px_1fr]">
                        @foreach ($fields as $key => $field)
                            @php [$label, $suffix, $min, $max, $help] = $field; $kind = $field[5] ?? 'number'; @endphp
                            <label for="set-{{ $key }}" @class(['font-medium', 'md:pt-2' => $kind !== 'switch', 'md:pt-0.5' => $kind === 'switch'])>{{ $label }}</label>
                            <div class="max-w-xl">
                                @if ($kind === 'switch')
                                    <label class="flex items-center gap-2.5">
                                        <input id="set-{{ $key }}" type="checkbox" wire:model="settings.{{ $key }}" @disabled(! $running) class="size-4 accent-accent">
                                        {{ __('Açık') }}
                                    </label>
                                @elseif ($kind === 'list')
                                    <textarea id="set-{{ $key }}" wire:model="settings.{{ $key }}" rows="3" @disabled(! $running) placeholder="192.0.2.10&#10;2001:db8::/48"
                                              class="w-full rounded-md border border-line bg-surface px-2.5 py-2 font-mono text-[13px] sm:w-80"></textarea>
                                @else
                                    <div class="flex items-center gap-2">
                                        <input id="set-{{ $key }}" type="text" inputmode="{{ $key === 'worker_processes' ? 'text' : 'numeric' }}" wire:model="settings.{{ $key }}" @disabled(! $running)
                                               class="h-9 w-40 rounded-md border border-line bg-surface px-2.5 font-mono text-[13px]">
                                        @if ($suffix)<span class="text-[13px] text-muted">{{ $suffix }}</span>@endif
                                    </div>
                                @endif
                                @error('settings.'.$key) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                                <p class="mt-1.5 text-[13px] text-muted">{{ $help }}@if ($min !== null) <span class="whitespace-nowrap">({{ \App\Support\Num::format($min, 0) }}–{{ \App\Support\Num::format($max, 0) }})</span>@endif</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-3">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveSettings" @disabled(! $running) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                            <x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="saveSettings" />{{ __('Kaydet ve uygula') }}
                        </button>
                        <span class="text-[13px] text-muted">{{ __('Ayar dosyası önce denetlenir; sunucu kabul etmezse önceki ayarlar kalır.') }}</span>
                    </div>
                </form>
            @else
                <div class="p-5">
                    @if (is_string($logs))
                        <p class="text-[13px] text-crit">{{ $logs }}</p>
                    @elseif ($logs === [] || $logs === null)
                        <p class="text-[13px] text-muted">{{ __('Günlükte kayıt yok.') }}</p>
                    @else
                        <pre class="scroll-thin max-h-[480px] overflow-auto rounded-md bg-[#0f1a1c] p-3 font-mono text-[12px] whitespace-pre-wrap text-[#d7e4e4]">{{ implode("\n", $logs) }}</pre>
                    @endif
                    <div class="mt-3 flex flex-wrap items-center gap-4 text-[13px]">
                        <button type="button" wire:click="$refresh" class="flex items-center gap-1.5 text-accent hover:underline"><x-icon name="refresh-cw" class="size-3.5" wire:loading.class="animate-spin" />{{ __('Yenile') }}</button>
                        @if (is_array($logs) && count($logs) >= $lines && $lines < 1000)
                            <button type="button" wire:click="moreLines" class="text-accent hover:underline">{{ __('Daha eski satırlar') }}</button>
                        @endif
                        <span class="text-muted">{{ __('Son :lines satır, en yenisi altta.', ['lines' => $lines]) }}</span>
                    </div>
                </div>
            @endif
        </section>
    @endif

    <x-modal title="{{ $confirm['title'] ?? __('Onay') }}" show="confirming" size="max-w-md">
        @if ($confirm)
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold">{{ $confirm['title'] }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ $confirm['text'] }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" @class(['h-9 rounded-md px-3.5 font-medium disabled:opacity-40', 'bg-crit text-on-crit' => $confirm['danger'], 'bg-accent text-accent-fg hover:brightness-110' => ! $confirm['danger']])>{{ $confirm['button'] }}</button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
