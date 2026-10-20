@php
    $area = 'w-full rounded-md border bg-surface p-3 font-mono text-[12.5px] leading-relaxed outline-none focus:border-accent';
    $apache = $site->web_server === \App\Models\Site::APACHE;
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">Web sunucusu yönergeleri</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">Web sunucusu yönergeleri</h1>
        <p class="mt-1 text-[13px] text-muted">
            Sitenin ayarlarına eklenecek kendi satırlarınız. Kaydettiğinizde web sunucusu önce yapılandırmayı dener; kabul etmezse hiçbir şey değişmez ve hatası burada görünür.
            Yalnızca yöneticiler görür ve değiştirebilir.
        </p>
    </div>

    <form wire:submit="save" class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="grid items-start gap-x-6 gap-y-2 p-5 text-[13.5px] lg:grid-cols-[12rem_minmax(0,1fr)]">
            <div class="lg:pt-1">
                <label for="nginx" class="font-medium">Nginx</label>
                <p class="mt-1 text-2xs text-muted">Ana alan adının ve ek alan adlarının <span class="font-mono">server { }</span> bloğuna eklenir.</p>
            </div>
            <div>
                <textarea id="nginx" wire:model="nginx" rows="10" spellcheck="false" placeholder="# Örnek&#10;add_header X-Frame-Options SAMEORIGIN always;&#10;location /eski-dosyalar/ {&#10;    autoindex on;&#10;}" class="{{ $area }} {{ $errors->has('nginx') ? 'border-crit' : 'border-line' }}"></textarea>
                @error('nginx') <p class="mt-1.5 font-mono text-[12.5px] break-words whitespace-pre-line text-crit">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4 lg:pt-1">
                <label for="apache" class="font-medium">Apache</label>
                <p class="mt-1 text-2xs text-muted">Sitenin <span class="font-mono">&lt;VirtualHost&gt;</span> bloğuna eklenir.</p>
            </div>
            <div class="lg:mt-4">
                @if ($apache)
                    <textarea id="apache" wire:model="apache" rows="8" spellcheck="false" placeholder="# Örnek&#10;Header always set X-Content-Type-Options nosniff" class="{{ $area }} {{ $errors->has('apache') ? 'border-crit' : 'border-line' }}"></textarea>
                    @error('apache') <p class="mt-1.5 font-mono text-[12.5px] break-words whitespace-pre-line text-crit">{{ $message }}</p> @enderror
                @else
                    <p class="rounded-md bg-surface-2 p-3 text-[13px] text-muted">Site yalnızca Nginx ile çalışıyor. Apache satırları için <a href="{{ route('sites.php', $site) }}" wire:navigate class="text-accent hover:underline">PHP ve Web Sunucusu</a> sayfasından Nginx + Apache yığınını seçin.@if (filled($this->apache)) Kayıtlı Apache satırları o zaman uygulanır.@endif</p>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-3">
            <p class="min-w-0 flex-1 text-2xs text-muted">Hesap askıdayken bu satırlar uygulanmaz. Yanlış bir satır siteyi bozabilir; değişikliği 10 dakika içinde geri alabilirsiniz.</p>
            <button type="submit" wire:loading.attr="disabled" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="check" />Dene ve uygula
            </button>
        </div>
    </form>
</div>
