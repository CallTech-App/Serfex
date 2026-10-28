@php
    use App\Services\Files\SshAccess;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $admin = auth()->user()->is_admin;
    $modes = [
        SshAccess::OFF => [__('Kapalı'), __('Bu kullanıcıyla SSH ya da SFTP ile bağlanılamaz. FTP hesapları bundan etkilenmez.')],
        SshAccess::SFTP => [__('Yalnızca SFTP'), __(':home klasörüne kilitli dosya erişimi; komut çalıştırılamaz.', ['home' => '/home/'.$account->name])],
        SshAccess::SHELL => [__('SFTP ve kabuk'), __('Sunucuda bu kullanıcıyla komut satırı (bash). Composer, Git ve Artisan çalıştırılabilir; veritabanına SSH tüneli açılabilir. Yalnızca yönetici açar.')],
    ];
    $on = $account->ssh_access !== SshAccess::OFF;
    $copy = fn (string $value) => '<button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText('.e(json_encode($value)).'); done = true; setTimeout(() => done = false, 1500)" class="shrink-0 text-[12.5px] text-accent hover:underline"><span x-show="!done">'.e(__('Kopyala')).'</span><span x-show="done" x-cloak>'.e(__('Kopyalandı')).'</span></button>';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('SSH erişimi') }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h1 class="text-[22px] font-semibold tracking-tight">{{ __('SSH erişimi') }}</h1>
            <a href="{{ route('sites.ftp', $site) }}" wire:navigate class="{{ $btn }}"><x-icon name="hard-drive-upload" />{{ __('FTP hesapları') }}</a>
        </div>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">
            <x-t text="Sitenin sistem kullanıcısı :user ile SSH anahtarı ya da parolayla bağlanılır. Yüklenen dosyalar sitenin kendi dosyaları gibi olur. Bir klasörü başkasına açmak için FTP hesabı daha uygundur."><x-slot:user><span class="font-mono text-fg">{{ $account->name }}</span></x-slot:user></x-t>
        </p>
    </div>

    @if ($suspended)
        <p class="flex gap-2 rounded-lg bg-warn-soft px-4 py-3 text-[13px] text-warn"><x-icon name="lock" class="mt-0.5 shrink-0" />{{ __('Müşteri askıda: ayarlar duruyor ama askı kalkana kadar kimse bağlanamaz.') }}</p>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="divide-y divide-line px-5 text-[13.5px]">
            {{-- Erişim --}}
            <form wire:submit="saveAccess" class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                <div>
                    <p class="font-medium">{{ __('Erişim') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">
                        @if ($otherSites->isNotEmpty())
                            {{ __('Kullanıcının diğer sitelerine de açılır: :sites', ['sites' => $otherSites->join(', ')]) }}
                        @else
                            {{ __('Yalnızca bu sitenin kullanıcısı') }}
                        @endif
                    </p>
                </div>
                <div class="space-y-2.5">
                    @foreach ($modes as $mode => [$label, $help])
                        @php $locked = $mode === SshAccess::SHELL && ! $admin && $account->ssh_access !== SshAccess::SHELL; @endphp
                        <label @class(['flex items-start gap-2.5', 'opacity-50' => $locked])>
                            <input type="radio" wire:model="access" value="{{ $mode }}" @disabled($locked) class="mt-0.5 size-4 accent-accent">
                            <span>{{ $label }}
                                @if ($mode === SshAccess::SHELL && ! $admin)
                                    <span class="ml-1 rounded bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ __('Yönetici açar') }}</span>
                                @endif
                                <span class="block text-[12.5px] text-muted">{{ $help }}</span>
                            </span>
                        </label>
                    @endforeach
                    <div class="pt-1">
                        <button type="submit" wire:loading.attr="disabled" class="{{ $btn }}">
                            <span wire:loading.remove wire:target="saveAccess">{{ __('Kaydet') }}</span>
                            <span wire:loading wire:target="saveAccess">{{ __('Uygulanıyor…') }}</span>
                        </button>
                    </div>
                </div>
            </form>

            {{-- Bağlantı --}}
            @if ($on)
                <div class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <div>
                        <p class="font-medium">{{ __('Bağlantı bilgileri') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('FileZilla, WinSCP, Cyberduck ya da terminal') }}</p>
                    </div>
                    <dl class="divide-y divide-line rounded-lg border border-line">
                        @foreach ([__('Sunucu') => $connection['host'], __('Port') => (string) $connection['port'], __('Kullanıcı') => $connection['user'], 'SFTP' => $connection['sftp']] + ($connection['ssh'] ? ['SSH' => $connection['ssh']] : []) as $label => $value)
                            <div class="flex items-center gap-3 px-3 py-2">
                                <dt class="w-20 shrink-0 text-muted">{{ $label }}</dt>
                                <dd class="min-w-0 flex-1 truncate font-mono text-[13px]" title="{{ $value }}">{{ $value }}</dd>
                                {!! $copy($value) !!}
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

            {{-- SSH anahtarları --}}
            <div class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                <div>
                    <p class="font-medium">{{ __('SSH anahtarları') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ __('Önerilen giriş yolu. Açık anahtarı (.pub) ekleyin.') }}</p>
                </div>
                <div class="min-w-0">
                    @if ($keys->isEmpty())
                        <p class="text-muted">{{ __('Henüz anahtar yok.') }}</p>
                    @else
                        <ul class="divide-y divide-line rounded-lg border border-line">
                            @foreach ($keys as $key)
                                <li wire:key="key-{{ $key->id }}" class="flex items-center gap-3 px-3 py-2.5">
                                    <x-icon name="key-round" class="shrink-0 text-muted" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-medium">{{ $key->name }} <span class="ml-1 rounded bg-surface-2 px-1.5 py-0.5 text-2xs font-normal text-muted">{{ $key->typeLabel() }}</span></p>
                                        <p class="truncate font-mono text-[12px] text-muted" title="{{ $key->fingerprint }}">{{ $key->fingerprint }}</p>
                                    </div>
                                    <span class="num hidden shrink-0 text-2xs text-muted sm:block">{{ \App\Support\When::date($key->created_at) }}</span>
                                    <button type="button" wire:click="confirmDelete({{ $key->id }})" class="shrink-0 text-[13px] text-crit hover:underline">{{ __('Sil') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <button type="button" wire:click="openAdd" class="{{ $btn }} mt-3"><x-icon name="plus" />{{ __('Anahtar ekle') }}</button>
                </div>
            </div>

            {{-- Parola --}}
            <div class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                <div>
                    <p class="font-medium">{{ __('Parolayla giriş') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ __('İsteğe bağlı. Serfex parolayı saklamaz.') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($account->ssh_password_at)
                        <x-status-badge :status="new \App\Support\Badge(__('Açık'), 'ok')" />
                        <span class="num text-[13px] text-muted">{{ __('Parola :date tarihinde belirlendi', ['date' => \App\Support\When::dateTime($account->ssh_password_at)]) }}</span>
                    @else
                        <x-status-badge :status="new \App\Support\Badge(__('Kapalı'), 'muted')" />
                    @endif
                    <div class="flex w-full flex-wrap gap-2">
                        <button type="button" wire:click="newPassword" wire:loading.attr="disabled" class="{{ $btn }}"><x-icon name="refresh-cw" class="size-3.5" />{{ $account->ssh_password_at ? __('Yeni parola belirle') : __('Parola belirle') }}</button>
                        @if ($account->ssh_password_at)
                            <button type="button" wire:click="$set('removingPassword', true)" class="h-9 px-1 text-[13px] text-crit hover:underline">{{ __('Parolayla girişi kapat') }}</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">
            <x-t text="Art arda hatalı girişler Fail2ban ile engellenir. Tarayıcıdan terminal (SSH terminali) sonraki sürümde. Port :port, sunucu :host."><x-slot:port><span class="num font-mono">{{ $connection['port'] }}</span></x-slot:port><x-slot:host><span class="font-mono">{{ $connection['host'] }}</span></x-slot:host></x-t>
        </p>
    </section>

    {{-- Anahtar ekle --}}
    <x-modal :title="__('Anahtar ekle')" show="adding">
        <form wire:submit="addKey">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Anahtar ekle') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="grid items-start gap-x-4 gap-y-1.5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                    <label for="key-name" class="sm:pt-2">{{ __('Ad') }}</label>
                    <div>
                        <input id="key-name" wire:model="keyName" maxlength="100" placeholder="{{ __('isteğe bağlı, örneğin Dizüstü') }}" class="h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent {{ $errors->has('keyName') ? 'border-crit' : 'border-line' }}">
                        @error('keyName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <label for="key-value" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Açık anahtar') }}</label>
                    <div>
                        <textarea id="key-value" wire:model="publicKey" rows="5" required spellcheck="false" placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA… sercan@laptop" class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[12.5px] break-all outline-none focus:border-accent {{ $errors->has('publicKey') ? 'border-crit' : 'border-line' }}"></textarea>
                        @error('publicKey')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted"><x-t text="Bilgisayarınızda :file dosyasının içeriği. Yoksa :command ile oluşturun."><x-slot:file><span class="font-mono">~/.ssh/id_ed25519.pub</span></x-slot:file><x-slot:command><span class="font-mono">ssh-keygen -t ed25519</span></x-slot:command></x-t></p>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('adding', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="{{ $primary }}">
                    <span wire:loading.remove wire:target="addKey">{{ __('Anahtarı ekle') }}</span>
                    <span wire:loading wire:target="addKey">{{ __('Uygulanıyor…') }}</span>
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Anahtar silme onayı --}}
    @php $deleting = $deletingId ? $keys->firstWhere('id', $deletingId) : null; @endphp
    <div x-data x-show="$wire.deletingId !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.deletingId = null">
        <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Anahtarı sil') }}">
            <p class="font-semibold">{{ __(':name anahtarı silinsin mi?', ['name' => $deleting?->name]) }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Bu anahtarla hemen bağlanılamaz olur; açık oturumlar sürer. 10 dakika içinde geri alabilirsiniz.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deletingId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="deleteKey" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Anahtarı sil') }}</button>
            </div>
        </div>
    </div>

    {{-- Parolayla girişi kapatma onayı --}}
    <x-modal :title="__('Parolayla girişi kapat')" show="removingPassword" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ __('Parolayla giriş kapatılsın mı?') }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Bundan sonra yalnızca SSH anahtarlarıyla bağlanılır. Geri alırsanız aynı parola yeniden çalışır.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('removingPassword', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="removePassword" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Kapat') }}</button>
            </div>
        </div>
    </x-modal>

    {{-- Parola bir kez gösterilir --}}
    @if ($credentials)
        <div x-data class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.set('credentials', null)">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Bağlantı bilgileri') }}">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Bağlantı bilgileri') }}</h2>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p class="flex gap-2 rounded-lg bg-warn-soft p-3 text-[13px]">
                        <x-icon name="key-round" class="mt-0.5 shrink-0 text-warn" />
                        <span>{{ __('Parolayı şimdi kopyalayın. Serfex parolaları saklamaz; bu pencere kapanınca bir daha gösterilmez, gerekirse yenisini belirlersiniz.') }}</span>
                    </p>
                    <dl class="divide-y divide-line rounded-lg border border-line">
                        @foreach ($credentials as $label => $value)
                            <div class="flex items-center gap-3 px-3 py-2">
                                <dt class="w-24 shrink-0 text-muted">{{ $label }}</dt>
                                <dd class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $value }}</dd>
                                {!! $copy($value) !!}
                            </div>
                        @endforeach
                    </dl>
                    @if (! $on)
                        <p class="text-2xs text-muted">{{ __('Erişim kapalıyken parola çalışmaz; yukarıdan SFTP\'yi açın.') }}</p>
                    @endif
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('credentials', null)" class="{{ $primary }}">{{ __('Kopyaladım, kapat') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
