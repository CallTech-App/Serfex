{{-- The repository fields of Git dağıtımı, for connecting and for settings. $hasToken: an access token is already saved. $picker (connecting only): the repository is picked from a connected account's list; $hosts: the Git hosts set up. --}}
@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $hasToken = $hasToken ?? false;
    $picker = $picker ?? null;
    $hosts = $hosts ?? [];
    $chip = 'flex h-8 cursor-pointer items-center gap-2 rounded-md border border-line px-3 text-[13px] has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40';
    $check = 'size-4 accent-[rgb(var(--accent))]';
@endphp
<div class="grid items-start gap-x-4 gap-y-1.5 sm:grid-cols-[11rem_minmax(0,1fr)]">
    @if ($hosts)
        <span class="sm:pt-1.5">{{ __('Depo') }}</span>
        <div class="mb-1 flex flex-wrap gap-2">
            @foreach ($hosts as $key => $label)
                <label class="{{ $chip }}"><input type="radio" wire:model.live="gitSource" value="{{ $key }}" class="{{ $check }}">{{ $label }}</label>
            @endforeach
            <label class="{{ $chip }}"><input type="radio" wire:model.live="gitSource" value="" class="{{ $check }}">{{ __('Adresi yazarak') }}</label>
        </div>
    @endif

    @if ($picker)
        <span class="hidden sm:block"></span>
        <div class="mb-1">@include('livewire.partials.git-picker')</div>
    @else
    <label for="git-repository" class="sm:pt-2">{{ __('Depo adresi') }}</label>
    <div>
        <input id="git-repository" wire:model.live.debounce.400ms="repository" required spellcheck="false" autocomplete="off" placeholder="https://github.com/kullanici/site.git"
               class="{{ $field }} font-mono {{ $errors->has('repository') ? 'border-crit' : 'border-line' }}">
        @error('repository')
            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
        @else
            <p class="mt-1.5 text-2xs text-muted"><x-t text="Herkese açık veya erişim anahtarıyla açılan bir :part adresi, ya da özel depo için :part2 biçiminde SSH adresi (Serfex bir dağıtım anahtarı oluşturur)."><x-slot:part><span class="font-mono">https://</span></x-slot:part><x-slot:part2><span class="font-mono">git@github.com:kullanici/site.git</span></x-slot:part2></x-t></p>
        @enderror
    </div>

    @if (str_starts_with($repository, 'https://'))
        <label for="git-token" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Erişim anahtarı') }}</label>
        <div>
            <input id="git-token" type="password" wire:model="token" spellcheck="false" autocomplete="new-password"
                   placeholder="{{ $hasToken ? __('kayıtlı · değiştirmek için yenisini yazın') : __('isteğe bağlı, özel depolar için') }}"
                   class="{{ $field }} font-mono {{ $errors->has('token') ? 'border-crit' : 'border-line' }}">
            @error('token')
                <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
            @else
                <p class="mt-1.5 text-2xs text-muted">{{ __('GitHub, GitLab veya Bitbucket\'ta yalnızca okuma izni olan bir erişim anahtarı (token). Şifreli saklanır ve yalnızca kod çekilirken kullanılır.') }}</p>
            @enderror
            @if ($hasToken)
                <label class="mt-1.5 flex items-center gap-2 text-[13px]"><input type="checkbox" wire:model="clearToken" class="size-4 accent-[rgb(var(--accent))]">{{ __('Kayıtlı anahtarı sil (depo herkese açık)') }}</label>
            @endif
        </div>
    @endif

    <label for="git-branch" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Dal') }}</label>
    <div>
        <input id="git-branch" wire:model="branch" required spellcheck="false" autocomplete="off" placeholder="main" class="{{ $field }} font-mono sm:w-56 {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
        @error('branch') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
    </div>
    @endif

    <label for="git-path" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Hedef klasör') }}</label>
    <div>
        <input id="git-path" wire:model="path" required spellcheck="false" autocomplete="off" placeholder="public_html" class="{{ $field }} font-mono sm:w-56 {{ $errors->has('path') ? 'border-crit' : 'border-line' }}">
        @error('path')
            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
        @else
            <p class="mt-1.5 text-2xs text-muted">{{ __('Site klasörüne göre. Depodaki dosyalar buraya yazılır; depoda olmayan dosyalarınız (yüklemeler, ayar dosyaları) silinmez.') }}</p>
        @enderror
    </div>

    <label for="git-commands" class="mt-2.5 sm:mt-0 sm:pt-2">{{ __('Dağıtım sonrası komutlar') }}</label>
    <div>
        <textarea id="git-commands" wire:model="commands" rows="3" spellcheck="false" placeholder="{{ __('isteğe bağlı, örneğin composer install --no-dev') }}"
                  class="w-full rounded-md border bg-surface-2 p-3 font-mono text-[12.5px] outline-none focus:border-accent {{ $errors->has('commands') ? 'border-crit' : 'border-line' }}"></textarea>
        @error('commands')
            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
        @else
            <p class="mt-1.5 text-2xs text-muted"><x-t text="Her dağıtımdan sonra hedef klasörde, sitenin kullanıcısıyla (:name) ve sitenin PHP sürümüyle çalışır; biri başarısız olursa dosyalar yine yayında kalır."><x-slot:name><span class="font-mono">{{ $site->systemUser->name }}</span></x-slot:name></x-t></p>
        @enderror
    </div>
</div>
