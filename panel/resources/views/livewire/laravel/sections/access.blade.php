@php
    use App\Models\LaravelApiToken;
    use App\Models\LaravelAppMember;
    use App\Support\Badge;
    use App\Support\When;
    $domain = $app->site->domain;
    $deployUrl = route('api.deploy', $domain);
    $link = 'text-[13px] whitespace-nowrap text-muted hover:text-fg';
    $pre = 'scroll-thin overflow-x-auto rounded-md border border-line bg-surface-2 p-3 font-mono text-[12.5px] whitespace-pre';
    $panelUrl = url('/');
    $installUrl = route('cli.install');
    $cli = "curl -fsSL {$installUrl} | sh\nserfex login {$panelUrl}\nserfex deploy -a {$domain} --wait";
    $actions = '- name: '.__('Serfex ile dağıt')."\n  env:\n    SERFEX_URL: {$panelUrl}\n    SERFEX_TOKEN: \${{ secrets.SERFEX_TOKEN }}\n  run: |\n    curl -fsSL {$installUrl} | sh\n    serfex deploy --ref \${{ github.ref_name }} --wait";
@endphp
<div>
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif

    {{-- Kişiler --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <p class="text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Kişiler') }}</p>
        @if ($manage)
            <button type="button" wire:click="openAdd" class="ml-auto flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="user-plus" />{{ __('Kişi ekle') }}</button>
        @endif
    </div>
    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[620px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Kişi') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Rol') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Yapabilecekleri') }}</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($members as $m)
                    <tr wire:key="m-{{ $m->id }}">
                        <td class="py-3 pr-3 pl-5">{{ $m->user->name }}
                            @if ($m->user->isInvited())<x-status-badge :status="new Badge(__('Davet bekliyor'), 'warn')" class="ml-1" />@endif
                            <div class="text-2xs text-muted">{{ $m->user->email }}</div>
                        </td>
                        <td class="px-3 py-3">
                            @if ($manage)
                                <select wire:change="setRole({{ $m->id }}, $event.target.value)" aria-label="{{ __('Rol') }}" class="h-8 rounded-md border border-line bg-surface px-2 text-[13px]">
                                    @foreach (LaravelAppMember::ROLES as $value => [$label])
                                        <option value="{{ $value }}" @selected($m->role === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                {{ LaravelAppMember::ROLES[$m->role][0] }}
                            @endif
                        </td>
                        <td class="px-3 py-3 text-muted">{{ LaravelAppMember::ROLES[$m->role][1] }}</td>
                        <td class="space-x-3 px-3 py-3 text-right whitespace-nowrap">
                            @if ($manage)
                                @if ($m->user->isInvited())<button type="button" wire:click="newInvite({{ $m->id }})" class="{{ $link }}">{{ __('Davet bağlantısı') }}</button>@endif
                                <button type="button" wire:click="remove({{ $m->id }})" class="{{ $link }} hover:text-crit">{{ __('Çıkar') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @foreach ($admins as $admin)
                    <tr wire:key="a-{{ $admin->id }}" class="text-muted">
                        <td class="py-3 pr-3 pl-5"><span class="text-fg">{{ $admin->name }}</span><div class="text-2xs">{{ $admin->email }}</div></td>
                        <td class="px-3 py-3">{{ __('Sunucu yöneticisi') }}</td>
                        <td class="px-3 py-3" colspan="2">{{ __('Tüm uygulamalarda sahip') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- API --}}
    <div class="flex flex-wrap items-center gap-2 border-y border-line px-5 py-3">
        <p class="text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Dağıtım API\'si') }}</p>
        @if ($manage)
            <button type="button" wire:click="openToken" class="ml-auto flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="key-round" />{{ __('Anahtar oluştur') }}</button>
        @endif
    </div>
    @if ($tokens->isNotEmpty())
        <ul class="divide-y divide-line border-b border-line text-[13.5px]">
            @foreach ($tokens as $t)
                <li wire:key="t-{{ $t->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="font-medium">{{ $t->name }}</span>
                    <span class="font-mono text-[12.5px] text-muted">sfx_…{{ $t->last_four }}</span>
                    <x-status-badge :status="new Badge(__(LaravelApiToken::ABILITIES[$t->ability][0] ?? $t->ability), $t->ability === 'operate' ? 'info' : 'muted')" />
                    <span class="text-2xs text-muted">{{ $t->created_by ? $t->created_by.' · ' : '' }}{{ When::withTime($t->created_at) }} · {{ $t->last_used_at ? __('son kullanım :last_used_at (:last_used_ip)', ['last_used_at' => When::withTime($t->last_used_at), 'last_used_ip' => $t->last_used_ip]) : __('hiç kullanılmadı') }}</span>
                    @if ($manage)<button type="button" wire:click="revoke({{ $t->id }})" class="ml-auto {{ $link }} hover:text-crit">{{ __('İptal et') }}</button>@endif
                </li>
            @endforeach
        </ul>
    @endif
    <div class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-[13.5px] md:grid-cols-[230px_1fr]">
        <p class="font-medium md:pt-2">{{ __('Komut satırından') }}</p>
        <div class="min-w-0 max-w-2xl space-y-1">
            <pre class="{{ $pre }}">{{ $cli }}</pre>
            <p class="text-2xs text-muted"><x-t text="serfex aracını kurar, girişi tarayıcıda onaylatır ve dağıtımı sonuna kadar izler. Diğer komutlar için :help."><x-slot:help><span class="font-mono">serfex help</span></x-slot:help></x-t></p>
        </div>
        <p class="font-medium md:pt-2">{{ __('CI\'dan (GitHub Actions)') }}</p>
        <div class="min-w-0 max-w-2xl space-y-1">
            <pre class="{{ $pre }}">{{ $actions }}</pre>
            <p class="text-2xs text-muted"><x-t text="Anahtarı deponun gizli değerlerine :part adıyla ekleyin. --wait dağıtım başarısız olursa adımı da başarısız yapar. Araç olmadan: :domain (Authorization: Bearer)."><x-slot:part><span class="font-mono">SERFEX_TOKEN</span></x-slot:part><x-slot:domain><span class="font-mono">{{ __('POST :domain', ['domain' => $deployUrl]) }}</span></x-slot:domain></x-t></p>
        </div>
    </div>

    @if ($manage)
    {{-- Kişi ekle --}}
    <x-modal :title="__('Kişi ekle')" show="adding" size="max-w-md">
        <form wire:submit="add">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Kişi ekle') }}</h2>
            <div class="grid grid-cols-1 gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[90px_1fr]">
                <label for="ac-email" class="sm:pt-2">{{ __('E-posta') }}</label>
                <div>
                    <input id="ac-email" type="email" wire:model="email" autocomplete="off" class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                    @error('email')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
                <label for="ac-name" class="sm:pt-2">{{ __('Ad') }}</label>
                <input id="ac-name" wire:model="name" placeholder="{{ __('İsteğe bağlı') }}" class="h-9 w-full rounded-md border border-line bg-surface px-3 outline-none focus:border-accent">
                <label for="ac-role" class="sm:pt-2">{{ __('Rol') }}</label>
                <div>
                    <select id="ac-role" wire:model.live="role" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                        @foreach (LaravelAppMember::ROLES as $value => [$label])<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <p class="mt-1 text-2xs text-muted">{{ LaravelAppMember::ROLES[$role][1] ?? '' }}</p>
                </div>
                <p class="text-2xs text-muted sm:col-span-2">{{ __('Panelde hesabı yoksa bir davet bağlantısı üretilir; kişiye siz iletirsiniz. Bağlantı :invite_days gün geçerlidir. Kişi panelde yalnızca eklendiği uygulamaları görür.', ['invite_days' => $inviteDays]) }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('adding', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Ekle') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Anahtar oluştur --}}
    <x-modal :title="__('Anahtar oluştur')" show="tokenForm" size="max-w-md">
        <form wire:submit="createToken">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Dağıtım anahtarı oluştur') }}</h2>
            <div class="p-5 text-[13.5px]">
                <label for="tk-name" class="mb-1.5 block">{{ __('Ad') }}</label>
                <input id="tk-name" wire:model="tokenName" class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('tokenName') ? 'border-crit' : 'border-line' }}">
                @error('tokenName')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Nerede kullanılacağını yazın, örneğin GitHub Actions.') }}</p>@enderror
                <p class="mt-4 mb-1.5">{{ __('Yetki') }}</p>
                <div class="space-y-2">
                    @foreach (LaravelApiToken::ABILITIES as $value => [$label, $text])
                        <label class="flex items-start gap-2">
                            <input type="radio" wire:model="tokenAbility" value="{{ $value }}" class="mt-1">
                            <span><span class="font-medium">{{ __($label) }}</span><br><span class="text-2xs text-muted">{{ __($text) }}</span></span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('tokenForm', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Oluştur') }}</button>
            </div>
        </form>
    </x-modal>
    @endif

    {{-- Bir kez gösterilen anahtar veya davet bağlantısı --}}
    @if ($secret)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ $secretKind === 'token' ? __('Yeni anahtar') : __('Davet bağlantısı') }}" x-data="{ copied: false }">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $secretKind === 'token' ? __('Yeni anahtar') : __('Davet bağlantısı') }}</h2>
                <div class="space-y-2 p-5 text-[13.5px]">
                    <div class="flex gap-2">
                        <input readonly value="{{ $secret }}" aria-label="{{ $secretKind === 'token' ? __('Anahtar') : __('Bağlantı') }}" class="h-9 min-w-0 flex-1 rounded-md border border-line bg-surface-2 px-3 font-mono text-[13px]">
                        <button type="button" @click="navigator.clipboard.writeText(@js($secret)); copied = true" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span></button>
                    </div>
                    <p class="text-2xs text-muted">{{ $secretKind === 'token' ? __('Bu anahtar yalnızca şimdi gösterilir; kaybederseniz yenisini oluşturun.') : __('Bağlantıyı kişiye iletin; açınca parolasını belirler. :invite_days gün geçerlidir.', ['invite_days' => $inviteDays]) }}</p>
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3"><button type="button" wire:click="closeSecret" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Tamam') }}</button></div>
            </div>
        </div>
    @endif
</div>
