{{-- The customer's login link, shown once after it is made. --}}
<div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
    <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Giriş bağlantısı" x-data="{ copied: false }">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Müşterinin giriş bağlantısı</h2>
        <div class="space-y-2 p-5 text-[13.5px]">
            <div class="flex gap-2">
                <input readonly value="{{ $link }}" aria-label="Bağlantı" class="h-9 min-w-0 flex-1 rounded-md border border-line bg-surface-2 px-3 font-mono text-[13px]">
                <button type="button" @click="navigator.clipboard.writeText(@js($link)); copied = true" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">Kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
            </div>
            <p class="text-2xs text-muted">Bağlantıyı müşteriye iletin; açınca şifresini belirler ve paneline girer. {{ \App\Http\Controllers\Auth\InvitationController::DAYS }} gün geçerlidir. Servik bu bağlantıyı e-postayla göndermez.</p>
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            @if ($next ?? null)
                <a href="{{ $next }}" wire:navigate class="flex h-9 items-center rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Müşteriye git</a>
            @endif
            <button type="button" wire:click="{{ $close }}" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Tamam</button>
        </div>
    </div>
</div>
