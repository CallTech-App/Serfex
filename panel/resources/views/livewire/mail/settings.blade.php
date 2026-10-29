@php
    use App\Enums\CertificateStatus;
    $date = fn ($d) => $d ? \App\Support\When::date($d) : null;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $certBusy = $certificate?->status === CertificateStatus::Issuing;
    $certError = $certificate?->renew_error ?? $certificate?->last_error;
    $selfSigned = ($setup['setup_at'] ?? null) !== null && ($setup['certificate'] ?? '') === '';
@endphp
<div class="space-y-6">
    @if ($certBusy)
        <div wire:poll.3s hidden></div>
    @endif

    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('mail.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('E-posta') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('E-posta ayarları') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('E-posta ayarları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Posta sunucusunun adı, yeni posta kutularının varsayılanları, gönderim sınırı ve web posta.') }}</p>
    </div>

    @include('livewire.mail.server-state')

    @if ($reverseDns)
        <p class="flex items-start gap-2 rounded-lg bg-warn-soft p-3 text-[13px]" role="alert"><x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />{{ $reverseDns }}</p>
    @endif

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="hostname" class="sm:pt-2">{{ __('Posta sunucusunun adı') }}</label>
                    <div>
                        <input id="hostname" wire:model="hostname" placeholder="{{ __('mail.ornek.com.tr') }}" spellcheck="false" autocomplete="off"
                               class="{{ $field }} font-mono sm:w-80 {{ $errors->has('hostname') ? 'border-crit' : 'border-line' }}">
                        @error('hostname')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted"><x-t text="Posta istemcileri bu adla bağlanır, web posta :hostname adresinde açılır. Adın A kaydı bu sunucuyu göstermeli; sertifikası otomatik alınır."><x-slot:hostname><span class="font-mono">https://{{ $hostname ?: 'bu-ad' }}/</span></x-slot:hostname></x-t></p>
                        @enderror
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="defaultQuota" class="sm:pt-2">{{ __('Yeni posta kutusu kotası') }}</label>
                    <select id="defaultQuota" wire:model.number="defaultQuota" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-48">
                        @foreach ($quotas as $mb => $label)
                            <option value="{{ $mb }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="hourlyLimit" class="sm:pt-2">{{ __('Saatlik gönderim sınırı') }}</label>
                    <div>
                        <div class="flex items-center gap-2">
                            <input id="hourlyLimit" wire:model.number="hourlyLimit" inputmode="numeric"
                                   class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('hourlyLimit') ? 'border-crit' : 'border-line' }}">
                            <span class="text-[13px] text-muted">{{ __('ileti, posta kutusu başına') }}</span>
                        </div>
                        @error('hourlyLimit')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Parolası çalınan bir kutunun sunucuyu spam kaynağına çevirmesini önler. 0 sınırsız demektir.') }}</p>
                        @enderror
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span class="sm:pt-0.5">{{ __('Web posta') }}</span>
                    <div>
                        <div class="space-y-2">
                            @foreach (['roundcube' => ['Roundcube', __('Yaygın ve eklentili; önerilen.')], 'snappymail' => ['SnappyMail', __('Hafif, hızlı ve sade görünümlü.')], 'off' => [__('Kapalı'), __('Postalara yalnızca posta programlarıyla ulaşılır.')]] as $key => [$label, $note])
                                <label class="flex items-start gap-2.5">
                                    <input type="radio" wire:model="webmail" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                                    <span>{{ $label }} <span class="text-muted">· {{ $note }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-2 text-2xs text-muted">{{ __('Webmail adresi aynı kalır ve panelden tek tıkla posta kutusuna girilir. Uygulama değişince kişiler ve ayarlar taşınmaz; öncekinin verileri sunucuda kalır. Web posta, sitelerin web sunucusunda kendi PHP havuzuyla çalışır; panelden ayrıdır.') }}</p>
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('Yeni siteler') }}</span>
                    <div>
                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="auto" class="size-4 accent-accent">{{ __('Yeni sitelerde e-postayı otomatik aç') }}</label>
                        <p class="mt-1 ml-[26px] text-2xs text-muted">{{ __('Site oluşturulunca alan adı için e-posta açılır ve DNS kayıtları yazılır; posta kutularını siz oluşturursunuz.') }}</p>
                    </div>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </section>

    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Posta sunucusu sertifikası') }}</h2>
        @if ($savedHostname === null)
            <p class="px-5 py-6 text-[13.5px] text-muted">{{ __('Sertifika almak için önce posta sunucusunun adını kaydedin.') }}</p>
        @else
            <dl class="divide-y divide-line px-5 text-[13.5px]">
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">{{ __('Durum') }}</dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        @if ($certificate)
                            <x-status-badge :status="$certificate->state()" />
                        @else
                            <x-status-badge :status="new \App\Support\Badge(__('Sertifika yok'), 'muted')" />
                        @endif
                        @if ($selfSigned)
                            <span class="text-2xs text-muted">{{ __('Şimdilik kendinden imzalı sertifika kullanılıyor; istemciler uyarı gösterebilir.') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">{{ __('Ad') }}</dt>
                    <dd class="font-mono text-[13px]">{{ $savedHostname }}</dd>
                </div>
                @if ($certificate?->isInstalled())
                    <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <dt class="text-muted">{{ __('Geçerlilik') }}</dt>
                        <dd class="num">{{ $date($certificate->not_before) }} – {{ $date($certificate->not_after) }}
                            <span class="text-muted">· {{ $certificate->isExpired() ? __('süresi doldu') : __(':days_left gün kaldı', ['days_left' => $certificate->daysLeft()]) }}</span></dd>
                    </div>
                @endif
                @if ($certError && ! $certBusy)
                    <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <dt class="text-muted">{{ $certificate->renew_error ? __('Son yenileme') : __('Hata') }}</dt>
                        <dd class="break-words">{{ $certError }}</dd>
                    </div>
                @endif
            </dl>
            <div class="border-t border-line px-5 py-3">
                <button type="button" wire:click="issueCertificate" @disabled($certBusy || ($setup['setup_at'] ?? null) === null)
                        class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                    <x-icon :name="$certBusy ? 'loader-circle' : 'refresh-cw'" @class(['animate-spin' => $certBusy]) />
                    {{ $certificate?->isInstalled() ? __('Şimdi yenile') : __('Let\'s Encrypt sertifikası al') }}
                </button>
                @if (($setup['setup_at'] ?? null) === null)
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Sertifika posta sunucusu kurulduktan sonra otomatik alınır.') }}</p>
                @endif
            </div>
        @endif
    </section>
</div>
