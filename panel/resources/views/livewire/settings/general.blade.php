@php
    use App\Enums\CertificateStatus;
    use App\Models\Certificate;
    $date = fn ($d) => $d?->timezone(config('servik.timezone'))->format('d.m.Y');
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $row = 'grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40';
    $panelBusy = $panel?->status === CertificateStatus::Issuing;
    $error = $panel?->renew_error ?? $panel?->last_error;
    $host = $savedHostname !== '' ? $savedHostname : request()->getHost();
@endphp
<x-settings-page current="settings.general" title="Genel" description="Panelin adresi, portu, saat dilimi ve oturum süresi; panelin kendi sertifikası.">
    @if ($panelBusy)
        <div wire:poll.3s hidden></div>
    @endif

    @if ($portChange)
        @php $onNew = $requestPort === (int) $portChange['to']; @endphp
        <div class="rounded-xl border border-line bg-warn-soft px-5 py-4 text-[13.5px]" role="alert">
            <p class="flex items-start gap-3">
                <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
                <span class="flex-1">
                    Panel şu an hem <span class="num font-mono">{{ $portChange['from'] }}</span> hem <span class="num font-mono">{{ $portChange['to'] }}</span> portunda açık.
                    @if ($onNew)
                        Bu sayfa yeni porttan açıldı; değişikliği onaylayabilirsiniz.
                    @else
                        Yeni adresi açın ve orada onaylayın: <a href="https://{{ $host }}:{{ $portChange['to'] }}{{ route('settings.general', absolute: false) }}" class="font-mono text-accent hover:underline">https://{{ $host }}:{{ $portChange['to'] }}</a>.
                    @endif
                    {{ \Illuminate\Support\Carbon::parse($portChange['until'])->timezone(config('servik.timezone'))->format('H:i') }}'e kadar onaylanmazsa panel {{ $portChange['from'] }} portunda kalır.
                </span>
            </p>
            <div class="mt-3 flex flex-wrap gap-2 pl-7">
                @if ($onNew)
                    <button type="button" wire:click="acceptPort" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">Yeni portu onayla</button>
                @endif
                <button type="button" wire:click="revertPort" class="{{ $btn }}">Vazgeç, {{ $portChange['from'] }} portunda kal</button>
            </div>
        </div>
    @endif

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="{{ $row }}">
                    <label for="hostname" class="sm:pt-2">Panel adresi</label>
                    <div>
                        <input id="hostname" wire:model="hostname" placeholder="panel.ornek.com.tr" spellcheck="false" autocomplete="off"
                               class="{{ $field }} font-mono sm:w-80 {{ $errors->has('hostname') ? 'border-crit' : 'border-line' }}">
                        @error('hostname')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Paneli bu adla açarsınız. Kaydedince Let's Encrypt sertifikası alınır; ad bu sunucuyu göstermiyorsa her saat yeniden denenir.</p>
                        @enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <span class="sm:pt-2">Panel portu</span>
                    <div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="num pt-0.5 font-mono text-[13px]">{{ $portNow }}</span>
                            <button type="button" wire:click="openPort" @disabled($portChange) class="text-[13px] text-accent hover:underline disabled:opacity-40 disabled:no-underline">Değiştir</button>
                        </div>
                        <p class="mt-1.5 text-2xs text-muted">Panel, phpMyAdmin ve Adminer bu portta açılır. Değiştirince eski port, siz yeni porttan onaylayana kadar açık kalır.</p>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="timezone" class="sm:pt-2">Saat dilimi</label>
                    <div>
                        <select id="timezone" wire:model="timezone" class="{{ $field }} sm:w-80 {{ $errors->has('timezone') ? 'border-crit' : 'border-line' }}">
                            @foreach ($timezones as $zone)
                                <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
                            @endforeach
                        </select>
                        @error('timezone')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Sunucunun ve panelin saati. Zamanlanmış görevler, yedekler ve güncelleme denetimi bu saate göre çalışır.</p>
                        @enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <span>Dil</span>
                    <div>
                        <span>Türkçe</span>
                        <p class="mt-1 text-2xs text-muted">İngilizce arayüz sonraki sürümlerde gelecek.</p>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="session" class="sm:pt-2">Oturum süresi</label>
                    <div>
                        <div class="flex items-center gap-2">
                            <input id="session" wire:model="sessionMinutes" inputmode="numeric" class="{{ $field }} num w-24 {{ $errors->has('sessionMinutes') ? 'border-crit' : 'border-line' }}">
                            <span class="text-[13px] text-muted">dakika işlem yapılmazsa oturum kapanır</span>
                        </div>
                        @error('sessionMinutes') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">Kaydet</button>
            </div>
        </form>
    </section>

    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Panel sertifikası</h2>
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
            @if ($panel?->isInstalled())
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">Sertifika</dt>
                    <dd>
                        <span class="font-mono text-[13px]">{{ implode(', ', $panel->domains) }}</span>
                        <span class="block text-2xs text-muted">{{ $panel->source === Certificate::CUSTOM ? 'Yüklenen sertifika' : "Let's Encrypt" }} · {{ $date($panel->not_before) }} – {{ $date($panel->not_after) }} · {{ $panel->isExpired() ? 'süresi doldu' : $panel->daysLeft().' gün kaldı' }}</span>
                        @if ($savedHostname !== '' && ! in_array($savedHostname, $panel->domains, true))
                            <span class="block text-2xs text-warn">Kurulu sertifika panel adresi <span class="font-mono">{{ $savedHostname }}</span> için değil.</span>
                        @endif
                    </dd>
                </div>
            @endif
            @if ($error && ! $panelBusy)
                <div class="grid gap-1 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <dt class="text-muted">{{ $panel->renew_error ? 'Son yenileme' : 'Hata' }}</dt>
                    <dd class="break-words">{{ $error }}</dd>
                </div>
            @endif
        </dl>
        <div class="flex flex-wrap gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="issuePanel" @disabled($panelBusy || $savedHostname === '') title="{{ $savedHostname === '' ? 'Önce panel adresini kaydedin' : '' }}" class="{{ $btn }}">
                <x-icon :name="$panelBusy ? 'loader-circle' : 'refresh-cw'" @class(['animate-spin' => $panelBusy]) />
                {{ $panel?->isInstalled() && $panel->isLetsEncrypt() ? 'Şimdi yenile' : "Let's Encrypt sertifikası al" }}
            </button>
            <button type="button" wire:click="openUpload" @disabled($panelBusy) class="{{ $btn }}"><x-icon name="upload" />Kendi sertifikanı yükle</button>
        </div>
    </section>

    {{-- Port değiştir --}}
    <x-modal title="Panel portunu değiştir" show="changingPort">
        <form wire:submit="savePort">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Panel portunu değiştir</h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                <div class="grid items-start gap-x-4 gap-y-1.5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                    <label for="new-port" class="sm:pt-2">Yeni port</label>
                    <div>
                        <input id="new-port" wire:model="port" inputmode="numeric" placeholder="9443" class="{{ $field }} num w-32 font-mono {{ $errors->has('port') ? 'border-crit' : 'border-line' }}">
                        @error('port') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-[13px] text-muted">Panel yeni portta da açılır; {{ $portNow }} portu da {{ \App\Services\Settings\PanelSettings::CONFIRM_MINUTES }} dakika açık kalır. Yeni adresten açıp onaylayınca eski port kapanır. Onaylamazsanız panel {{ $portNow }} portunda kalır. Güvenlik duvarı açıksa yeni port kendiliğinden açılır; sunucunuzun önünde başka bir güvenlik duvarı varsa orada da açın.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('changingPort', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">
                    <span wire:loading.remove wire:target="savePort">Yeni portu aç</span>
                    <span wire:loading wire:target="savePort">Uygulanıyor…</span>
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Sertifika yükle --}}
    <x-modal title="Panel sertifikası yükle" show="uploading" size="max-w-2xl">
        <form wire:submit="uploadCertificate">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Panel sertifikası yükle</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                @foreach (['certificate' => ['Sertifika', '-----BEGIN CERTIFICATE-----'], 'privateKey' => ['Özel anahtar', '-----BEGIN PRIVATE KEY-----'], 'chain' => ['Ara sertifikalar', 'isteğe bağlı']] as $name => [$label, $placeholder])
                    <div>
                        <label for="up-{{ $name }}" class="mb-1.5 block">{{ $label }}</label>
                        <textarea id="up-{{ $name }}" wire:model="{{ $name }}" rows="4" placeholder="{{ $placeholder }}" spellcheck="false"
                                  class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[12px] outline-none focus:border-accent {{ $errors->has($name) ? 'border-crit' : 'border-line' }}"></textarea>
                        @error($name) <p class="mt-1 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <p class="text-2xs text-muted">Yüklenen sertifika kendiliğinden yenilenmez; süresi dolmadan yenisini yükleyin ya da Let's Encrypt'e dönün. Özel anahtar yalnızca sunucuda saklanır.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('uploading', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">
                    <span wire:loading.remove wire:target="uploadCertificate">Sertifikayı kur</span>
                    <span wire:loading wire:target="uploadCertificate">Kuruluyor…</span>
                </button>
            </div>
        </form>
    </x-modal>
</x-settings-page>
