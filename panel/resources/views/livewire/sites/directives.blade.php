@php
    $area = 'w-full rounded-md border bg-surface p-3 font-mono text-[12.5px] leading-relaxed outline-none focus:border-accent';
    $apache = $site->web_server === \App\Models\Site::APACHE;
@endphp
<div class="space-y-6">
    <div>
        <x-site-header :site="$site" active="overview" :tool="__('Web sunucusu yönergeleri')" />
        <h1 class="mt-6 text-[18px] font-semibold tracking-tight">{{ __('Web sunucusu yönergeleri') }}</h1>
        <p class="mt-1 text-[13px] text-muted">
            {{ __('Sitenin ayarlarına eklenecek kendi satırlarınız. Kaydettiğinizde web sunucusu önce yapılandırmayı dener; kabul etmezse hiçbir şey değişmez ve hatası burada görünür. Yalnızca yöneticiler görür ve değiştirebilir.') }}
        </p>
    </div>

    <form wire:submit="save" class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="grid items-start gap-x-6 gap-y-2 p-5 text-[13.5px] lg:grid-cols-[12rem_minmax(0,1fr)]">
            <div class="lg:pt-1">
                <label for="nginx" class="font-medium">Nginx</label>
                <p class="mt-1 text-2xs text-muted"><x-t text="Ana alan adının ve ek alan adlarının :part bloğuna eklenir."><x-slot:part><span class="font-mono">server { }</span></x-slot:part></x-t></p>
            </div>
            <div>
                <textarea id="nginx" wire:model="nginx" rows="10" spellcheck="false" placeholder="{{ __("# Örnek\nadd_header X-Frame-Options SAMEORIGIN always;\nlocation /eski-dosyalar/ {\n    autoindex on;\n}") }}" class="{{ $area }} {{ $errors->has('nginx') ? 'border-crit' : 'border-line' }}"></textarea>
                @error('nginx') <p class="mt-1.5 font-mono text-[12.5px] break-words whitespace-pre-line text-crit">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4 lg:pt-1">
                <label for="apache" class="font-medium">Apache</label>
                <p class="mt-1 text-2xs text-muted"><x-t text="Sitenin :part bloğuna eklenir."><x-slot:part><span class="font-mono">&lt;VirtualHost&gt;</span></x-slot:part></x-t></p>
            </div>
            <div class="lg:mt-4">
                @if ($apache)
                    <textarea id="apache" wire:model="apache" rows="8" spellcheck="false" placeholder="{{ __("# Örnek\nHeader always set X-Content-Type-Options nosniff") }}" class="{{ $area }} {{ $errors->has('apache') ? 'border-crit' : 'border-line' }}"></textarea>
                    @error('apache') <p class="mt-1.5 font-mono text-[12.5px] break-words whitespace-pre-line text-crit">{{ $message }}</p> @enderror
                @else
                    <p class="rounded-md bg-surface-2 p-3 text-[13px] text-muted"><x-t text="Site yalnızca Nginx ile çalışıyor. Apache satırları için :link sayfasından Nginx + Apache yığınını seçin."><x-slot:link><a href="{{ route('sites.php', $site) }}" wire:navigate class="text-accent hover:underline">{{ __('PHP ve Web Sunucusu') }}</a></x-slot:link></x-t>@if (filled($this->apache)) {{ __('Kayıtlı Apache satırları o zaman uygulanır.') }}@endif</p>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-3">
            <p class="min-w-0 flex-1 text-2xs text-muted">{{ __('Hesap askıdayken bu satırlar uygulanmaz. Yanlış bir satır siteyi bozabilir; değişikliği 10 dakika içinde geri alabilirsiniz.') }}</p>
            <button type="submit" wire:loading.attr="disabled" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="check" />{{ __('Dene ve uygula') }}
            </button>
        </div>
    </form>
</div>
