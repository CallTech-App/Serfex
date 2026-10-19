@php
    use App\Models\Package;
    use App\Services\Customers\Quotas;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $tone = ['ok' => '', 'warn' => 'font-medium text-warn', 'crit' => 'font-medium text-crit'];
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">Müşteriler ve Paketler</h1>
        <p class="mt-1 text-[13px] text-muted">Müşteri hesaplarını ve barındırma paketlerini yönetin.</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (['musteriler' => 'Müşteriler', 'paketler' => 'Paketler'] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'musteriler')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openCreate" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />Müşteri ekle
                </button>
                <button type="button" wire:click="suspendSelected" wire:confirm="Seçili müşterilerin siteleri ve postası durdurulsun mu? Verileri silinmez." @disabled($selected === [])
                        class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                    Askıya al{{ $selected ? ' ('.count($selected).')' : '' }}
                </button>
                <label class="relative ml-auto w-full sm:w-64">
                    <span class="sr-only">Müşteri ara</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Müşteri ara"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="w-10 py-2.5 pl-5"><span class="sr-only">Seç</span></th>
                            <th class="px-3 py-2.5 font-medium">Müşteri</th>
                            <th class="px-3 py-2.5 font-medium">Paket</th>
                            <th class="px-3 py-2.5 text-right font-medium">Site</th>
                            <th class="px-3 py-2.5 font-medium">Disk</th>
                            <th class="px-3 py-2.5 font-medium">Durum</th>
                            <th class="py-2.5 pr-5 pl-3"><span class="sr-only">İşlemler</span></th>
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
                                        <input type="checkbox" wire:model.live="selected" value="{{ $customer->id }}" aria-label="{{ $customer->displayName() }} seç" class="size-4 accent-accent">
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
                                        <x-icon name="triangle-alert" @class(['ml-1 inline size-3.5 align-[-2px]', 'text-warn' => $worst === 'warn', 'text-crit' => $worst === 'crit']) title="Başka bir sınıra yaklaştı ya da aştı" />
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <x-customer-status :customer="$customer" />
                                </td>
                                <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    @if ($customer->trashed())
                                        <button type="button" wire:click="restore({{ $customer->id }})" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-line px-2.5 text-[13px] hover:bg-surface-2">
                                            <x-icon name="rotate-ccw" class="size-3.5" />Geri al
                                        </button>
                                    @elseif ($customer->user)
                                        <form method="POST" action="{{ route('customers.impersonate', $customer) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[13px] text-muted hover:bg-surface-2 hover:text-fg" title="Paneli bu müşterinin gördüğü gibi açın">
                                                <x-icon name="log-in" class="size-3.5" />Giriş yap
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center">
                                    @if ($search !== '')
                                        <p class="text-muted">Aramanızla eşleşen müşteri yok.</p>
                                    @else
                                        <p class="font-medium">Henüz müşteri yok</p>
                                        <p class="mt-1 text-[13px] text-muted">Müşteri ekleyin; siteleri onun hesabında açın ya da mevcut siteleri ona bağlayın. Müşteri yalnızca kendi sitelerini görür.</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($customers->total() > 0)
                <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                    <span>{{ $customers->firstItem() }}–{{ $customers->lastItem() }} / {{ $customers->total() }} müşteri</span>
                    @if ($customers->hasPages())
                        <span class="ml-auto flex items-center gap-1">
                            <button type="button" wire:click="previousPage" @disabled($customers->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Önceki</button>
                            <span class="px-2">Sayfa {{ $customers->currentPage() }} / {{ $customers->lastPage() }}</span>
                            <button type="button" wire:click="nextPage" @disabled(! $customers->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Sonraki</button>
                        </span>
                    @endif
                </div>
            @endif
        @else
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openPackage" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />Paket ekle
                </button>
                <p class="ml-auto text-[13px] text-muted">Paketteki değişiklik o paketi kullanan tüm müşterilere hemen uygulanır.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Paket</th>
                            @foreach (Package::LIMITS as [, $label])
                                <th class="px-3 py-2.5 text-right font-medium">{{ $label === 'Laravel uygulaması' ? 'Laravel' : $label }}</th>
                            @endforeach
                            <th class="px-3 py-2.5 font-medium">Özellikler</th>
                            <th class="px-3 py-2.5 text-right font-medium">Müşteri</th>
                            <th class="py-2.5 pr-5 pl-3"><span class="sr-only">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($packages as $package)
                            <tr wire:key="package-{{ $package->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5">
                                    <button type="button" wire:click="openPackage({{ $package->id }})" class="font-medium hover:text-accent">{{ $package->name }}</button>
                                    @if ($package->is_default)<span class="ml-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted">Varsayılan</span>@endif
                                </td>
                                @foreach (array_keys(Package::LIMITS) as $key)
                                    @php $limit = $package->limit($key); @endphp
                                    <td class="num px-3 py-3 text-right whitespace-nowrap">
                                        @if ($limit === null)
                                            <span class="text-muted">Sınırsız</span>
                                        @elseif (in_array($key, ['disk', 'traffic'], true))
                                            {{ \App\Support\Bytes::format($limit) }}
                                        @else
                                            {{ number_format($limit, 0, ',', '.') }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-[12.5px] text-muted">
                                    @forelse (collect(Package::FEATURES)->filter(fn ($f) => $package->{$f[0]})->values() as $i => $f)
                                        <span class="whitespace-nowrap">{{ $f[1] }}{{ $loop->last ? '' : ',' }}</span>
                                    @empty
                                        Yalnızca site
                                    @endforelse
                                    @if ($package->php_versions !== null)
                                        <span class="block">PHP {{ implode(', ', $package->php_versions) }}</span>
                                    @endif
                                </td>
                                <td class="num px-3 py-3 text-right">{{ $package->customers_count }}</td>
                                <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="openPackage({{ $package->id }})" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ $package->name }} paketini düzenle" title="Düzenle" style="display:inline-grid">
                                        <x-icon name="pencil" />
                                    </button>
                                    <button type="button" wire:click="deletePackage({{ $package->id }})" @disabled($package->customers_count > 0 || $package->is_default)
                                            class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-crit disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-muted" style="display:inline-grid"
                                            aria-label="{{ $package->name }} paketini sil" title="{{ $package->is_default ? 'Varsayılan paket silinemez' : ($package->customers_count ? 'Paketi kullanan müşteriler var' : 'Sil') }}">
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
    <x-modal title="Müşteri ekle" show="creating">
        <form wire:submit="create" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">Müşteri ekle</h2>
            <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="company" class="mb-1.5 block">Firma adı <span class="text-muted">(isteğe bağlı)</span></label>
                        <input id="company" wire:model.live.debounce.400ms="company" autocomplete="organization" class="{{ $field }} {{ $errors->has('company') ? 'border-crit' : 'border-line' }}">
                        @error('company') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="name" class="mb-1.5 block">Yetkili kişi</label>
                        <input id="name" wire:model.live.debounce.400ms="name" required autocomplete="name" class="{{ $field }} {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                        @error('name') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email" class="mb-1.5 block">E-posta</label>
                        <input id="email" type="email" wire:model="email" required autocomplete="email" class="{{ $field }} {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                        @error('email') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="phone" class="mb-1.5 block">Telefon <span class="text-muted">(isteğe bağlı)</span></label>
                        <input id="phone" type="tel" wire:model="phone" autocomplete="tel" class="{{ $field }} {{ $errors->has('phone') ? 'border-crit' : 'border-line' }}">
                        @error('phone') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="username" class="mb-1.5 block">Sistem kullanıcısı</label>
                        <input id="username" wire:model.blur="username" required autocomplete="off" spellcheck="false" class="{{ $field }} font-mono text-[13px] {{ $errors->has('username') ? 'border-crit' : 'border-line' }}">
                        @error('username') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @else <p class="mt-1.5 text-2xs text-muted">Siteleri /home/{{ $username ?: 'kullanici' }} altında açılır.</p> @enderror
                    </div>
                    <div>
                        <label for="package" class="mb-1.5 block">Paket</label>
                        <select id="package" wire:model="packageId" class="{{ $field }} border-line px-2.5">
                            @foreach ($packages as $package)
                                <option value="{{ $package->id }}">{{ $package->name }}</option>
                            @endforeach
                        </select>
                        @error('packageId') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-2xs text-muted">Müşteri eklendikten sonra bir giriş bağlantısı gösterilir. Bağlantıyı müşteriye iletin; şifresini kendisi belirler.</p>
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="create" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Müşteriyi ekle</button>
            </div>
        </form>
    </x-modal>

    {{-- Davet bağlantısı --}}
    @if ($inviteLink)
        @include('livewire.customers.invite', ['link' => $inviteLink, 'close' => 'closeInvite', 'next' => $invitedId ? route('customers.show', $invitedId) : null])
    @endif

    {{-- Paket ekle / düzenle --}}
    <x-modal title="Paket" show="editingPackage" size="max-w-2xl">
        <form wire:submit="savePackage" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $packageEditId ? 'Paketi düzenle' : 'Paket ekle' }}</h2>
            <div class="space-y-5 overflow-y-auto p-5 text-[13.5px]">
                <div>
                    <label for="pkgName" class="mb-1.5 block">Paket adı</label>
                    <input id="pkgName" wire:model="pkgName" required class="{{ $field }} sm:w-72 {{ $errors->has('pkgName') ? 'border-crit' : 'border-line' }}">
                    @error('pkgName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <fieldset>
                    <legend class="mb-2 font-medium">Sınırlar</legend>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach (Package::LIMITS as $key => [, $label, $unit])
                            <div>
                                <label for="limit-{{ $key }}" class="mb-1.5 block text-[13px]">{{ $label }}@if ($unit) <span class="text-muted">({{ $unit }})</span>@endif</label>
                                <input id="limit-{{ $key }}" wire:model="limits.{{ $key }}" inputmode="decimal" placeholder="Sınırsız" class="{{ $field }} num {{ $errors->has('limits.'.$key) ? 'border-crit' : 'border-line' }}">
                                @error('limits.'.$key) <p class="mt-1 text-[12.5px] text-crit">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-2xs text-muted">Boş bırakılan alan sınırsız sayılır. Veritabanı sınırı, veritabanı yönetimi geldiğinde uygulanır.</p>
                </fieldset>
                <fieldset>
                    <legend class="mb-2 font-medium">Özellikler</legend>
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
                    <legend class="mb-2 font-medium">PHP sürümleri</legend>
                    <label class="flex items-center gap-2.5">
                        <input type="checkbox" wire:model.live="allPhp" class="size-4 accent-accent">Kurulu tüm sürümler
                    </label>
                    @unless ($allPhp)
                        <div class="mt-2.5 flex flex-wrap gap-x-5 gap-y-2 pl-6">
                            @forelse ($installedPhp as $version)
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="pkgPhp" value="{{ $version }}" class="size-4 accent-accent"><span class="font-mono text-[13px]">PHP {{ $version }}</span></label>
                            @empty
                                <p class="text-[13px] text-muted">Kurulu PHP sürümleri okunamadı.</p>
                            @endforelse
                        </div>
                    @endunless
                    @error('pkgPhp') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-2 text-2xs text-muted">Yeni sitelerde ve Laravel kurulumlarında yalnızca bu sürümler sunulur; mevcut siteler değişmez.</p>
                </fieldset>
                <label class="flex items-center gap-2.5">
                    <input type="checkbox" wire:model="pkgDefault" class="size-4 accent-accent">Yeni müşterilerde varsayılan paket
                </label>
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editingPackage', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="savePackage" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Paketi kaydet</button>
            </div>
        </form>
    </x-modal>
</div>
