@php
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $old = $editId ? collect($dirs->firstWhere('id', $editId)?->userNames() ?? []) : collect();
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Parola korumalı klasörler') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Parola korumalı klasörler') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Bu klasörlerdeki sayfalar açılmadan önce tarayıcı kullanıcı adı ve parola sorar. Yönetim panelleri, test sayfaları ve yayına hazır olmayan bölümler için.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openCreate" class="{{ $primary }}"><x-icon name="folder-lock" />{{ __('Klasör koru') }}</button>
        </div>
        <div class="relative overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[560px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Klasör') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Kullanıcılar') }}</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($dirs as $d)
                        <tr wire:key="dir-{{ $d->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5">
                                <a href="{{ ($site->hasHttps() ? 'https://' : 'http://').$site->domain.$d->path }}" target="_blank" rel="noopener" class="font-mono text-[13px] font-medium hover:text-accent">{{ $d->path }}</a>
                                @if ($d->realm)
                                    <span class="mt-0.5 block text-[12.5px] text-muted">{{ $d->realm }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 font-mono text-[13px]">{{ implode(', ', array_slice($d->userNames(), 0, 4)) }}{{ count($d->userNames()) > 4 ? ' +'.(count($d->userNames()) - 4) : '' }}</td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="openEdit({{ $d->id }})" class="text-[13px] text-accent hover:underline">{{ __('Düzenle') }}</button>
                                <button type="button" wire:click="delete({{ $d->id }})" class="ml-3 text-[13px] text-crit hover:underline">{{ __('Korumayı kaldır') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-5 py-12 text-center">
                                <p class="font-medium">{{ __('Korunan klasör yok') }}</p>
                                <p class="mt-1 text-[13px] text-muted"><x-t text="Örneğin :part klasörünü yalnızca parolayı bilenler açsın."><x-slot:part><span class="font-mono">{{ __('/yonetim/') }}</span></x-slot:part></x-t></p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Parolalar yalnızca şifrelenmiş olarak saklanır. Let\'s Encrypt doğrulaması korunan klasörlerden etkilenmez. Site :site ile çalışıyor; koruma Nginx\'te uygulanır.', ['site' => $site->stackLabel()]) }}</p>
    </section>

    <x-modal title="{{ $editId ? __('Korumalı klasörü düzenle') : __('Klasör koru') }}" show="editing">
        <form wire:submit="save">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $editId ? __('Korumalı klasörü düzenle') : __('Klasör koru') }}</h2>
            <div class="grid items-start gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[6rem_minmax(0,1fr)]">
                <label for="p-path" class="sm:pt-2">{{ __('Klasör') }}</label>
                <div>
                    <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('path') ? 'border-crit' : 'border-line' }}">
                        <span class="grid max-w-[55%] place-items-center truncate border-r border-line bg-surface-2 px-3 font-mono text-[12.5px] text-muted">{{ $site->domain }}</span>
                        <input id="p-path" wire:model="path" placeholder="{{ __('/yonetim/') }}" spellcheck="false" autocomplete="off" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                    </div>
                    @error('path') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <label for="p-realm" class="sm:pt-2">{{ __('Başlık') }}</label>
                <div>
                    <input id="p-realm" wire:model="realm" maxlength="60" placeholder="{{ __('isteğe bağlı, örneğin Yönetim') }}" class="{{ $field }} w-full {{ $errors->has('realm') ? 'border-crit' : 'border-line' }}">
                    @error('realm') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <span class="sm:pt-2">{{ __('Kullanıcılar') }}</span>
                <div class="space-y-2">
                    @foreach ($logins as $i => $l)
                        <div wire:key="login-{{ $i }}" class="flex gap-2">
                            <input wire:model="logins.{{ $i }}.name" placeholder="{{ __('kullanıcı adı') }}" autocomplete="off" spellcheck="false" aria-label="{{ __('Kullanıcı adı') }}" class="{{ $field }} border-line min-w-0 flex-1 font-mono text-[13px]">
                            <input type="password" wire:model="logins.{{ $i }}.password" autocomplete="new-password" aria-label="{{ __('Parola') }}"
                                   placeholder="{{ $old->contains($l['name']) ? __('değişmesin') : __('parola') }}" class="{{ $field }} border-line min-w-0 flex-1 text-[13px]">
                            <button type="button" wire:click="removeLogin({{ $i }})" @disabled(count($logins) === 1) class="grid size-9 shrink-0 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" aria-label="{{ __('Kullanıcıyı çıkar') }}"><x-icon name="x" /></button>
                        </div>
                    @endforeach
                    @error('logins') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
                    <button type="button" wire:click="addLogin" class="text-[13px] text-accent hover:underline">{{ __('Kullanıcı ekle') }}</button>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editing', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="submit" class="{{ $primary }}">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>
</div>
