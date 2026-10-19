@php
    use App\Models\DatabaseUser;
    use App\Support\Bytes;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $ready = $server['state'] === 'ready' || ($server['state'] === 'nomanager' && $installed !== []);
    $tabs = ['veritabanlari' => 'Veritabanları', 'kullanicilar' => 'Kullanıcılar'];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            @if ($filterSite)
                <nav class="mb-2 flex items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
                    <a href="{{ route('sites.show', $filterSite) }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" /><span class="font-mono">{{ $filterSite->domain }}</span></a>
                    <x-icon name="chevron-right" class="size-3.5" />
                    <span class="text-fg">Veritabanları</span>
                </nav>
            @endif
            <h1 class="text-[22px] font-semibold tracking-tight">Veritabanları</h1>
            <p class="mt-1 text-[13px] text-muted">
                @if ($filterSite)
                    <span class="font-mono">{{ $filterSite->domain }}</span> sitesinin veritabanları ve kullanıcıları.
                    <button type="button" wire:click="$set('siteId', 0)" class="text-accent hover:underline">Tümünü göster</button>
                @else
                    {{ auth()->user()->is_admin ? 'Bu sunucudaki müşteri veritabanları ve kullanıcıları.' : 'Sitelerinizin veritabanları ve kullanıcıları.' }} Ayrıntı için bir veritabanına tıklayın.
                @endif
            </p>
        </div>
        @if (auth()->user()->is_admin)
            <a href="{{ route('databases.settings') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                <x-icon name="settings" />Veritabanı sunucuları
            </a>
        @endif
    </div>

    @include('livewire.databases.server-state')

    @if ($installed !== [] || $total > 0)
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                            @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                @if ($tab === 'veritabanlari')
                    <button type="button" wire:click="openCreate" @disabled(! $ready || $sites->isEmpty()) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                        <x-icon name="plus" />Veritabanı oluştur
                    </button>
                @else
                    <button type="button" wire:click="openAddUser" @disabled(! $ready || $userDatabases->isEmpty()) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                        <x-icon name="plus" />Kullanıcı ekle
                    </button>
                @endif
                <label class="relative ml-auto w-full sm:w-64">
                    <span class="sr-only">Ara</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $tab === 'veritabanlari' ? 'Veritabanı ya da site ara' : 'Kullanıcı ara' }}"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>

            @if ($tab === 'veritabanlari')
                <div class="relative overflow-x-auto">
                    <table class="w-full text-[13.5px] sm:min-w-[720px]">
                        <thead>
                            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">Ad</th>
                                <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Site</th>
                                @if (count($installed) > 1)
                                    <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Sunucu</th>
                                @endif
                                <th class="px-3 py-2.5 text-right font-medium">Boyut</th>
                                <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Kullanıcılar</th>
                                <th class="py-2.5 pr-5 pl-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($databases as $db)
                                <tr wire:key="db-{{ $db->id }}" class="hover:bg-surface-2/60">
                                    <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">
                                        <a href="{{ route('databases.show', $db) }}" wire:navigate class="hover:text-accent">{{ $db->name }}</a>
                                        <span class="mt-0.5 block font-normal text-[12.5px] text-muted sm:hidden">{{ $db->site?->domain ?? '—' }}</span>
                                    </td>
                                    <td class="hidden px-3 py-3 font-mono text-[13px] whitespace-nowrap text-muted sm:table-cell">{{ $db->site?->domain ?? '—' }}</td>
                                    @if (count($installed) > 1)
                                        <td class="hidden px-3 py-3 whitespace-nowrap text-muted sm:table-cell">{{ $labels[$db->server] ?? $db->serverLabel() }}</td>
                                    @endif
                                    <td class="num px-3 py-3 text-right whitespace-nowrap">{{ $db->measured_at ? Bytes::format($db->size_bytes) : '—' }}</td>
                                    <td class="hidden px-3 py-3 font-mono text-[12.5px] text-muted sm:table-cell">{{ $db->users->pluck('name')->join(', ') ?: '—' }}</td>
                                    <td class="py-3 pr-5 pl-3 text-right">
                                        <a href="{{ route('databases.manager', $db) }}" target="_blank" rel="noopener" aria-label="{{ $db->managerLabel() }}" class="inline-flex items-center gap-1.5 text-[13px] whitespace-nowrap text-accent hover:underline">
                                            <span class="hidden sm:inline">{{ $db->managerLabel() }}</span><x-icon name="external-link" class="size-3.5" />
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center">
                                        @if ($search !== '')
                                            <p class="text-muted">Aramanızla eşleşen veritabanı yok.</p>
                                        @else
                                            <p class="font-medium">Henüz veritabanı yok</p>
                                            <p class="mt-1 text-[13px] text-muted">"Veritabanı oluştur" ile bir site için veritabanı ve kullanıcısını birlikte açın.</p>
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($databases->total() > 0)
                    <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                        <span>{{ $total }}@if ($limit !== null) / {{ $limit }}@endif veritabanı · toplam {{ Bytes::format($totalSize) }}@if ($versions) · {{ implode(' · ', $versions) }}@endif</span>
                        @if ($databases->hasPages())
                            <span class="ml-auto flex items-center gap-1">
                                <button type="button" wire:click="previousPage('vt')" @disabled($databases->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Önceki</button>
                                <span class="px-2">Sayfa {{ $databases->currentPage() }} / {{ $databases->lastPage() }}</span>
                                <button type="button" wire:click="nextPage('vt')" @disabled(! $databases->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Sonraki</button>
                            </span>
                        @endif
                    </div>
                @endif
            @else
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[680px] text-[13.5px]">
                        <thead>
                            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">Kullanıcı</th>
                                <th class="px-3 py-2.5 font-medium">Bağlantı</th>
                                <th class="px-3 py-2.5 font-medium">Veritabanları</th>
                                <th class="py-2.5 pr-5 pl-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($users as $u)
                                <tr wire:key="dbu-{{ $u->id }}" class="hover:bg-surface-2/60">
                                    <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">{{ $u->name }}</td>
                                    <td @class(['px-3 py-3 text-[12.5px]', 'font-mono' => $u->hosts !== [], 'text-muted' => $u->hosts === []])>{{ $u->hostsLabel() }}</td>
                                    <td class="px-3 py-3 text-[12.5px]">
                                        @forelse ($u->databases as $d)
                                            <span class="mr-2 inline-block whitespace-nowrap"><span class="font-mono">{{ $d->name }}</span> <span class="text-muted">({{ DatabaseUser::accessLabel($d->pivot->access) }})</span></span>
                                        @empty
                                            <span class="text-muted">Erişimi yok</span>
                                        @endforelse
                                    </td>
                                    <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                        <button type="button" wire:click="openUser({{ $u->id }})" class="text-[13px] text-accent hover:underline">Düzenle</button>
                                        <button type="button" wire:click="deleteUser({{ $u->id }})" class="ml-3 text-[13px] text-muted hover:text-crit">Sil</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-12 text-center text-muted">{{ $search !== '' ? 'Aramanızla eşleşen kullanıcı yok.' : 'Henüz veritabanı kullanıcısı yok.' }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($users->hasPages())
                    <div class="num flex items-center justify-end gap-1 border-t border-line px-5 py-3 text-2xs text-muted">
                        <button type="button" wire:click="previousPage('ku')" @disabled($users->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Önceki</button>
                        <span class="px-2">Sayfa {{ $users->currentPage() }} / {{ $users->lastPage() }}</span>
                        <button type="button" wire:click="nextPage('ku')" @disabled(! $users->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Sonraki</button>
                    </div>
                @endif
            @endif
        </section>
    @endif

    {{-- Veritabanı oluştur --}}
    <x-modal title="Veritabanı oluştur" show="creating">
        <form wire:submit="create">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Veritabanı oluştur</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="grid gap-4 {{ count($installed) > 1 ? 'sm:grid-cols-[minmax(0,1fr)_10rem]' : '' }}">
                    <div>
                        <label for="db-site" class="mb-1.5 block">Site</label>
                        <select id="db-site" wire:model.live="formSite" class="{{ $field }} border-line font-mono">
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}">{{ $s->domain }}</option>
                            @endforeach
                        </select>
                        @error('formSite') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    @if (count($installed) > 1)
                        <div>
                            <label for="db-server" class="mb-1.5 block">Sunucu</label>
                            <select id="db-server" wire:model="formServer" class="{{ $field }} border-line">
                                @foreach ($installed as $srv)
                                    <option value="{{ $srv }}">{{ $labels[$srv] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div>
                    <label for="db-name" class="mb-1.5 block">Veritabanı adı</label>
                    <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('dbSuffix') ? 'border-crit' : 'border-line' }}">
                        <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ $prefix }}</span>
                        <input id="db-name" wire:model="dbSuffix" required placeholder="ad" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                    </div>
                    @error('dbSuffix') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="withUser" class="size-4 accent-accent">
                    <span>Bu veritabanı için yeni kullanıcı oluştur</span>
                </label>
                @if ($withUser)
                    <div>
                        <label for="db-user" class="mb-1.5 block">Kullanıcı adı</label>
                        <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('userSuffix') ? 'border-crit' : 'border-line' }}">
                            <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ $prefix }}</span>
                            <input id="db-user" wire:model="userSuffix" required placeholder="kullanıcı" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                        </div>
                        @error('userSuffix') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="db-pass" class="mb-1.5 block">Parola</label>
                        <div class="flex gap-2">
                            <input id="db-pass" wire:model="password" autocomplete="new-password" spellcheck="false" class="{{ $field }} min-w-0 flex-1 font-mono {{ $errors->has('password') ? 'border-crit' : 'border-line' }}">
                            <button type="button" wire:click="newPassword('password')" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">Yeni oluştur</button>
                        </div>
                        @error('password')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Oluşturduktan sonra bir kez gösterilir; Servik parolayı saklamaz.</p>
                        @enderror
                    </div>
                    <div>
                        <label for="db-access" class="mb-1.5 block">Yetki</label>
                        <select id="db-access" wire:model="access" class="{{ $field }} border-line">
                            @foreach (DatabaseUser::ACCESS as $key => [$label, $hint])
                                <option value="{{ $key }}">{{ $label }} — {{ $hint }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="create" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Oluştur</button>
            </div>
        </form>
    </x-modal>

    {{-- Kullanıcı ekle --}}
    <x-modal title="Kullanıcı ekle" show="addingUser">
        <form wire:submit="addUser">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Kullanıcı ekle</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="u-db" class="mb-1.5 block">Veritabanı</label>
                        <select id="u-db" wire:model.live="userDatabase" class="{{ $field }} border-line font-mono">
                            @foreach ($userDatabases as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="u-access" class="mb-1.5 block">Yetki</label>
                        <select id="u-access" wire:model="newUserAccess" class="{{ $field }} border-line">
                            @foreach (DatabaseUser::ACCESS as $key => [$label])
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label for="u-name" class="mb-1.5 block">Kullanıcı adı</label>
                    <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('newUserSuffix') ? 'border-crit' : 'border-line' }}">
                        <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ $userPrefix }}</span>
                        <input id="u-name" wire:model="newUserSuffix" required placeholder="rapor" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                    </div>
                    @error('newUserSuffix') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    @error('userDatabase') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-pass" class="mb-1.5 block">Parola</label>
                    <div class="flex gap-2">
                        <input id="u-pass" wire:model="newUserPassword" autocomplete="new-password" spellcheck="false" class="{{ $field }} min-w-0 flex-1 font-mono {{ $errors->has('newUserPassword') ? 'border-crit' : 'border-line' }}">
                        <button type="button" wire:click="newPassword('newUserPassword')" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">Yeni oluştur</button>
                    </div>
                    @error('newUserPassword') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('addingUser', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="addUser" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Kullanıcıyı ekle</button>
            </div>
        </form>
    </x-modal>

    @include('livewire.databases.user-dialog')
    @include('livewire.databases.credentials')
</div>
