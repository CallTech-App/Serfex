@php
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Hesap bilgileri') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Panelde ve size gönderilen e-postalarda görünen bilgileriniz.') }}</p>
    </div>

    <x-account-nav current="account.profile" />

    <section class="rounded-xl border border-line bg-surface">
        <div class="max-w-4xl divide-y divide-line px-5 text-[13.5px]">
            <div class="{{ $row }}">
                <label for="name" class="sm:pt-2">{{ __('Ad soyad') }}</label>
                <form wire:submit="saveName">
                    <div class="flex items-center gap-2">
                        <input id="name" wire:model="name" autocomplete="name" maxlength="100" class="{{ $field }} min-w-0 max-w-sm flex-1 {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                        <button type="submit" class="{{ $btn }}">{{ __('Kaydet') }}</button>
                    </div>
                    @error('name') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </form>
            </div>

            <div class="{{ $row }}">
                <span class="sm:pt-2">{{ __('E-posta') }}</span>
                <div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="font-mono text-[14px] break-all">{{ $user->email }}</span>
                        <button type="button" wire:click="openEmail" class="{{ $btn }}"><x-icon name="pencil" />{{ __('Değiştir') }}</button>
                    </div>
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Bu adresle giriş yaparsınız; parola sıfırlama bağlantıları ve panelin bildirimleri bu adrese gelir.') }}</p>

                    @if ($user->pending_email)
                        <div class="mt-3 rounded-lg border border-line {{ $pendingExpired ? 'bg-warn-soft' : 'bg-info-soft' }} px-4 py-3" role="status">
                            <p class="flex items-start gap-2">
                                <x-icon :name="$pendingExpired ? 'triangle-alert' : 'mail'" class="mt-0.5 shrink-0 {{ $pendingExpired ? 'text-warn' : 'text-info' }}" />
                                <span>
                                    @if ($pendingExpired)
                                        <x-t text=":email adresine gönderilen doğrulama bağlantısının süresi doldu. Adres değişmedi."><x-slot:email><span class="font-mono">{{ $user->pending_email }}</span></x-slot:email></x-t>
                                    @else
                                        <x-t text=":email adresine doğrulama bağlantısı gönderildi. Bağlantı açılana kadar şimdiki adresiniz geçerli."><x-slot:email><span class="font-mono">{{ $user->pending_email }}</span></x-slot:email></x-t>
                                    @endif
                                </span>
                            </p>
                            <div class="mt-2.5 flex flex-wrap gap-2">
                                <button type="button" wire:click="resendEmail" class="{{ $btn }}"><x-icon name="send" />{{ __('Yeniden gönder') }}</button>
                                <button type="button" wire:click="cancelEmail" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @if ($customer)
                <div class="{{ $row }}">
                    <label for="phone" class="sm:pt-2">{{ __('Telefon') }}</label>
                    <form wire:submit="savePhone">
                        <div class="flex items-center gap-2">
                            <input id="phone" wire:model="phone" type="tel" autocomplete="tel" maxlength="40" class="{{ $field }} min-w-0 max-w-60 flex-1 font-mono {{ $errors->has('phone') ? 'border-crit' : 'border-line' }}">
                            <button type="submit" class="{{ $btn }}">{{ __('Kaydet') }}</button>
                        </div>
                        @error('phone') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @else <p class="mt-1.5 text-2xs text-muted">{{ __('Barındırma sağlayıcınız size ulaşmak için kullanır.') }}</p> @enderror
                    </form>
                </div>
            @endif

            <div class="{{ $row }}">
                <label for="locale" class="sm:pt-2">{{ __('Dil') }}</label>
                <form wire:submit="saveLocale">
                    <div class="flex flex-wrap items-center gap-2">
                        <select id="locale" wire:model="locale" class="{{ $field }} border-line px-2.5">
                            <option value="">{{ __('Sunucunun dili (:name)', ['name' => \App\Support\Locales::all()[\App\Support\Locales::serverDefault()]]) }}</option>
                            @foreach (\App\Support\Locales::all() as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="{{ $btn }}">{{ __('Kaydet') }}</button>
                    </div>
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Panel ve size gönderilen e-postalar bu dilde olur.') }}</p>
                </form>
            </div>

            <div class="{{ $row }}">
                <span>{{ __('Hesap türü') }}</span>
                <p>
                    {{ $user->roleName() }}@if ($customer) · <span class="text-muted">{{ $customer->displayName() }}</span>@endif
                </p>
            </div>
        </div>
    </section>

    <x-modal :title="__('E-posta adresini değiştir')" show="changingEmail">
        <form wire:submit="changeEmail">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('E-posta adresini değiştir') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                @if ($impersonating)
                    <p class="rounded-md bg-warn-soft px-3 py-2">{{ __('Müşteri olarak çalışırken e-posta değiştirilemez. Müşterinin adresini Müşteriler sayfasından değiştirin.') }}</p>
                @endif
                <div>
                    <label for="new-email" class="mb-1.5 block">{{ __('Yeni e-posta') }}</label>
                    <input id="new-email" type="email" wire:model="newEmail" autocomplete="email" maxlength="190" class="{{ $field }} w-full font-mono {{ $errors->has('newEmail') ? 'border-crit' : 'border-line' }}">
                    @error('newEmail') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email-password" class="mb-1.5 block">{{ __('Şimdiki parolanız') }}</label>
                    <input id="email-password" type="password" wire:model="password" autocomplete="current-password" class="{{ $field }} w-full {{ $errors->has('password') ? 'border-crit' : 'border-line' }}">
                    @error('password') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                @if ($mailWorks)
                    <p class="text-2xs text-muted">{{ __('Yeni adrese bir doğrulama bağlantısı gönderilir; bağlantıyı açana kadar şimdiki adresiniz geçerli kalır. Bağlantı :hours saat geçerlidir.', ['hours' => \App\Services\Security\EmailChange::HOURS]) }}</p>
                @else
                    <p class="rounded-md bg-warn-soft px-3 py-2">{{ __('Panel şu an e-posta gönderemiyor, bu yüzden yeni adres doğrulanmadan hemen geçerli olur. Adresi dikkatle yazın.') }}</p>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('changingEmail', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="submit" class="{{ $primary }}" @disabled($impersonating)>@if ($mailWorks)<x-icon name="send" />{{ __('Doğrulama bağlantısı gönder') }}@else{{ __('Adresi değiştir') }}@endif</button>
            </div>
        </form>
    </x-modal>
</div>
