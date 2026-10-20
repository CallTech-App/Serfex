@php
    use App\Models\Package;
    use App\Services\Customers\Quotas;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $tone = ['ok' => '', 'warn' => 'font-medium text-warn', 'crit' => 'font-medium text-crit'];
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Müşteriler ve Paketler') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Müşteri hesaplarını ve barındırma paketlerini yönetin.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (['musteriler' => __('Müşteriler'), 'paketler' => __('Paketler')] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'musteriler')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openCreate" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />{{ __('Müşteri ekle') }}
                </button>
                <button type="button" wire:click="suspendSelected" wire:confirm="{{ __('Seçili müşterilerin siteleri ve postası durdurulsun mu? Verileri silinmez.') }}" @disabled($selected === [])
                        class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                    {{ __('Askıya al:selected', ['selected' => $selected ? ' ('.count($selected).')' : '']) }}
                </button>
                <label class="relative ml-auto w-full sm:w-64">
                    <span class="sr-only">{{ __('Müşteri ara') }}</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Müşteri ara') }}"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="w-10 py-2.5 pl-5"><span class="sr-only">{{ __('Seç') }}</span></th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Müşteri') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Paket') }}</th>
                            <th class="px-3 py-2.5 text-right font-medium">{{ __('Site') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Disk') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                            <th class="py-2.5 pr-5 pl-3"><span class="sr-only">{{ __('İşlemler') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($customers as $customer)
                            @php
                                $report = $reports[$customer->id];
                                $worst = Quotas::worst($report);
                            @endphp
                            <tr wire:key="customer-{{ $customer->id }}" @class(['hover:bg-surface-2/60', 'opacity-60' => $customer->trashed()])>
                                <td class="py-3 pl-5">
                                    @unless ($customer->trashed())
                                        <input type="checkbox" wire:model.live="selected" value="{{ $customer->id }}" aria-label="{{ __(':customer seç', ['customer' => $customer->displayName()]) }}" class="size-4 accent-accent">
                                    @endunless
                                </td>
                                <td class="px-3 py-3">
                                    @if ($customer->trashed())
                                        <span class="font-medium">{{ $customer->displayName() }}</span>
                                    @else
                                        <a href="{{ route('customers.show', $customer) }}" wire:navigate class="font-medium hover:text-accent">{{ $customer->displayName() }}</a>
                                    @endif
                                    <span class="block text-[12.5px] text-muted">{{ $customer->company ? $customer->name.' · ' : '' }}<span class="font-mono">{{ $customer->username }}</span></span>
                                </td>
                                <td class="px-3 py-3">{{ $customer->package->name }}</td>
                                <td class="num px-3 py-3 text-right {{ $tone[$report['sites']['level']] }}">{{ $report['sites']['text'] }}</td>
                                <td class="num px-3 py-3 whitespace-nowrap {{ $tone[$report['disk']['level']] }}">
                                    {{ $report['disk']['text'] }}
                                    @if ($worst !== 'ok' && $report['disk']['level'] === 'ok')
                                        <x-icon name="triangle-alert" @class(['ml-1 inline size-3.5 align-[-2px]', 'text-warn' => $worst === 'warn', 'text-crit' => $worst === 'crit']) title="{{ __('Başka bir sınıra yaklaştı ya da aştı') }}" />
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <x-customer-status :customer="$customer" />
                                </td>
                                <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    @if ($customer->trashed())
                                        <button type="button" wire:click="restore({{ $customer->id }})" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-line px-2.5 text-[13px] hover:bg-surface-2">
                                            <x-icon name="rotate-ccw" class="size-3.5" />{{ __('Geri al') }}
                                        </button>
                                    @elseif ($customer->user)
                                        <form method="POST" action="{{ route('customers.impersonate', $customer) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[13px] text-muted hover:bg-surface-2 hover:text-fg" title="{{ __('Paneli bu müşterinin gördüğü gibi açın') }}">
                                                <x-icon name="log-in" class="size-3.5" />{{ __('Giriş yap') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center">
                                    @if ($search !== '')
                                        <p class="text-muted">{{ __('Aramanızla eşleşen müşteri yok.') }}</p>
                                    @else
                                        <p class="font-medium">{{ __('Henüz müşteri yok') }}</p>
                                        <p class="mt-1 text-[13px] text-muted">{{ __('Müşteri ekleyin; siteleri onun hesabında açın ya da mevcut siteleri ona bağlayın. Müşteri yalnızca kendi sitelerini görür.') }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($customers->total() > 0)
                <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                    <span>{{ __(':customers–:customers2 / :customers3 müşteri', ['customers' => $customers->firstItem(), 'customers2' => $customers->lastItem(), 'customers3' => $customers->total()]) }}</span>
                    @if ($customers->hasPages())
                        <span class="ml-auto flex items-center gap-1">
                            <button type="button" wire:click="previousPage" @disabled($customers->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
                            <span class="px-2">{{ __('Sayfa :customers / :customers2', ['customers' => $customers->currentPage(), 'customers2' => $customers->lastPage()]) }}</span>
                            <button type="button" wire:click="nextPage" @disabled(! $customers->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
                        </span>
                    @endif
                </div>
            @endif
        @else
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openPackage" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />{{ __('Paket ekle') }}
                </button>
                <p class="ml-auto text-[13px] text-muted">{{ __('Paketteki değişiklik o paketi kullanan tüm müşterilere hemen uygulanır.') }}</p>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[900px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Paket') }}</th>
                            @foreach (Package::LIMITS as [, $label])
                                <th class="px-3 py-2.5 text-right font-medium">{{ ['Laravel uygulaması' => 'Laravel', 'Ek alan adı ve yönlendirme' => __('Ek alan adı')][$label] ?? __($label) }}</th>
                            @endforeach
                            <th class="px-3 py-2.5 font-medium">{{ __('Özellikler') }}</th>
                            <th class="px-3 py-2.5 text-right font-medium">{{ __('Müşteri') }}</th>
                            <th class="py-2.5 pr-5 pl-3"><span class="sr-only">{{ __('İşlemler') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($packages as $package)
                            <tr wire:key="package-{{ $package->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5">
                                    <button type="button" wire:click="openPackage({{ $package->id }})" class="font-medium hover:text-accent">{{ $package->name }}</button>
                                    @if ($package->is_default)<span class="ml-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted">{{ __('Varsayılan') }}</span>@endif
                                </td>
                                @foreach (array_keys(Package::LIMITS) as $key)
                                    @php $limit = $package->limit($key); @endphp
                                    <td class="num px-3 py-3 text-right whitespace-nowrap">
                                        @if ($limit === null)
                                            <span class="text-muted">{{ __('Sınırsız') }}</span>
                                        @elseif (in_array($key, ['disk', 'traffic'], true))
                                            {{ \App\Support\Bytes::format($limit) }}
                                        @else
                                            {{ \App\Support\Num::format($limit, 0) }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-[12.5px] text-muted">
                                    @forelse (collect(Package::FEATURES)->filter(fn ($f) => $package->{$f[0]})->values() as $i => $f)
                                        <span class="whitespace-nowrap">{{ $f[1] }}{{ $loop->last ? '' : ',' }}</span>
                                    @empty
                                        {{ __('Yalnızca site') }}
                                    @endforelse
                                    @if ($package->php_versions !== null)
                                        <span class="block">PHP {{ implode(', ', $package->php_versions) }}</span>
                                    @endif
                                </td>
                                <td class="num px-3 py-3 text-right">{{ $package->customers_count }}</td>
                                <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="openPackage({{ $package->id }})" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __(':name paketini düzenle', ['name' => $package->name]) }}" title="{{ __('Düzenle') }}" style="display:inline-grid">
                                        <x-icon name="pencil" />
                                    </button>
                                    <button type="button" wire:click="deletePackage({{ $package->id }})" @disabled($package->customers_count > 0 || $package->is_default)
                                            class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-crit disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-muted" style="display:inline-grid"
                                            aria-label="{{ __(':name paketini sil', ['name' => $package->name]) }}" title="{{ $package->is_default ? __('Varsayılan paket silinemez') : ($package->customers_count ? __('Paketi kullanan müşteriler var') : __('Sil')) }}">
                                        <x-icon name="trash-2" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Müşteri ekle --}}
    <x-modal title="{{ __('Müşteri ekle') }}" show="creating">
        <form wire:submit="create" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Müşteri ekle') }}</h2>
            <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="company" class="mb-1.5 block"><x-t text="Firma adı :part"><x-slot:part><span class="text-muted">{{ __('(isteğe bağlı)') }}</span></x-slot:part></x-t></label>
                        <input id="company" wire:model.live.debounce.400ms="company" autocomplete="organization" class="{{ $field }} {{ $errors->has('company') ? 'border-crit' : 'border-line' }}">
                        @error('company') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="name" class="mb-1.5 block">{{ __('Yetkili kişi') }}</label>
                        <input id="name" wire:model.live.debounce.400ms="name" required autocomplete="name" class="{{ $field }} {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                        @error('name') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email" class="mb-1.5 block">{{ __('E-posta') }}</label>
                        <input id="email" type="email" wire:model="email" required autocomplete="email" class="{{ $field }} {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                        @error('email') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="phone" class="mb-1.5 block"><x-t text="Telefon :part"><x-slot:part><span class="text-muted">{{ __('(isteğe bağlı)') }}</span></x-slot:part></x-t></label>
                        <input id="phone" type="tel" wire:model="phone" autocomplete="tel" class="{{ $field }} {{ $errors->has('phone') ? 'border-crit' : 'border-line' }}">
                        @error('phone') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="username" class="mb-1.5 block">{{ __('Sistem kullanıcısı') }}</label>
                        <input id="username" wire:model.blur="username" required autocomplete="off" spellcheck="false" class="{{ $field }} font-mono text-[13px] {{ $errors->has('username') ? 'border-crit' : 'border-line' }}">
                        @error('username') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @else <p class="mt-1.5 text-2xs text-muted">{{ __('Siteleri /home/:username altında açılır.', ['username' => $username ?: 'kullanici']) }}</p> @enderror
                    </div>
                    <div>
                        <label for="package" class="mb-1.5 block">{{ __('Paket') }}</label>
                        <select id="package" wire:model="packageId" class="{{ $field }} border-line px-2.5">
                            @foreach ($packages as $package)
                                <option value="{{ $package->id }}">{{ $package->name }}</option>
                            @endforeach
                        </select>
                        @error('packageId') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-2xs text-muted">{{ __('Müşteri eklendikten sonra bir giriş bağlantısı gösterilir. Bağlantıyı müşteriye iletin; şifresini kendisi belirler.') }}</p>
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="create" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Müşteriyi ekle') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Davet bağlantısı --}}
    @if ($inviteLink)
        @include('livewire.customers.invite', ['link' => $inviteLink, 'close' => 'closeInvite', 'next' => $invitedId ? route('customers.show', $invitedId) : null])
    @endif

    {{-- Paket ekle / düzenle --}}
    <x-modal title="{{ __('Paket') }}" show="editingPackage" size="max-w-2xl">
        <form wire:submit="savePackage" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $packageEditId ? __('Paketi düzenle') : __('Paket ekle') }}</h2>
            <div class="space-y-5 overflow-y-auto p-5 text-[13.5px]">
                <div>
                    <label for="pkgName" class="mb-1.5 block">{{ __('Paket adı') }}</label>
                    <input id="pkgName" wire:model="pkgName" required class="{{ $field }} sm:w-72 {{ $errors->has('pkgName') ? 'border-crit' : 'border-line' }}">
                    @error('pkgName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <fieldset>
                    <legend class="mb-2 font-medium">{{ __('Sınırlar') }}</legend>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach (Package::LIMITS as $key => [, $label, $unit])
                            <div>
                                <label for="limit-{{ $key }}" class="mb-1.5 block text-[13px]">{{ $label }}@if ($unit) <span class="text-muted">({{ $unit }})</span>@endif</label>
                                <input id="limit-{{ $key }}" wire:model="limits.{{ $key }}" inputmode="decimal" placeholder="{{ __('Sınırsız') }}" class="{{ $field }} num {{ $errors->has('limits.'.$key) ? 'border-crit' : 'border-line' }}">
                                @error('limits.'.$key) <p class="mt-1 text-[12.5px] text-crit">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-2xs text-muted">{{ __('Boş bırakılan alan sınırsız sayılır. Veritabanlarının boyutu disk kullanımına eklenir.') }}</p>
                </fieldset>
                <fieldset>
                    <legend class="mb-2 font-medium">{{ __('Özellikler') }}</legend>
                    <div class="space-y-2.5">
                        @foreach (Package::FEATURES as $key => [, $label, $help])
                            <label class="flex items-start gap-2.5">
                                <input type="checkbox" wire:model.live="features" value="{{ $key }}" class="mt-0.5 size-4 accent-accent" @disabled($key === 'shell' && ! in_array('laravel', $features, true))>
                                <span><span class="block">{{ $label }}</span><span class="block text-[12.5px] text-muted">{{ $help }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset>
                    <legend class="mb-2 font-medium">{{ __('PHP sürümleri') }}</legend>
                    <label class="flex items-center gap-2.5">
                        <input type="checkbox" wire:model.live="allPhp" class="size-4 accent-accent">{{ __('Kurulu tüm sürümler') }}
                    </label>
                    @unless ($allPhp)
                        <div class="mt-2.5 flex flex-wrap gap-x-5 gap-y-2 pl-6">
                            @forelse ($installedPhp as $version)
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="pkgPhp" value="{{ $version }}" class="size-4 accent-accent"><span class="font-mono text-[13px]">PHP {{ $version }}</span></label>
                            @empty
                                <p class="text-[13px] text-muted">{{ __('Kurulu PHP sürümleri okunamadı.') }}</p>
                            @endforelse
                        </div>
                    @endunless
                    @error('pkgPhp') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-2 text-2xs text-muted">{{ __('Yeni sitelerde ve Laravel kurulumlarında yalnızca bu sürümler sunulur; mevcut siteler değişmez.') }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <label for="pkg-php-memory" class="text-[13px]">{{ __('PHP bellek sınırı en fazla') }}</label>
                        <input id="pkg-php-memory" type="text" inputmode="numeric" wire:model="pkgPhpMemory" placeholder="{{ __('sınırsız') }}" class="h-8 w-24 rounded-md border border-line bg-surface px-2 font-mono text-[13px]">
                        <span class="text-[13px] text-muted">MB</span>
                    </div>
                    @error('pkgPhpMemory') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-1 text-2xs text-muted">{{ __('Sitelerin memory_limit değeri bunu aşamaz; site kodu da yükseltemez.') }}</p>
                </fieldset>
                <label class="flex items-center gap-2.5">
                    <input type="checkbox" wire:model="pkgDefault" class="size-4 accent-accent">{{ __('Yeni müşterilerde varsayılan paket') }}
                </label>
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editingPackage', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="savePackage" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Paketi kaydet') }}</button>
            </div>
        </form>
    </x-modal>
</div>
