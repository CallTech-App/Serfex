@php
    use App\Models\Site;
    $busy = $site->status->isBusy();
    $hints = [
        Site::NGINX => 'Nginx statik dosyaları sunar, PHP isteklerini sitenin kendi PHP-FPM havuzuna gönderir. .htaccess dosyaları okunmaz.',
        Site::APACHE => 'Nginx önde durur ve statik dosyaları sunar; diğer istekler Apache\'ye gider. .htaccess dosyaları (yönlendirme, erişim kuralları) çalışır. PHP yine sitenin kendi PHP-FPM havuzunda çalışır.',
    ];
@endphp
<div class="space-y-6">
    @if ($busy)
        <div wire:poll.2s hidden></div>
    @endif

    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">PHP ve Web Sunucusu</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-[22px] font-semibold tracking-tight">PHP ve Web Sunucusu</h1>
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="arrow-left" />Siteye dön</a>
        </div>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">Bu ayarlar yalnızca <span class="font-mono text-fg">{{ $site->domain }}</span> için geçerlidir. Kaydettiğinizde hemen uygulanır; sunucu kabul etmezse site önceki ayarlarıyla çalışmaya devam eder.</p>
    </div>

    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-3 text-[13.5px] text-crit" role="alert">{{ $agentError }}</div>
    @endif

    <form wire:submit="save" class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="grid grid-cols-1 gap-x-6 gap-y-5 p-5 text-[13.5px] md:grid-cols-[220px_1fr]">
            <label for="php-version" class="font-medium md:pt-2">PHP sürümü</label>
            <div class="max-w-xl">
                <select id="php-version" wire:model="phpVersion" @disabled($busy) class="h-9 w-56 rounded-md border border-line bg-surface px-2.5">
                    @foreach ($versions as $v)
                        <option value="{{ $v }}">PHP {{ $v }}</option>
                    @endforeach
                </select>
                @error('phpVersion') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                @if (auth()->user()->is_admin)
                    <p class="mt-1.5 text-[13px] text-muted">Başka bir sürüm için <a href="{{ route('components.index') }}" wire:navigate class="text-accent hover:underline">Yazılım Yönetimi</a>'nden kurun.</p>
                @endif
            </div>

            <span class="font-medium md:pt-2" id="stack-label">Web sunucusu</span>
            <div class="max-w-xl" role="radiogroup" aria-labelledby="stack-label">
                <div class="grid gap-2">
                    @foreach (Site::STACKS as $key => $label)
                        @php $disabled = $key === Site::APACHE && ! $apacheInstalled && $site->web_server !== Site::APACHE; @endphp
                        <label @class(['flex gap-3 rounded-lg border p-3', 'border-accent bg-accent-soft' => $webServer === $key, 'border-line hover:bg-surface-2 cursor-pointer' => $webServer !== $key && ! $disabled, 'border-line opacity-60 cursor-not-allowed' => $disabled])>
                            <input type="radio" wire:model.live="webServer" value="{{ $key }}" @disabled($disabled || $busy) class="mt-0.5 size-4 accent-accent">
                            <span>
                                <span class="block font-medium">{{ $label }}{{ $key === Site::NGINX ? ' (önerilen)' : '' }}</span>
                                <span class="mt-0.5 block text-[12.5px] text-muted">{{ $hints[$key] }}</span>
                                @if ($disabled)
                                    <span class="mt-1 block text-[12.5px] text-muted">Sunucuda Apache kurulu değil.@if (auth()->user()->is_admin) <a href="{{ route('components.index') }}" wire:navigate class="text-accent hover:underline">Yazılım Yönetimi'nden kurun</a>.@endif</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('webServer') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                @if ($octane)
                    <p class="mt-2 text-[13px] text-muted">Bu sitede Laravel Octane açık; dinamik istekler uygulama sunucusuna gider.</p>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-b-xl border-t border-line px-5 py-3">
            <button type="submit" wire:loading.attr="disabled" wire:target="save" @disabled($busy) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="save" />Kaydet
            </button>
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="grid h-9 place-items-center rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</a>
            @if ($busy)
                <span class="text-[13px] text-muted">Site güncelleniyor, bitince değiştirebilirsiniz.</span>
            @endif
        </div>
    </form>
</div>
