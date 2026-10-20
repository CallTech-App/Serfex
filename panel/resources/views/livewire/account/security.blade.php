@php
    use App\Support\Badge;
    $tz = config('servik.timezone');
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Hesap güvenliği') }}</h1>
        <p class="mt-1 text-[13px] text-muted"><x-t text=":email hesabının girişi ve açık oturumları."><x-slot:email><span class="font-mono">{{ $user->email }}</span></x-slot:email></x-t></p>
    </div>

    @if ($required && ! $user->hasTwoFactor())
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p>{{ __('Sunucu yöneticisi iki adımlı doğrulamayı zorunlu tuttu. Panele devam etmek için aşağıdan kurun.') }}</p>
        </div>
    @endif

    <section class="rounded-xl border border-line bg-surface">
        <div class="max-w-4xl divide-y divide-line px-5 text-[13.5px]">
            <div class="{{ $row }}">
                <label for="locale" class="sm:pt-2">{{ __('Dil') }}</label>
                <form wire:submit="saveLocale">
                    <div class="flex flex-wrap items-center gap-2">
                        <select id="locale" wire:model="locale" class="{{ $field }} border-line px-2.5">
                            <option value="">{{ __('Sunucunun dili (:name)', ['name' => \App\Support\Locales::all()[\App\Support\Locales::serverDefault()]]) }}</option>
                            @foreach (\App\Support\Locales::all() as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="{{ $btn }}">{{ __('Kaydet') }}</button>
                    </div>
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Panel ve size gönderilen e-postalar bu dilde olur.') }}</p>
                </form>
            </div>
            <div class="{{ $row }}">
                <span>{{ __('İki adımlı doğrulama') }}</span>
                <div>
                    @if ($user->hasTwoFactor())
                        <p class="flex flex-wrap items-center gap-2">
                            <x-status-badge :status="new Badge(__('Açık'), 'ok')" />
                            <span class="text-muted">{{ __(':two_factor_confirmed_at tarihinden beri. Girişte parolanın ardından uygulamadaki kod sorulur.', ['two_factor_confirmed_at' => \App\Support\When::date($user->two_factor_confirmed_at)]) }}</span>
                        </p>
                        <p class="mt-2 text-muted">{{ __(':codes_left kurtarma kodu kaldı.', ['codes_left' => $codesLeft]) }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="regenerate" wire:confirm="{{ __('Eski kurtarma kodları çalışmayacak. Yeni kodlar oluşturulsun mu?') }}" class="{{ $btn }}"><x-icon name="key-round" />{{ __('Yeni kurtarma kodları') }}</button>
                            <button type="button" wire:click="openDisable" class="{{ $btn }}">{{ __('Kapat') }}</button>
                        </div>
                    @else
                        <p><x-status-badge :status="new Badge(__('Kapalı'), 'muted')" /></p>
                        <p class="mt-2 text-muted">{{ __('Parolanız ele geçse bile hesabınıza girilemesin: Google Authenticator, Microsoft Authenticator, 1Password ya da Bitwarden gibi bir uygulamanın ürettiği kod da sorulur.') }}</p>
                        <button type="button" wire:click="startSetup" class="{{ $primary }} mt-3"><x-icon name="shield-check" />{{ __('Kur') }}</button>
                    @endif

                    @if ($recoveryCodes)
                        <div class="mt-4 rounded-lg border border-line bg-surface-2 p-4">
                            <p class="font-medium">{{ __('Kurtarma kodlarınız') }}</p>
                            <p class="mt-1 text-[13px] text-muted">{{ __('Telefonunuzu kaybederseniz bu kodlardan biriyle girebilirsiniz; her biri bir kez kullanılır. Bir yere kaydedin, bir daha gösterilmeyecek.') }}</p>
                            <div class="mt-3 grid w-fit grid-cols-2 gap-x-6 gap-y-1 font-mono text-[14px]" x-ref="codes">
                                @foreach ($recoveryCodes as $c)
                                    <span>{{ $c }}</span>
                                @endforeach
                            </div>
                            <div class="mt-3 flex gap-2">
                                <button type="button" x-data @click="navigator.clipboard.writeText(@js(implode("\n", $recoveryCodes))); $dispatch('toast', { message: @js(__('Kodlar kopyalandı.')) })" class="{{ $btn }}"><x-icon name="copy" />{{ __('Kopyala') }}</button>
                                <button type="button" wire:click="$set('recoveryCodes', null)" class="{{ $btn }}">{{ __('Kaydettim') }}</button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="{{ $row }}">
                <span>{{ __('Açık oturumlar') }}</span>
                <div>
                    @if (! $sessionsAvailable)
                        <p class="text-muted">{{ __('Oturumlar bu kurulumda listelenemiyor.') }}</p>
                    @else
                        <ul class="divide-y divide-line border-y border-line">
                            @foreach ($sessions as $s)
                                <li wire:key="s-{{ $s['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-0.5 py-2.5">
                                    <span class="font-medium">{{ $s['agent'] }}</span>
                                    <span class="font-mono text-[13px] text-muted">{{ $s['ip'] }}</span>
                                    @if ($s['current'])
                                        <x-status-badge :status="new Badge(__('Bu tarayıcı'), 'info')" />
                                    @else
                                        <span class="num text-2xs text-muted">{{ $s['last']->timezone($tz)->diffForHumans() }}</span>
                                        <button type="button" wire:click="revoke(@js($s['id']))" class="ml-auto text-[13px] text-muted hover:text-fg">{{ __('Oturumu kapat') }}</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if (count($sessions) > 1)
                            <button type="button" wire:click="revokeOthers" class="{{ $btn }} mt-3"><x-icon name="log-out" />{{ __('Diğer bütün oturumları kapat') }}</button>
                        @endif
                        <p class="mt-2 text-2xs text-muted">{{ __('Tanımadığınız bir oturum görürseniz kapatın ve parolanızı değiştirin.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- Kurulum --}}
    <x-modal title="{{ __('İki adımlı doğrulamayı kur') }}" show="settingUp">
        <form wire:submit="confirmSetup">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('İki adımlı doğrulamayı kur') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <p>{{ __('1. Doğrulama uygulamasıyla bu kodu tarayın.') }}</p>
                @if ($qr)
                    <div class="mx-auto w-fit rounded-lg bg-white p-3 [&_svg]:block">{!! $qr !!}</div>
                    <p class="text-center text-2xs text-muted">{{ __('Tarayamıyorsanız bu anahtarı elle girin:') }}<br><span class="font-mono text-[13px] tracking-wider text-fg select-all">{{ trim(chunk_split((string) $secret, 4, ' ')) }}</span></p>
                @endif
                <div>
                    <label for="tf-code" class="mb-1.5 block">{{ __('2. Uygulamanın gösterdiği 6 haneli kodu yazın.') }}</label>
                    <input id="tf-code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" class="{{ $field }} w-40 font-mono tracking-widest {{ $errors->has('code') ? 'border-crit' : 'border-line' }}">
                    @error('code') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('settingUp', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="submit" class="{{ $primary }}">{{ __('Doğrula ve aç') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Kapat --}}
    <x-modal title="{{ __('İki adımlı doğrulamayı kapat') }}" show="disabling">
        <form wire:submit="disable">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('İki adımlı doğrulamayı kapat') }}</h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                <p>{{ __('Hesabınıza yalnızca parolayla girilebilecek. Onaylamak için parolanızı yazın.') }}</p>
                <input type="password" wire:model="password" autocomplete="current-password" aria-label="{{ __('Parola') }}" class="{{ $field }} w-full {{ $errors->has('password') ? 'border-crit' : 'border-line' }}">
                @error('password') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('disabling', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="submit" class="flex h-9 items-center rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110">{{ __('Kapat') }}</button>
            </div>
        </form>
    </x-modal>
</div>
