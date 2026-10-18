@php
    use App\Support\Badge;
@endphp
<form wire:submit="down">
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4 text-[13.5px]">
        <x-status-badge :status="$down ? new Badge('Bakım modunda', 'warn') : new Badge('Uygulama açık', 'ok')" />
        <span class="text-[13px] text-muted">{{ $down ? 'Ziyaretçiler 503 bakım sayfasını görüyor.' : 'Sıfır kesintili dağıtımda bakım moduna gerek yoktur.' }}</span>
    </div>
    <div class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-[13.5px] md:grid-cols-[230px_1fr]">
        <p class="font-medium md:pt-2">Atlama bağlantısı</p>
        <div class="max-w-xl" x-data="{ copied: false }">
            <div class="flex gap-2">
                <input readonly value="{{ $link }}" aria-label="Atlama bağlantısı" class="h-9 min-w-0 flex-1 rounded-md border border-line bg-surface-2 px-3 font-mono text-[13px]">
                <button type="button" @click="navigator.clipboard.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 2000)" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">Kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
                @unless ($down)
                    <button type="button" wire:click="regenerate" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">Yenile</button>
                @endunless
            </div>
            @error('secret')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">Bu bağlantıyı açan ekip üyeleri bakım sırasında uygulamayı normal görür (<span class="font-mono">--secret</span>).</p>@enderror
        </div>
        <label for="m-retry" class="font-medium md:pt-2">Tekrar deneme süresi</label>
        <div class="max-w-xl">
            <div class="flex items-center gap-2"><input id="m-retry" type="number" min="0" wire:model="retry" @disabled($down) class="num h-9 w-24 rounded-md border bg-surface px-3 outline-none focus:border-accent disabled:opacity-60 {{ $errors->has('retry') ? 'border-crit' : 'border-line' }}"><span class="text-[13px] text-muted">saniye (<span class="font-mono">Retry-After</span>)</span></div>
            @error('retry')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
        </div>
        <p class="font-medium md:pt-1">Ziyaretçinin göreceği sayfa</p>
        <p class="max-w-xl text-muted">Uygulamanın kendi 503 sayfası (<span class="font-mono">resources/views/errors/503.blade.php</span>). IP izin listesi ve Servik'in hazır bakım sayfası sonraki sürümde.</p>
        <div class="md:col-start-2">
            @if ($down)
                <button type="button" wire:click="up" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">Bakımdan çıkar</button>
            @else
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-warn px-3.5 text-[13.5px] font-medium text-white hover:brightness-110 disabled:opacity-60">Bakım moduna al</button>
            @endif
        </div>
    </div>
</form>
