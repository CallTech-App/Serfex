@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">FTP hesapları</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h1 class="text-[22px] font-semibold tracking-tight">FTP hesapları</h1>
            <a href="{{ route('sites.files', $site) }}" wire:navigate class="{{ $btn }} font-medium"><x-icon name="folder" />Dosya Yöneticisi</a>
        </div>
        <p class="mt-1 text-[13px] text-muted">
            Bu hesaplarla FileZilla, WinSCP ya da Cyberduck gibi bir programla <strong class="font-medium text-fg">SFTP</strong> üzerinden bağlanılır:
            sunucu <span class="font-mono text-fg">{{ $site->domain }}</span>, port <span class="font-mono text-fg">{{ $port }}</span>.
            Her hesap yalnızca kendi klasörünü görür; yüklenen dosyalar sitenin kullanıcısına ait olur.
        </p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openCreate" @disabled($refusal) title="{{ $refusal }}" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="plus" />Hesap ekle
            </button>
            @if ($refusal)
                <span class="text-[13px] text-muted">{{ $refusal }}</span>
            @endif
        </div>
        <div class="relative overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[640px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">Hesap</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Klasör</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">Parola değişti</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($accounts as $account)
                        <tr wire:key="ftp-{{ $account->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5">
                                <span class="font-mono text-[13px] font-medium">{{ $account->name }}</span>
                                @if ($site->isSuspended())
                                    <span class="ml-1.5 rounded bg-warn-soft px-1.5 py-0.5 text-2xs text-warn">Kilitli</span>
                                @endif
                                @if ($account->note)
                                    <span class="mt-0.5 block text-[12.5px] text-muted">{{ $account->note }}</span>
                                @endif
                                <span class="mt-0.5 block font-mono text-[12.5px] text-muted sm:hidden">{{ $account->directory === '' ? '/' : $account->directory.'/' }}</span>
                            </td>
                            <td class="hidden px-3 py-3 font-mono text-[13px] sm:table-cell" title="{{ $account->path() }}">{{ $account->directory === '' ? $site->domain.'/' : $account->directory.'/' }}</td>
                            <td class="num hidden px-3 py-3 whitespace-nowrap text-muted md:table-cell">{{ $account->password_changed_at?->timezone(config('servik.timezone'))->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="openEdit({{ $account->id }})" class="text-[13px] text-accent hover:underline">Düzenle</button>
                                <button type="button" wire:click="confirmDelete({{ $account->id }})" class="ml-3 text-[13px] text-crit hover:underline">Sil</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center">
                                <p class="font-medium">Henüz FTP hesabı yok</p>
                                <p class="mt-1 text-[13px] text-muted">Bir geliştiriciye ya da tasarımcıya sitenin yalnızca bir klasörünü açmak için hesap ekleyin.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="num border-t border-line px-5 py-3 text-2xs text-muted">
            {{ $usage ? $usage['text'].' FTP hesabı (paket)' : $accounts->count().' hesap' }} · Site klasörü <span class="font-mono">{{ $root }}</span>
        </p>
    </section>

    {{-- Hesap ekle / düzenle --}}
    <x-modal title="{{ $editId ? 'Hesabı düzenle' : 'Hesap ekle' }}" show="editing">
        <form wire:submit="save">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $editId ? 'Hesabı düzenle' : 'Hesap ekle' }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="grid items-start gap-x-4 gap-y-1.5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                    <label for="ftp-name" class="sm:pt-2">Kullanıcı adı</label>
                    <div>
                        @if ($editId)
                            <p class="pt-2 font-mono text-[13px]">{{ $prefix }}{{ $suffix }}</p>
                        @else
                            <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('suffix') ? 'border-crit' : 'border-line' }}">
                                <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ $prefix }}</span>
                                <input id="ftp-name" wire:model="suffix" required placeholder="tasarimci" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                            </div>
                        @endif
                        @error('suffix') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>

                    <label for="ftp-dir" class="mt-2.5 sm:mt-0 sm:pt-2">Klasör</label>
                    <div>
                        <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('directory') ? 'border-crit' : 'border-line' }}">
                            <span class="grid max-w-[55%] place-items-center truncate border-r border-line bg-surface-2 px-3 font-mono text-[12.5px] text-muted" title="{{ $root }}">{{ $site->domain }}/</span>
                            <input id="ftp-dir" wire:model="directory" placeholder="boş: tüm site klasörü" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                        </div>
                        @error('directory')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Hesap bu klasöre kilitlenir, üstüne çıkamaz. Yoksa oluşturulur.</p>
                        @enderror
                    </div>

                    <label for="ftp-note" class="mt-2.5 sm:mt-0 sm:pt-2">Not</label>
                    <div>
                        <input id="ftp-note" wire:model="note" maxlength="100" placeholder="isteğe bağlı, örneğin Ajans" class="{{ $field }} {{ $errors->has('note') ? 'border-crit' : 'border-line' }}">
                        @error('note') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>

                    <span class="mt-2.5 sm:mt-0 sm:pt-2">Parola</span>
                    <div>
                        @if ($editId)
                            <label class="flex h-9 items-center gap-2">
                                <input type="checkbox" wire:model.live="changePassword" class="size-4 accent-accent">
                                <span>Yeni parola belirle</span>
                            </label>
                        @endif
                        @if ($changePassword)
                            <div class="flex gap-2">
                                <input id="ftp-pass" wire:model="password" aria-label="Parola" autocomplete="new-password" spellcheck="false" class="{{ $field }} font-mono {{ $errors->has('password') ? 'border-crit' : 'border-line' }}">
                                <button type="button" wire:click="newPassword" class="grid size-9 shrink-0 place-items-center rounded-md border border-line hover:bg-surface-2" aria-label="Yeni parola üret" title="Yeni parola üret"><x-icon name="refresh-cw" class="size-3.5" /></button>
                            </div>
                            @error('password')
                                <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                            @else
                                <p class="mt-1.5 text-2xs text-muted">Kaydettikten sonra bir kez gösterilir; Servik parolayı saklamaz.</p>
                            @enderror
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">{{ $editId ? 'Kaydet' : 'Hesabı oluştur' }}</span>
                    <span wire:loading wire:target="save">Uygulanıyor…</span>
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Silme onayı --}}
    @php $deleting = $deletingId ? $accounts->firstWhere('id', $deletingId) : null; @endphp
    <div x-data x-show="$wire.deletingId !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.deletingId = null">
        <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="Hesabı sil">
            <p class="font-semibold"><span class="font-mono">{{ $deleting?->name }}</span> silinsin mi?</p>
            <p class="mt-1 text-[13px] text-muted">Hesap hemen bağlanamaz olur. Klasördeki dosyalar silinmez. 10 dakika içinde geri alabilirsiniz.</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deletingId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="button" wire:click="delete" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">Hesabı sil</button>
            </div>
        </div>
    </div>

    {{-- Parola bir kez gösterilir --}}
    @if ($credentials)
        <div x-data class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.set('credentials', null)">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Bağlantı bilgileri">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Bağlantı bilgileri</h2>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p class="flex gap-2 rounded-lg bg-warn-soft p-3 text-[13px]">
                        <x-icon name="key-round" class="mt-0.5 shrink-0 text-warn" />
                        <span>Parolayı şimdi kopyalayın. Servik parolaları saklamaz; bu pencere kapanınca bir daha gösterilmez, gerekirse yenisini belirlersiniz.</span>
                    </p>
                    <dl class="divide-y divide-line rounded-lg border border-line">
                        @foreach ($credentials as $label => $value)
                            <div class="flex items-center gap-3 px-3 py-2">
                                <dt class="w-24 shrink-0 text-muted">{{ $label }}</dt>
                                <dd class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $value }}</dd>
                                <button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($value)); done = true; setTimeout(() => done = false, 1500)" class="shrink-0 text-[12.5px] text-accent hover:underline">
                                    <span x-show="!done">Kopyala</span><span x-show="done" x-cloak>Kopyalandı</span>
                                </button>
                            </div>
                        @endforeach
                    </dl>
                    <p class="text-2xs text-muted">Klasik FTP (şifresiz) desteklenmez; programınızda bağlantı türü olarak SFTP seçin.</p>
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('credentials', null)" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Kopyaladım, kapat</button>
                </div>
            </div>
        </div>
    @endif
</div>
