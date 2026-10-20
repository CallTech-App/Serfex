@php
    use App\Models\User;
    use App\Support\When;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $me = auth()->id();
@endphp
<x-settings-page current="settings.admins" title="Yöneticiler" description="Sunucuyu kimlerin hangi rolle yönettiği. Müşterilerin girişleri Müşteriler ve Paketler'de.">
    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openInvite" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="user-plus" />Yönetici ekle
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[640px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">Ad</th>
                        <th class="px-3 py-2.5 font-medium">Rol</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">İki adımlı doğrulama</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Son giriş</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($admins as $admin)
                        <tr wire:key="admin-{{ $admin->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5">
                                <span class="font-medium">{{ $admin->name }}</span>
                                @if ($admin->id === $me)
                                    <span class="ml-1.5 rounded bg-accent-soft px-1.5 py-0.5 text-2xs text-accent">Siz</span>
                                @endif
                                <span class="block font-mono text-[12.5px] text-muted">{{ $admin->email }}</span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">{{ User::ROLES[$admin->adminRole()][0] }}</td>
                            <td class="hidden px-3 py-3 md:table-cell">
                                <x-status-badge :status="$admin->hasTwoFactor() ? new \App\Support\Badge('Açık', 'ok') : new \App\Support\Badge('Kapalı', 'muted')" />
                            </td>
                            <td class="num hidden px-3 py-3 whitespace-nowrap text-muted sm:table-cell">
                                @if ($admin->isInvited())
                                    <span class="text-info">Davet bekliyor</span>
                                @else
                                    {{ When::withTime($admin->last_login_at) }}
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                @if ($admin->id !== $me)
                                    @if ($admin->isInvited())
                                        <button type="button" wire:click="reinvite({{ $admin->id }})" class="text-[13px] text-accent hover:underline">Yeni bağlantı</button>
                                    @endif
                                    @if ($admin->hasTwoFactor())
                                        <button type="button" wire:click="resetTwoFactor({{ $admin->id }})" wire:confirm="{{ $admin->name }} için iki adımlı doğrulama kapatılsın mı? Telefonunu kaybettiyse bir sonraki girişte yeniden kurar." class="ml-3 text-[13px] text-accent hover:underline">2FA sıfırla</button>
                                    @endif
                                    <button type="button" wire:click="openEdit({{ $admin->id }})" class="ml-3 text-[13px] text-accent hover:underline">Rolü değiştir</button>
                                    <button type="button" wire:click="confirmRemove({{ $admin->id }})" class="ml-3 text-[13px] text-crit hover:underline">Kaldır</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="space-y-1 border-t border-line px-5 py-3 text-2xs text-muted">
            @foreach (User::ROLES as [$label, $help])
                <p><span class="font-medium text-fg">{{ $label }}:</span> {{ $help }}</p>
            @endforeach
        </div>
    </section>

    {{-- Yönetici ekle --}}
    <x-modal title="Yönetici ekle" show="inviting">
        <form wire:submit="invite">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Yönetici ekle</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="grid items-start gap-x-4 gap-y-1.5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                    <label for="admin-email" class="sm:pt-2">E-posta</label>
                    <div>
                        <input id="admin-email" type="email" wire:model="email" required autocomplete="off" spellcheck="false" placeholder="emre@ornek.com.tr" class="{{ $field }} font-mono {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                        @error('email') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>

                    <label for="admin-name" class="mt-2.5 sm:mt-0 sm:pt-2">Ad</label>
                    <div>
                        <input id="admin-name" wire:model="name" maxlength="100" placeholder="isteğe bağlı; kendisi de girebilir" class="{{ $field }} {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                        @error('name') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>

                    <span class="mt-2.5 sm:mt-0 sm:pt-1">Rol</span>
                    <div class="space-y-2">
                        @foreach (User::ROLES as $key => [$label, $help])
                            <label class="flex items-start gap-2.5">
                                <input type="radio" wire:model="role" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                                <span>{{ $label }}<span class="block text-2xs text-muted">{{ $help }}</span></span>
                            </label>
                        @endforeach
                        @error('role') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-2xs text-muted">Bir davet bağlantısı oluşur ({{ \App\Http\Controllers\Auth\InvitationController::DAYS }} gün geçerli). Kişi parolasını kendisi belirler. Panelin e-posta gönderimi ayarlıysa bağlantı e-postayla da gider.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('inviting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Davet et</button>
            </div>
        </form>
    </x-modal>

    {{-- Rolü değiştir --}}
    @php $editing = $editId ? $admins->firstWhere('id', $editId) : null; @endphp
    <div x-data x-show="$wire.editId !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.editId = null">
        <form wire:submit="saveRole" class="w-full max-w-md rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Rolü değiştir">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $editing?->name }} · rol</h2>
            <div class="space-y-2 p-5 text-[13.5px]">
                @foreach (User::ROLES as $key => [$label, $help])
                    <label class="flex items-start gap-2.5">
                        <input type="radio" wire:model="editRole" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                        <span>{{ $label }}<span class="block text-2xs text-muted">{{ $help }}</span></span>
                    </label>
                @endforeach
                @error('editRole') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Kaydet</button>
            </div>
        </form>
    </div>

    {{-- Kaldır --}}
    @php $removing = $removingId ? $admins->firstWhere('id', $removingId) : null; @endphp
    <div x-data x-show="$wire.removingId !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.removingId = null">
        <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="Yöneticiyi kaldır">
            <p class="font-semibold">{{ $removing?->name }} kaldırılsın mı?</p>
            <p class="mt-1 text-[13px] text-muted">Açık oturumları hemen kapanır, bir daha giriş yapamaz. Yaptıkları İşlem günlüğünde kalır. {{ \App\Services\Settings\Administrators::UNDO_MINUTES }} dakika içinde geri alabilirsiniz.</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('removingId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="button" wire:click="remove" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">Kaldır</button>
            </div>
        </div>
    </div>

    {{-- Davet bağlantısı bir kez gösterilir --}}
    @if ($invitation)
        <div x-data class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.set('invitation', null)">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Davet bağlantısı">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Davet bağlantısı</h2>
                <div class="space-y-3 p-5 text-[13.5px]">
                    <p>{{ $invitation['mailed'] ? 'Bağlantı '.$invitation['email'].' adresine gönderildi. Ulaşmazsa bu bağlantıyı kendiniz iletin:' : 'Bu bağlantıyı '.$invitation['email'].' adresinin sahibine iletin:' }}</p>
                    <div class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                        <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]">{{ $invitation['link'] }}</span>
                        <button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($invitation['link'])); done = true; setTimeout(() => done = false, 1500)" class="shrink-0 text-[12.5px] text-accent hover:underline">
                            <span x-show="!done">Kopyala</span><span x-show="done" x-cloak>Kopyalandı</span>
                        </button>
                    </div>
                    <p class="text-2xs text-muted">Bağlantı yalnızca şimdi gösterilir ve {{ \App\Http\Controllers\Auth\InvitationController::DAYS }} gün geçerlidir. Gerekirse listeden yenisini oluşturun.</p>
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('invitation', null)" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Tamam</button>
                </div>
            </div>
        </div>
    @endif
</x-settings-page>
