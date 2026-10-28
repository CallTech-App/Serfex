@php
    use App\Models\AppTemplate;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $chip = 'flex h-8 cursor-pointer items-center gap-2 rounded-md border border-line px-3 text-[13px] has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40';
    $check = 'size-4 accent-[rgb(var(--accent))]';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $icons = ['github' => 'github', 'gitlab' => 'gitlab', 'bitbucket' => 'git-branch', 'other' => 'link'];
@endphp
<x-settings-page current="settings.templates" :title="__('Uygulama şablonları')" :description="__('Kendi Laravel uygulamanızı bir kez tanımlayın, sonra herhangi bir siteye tek tıkla kurun ve yeni sürümünü bağlı sitelere dağıtın. Şablonlar yalnızca bu sunucuda, panelin veritabanında durur.')">
    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openAdd" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="plus" />{{ __('Şablon ekle') }}</button>
            <button type="button" wire:click="openImport" class="{{ $btn }}"><x-icon name="upload" />{{ __('İçe aktar') }}</button>
        </div>
        @if ($templates->isEmpty())
            <div class="px-5 py-10 text-center text-[13.5px]">
                <p class="font-medium">{{ __('Henüz şablon yok.') }}</p>
                <p class="mx-auto mt-1 max-w-md text-[13px] text-muted">{{ __('Kendi uygulamanızın Git deposunu ya da sürüm paketini ekleyin; veritabanını, .env değerlerini ve kurulum adımlarını bir kez ayarlayın.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Şablon') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Sürüm') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Siteler') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Kimler kurabilir') }}</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($templates as $t)
                            <tr class="hover:bg-surface-2/60" wire:key="tpl-{{ $t->id }}">
                                <td class="py-3 pr-3 pl-5">
                                    <a href="{{ route('settings.templates.show', $t) }}" wire:navigate class="font-medium hover:text-accent">{{ $t->name }}</a>
                                    <span class="mt-0.5 flex items-center gap-1.5 text-2xs text-muted"><x-icon :name="$icons[$t->provider] ?? 'link'" class="size-3" /><span class="font-mono">{{ $t->repositoryLabel() }}</span>@if ($t->isPackage()) · {{ __('sürüm paketi') }}@endif</span>
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    @if ($t->isPackage())
                                        <span class="font-mono">{{ $t->latest_version ?? '—' }}</span>
                                    @elseif ($t->ref_type === 'tag')
                                        <span class="text-muted">{{ __('etiket') }}</span> <span class="font-mono">{{ $t->ref }}</span>
                                    @else
                                        <span class="text-muted">{{ __('dal') }}</span> <span class="font-mono">{{ $t->ref }}</span> @if ($t->latest_commit)<span class="font-mono text-muted">{{ substr($t->latest_commit, 0, 7) }}</span>@endif
                                    @endif
                                    @if ($t->check_error)<span class="ml-1 text-crit" title="{{ $t->check_error }}"><x-icon name="triangle-alert" class="inline size-3.5" /></span>@endif
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <span class="num">{{ $t->apps_count }}</span>
                                    @if ($t->apps_count > 0)
                                        @if ($behind[$t->id] > 0)
                                            <span class="ml-1.5 inline-flex items-center gap-1.5 rounded-full bg-warn-soft px-2 py-0.5 text-2xs font-medium text-warn"><i class="size-1.5 rounded-full bg-warn"></i>{{ trans_choice(':count güncel değil', $behind[$t->id]) }}</span>
                                        @elseif (! $t->isPackage())
                                            <span class="ml-1.5 inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-2xs font-medium text-ok"><i class="size-1.5 rounded-full bg-ok"></i>{{ __('Hepsi güncel') }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap {{ $t->customers_can_install ? '' : 'text-muted' }}">{{ $t->customers_can_install ? __('Yöneticiler ve müşteriler') : __('Yalnızca yöneticiler') }}</td>
                                <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap"><a href="{{ route('settings.templates.show', $t) }}" wire:navigate class="text-[13px] text-accent hover:underline">{{ __('Aç') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="border-t border-line px-5 py-3 text-2xs text-muted">
            <x-t text="Kurulum: :site ya da :laravel. Her site kendi kodunu, veritabanını ve :env dosyasını alır."><x-slot:site><span class="text-fg">{{ __('Siteler → Site ekle → Uygulama') }}</span></x-slot:site><x-slot:laravel><span class="text-fg">{{ __('Laravel → Laravel uygulaması kur → Şablondan') }}</span></x-slot:laravel><x-slot:env><span class="font-mono">.env</span></x-slot:env></x-t>
        </div>
    </section>

    {{-- Şablon ekle --}}
    <x-modal :title="__('Şablon ekle')" show="adding">
        <form wire:submit="add" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Şablon ekle') }}</h2>
            <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
                <div>
                    <label for="tpl-name" class="mb-1.5 block">{{ __('Ad') }}</label>
                    <input id="tpl-name" wire:model="name" maxlength="60" placeholder="{{ __('Mağaza') }}" class="{{ $field }} {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                    @error('name')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
                <div>
                    <p class="mb-1.5">{{ __('Kaynak') }}</p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-line p-3 has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40"><input type="radio" wire:model.live="source" value="git" class="mt-0.5 {{ $check }}"><span><span class="font-medium">{{ __('Git deposu') }}</span><span class="block text-2xs text-muted">{{ __('Serfex kurar ve günceller') }}</span></span></label>
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-line p-3 has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40"><input type="radio" wire:model.live="source" value="package" class="mt-0.5 {{ $check }}"><span><span class="font-medium">{{ __('Sürüm paketi') }}</span><span class="block text-2xs text-muted">{{ __('Uygulama kendini günceller') }}</span></span></label>
                    </div>
                </div>
                <div>
                    <p class="mb-1.5">{{ $source === 'package' ? __('Paketin geldiği yer') : __('Git deposu') }}</p>
                    <div class="mb-2 flex flex-wrap gap-2">
                        @foreach (AppTemplate::PROVIDERS as $key => $label)
                            @continue($source === 'package' && $key !== 'github' && $key !== 'other')
                            <label class="{{ $chip }}"><input type="radio" wire:model.live="provider" value="{{ $key }}" class="{{ $check }}">{{ $source === 'package' && $key === 'other' ? __('Adres') : __($label) }}</label>
                        @endforeach
                    </div>
                    @if ($source === 'package' && $provider === 'other')
                        <input wire:model="packageUrl" aria-label="{{ __('Paket adresi') }}" spellcheck="false" placeholder="https://indir.ornek.com/uygulama-latest.zip" class="{{ $field }} font-mono {{ $errors->has('packageUrl') ? 'border-crit' : 'border-line' }}">
                        @error('packageUrl')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Her kurulumda bu adresteki zip indirilir; sürümü kurulduktan sonra uygulamanın VERSION dosyasından okunur.') }}</p>@enderror
                    @elseif ($picker)
                        @include('livewire.partials.git-picker', ['pickerBranch' => $source === 'git' && $refType === 'branch', 'pickerNote' => __('Serfex her kurulumda depoya o siteye ait, salt okunur bir dağıtım anahtarı ekler.')])
                        <button type="button" wire:click="$set('typeAddress', true)" class="mt-2 text-[13px] text-accent hover:underline">{{ __('Depo adresini elle yaz') }}</button>
                    @else
                        <input wire:model="repository" aria-label="{{ __('Depo') }}" autocomplete="off" spellcheck="false"
                               placeholder="{{ $provider === 'other' ? 'https://git.ornek.com/ekip/uygulama.git' : __('kullanıcı/depo veya depo adresi') }}"
                               class="{{ $field }} font-mono {{ $errors->has('repository') ? 'border-crit' : 'border-line' }}">
                        @error('repository')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                        @if ($source === 'git')
                            <label class="mt-2 flex items-center gap-2.5"><input type="checkbox" wire:model="private" class="{{ $check }}">{{ __('Özel depo') }} <span class="text-2xs text-muted">{{ __('· her kuruluma bir dağıtım anahtarı, siz depoya eklersiniz') }}</span></label>
                        @else
                            <p class="mt-1 text-2xs text-muted">{{ __('Herkese açık bir depo. Özel bir depo için GitHub hesabınızı bağlayıp listeden seçin.') }}</p>
                        @endif
                        @if ($typeAddress && isset($hosts[$provider]))
                            <button type="button" wire:click="$set('typeAddress', false)" class="mt-2 text-[13px] text-accent hover:underline">{{ __(':provider listesinden seç', ['provider' => $hosts[$provider]]) }}</button>
                        @endif
                    @endif
                </div>
                @if ($source === 'git')
                    <div>
                        <p class="mb-1.5">{{ __('Sürüm') }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="{{ $chip }}"><input type="radio" wire:model.live="refType" value="branch" class="{{ $check }}">{{ __('Dal') }}</label>
                            <label class="{{ $chip }}"><input type="radio" wire:model.live="refType" value="tag" class="{{ $check }}">{{ __('Etiket') }}</label>
                            @if (! $picker || $refType === 'tag' || $pickedRepo === '')
                                <input wire:model="branch" aria-label="{{ $refType === 'tag' ? __('Etiket') : __('Dal') }}" spellcheck="false" placeholder="{{ $refType === 'tag' ? 'v1.0.0' : 'main' }}" class="h-9 w-40 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
                            @endif
                        </div>
                        @error('branch')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ $refType === 'tag' ? __('Yeni kurulumlar bu etiketle yapılır. Yeni bir sürüm çıkınca etiketi şablonun sayfasında değiştirirsiniz.') : __('Dala gelen her yeni commit yeni sürüm sayılır; siteler siz güncelleyene kadar değişmez.') }}</p>@enderror
                    </div>
                @elseif ($provider !== 'other')
                    <div>
                        <label for="tpl-asset" class="mb-1.5 block">{{ __('Paket dosyası') }}</label>
                        <input id="tpl-asset" wire:model="packageAsset" spellcheck="false" placeholder="uygulama-*.zip" class="h-9 w-56 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('packageAsset') ? 'border-crit' : 'border-line' }}">
                        @error('packageAsset')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Deponun en son yayımlanan sürümüne (Releases) eklenmiş, bu ada uyan zip indirilir. Paket vendor/ ve derlenmiş ön yüzle birlikte hazır olmalı.') }}</p>@enderror
                    </div>
                @endif
                <p class="text-2xs text-muted">{{ __('Veritabanı, .env, kurulum adımları gibi ayarları bir sonraki sayfada yaparsınız. Şablon henüz hiçbir siteye dokunmaz.') }}</p>
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('adding', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="add" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Şablonu oluştur') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- İçe aktar --}}
    <x-modal :title="__('Şablonu içe aktar')" show="importing">
        <form wire:submit="import">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Şablonu içe aktar') }}</h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                <p class="text-muted">{{ __('Başka bir Serfex panelinden "Dışa aktar" ile alınan .json dosyasını seçin. Git hesabı dosyada olmaz; özel bir depo için şablonun sayfasında hesabınızı seçersiniz.') }}</p>
                <input type="file" wire:model="file" accept="application/json,.json" class="block w-full text-[13px] file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-surface file:px-3 file:text-[13px] hover:file:bg-surface-2">
                @error('file')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('importing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="import,file" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('İçe aktar') }}</button>
            </div>
        </form>
    </x-modal>
</x-settings-page>
