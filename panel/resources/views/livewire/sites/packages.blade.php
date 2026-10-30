@php
    use App\Services\Laravel\LaravelApps;
    use App\Support\Badge;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $select = 'h-9 rounded-md border border-line bg-surface px-2 text-[13px] outline-none focus:border-accent';
    $prefix = $tool === 'composer' ? 'composer' : $packageManager;
    $state = fn ($c) => match ($c->status) {
        'queued' => new Badge(__('Sırada'), 'info', true),
        'running' => new Badge(__('Çalışıyor'), 'info', true),
        'succeeded' => new Badge(__('Bitti'), 'ok'),
        default => new Badge($c->exit_code !== null ? __('Çıkış kodu :exit_code', ['exit_code' => $c->exit_code]) : __('Başarısız'), 'crit'),
    };
    $folder = fn (string $path) => $path === '' ? __('site klasörü') : $path;
    $nodeLines = $tools['node'] ?? [];
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
@endphp
<div class="space-y-6">
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif
    <div>
        <x-site-header :site="$site" active="overview" :tool="__('Composer ve Node.js')" />
        <h1 class="mt-6 text-[18px] font-semibold tracking-tight">{{ __('Composer ve Node.js') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted"><x-t text="PHP paketlerini kurun, ön yüzü derleyin. Komutlar sitenin kullanıcısıyla (:user) seçtiğiniz klasörde, sitenin PHP sürümüyle çalışır; Composer ve Node.js ilk kullanımda indirilir."><x-slot:user><span class="font-mono text-fg">{{ $site->systemUser->name }}</span></x-slot:user></x-t></p>
    </div>

    @if ($site->laravelApp)
        <p class="flex gap-2 rounded-lg bg-info-soft px-4 py-3 text-[13px]"><x-icon name="layers" class="mt-0.5 shrink-0 text-info" />
            <span><x-t text="Bu sitedeki Laravel uygulamasının komutları ve Artisan :link sayfasında. Buradaki komutlar dağıtımın dışında kalır."><x-slot:link><a href="{{ route('laravel.show', [$site->laravelApp, 'section' => 'commands']) }}" wire:navigate class="text-accent hover:underline">{{ __('Laravel') }}</a></x-slot:link></x-t></span>
        </p>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <div class="inline-flex rounded-md border border-line p-0.5 text-[13px]" role="tablist" aria-label="{{ __('Araç') }}">
                @foreach (['composer' => 'Composer', 'node' => 'Node.js'] as $key => $label)
                    <button type="button" role="tab" wire:click="$set('tool', '{{ $key }}')" aria-selected="{{ $tool === $key ? 'true' : 'false' }}"
                            class="h-7 rounded px-2.5 {{ $tool === $key ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:text-fg' }}">{{ $label }}</button>
                @endforeach
            </div>
            <span class="ml-auto text-2xs text-muted">
                @if ($tool === 'composer')
                    {{ filled($tools['composer'] ?? '') ? 'Composer '.$tools['composer'] : __('Composer ilk komutta indirilir') }}
                @else
                    {{ $nodeLines ? 'Node.js '.collect($nodeLines)->values()->join(', ') : __('Node.js ilk komutta indirilir') }}
                @endif
            </span>
        </div>

        <div class="divide-y divide-line px-5 text-[13.5px]">
            {{-- Klasör --}}
            <div class="{{ $row }}">
                <div>
                    <p class="font-medium">{{ __('Klasör') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ $tool === 'composer' ? __('composer.json bulunan klasörler') : __('package.json bulunan klasörler') }}</p>
                </div>
                <div class="space-y-2">
                    @if ($tools === null)
                        <p class="text-[13px] text-crit">{{ __('Sunucu ajanına ulaşılamıyor.') }}</p>
                    @elseif ($projects->isEmpty())
                        <p class="text-[13px] text-muted">{{ $tool === 'composer' ? __('Sitede composer.json bulunamadı. Komut site klasöründe çalışır; composer init ya da composer require ile başlayabilirsiniz.') : __('Sitede package.json bulunamadı. Komut site klasöründe çalışır; npm init -y ile başlayabilirsiniz.') }}</p>
                    @else
                        <div class="divide-y divide-line rounded-lg border border-line">
                            @foreach ($projects as $p)
                                <label wire:key="project-{{ md5($p['path']) }}" class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-2">
                                    <input type="radio" wire:model.live="path" value="{{ $p['path'] }}" class="size-4 accent-accent">
                                    <span class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $folder($p['path']) }}</span>
                                    <span class="flex shrink-0 flex-wrap justify-end gap-1.5 text-2xs">
                                        @if ($tool === 'composer')
                                            <span class="{{ $p['composer_lock'] ? 'text-muted' : 'text-warn' }}">{{ $p['composer_lock'] ? 'composer.lock' : __('composer.lock yok') }}</span>
                                            <span class="{{ $p['vendor'] ? 'text-muted' : 'text-warn' }}">{{ $p['vendor'] ? 'vendor/' : __('vendor/ kurulmamış') }}</span>
                                        @else
                                            <span class="font-mono text-muted">{{ $p['manager'] ?? 'npm' }}</span>
                                            <span class="{{ $p['modules'] ? 'text-muted' : 'text-warn' }}">{{ $p['modules'] ? 'node_modules/' : __('node_modules/ kurulmamış') }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    <button type="button" wire:click="refreshScan" class="text-[12.5px] text-accent hover:underline">{{ __('Yeniden tara') }}</button>
                </div>
            </div>

            {{-- Node.js sürümü --}}
            @if ($tool === 'node')
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Node.js sürümü') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('Bu sitenin komutları ve Laravel derlemeleri') }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <select wire:model="node" wire:change="saveNode" aria-label="{{ __('Node.js sürümü') }}" class="{{ $select }}">
                            <option value="">{{ __('Sunucunun varsayılanı') }}{{ filled($tools['default_node'] ?? '') ? ' ('.$tools['default_node'].')' : '' }}</option>
                            @foreach ($nodeLines as $line => $version)
                                <option value="{{ $line }}">Node.js {{ $line }} ({{ $version }})</option>
                            @endforeach
                        </select>
                        @if (auth()->user()->is_admin)
                            <p class="text-2xs text-muted"><x-t text="Başka bir sürüm için :link sayfasından kurun."><x-slot:link><a href="{{ route('components.index') }}" wire:navigate class="text-accent hover:underline">{{ __('Yazılım Yönetimi') }}</a></x-slot:link></x-t></p>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Komut --}}
            <form wire:submit="run" class="space-y-3 py-4">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($tool === 'node')
                        <select wire:model.live="packageManager" aria-label="{{ __('Paket yöneticisi') }}" class="{{ $select }} font-mono">
                            @foreach (LaravelApps::PACKAGE_MANAGERS as $pm)
                                <option>{{ $pm }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="flex min-w-0 flex-1 overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('command') ? 'border-crit' : 'border-line' }}">
                        <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] whitespace-nowrap text-muted">{{ $prefix }}</span>
                        <input wire:model="command" aria-label="{{ __('Komut') }}" autocomplete="off" spellcheck="false" placeholder="{{ $tool === 'composer' ? 'require vendor/paket' : 'run build' }}"
                               class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                        <button type="submit" wire:loading.attr="disabled" wire:target="run" class="bg-accent px-3.5 text-[13px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">{{ __('Çalıştır') }}</button>
                    </div>
                </div>
                @error('command')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                @error('path')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="mr-1 text-2xs text-muted">{{ __('Hızlı:') }}</span>
                    @foreach ($quick as $q)
                        <button type="button" wire:key="quick-{{ md5($q) }}" wire:click="pick(@js($q))" class="h-7 rounded-md border border-line px-2 font-mono text-[12px] hover:bg-surface-2">{{ $q }}</button>
                    @endforeach
                </div>

                @if ($shown)
                    <div class="flex flex-wrap items-center gap-2 text-[13px]">
                        <span class="font-mono">{{ $shown->tool }} {{ $shown->command }}</span>
                        <span class="font-mono text-2xs text-muted">{{ $folder($shown->path) }}</span>
                        <x-status-badge :status="$state($shown)" />
                        @if ($shown->error)<span class="text-2xs text-crit">{{ $shown->error }}</span>@endif
                    </div>
                    <x-run-log :run="$shown->run" class="h-72" />
                @else
                    <pre class="h-72 rounded-md bg-[#0f1a1c] p-3 font-mono text-[12.5px] text-[#7f9a9a]">{{ __('Çıktı burada görünür.') }}</pre>
                @endif
                <p class="text-2xs text-muted"><x-t text="Komut :path içinde çalışır. Çıktı canlı akar; sayfadan çıksanız da komut sürer (en çok 30 dakika)."><x-slot:path><span class="font-mono">{{ $directory }}</span></x-slot:path></x-t></p>
            </form>
        </div>

        @if ($recent->isNotEmpty())
            <div class="border-t border-line">
                <p class="px-5 pt-3 text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Son komutlar') }}</p>
                <ul class="divide-y divide-line px-5 pb-2 text-[13px]">
                    @foreach ($recent as $c)
                        <li wire:key="cmd-{{ $c->id }}" class="flex items-center gap-3 py-2">
                            <button type="button" wire:click="show({{ $c->id }})" class="min-w-0 truncate text-left font-mono {{ $shown?->id === $c->id ? 'text-accent' : 'hover:text-accent' }}">{{ $c->tool }} {{ $c->command }}</button>
                            <span class="hidden shrink-0 font-mono text-2xs text-muted sm:inline">{{ $folder($c->path) }}</span>
                            <span class="ml-auto shrink-0 text-2xs text-muted">{{ \App\Support\When::withTime($c->created_at) }}</span>
                            <x-status-badge :status="$state($c)" class="shrink-0" />
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</div>
