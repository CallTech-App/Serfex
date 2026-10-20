@php $admin = auth()->user()->is_admin; @endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on a site. --}}
    @if ($busy)
        <div wire:poll.2s hidden></div>
    @endif

    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Siteler') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ $admin ? __('Sunucudaki web sitelerini ve alan adlarını yönetin.') : __('Web sitelerinizi ve alan adlarınızı yönetin.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openCreate" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="plus" />{{ __('Site ekle') }}
            </button>
            <label class="relative ml-auto w-full sm:w-64">
                <span class="sr-only">{{ __('Site ara') }}</span>
                <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Ara') }}"
                       class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
            </label>
        </div>

        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[680px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Alan adı') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Sistem kullanıcısı') }}</th>
                        @if ($admin)<th class="px-3 py-2.5 font-medium">{{ __('Müşteri') }}</th>@endif
                        <th class="px-3 py-2.5 font-medium">PHP</th>
                        <th class="px-3 py-2.5 text-right font-medium">
                            <button type="button" wire:click="$set('sort', '{{ $sort === 'trafik' ? '' : 'trafik' }}')" class="inline-flex items-center gap-1 uppercase hover:text-fg" title="{{ __('Bu ayın trafiğine göre sırala') }}">
                                {{ __('Trafik (bu ay)') }}@if ($sort === 'trafik')<x-icon name="arrow-down" class="size-3" />@endif
                            </button>
                        </th>
                        <th class="py-2.5 pr-5 pl-3 font-medium">{{ __('Durum') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($sites as $site)
                        <tr wire:key="site-{{ $site->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5 font-mono text-[13px]">
                                <a href="{{ route('sites.show', $site) }}" wire:navigate class="hover:text-accent">{{ $site->domain }}</a>
                                @if ($site->preview)<span class="ml-1.5 rounded-full bg-surface-2 px-1.5 py-0.5 font-sans text-2xs text-muted" title="{{ __('Laravel önizleme ortamı') }}">{{ __('Önizleme') }}</span>@endif
                            </td>
                            <td class="px-3 py-3 font-mono text-[13px] text-muted">{{ $site->systemUser->name }}</td>
                            @if ($admin)
                                <td class="px-3 py-3">
                                    @if ($owner = $site->systemUser->customer)
                                        <a href="{{ route('customers.show', $owner) }}" wire:navigate class="hover:text-accent">{{ $owner->displayName() }}</a>
                                        @if ($owner->isSuspended())<span class="ml-1.5 rounded-full bg-warn-soft px-1.5 py-0.5 text-2xs font-medium text-warn">{{ __('Askıda') }}</span>@endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 py-3 font-mono text-[13px]">{{ $site->php_version }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap num">
                                @if ($site->status === \App\Enums\SiteStatus::Active)
                                    <a href="{{ route('sites.stats', $site) }}" wire:navigate @class(['hover:text-accent', 'text-muted' => ! ($traffic[$site->id] ?? 0)])>{{ \App\Support\Bytes::format((int) ($traffic[$site->id] ?? 0)) }}</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3"><x-status-badge :status="$site->status" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $admin ? 6 : 5 }}" class="px-5 py-12 text-center">
                                @if ($search !== '')
                                    <p class="text-muted">{{ __('Aramanızla eşleşen site yok.') }}</p>
                                @else
                                    <p class="font-medium">{{ __('Henüz site yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('İlk sitenizi ekleyin; Servik sistem kullanıcısını, Nginx ve PHP-FPM ayarlarını hazırlar.') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($sites->total() > 0)
            <div class="flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted num">
                <span>{{ __(':sites–:sites2 / :sites3 site', ['sites' => $sites->firstItem(), 'sites2' => $sites->lastItem(), 'sites3' => $sites->total()]) }}</span>
                @if ($sites->hasPages())
                    <span class="ml-auto flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($sites->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
                        <span class="px-2">{{ __('Sayfa :sites / :sites2', ['sites' => $sites->currentPage(), 'sites2' => $sites->lastPage()]) }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $sites->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
                    </span>
                @endif
            </div>
        @endif
    </section>

    {{-- Site ekle --}}
    <x-modal :title="__('Site ekle')" show="creating">
        <form wire:submit="create">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Site ekle') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                @if ($agentError)
                    <p class="relative overflow-hidden rounded-md bg-crit-soft py-2.5 pr-3 pl-4 text-[13px] text-crit">
                        <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>{{ $agentError }}
                    </p>
                @endif
                <div>
                    <label for="domain" class="mb-1.5 block">{{ __('Alan adı') }}</label>
                    <input id="domain" wire:model.live.debounce.400ms="domain" required placeholder="{{ __('ornek.com.tr') }}" autocomplete="off" spellcheck="false"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('domain') ? 'border-crit' : 'border-line' }}">
                    @error('domain') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <label class="mt-2 flex items-center gap-2 text-[13px] text-muted">
                        <input type="checkbox" wire:model="wwwAlias" class="size-4 accent-accent">
                        <span><x-t text=":www ile de açılsın"><x-slot:www><span class="font-mono">www.</span></x-slot:www></x-t></span>
                    </label>
                </div>
                @if ($admin && $customers->isNotEmpty())
                    <div>
                        <label for="customerId" class="mb-1.5 block">{{ __('Müşteri') }}</label>
                        <select id="customerId" wire:model.live="customerId" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                            <option value="">{{ __('Müşterisiz (yönetici)') }}</option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-2xs text-muted">{{ __('Müşterinin sitesi onun paket sınırlarına sayılır ve müşteri panelinde görünür.') }}</p>
                    </div>
                @endif
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="systemUser" class="mb-1.5 block">{{ __('Sistem kullanıcısı') }}</label>
                        <input id="systemUser" wire:model.live.debounce.400ms="systemUser" required autocomplete="off" spellcheck="false" @readonly(! $admin || $customerId !== '')
                               class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none read-only:bg-surface-2 read-only:text-muted focus:border-accent {{ $errors->has('systemUser') ? 'border-crit' : 'border-line' }}">
                        @error('systemUser')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ ! $admin ? __('Hesabınızın sistem kullanıcısı; dosyalar ona ait olur.') : ($customerId !== '' ? __('Müşterinin sistem kullanıcısı; dosyalar ona ait olur.') : __('Dosyalar bu kullanıcıya ait olur. Varsa mevcut kullanıcı kullanılır.')) }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="phpVersion" class="mb-1.5 block">{{ __('PHP sürümü') }}</label>
                        <select id="phpVersion" wire:model="phpVersion" @disabled($phpVersions === []) class="h-9 w-full rounded-md border border-line bg-surface px-2.5 font-mono text-[13px]">
                            @foreach ($phpVersions as $v)
                                <option value="{{ $v }}">{{ $v }}</option>
                            @endforeach
                        </select>
                        @error('phpVersion') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" @disabled($phpVersions === []) wire:loading.attr="disabled" wire:target="create"
                        class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Siteyi oluştur') }}</button>
            </div>
        </form>
    </x-modal>
</div>
