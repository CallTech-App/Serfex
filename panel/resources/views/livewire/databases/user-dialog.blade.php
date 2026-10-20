{{-- One database user: password, where it may connect from, which databases it may use. --}}
@php
    use App\Models\DatabaseUser;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
@endphp
<x-modal :title="__('Kullanıcıyı düzenle')" show="userDialog" size="max-w-xl">
    @if ($editUser)
        <form wire:submit="saveUser">
            <h2 class="flex h-12 items-center gap-2 border-b border-line px-5 text-[14.5px] font-semibold">
                <span class="truncate font-mono">{{ $editUser->name }}</span>
            </h2>
            <div class="max-h-[70vh] space-y-5 overflow-y-auto p-5 text-[13.5px]">
                <fieldset>
                    <legend class="mb-2 font-medium">{{ __('Parola') }}</legend>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="changePassword" class="size-4 accent-accent">
                        <span>{{ __('Parolayı değiştir') }}</span>
                    </label>
                    @if ($changePassword)
                        <div class="mt-2 flex gap-2">
                            <input wire:model="editPassword" aria-label="{{ __('Yeni parola') }}" autocomplete="new-password" spellcheck="false" class="{{ $field }} min-w-0 flex-1 font-mono {{ $errors->has('editPassword') ? 'border-crit' : 'border-line' }}">
                            <button type="button" wire:click="newPassword('editPassword')" class="h-9 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">{{ __('Yeni oluştur') }}</button>
                        </div>
                        @error('editPassword')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Bu kullanıcıyla bağlanan uygulamaların ayarlarını da güncelleyin.') }}</p>
                        @enderror
                    @endif
                </fieldset>

                <fieldset>
                    <legend class="mb-2 font-medium">{{ __('Veritabanları') }}</legend>
                    @if ($grantable->isEmpty())
                        <p class="text-[13px] text-muted">{{ __('Bu hesabın başka veritabanı yok.') }}</p>
                    @else
                        <div class="divide-y divide-line rounded-lg border border-line">
                            @foreach ($grantable as $d)
                                <div wire:key="grant-{{ $d->id }}" class="flex items-center gap-3 px-3 py-2">
                                    <span class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $d->name }}</span>
                                    <select wire:model="editGrants.{{ $d->id }}" aria-label="{{ __(':name yetkisi', ['name' => $d->name]) }}" class="h-8 rounded-md border border-line bg-surface px-2 text-[13px]">
                                        <option value="">{{ __('Erişim yok') }}</option>
                                        @foreach (DatabaseUser::ACCESS as $key => [$label])
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </fieldset>

                <fieldset>
                    <legend class="mb-2 font-medium">{{ __('Uzak bağlantı') }}</legend>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" wire:model.live="remote" class="mt-0.5 size-4 accent-accent">
                        <span>{{ __('Başka makinelerden bağlanabilsin') }}<span class="block text-[12.5px] text-muted">{{ __('Kapalıyken yalnızca bu sunucudaki siteler bağlanır. Açıkken veritabanı sunucusu dışarıyı dinler; yalnızca yazdığınız adreslere izin verilir.') }}</span></span>
                    </label>
                    @if ($remote)
                        <textarea wire:model="hostsText" rows="3" aria-label="{{ __('İzin verilen adresler') }}" placeholder="78.186.44.12&#10;10.8.0.%&#10;88.247.120.0/28" spellcheck="false"
                                  class="mt-2 w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('hostsText') ? 'border-crit' : 'border-line' }}"></textarea>
                        @error('hostsText')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Her satıra bir adres: IP adresi, ağ (88.247.120.0/28), önek (10.8.0.%) ya da her yerden için %.') }}</p>
                        @enderror
                    @endif
                    @error('hostsText')
                        @if (! $remote) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @endif
                    @enderror
                </fieldset>
            </div>
            <div class="flex items-center gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="deleteUser({{ $editUser->id }})" class="h-9 rounded-md px-2 text-[13.5px] text-muted hover:text-crit">{{ __('Kullanıcıyı sil') }}</button>
                <button type="button" wire:click="$set('userDialog', false)" class="ml-auto h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="saveUser" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Kaydet') }}</button>
            </div>
        </form>
    @endif
</x-modal>
