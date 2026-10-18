@php
    use App\Enums\CertificateStatus;
    $date = fn ($d) => $d?->timezone(config('app.timezone'))->format('d.m.Y');
    $field = 'h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent';
    $panelBusy = $panel?->status === CertificateStatus::Issuing;
    $error = $panel?->renew_error ?? $panel?->last_error;
@endphp
<div class="space-y-6">
    @if ($panelBusy)
        <div wire:poll.3s hidden></div>
    @endif

    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('ssl.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />SSL Sertifikaları</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">SSL ayarları</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">SSL ayarları</h1>
        <p class="mt-1 text-[13px] text-muted">Let's Encrypt hesabı, yeni siteler için otomatik sertifika ve panelin kendi adresi.</p>
    </div>

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="email" class="sm:pt-2">Let's Encrypt e-postası</label>
                    <div>
                        <input id="email" type="email" wire:model="email" placeholder="{{ $defaultEmail }}" spellcheck="false" autocomplete="off"
                               class="{{ $field }} sm:w-80 {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                        @error('email')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Hesap bu adresle açılır. Boş bırakılırsa yönetici hesabınızın adresi kullanılır.</p>
                        @enderror
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>Yeni siteler</span>
                    <div>
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" wire:model="auto" class="size-4 accent-accent">
                            Yeni sitelere otomatik Let's Encrypt sertifikası al
                        </label>
                        <p class="mt-1 ml-[26px] text-2xs text-muted">Alan adı ve ek adlar bu sunucuyu gösteriyorsa sertifika site oluşturulunca alınır, göstermiyorsa her saat yeniden denenir. Sertifikalar bitişten {{ config('servik.ssl.renew_days') }} gün önce yenilenir.</p>
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="hostname" class="sm:pt-2">Panel adresi</label>
                    <div>
                        <input id="hostname" wire:model="hostname" placeholder="panel.ornek.com.tr" spellcheck="false" autocomplete="off"
                               class="{{ $field }} sm:w-80 {{ $errors->has('hostname') ? 'border-crit' : 'border-line' }}">
                        @error('hostname')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Paneli bu adla açarsınız. Panelin sertifikası müşteri sitelerinden ayrı tutulur ve panelin kendi web sunucusunda kullanılır.</p>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">Kaydet</button>
            </div>
        </form>
    </section>

    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Panel sertifikası</h2>
        @if ($savedHostname === '')
            <p class="px-5 py-6 text-[13.5px] text-muted">Panel için sertifika almak üzere önce yukarıda panel adresini kaydedin.</p>
        @else
            <dl class="divide-y divide-line px-5 text-[13.5px]">
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Durum</dt>
                    <dd>
                        @if ($panel)
                            <x-status-badge :status="$panel->state()" />
                        @else
                            <x-status-badge :status="new \App\Support\CertificateState('Sertifika yok', 'muted')" />
                        @endif
                    </dd>
                </div>
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Adres</dt>
                    <dd class="font-mono text-[13px]">
                        {{ $savedHostname }}
                        @if ($panel?->isInstalled() && ! in_array($savedHostname, $panel->domains, true))
                            <span class="block font-sans text-2xs text-muted">Kurulu sertifika eski adres <span class="font-mono">{{ implode(', ', $panel->domains) }}</span> için; yeni adres için sertifika alın.</span>
                        @endif
                    </dd>
                </div>
                @if ($panel?->isInstalled())
                    <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <dt class="text-muted">Geçerlilik</dt>
                        <dd class="num">{{ $date($panel->not_before) }} – {{ $date($panel->not_after) }}
                            <span class="text-muted">· {{ $panel->isExpired() ? 'süresi doldu' : $panel->daysLeft().' gün kaldı' }}</span></dd>
                    </div>
                @endif
                @if ($error && ! $panelBusy)
                    <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <dt class="text-muted">{{ $panel->renew_error ? 'Son yenileme' : 'Hata' }}</dt>
                        <dd class="break-words">{{ $error }}</dd>
                    </div>
                @endif
            </dl>
            <div class="border-t border-line px-5 py-3">
                <button type="button" wire:click="issuePanel" @disabled($panelBusy)
                        class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                    <x-icon :name="$panelBusy ? 'loader-circle' : 'refresh-cw'" @class(['animate-spin' => $panelBusy]) />
                    {{ $panel?->isInstalled() ? 'Şimdi yenile' : "Let's Encrypt sertifikası al" }}
                </button>
            </div>
        @endif
    </section>
</div>
