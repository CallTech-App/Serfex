{{-- Plesk sunucusu: sign in over SSH, then pick a subscription to copy here. --}}
@php
    use App\Support\Bytes;
@endphp
@if ($pleskServer === null)
    <div class="divide-y divide-line">
        <div class="{{ $row }} pt-0">
            <label for="pl-host" class="pt-2 font-medium">{{ __('Sunucu') }}</label>
            <div>
                <div class="flex gap-2">
                    <input id="pl-host" wire:model="pleskHost" placeholder="plesk.eskisunucu.com" autocomplete="off" spellcheck="false" class="{{ $field }} min-w-0 flex-1 font-mono">
                    <input wire:model="pleskPort" type="number" min="1" max="65535" aria-label="{{ __('SSH bağlantı noktası') }}" class="{{ $field }} num w-20 font-mono">
                </div>
                @error('pleskHost') <p class="mt-1 text-2xs text-crit">{{ $message }}</p> @enderror
                @error('pleskPort') <p class="mt-1 text-2xs text-crit">{{ $message }}</p> @enderror
                <p class="mt-1 text-2xs text-muted">{{ __('Eski Plesk sunucusunun adı ya da IP adresi ve SSH bağlantı noktası.') }}</p>
            </div>
        </div>
        <div class="{{ $row }}">
            <label for="pl-user" class="pt-2 font-medium">{{ __('Kullanıcı') }}</label>
            <div>
                <input id="pl-user" wire:model="pleskUser" autocomplete="off" spellcheck="false" class="{{ $field }} w-full max-w-xs font-mono">
                @error('pleskUser') <p class="mt-1 text-2xs text-crit">{{ $message }}</p> @enderror
                <p class="mt-1 text-2xs text-muted">{{ __('root ya da parolasız sudo kullanabilen bir kullanıcı.') }}</p>
            </div>
        </div>
        <div class="{{ $row }}">
            <p class="font-medium">{{ __('Giriş') }}</p>
            <div class="space-y-3">
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="pleskAuth" value="key" class="accent-[rgb(var(--accent))]">{{ __('Serfex\'in SSH anahtarı') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="pleskAuth" value="password" class="accent-[rgb(var(--accent))]">{{ __('Parola') }}</label>
                </div>
                @if ($pleskAuth === 'key')
                    @if ($publicKey ?? null)
                        @php $line = 'echo '.escapeshellarg($publicKey).' >> ~/.ssh/authorized_keys'; @endphp
                        <p class="text-2xs text-muted">{{ __('Bu satırı eski sunucuda bu kullanıcıyla bir kez çalıştırın:') }}</p>
                        <div class="flex items-start gap-3 rounded-lg border border-line bg-surface-2 px-3 py-2">
                            <span class="min-w-0 flex-1 font-mono text-[12.5px] break-all">{{ $line }}</span>{!! $copy($line) !!}
                        </div>
                    @else
                        <p class="text-2xs text-crit">{{ __('Sunucu ajanına ulaşılamıyor.') }}</p>
                    @endif
                @else
                    <input type="password" wire:model="pleskPassword" aria-label="{{ __('Parola') }}" autocomplete="off" class="{{ $field }} w-full max-w-xs">
                    @error('pleskPassword') <p class="text-2xs text-crit">{{ $message }}</p> @enderror
                    <p class="text-2xs text-muted">{{ __('Parola yalnızca aktarım bitene kadar, şifreli olarak saklanır.') }}</p>
                @endif
            </div>
        </div>
    </div>
    <div class="flex justify-end">
        <button type="button" wire:click="connectPlesk" wire:loading.attr="disabled" class="{{ $primary }}">
            <x-icon name="plug" />
            <span wire:loading.remove wire:target="connectPlesk">{{ __('Bağlan') }}</span>
            <span wire:loading wire:target="connectPlesk">{{ __('Bağlanılıyor…') }}</span>
        </button>
    </div>
    <div class="rounded-lg bg-surface-2 p-4 text-[13px]">
        <p class="font-medium">{{ __('Nasıl çalışır?') }}</p>
        <ul class="mt-1.5 list-disc space-y-1 pl-5 text-muted">
            <li>{{ __('Serfex eski sunucuya SSH ile bağlanır ve aboneliği Plesk\'in kendi araçlarıyla okur. Plesk yedeği almanız gerekmez.') }}</li>
            <li>{{ __('Dosyalar, posta kutuları ve MySQL veritabanları bu sunucuya kopyalanır. Eski sunucuda hiçbir şey değişmez.') }}</li>
            <li>{{ __('Linux üzerinde Plesk Obsidian (18) gerekir. Bu sunucu eski sunucunun SSH bağlantı noktasına ulaşabilmelidir.') }}</li>
        </ul>
    </div>
@else
    <div class="flex flex-wrap items-start gap-x-4 gap-y-2 rounded-lg border border-line p-3">
        <x-icon name="server" class="mt-0.5 text-muted" />
        <div class="min-w-0 flex-1">
            <p><span class="font-mono">{{ $pleskServer['hostname'] ?: $pleskHost }}</span> <span class="text-muted">· Plesk {{ $pleskServer['version'] }}</span></p>
            <p class="mt-0.5 text-2xs text-muted">{{ __('SSH parmak izi') }} <span class="font-mono break-all">{{ $pleskServer['fingerprint'] }}</span></p>
        </div>
        <button type="button" wire:click="disconnectPlesk" class="text-[13px] text-accent hover:underline">{{ __('Başka sunucu') }}</button>
    </div>
    <div>
        <p class="mb-2 font-medium">{{ __('Abonelikler') }}</p>
        <div class="divide-y divide-line rounded-lg border border-line">
            @forelse ($pleskServer['subscriptions'] as $sub)
                <label wire:key="pl-{{ $sub['id'] }}" class="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-surface-2/60">
                    <input type="radio" wire:model="pleskSubscription" value="{{ $sub['id'] }}" class="mt-1 accent-[rgb(var(--accent))]">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-mono">{{ $sub['domain'] }}</span>
                            @if ($sub['domains'] > 0)
                                <span class="rounded-full bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ trans_choice('+:count ek alan adı', $sub['domains'], ['count' => $sub['domains']]) }}</span>
                            @endif
                        </span>
                        <span class="mt-0.5 block text-2xs text-muted">
                            <span class="font-mono">{{ $sub['user'] }}</span>
                            @if ($sub['owner'] !== '') · {{ __('Müşteri: :name', ['name' => $sub['owner']]) }}@endif
                        </span>
                    </span>
                    <span class="num shrink-0 text-2xs text-muted">{{ Bytes::format($sub['size']) }}</span>
                </label>
            @empty
                <p class="px-3 py-4 text-center text-[13px] text-muted">{{ __('Bu sunucuda web barındırması olan abonelik yok.') }}</p>
            @endforelse
        </div>
        <p class="mt-2 text-2xs text-muted">{{ __('Seçtiğiniz abonelik önce bu sunucuya kopyalanır; neyin taşınacağını sonra seçersiniz. Eski sunucuda hiçbir şey değişmez.') }}</p>
    </div>
    <div class="flex justify-end">
        <button type="button" wire:click="pullPlesk" wire:loading.attr="disabled" @disabled($pleskServer['subscriptions'] === []) class="{{ $primary }}">
            <x-icon name="download" />{{ __('Aboneliği al') }}
        </button>
    </div>
@endif
