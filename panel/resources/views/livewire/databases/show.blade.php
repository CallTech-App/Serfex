@php
    use App\Models\DatabaseUser;
    use App\Services\Databases\Databases;
    use App\Support\Bytes;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $deleted = $database->trashed();
    $port = $database->server === 'postgresql' ? 5432 : 3306;
    $row = 'grid gap-1 px-5 py-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-4';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('databases.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Veritabanları') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="font-mono text-fg">{{ $database->name }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $database->name }}</h1>
                <span class="rounded-md border border-line px-2 py-0.5 text-2xs text-muted">{{ $serverLabel }}</span>
            </div>
            @unless ($deleted)
                <a href="{{ route('databases.manager', $database) }}" target="_blank" rel="noopener" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                    <x-icon name="external-link" />{{ $database->server === 'postgresql' ? __('Adminer\'da aç') : __('phpMyAdmin\'de aç') }}
                </a>
            @endunless
        </div>
    </div>

    @include('livewire.databases.server-state')

    @if ($deleted)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
            <x-icon name="loader-circle" class="animate-spin text-info" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Bu veritabanı silindi') }}</p>
                <p class="mt-0.5 text-[13px] text-muted">{{ $database->backup_on_delete
                    ? __('Kullanıcıların erişimi kaldırıldı. Veriler :time sularında yedeği alınarak sunucudan silinir.', ['time' => \App\Support\When::time($database->deleted_at->addSeconds(Databases::UNDO_SECONDS))])
                    : __('Kullanıcıların erişimi kaldırıldı. Veriler :time sularında sunucudan silinir.', ['time' => \App\Support\When::time($database->deleted_at->addSeconds(Databases::UNDO_SECONDS))]) }}</p>
            </div>
            <button type="button" wire:click="undoDelete" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="rotate-ccw" class="size-3.5" />{{ __('Geri al') }}
            </button>
        </div>
    @endif

    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Genel') }}</h2>
        <dl class="divide-y divide-line text-[13.5px]">
            <div class="{{ $row }}"><dt class="text-muted">{{ __('Sunucu') }}</dt><dd>{{ $serverLabel }}</dd></div>
            <div class="{{ $row }}">
                <dt class="text-muted">{{ __('Site') }}</dt>
                <dd class="font-mono">
                    @if ($database->site)
                        <a href="{{ route('sites.show', $database->site) }}" wire:navigate class="hover:text-accent">{{ $database->site->domain }}</a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            @if (auth()->user()->is_admin && $database->customer())
                <div class="{{ $row }}">
                    <dt class="text-muted">{{ __('Müşteri') }}</dt>
                    <dd><a href="{{ route('customers.show', $database->customer()) }}" wire:navigate class="hover:text-accent">{{ $database->customer()->displayName() }}</a></dd>
                </div>
            @endif
            <div class="{{ $row }}">
                <dt class="text-muted">{{ __('Boyut') }}</dt>
                <dd class="num">
                    @if ($database->measured_at)
                        {{ Bytes::format($database->size_bytes) }}@if ($database->tables !== null) {{ __('· :tables tablo', ['tables' => $database->tables]) }} @endif
                        <span class="text-muted">{{ __('· :measured_at ölçüldü', ['measured_at' => $database->measured_at->diffForHumans()]) }}</span>
                    @else
                        <span class="text-muted">{{ __('Henüz ölçülmedi (yarım saatte bir ölçülür)') }}</span>
                    @endif
                </dd>
            </div>
            @if ($apps->isNotEmpty())
                <div class="{{ $row }}">
                    <dt class="text-muted">{{ __('Laravel uygulaması') }}</dt>
                    <dd class="font-mono">
                        @foreach ($apps as $app)
                            <a href="{{ route('laravel.show', $app) }}" wire:navigate class="mr-3 hover:text-accent">{{ $app->site?->domain }}</a>
                        @endforeach
                    </dd>
                </div>
            @endif
            <div class="{{ $row }}">
                <dt class="text-muted">{{ __('Bağlantı') }}</dt>
                <dd>
                    <span class="font-mono">127.0.0.1:{{ $port }}</span>
                    <span class="text-muted">· <x-t text="siteler :host da kullanabilir"><x-slot:host><span class="font-mono">localhost</span></x-slot:host></x-t></span>
                </dd>
            </div>
        </dl>
    </section>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <h2 class="mr-auto text-[14.5px] font-semibold">{{ __('Kullanıcılar') }}</h2>
            <button type="button" wire:click="openAdd" @disabled($deleted) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                <x-icon name="plus" />{{ __('Kullanıcı ekle') }}
            </button>
        </div>
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[600px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Kullanıcı') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Yetki') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Bağlantı') }}</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($database->users as $u)
                        <tr wire:key="u-{{ $u->id }}">
                            <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">{{ $u->name }}</td>
                            <td class="px-3 py-2">
                                <select aria-label="{{ __(':name yetkisi', ['name' => $u->name]) }}" wire:change="setAccess({{ $u->id }}, $event.target.value)" @disabled($deleted) class="h-8 rounded-md border border-line bg-surface px-2 text-[13px] disabled:opacity-40">
                                    @foreach (DatabaseUser::ACCESS as $key => [$label])
                                        <option value="{{ $key }}" @selected($u->pivot->access === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td @class(['px-3 py-3 text-[12.5px]', 'font-mono' => $u->hosts !== [], 'text-muted' => $u->hosts === []])>{{ $u->hostsLabel() }}</td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="openUser({{ $u->id }})" class="text-[13px] text-accent hover:underline">{{ __('Düzenle') }}</button>
                                <button type="button" wire:click="revoke({{ $u->id }})" @disabled($deleted) class="ml-3 text-[13px] text-muted hover:text-crit disabled:opacity-40">{{ __('Erişimi kaldır') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-muted">{{ __('Bu veritabanına bağlanabilen kullanıcı yok. "Kullanıcı ekle" ile bir kullanıcı verin.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @unless ($deleted)
        <section class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface px-5 py-4 text-[13.5px]">
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Veritabanını sil') }}</p>
                <p class="mt-0.5 text-[13px] text-muted">{{ __('Kullanıcıların erişimi hemen kalkar; veriler 10 dakika sonra silinir. O zamana kadar geri alabilirsiniz.') }}</p>
            </div>
            <button type="button" wire:click="openDelete" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] font-medium text-crit hover:bg-crit-soft">{{ __('Sil') }}</button>
        </section>
    @endunless

    {{-- Kullanıcı ekle --}}
    <x-modal :title="__('Kullanıcı ekle')" show="adding">
        <form wire:submit="add">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Kullanıcı ekle') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="flex gap-4">
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="addMode" value="new" class="size-4 accent-accent">{{ __('Yeni kullanıcı') }}</label>
                    <label @class(['flex items-center gap-2', 'opacity-40' => $candidates->isEmpty()])><input type="radio" wire:model.live="addMode" value="existing" @disabled($candidates->isEmpty()) class="size-4 accent-accent">{{ __('Var olan kullanıcı') }}</label>
                </div>
                @if ($addMode === 'new')
                    <div>
                        <label for="a-name" class="mb-1.5 block">{{ __('Kullanıcı adı') }}</label>
                        <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('newUserSuffix') ? 'border-crit' : 'border-line' }}">
                            <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ $prefix }}</span>
                            <input id="a-name" wire:model="newUserSuffix" required placeholder="{{ __('kullanıcı') }}" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                        </div>
                        @error('newUserSuffix') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="a-pass" class="mb-1.5 block">{{ __('Parola') }}</label>
                        <div class="flex gap-2">
                            <input id="a-pass" wire:model="newUserPassword" autocomplete="new-password" spellcheck="false" class="{{ $field }} min-w-0 flex-1 font-mono {{ $errors->has('newUserPassword') ? 'border-crit' : 'border-line' }}">
                            <button type="button" wire:click="newPassword('newUserPassword')" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">{{ __('Yeni oluştur') }}</button>
                        </div>
                        @error('newUserPassword') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="a-user" class="mb-1.5 block">{{ __('Kullanıcı') }}</label>
                        <select id="a-user" wire:model="existingUser" class="{{ $field }} border-line font-mono">
                            @foreach ($candidates as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        @error('existingUser') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="a-access" class="mb-1.5 block">{{ __('Yetki') }}</label>
                    <select id="a-access" wire:model="addAccess" class="{{ $field }} border-line">
                        @foreach (DatabaseUser::ACCESS as $key => [$label, $hint])
                            <option value="{{ $key }}">{{ $label }} — {{ $hint }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('adding', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="add" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Ekle') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Veritabanını sil --}}
    <x-modal :title="__('Veritabanını sil')" show="deleting" size="max-w-md">
        <form wire:submit="delete" class="p-5 text-[13.5px]">
            <p class="font-semibold"><x-t text=":name silinsin mi?"><x-slot:name><span class="font-mono">{{ $database->name }}</span></x-slot:name></x-t></p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Tüm tablolar ve veriler silinir. Kullanıcılar kalır ama bu veritabanına erişemez. 10 dakika içinde geri alabilirsiniz.') }}</p>
            <label class="mt-3 flex items-start gap-2 text-[13px]">
                <input type="checkbox" wire:model="backup" class="mt-0.5 size-4 accent-accent">
                <span>{{ __('Silmeden önce yedek al') }}<span class="block text-muted">{{ __('Sıkıştırılmış döküm sunucuda 30 gün tutulur.') }}</span></span>
            </label>
            <label for="confirmName" class="mt-4 block text-[13px]">{{ __('Onaylamak için veritabanı adını yazın') }}</label>
            <input id="confirmName" wire:model.live="confirmName" autocomplete="off" spellcheck="false" placeholder="{{ $database->name }}"
                   class="mt-1.5 h-9 w-full rounded-md border border-line bg-surface px-3 font-mono text-[13px] outline-none focus:border-crit">
            @error('confirmName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deleting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" @disabled($confirmName !== $database->name) class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit disabled:cursor-not-allowed disabled:opacity-40">{{ __('Veritabanını sil') }}</button>
            </div>
        </form>
    </x-modal>

    @include('livewire.databases.user-dialog')
    @include('livewire.databases.credentials')
</div>
