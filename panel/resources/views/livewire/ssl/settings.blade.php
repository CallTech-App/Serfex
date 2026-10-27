@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('ssl.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('SSL Sertifikaları') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('SSL ayarları') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('SSL ayarları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Let\'s Encrypt hesabı ve yeni siteler için otomatik sertifika.') }}</p>
    </div>

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="email" class="sm:pt-2">{{ __('Let\'s Encrypt e-postası') }}</label>
                    <div>
                        <input id="email" type="email" wire:model="email" placeholder="{{ $defaultEmail }}" spellcheck="false" autocomplete="off"
                               class="{{ $field }} sm:w-80 {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                        @error('email')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Hesap bu adresle açılır. Boş bırakılırsa yönetici hesabınızın adresi kullanılır.') }}</p>
                        @enderror
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('Yeni siteler') }}</span>
                    <div>
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" wire:model="auto" class="size-4 accent-accent">
                            {{ __('Yeni sitelere otomatik Let\'s Encrypt sertifikası al') }}
                        </label>
                        <p class="mt-1 ml-[26px] text-2xs text-muted">{{ __('Alan adı ve ek adlar bu sunucuyu gösteriyorsa sertifika site oluşturulunca alınır, göstermiyorsa her saat yeniden denenir. Sertifikalar bitişten :config gün önce yenilenir.', ['config' => config('serfex.ssl.renew_days')]) }}</p>
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('Panelin sertifikası') }}</span>
                    <p class="text-[13px] text-muted">
                        @if ($panelHostname !== '')
                            <span class="font-mono text-fg">{{ $panelHostname }}</span> ·
                        @endif
                        @if (auth()->user()->isFullAdmin())
                            <x-t text="Panelin adresi, portu ve sertifikası :link sayfasında."><x-slot:link><a href="{{ route('settings.general') }}" wire:navigate class="text-accent hover:underline">{{ __('Araçlar ve Ayarlar → Genel') }}</a></x-slot:link></x-t>
                        @else
                            {{ __('Panelin adresi, portu ve sertifikası Araçlar ve Ayarlar\'da; bir tam yönetici değiştirebilir.') }}
                        @endif
                    </p>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </section>
</div>
