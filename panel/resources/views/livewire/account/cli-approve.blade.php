@php
    use App\Models\ApiKey;
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Komut satırı girişi') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('serfex komut satırı aracı bu hesapla çalışmak için onayınızı istiyor.') }}</p>
    </div>

    <section class="max-w-3xl rounded-xl border border-line bg-surface">
        @if ($done === 'approved')
            <div class="flex items-start gap-3 px-5 py-6 text-[13.5px]" role="status">
                <x-icon name="circle-check" class="mt-0.5 shrink-0 text-ok" />
                <div>
                    <p class="font-medium">{{ __('Giriş onaylandı.') }}</p>
                    <p class="mt-1 text-muted">{{ __('Komut satırına dönebilirsiniz; birkaç saniye içinde girmiş olur. Bu oturumu Hesap güvenliği sayfasından istediğiniz zaman kapatabilirsiniz.') }}</p>
                </div>
            </div>
        @elseif ($done === 'denied')
            <div class="flex items-start gap-3 px-5 py-6 text-[13.5px]" role="status">
                <x-icon name="circle-x" class="mt-0.5 shrink-0 text-muted" />
                <p>{{ __('Giriş reddedildi; komut satırı bu hesaba erişemez.') }}</p>
            </div>
        @elseif ($login === null || ! $login->isPending())
            <form wire:submit="lookup" class="px-5 py-5 text-[13.5px]">
                @if ($code !== '')
                    <p class="mb-3 text-muted">{{ __('Bu bağlantıdaki girişin süresi dolmuş ya da kullanılmış. serfex login ile yeniden başlayın.') }}</p>
                @endif
                <label for="cli-code" class="mb-1.5 block">{{ __('Komut satırında görünen kodu yazın') }}</label>
                <div class="flex flex-wrap items-center gap-2">
                    <input id="cli-code" wire:model="typed" autocomplete="off" maxlength="9" placeholder="ABCD-2345" class="{{ $field }} w-44 font-mono uppercase tracking-widest {{ $errors->has('typed') ? 'border-crit' : 'border-line' }}">
                    <button type="submit" class="{{ $btn }}">{{ __('Devam') }}</button>
                </div>
                @error('typed') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            </form>
        @else
            <form wire:submit="approve">
                <div class="divide-y divide-line px-5 text-[13.5px]">
                    <div class="{{ $row }}">
                        <span>{{ __('Kod') }}</span>
                        <div>
                            <p class="font-mono text-[18px] tracking-widest">{{ $login->code }}</p>
                            <p class="mt-1 text-2xs text-muted">{{ __('Komut satırında aynı kodu gördüğünüzden emin olun. Görmüyorsanız reddedin.') }}</p>
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <span>{{ __('Bilgisayar') }}</span>
                        <p><span class="font-mono">{{ $login->client }}</span>@if ($login->ip) <span class="text-muted">· <span class="font-mono">{{ $login->ip }}</span></span>@endif</p>
                    </div>
                    <div class="{{ $row }}">
                        <span>{{ __('Hesap') }}</span>
                        <p>{{ $user->name }} <span class="text-muted">· {{ $user->roleName() }}</span></p>
                    </div>
                    <div class="{{ $row }}">
                        <span class="sm:pt-1">{{ __('Yetki') }}</span>
                        <div class="space-y-2">
                            @foreach (ApiKey::SCOPES as $value => [$name, $text])
                                <label class="flex items-start gap-2 {{ $user->isReadOnly() && $value === 'write' ? 'opacity-40' : '' }}">
                                    <input type="radio" wire:model="scope" value="{{ $value }}" class="mt-1" @disabled($user->isReadOnly() && $value === 'write')>
                                    <span><span class="font-medium">{{ __($name) }}</span><br><span class="text-muted">{{ $value === 'write' ? __('Panelde yapabildiğiniz her şeyi komut satırından da yapar.') : __('Yalnızca okur; hiçbir şeyi değiştiremez.') }}</span></span>
                                </label>
                            @endforeach
                            @error('scope') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="deny" class="{{ $btn }}">{{ __('Reddet') }}</button>
                    <button type="submit" class="{{ $primary }}"><x-icon name="terminal" />{{ __('Onayla') }}</button>
                </div>
            </form>
        @endif
    </section>
</div>
