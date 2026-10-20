@php
    $date = fn ($d) => $d ? \App\Support\When::date($d) : null;
@endphp
<div class="space-y-6">
    {{-- Refresh while certificates are being issued. --}}
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-semibold tracking-tight">{{ __('SSL Sertifikaları') }}</h1>
            <p class="mt-1 text-[13px] text-muted">{{ __(':is_admin HTTPS sertifikaları. Ayrıntı ve işlemler için bir siteye tıklayın.', ['is_admin' => auth()->user()->is_admin ? __('Sunucudaki sitelerin') : __('Sitelerinizin')]) }}</p>
        </div>
        @if (auth()->user()->is_admin)
            <a href="{{ route('ssl.settings') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                <x-icon name="settings" />{{ __('SSL ayarları') }}
            </a>
        @endif
    </div>

    @if ($attention > 0)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-warn"></span>
            <x-icon name="triangle-alert" class="text-warn" />
            <div class="min-w-0 flex-1 basis-64">
                <p class="font-medium">{{ __(':attention sertifika ilgilenmenizi bekliyor', ['attention' => $attention]) }}</p>
                <p class="mt-0.5 text-[13px] text-muted">{{ __('Alınamayan, yenilenemeyen ya da süresi dolmak üzere olan sertifikalar. Yenilenemeyen sertifikalar süreleri dolana kadar çalışmaya devam eder.') }}</p>
            </div>
            @if ($panel?->state()->needsAttention())
                <a href="{{ route('ssl.settings') }}" wire:navigate class="flex h-8 items-center rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">{{ __('Panel sertifikası') }}</a>
            @endif
            @if ($mail?->state()->needsAttention())
                <a href="{{ route('mail.settings') }}" wire:navigate class="flex h-8 items-center rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">{{ __('Posta sunucusu sertifikası') }}</a>
            @endif
            @if ($filter !== 'problem')
                <button type="button" wire:click="$set('filter', 'problem')" class="flex h-8 items-center rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">{{ __('Sorunlu olanları göster') }}</button>
            @endif
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="issueSelected" @disabled($selected === [])
                    class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40">
                <x-icon name="shield-check" />{{ __('Let\'s Encrypt sertifikası al:selected', ['selected' => $selected ? ' ('.count($selected).')' : '']) }}
            </button>
            <div class="ml-auto flex w-full gap-2 sm:w-auto">
                <select wire:model.live="filter" aria-label="{{ __('Durum') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                    <option value="">{{ __('Tümü') }}</option>
                    <option value="problem">{{ __('Sorunlu ya da sertifikasız') }}</option>
                </select>
                <label class="relative flex-1 sm:w-60 sm:flex-none">
                    <span class="sr-only">{{ __('Site ara') }}</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Site ara') }}"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>
        </div>

        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[760px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="w-10 py-2.5 pl-5"><span class="sr-only">{{ __('Seç') }}</span></th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Site') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Veren') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Bitiş') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Otomatik yenileme') }}</th>
                        <th class="py-2.5 pr-5 pl-3 font-medium">{{ __('HTTPS yönlendirme') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($sites as $site)
                        @php
                            $certificate = $site->certificate;
                            $state = $site->sslState();
                            $error = $certificate?->renew_error ?? $site->pendingCertificate()?->last_error;
                        @endphp
                        <tr wire:key="ssl-{{ $site->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pl-5">
                                <input type="checkbox" wire:model.live="selected" value="{{ $site->id }}" aria-label="{{ __(':domain seç', ['domain' => $site->domain]) }}" class="size-4 accent-accent">
                            </td>
                            <td class="px-3 py-3 font-mono text-[13px] font-medium">
                                <a href="{{ route('sites.ssl', $site) }}" wire:navigate class="hover:text-accent">{{ $site->domain }}</a>
                                @if ($certificate?->isWildcard())
                                    <span class="ml-1 font-sans text-2xs font-normal text-muted">{{ __('joker') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3"><x-status-badge :status="$state" :title="$error" /></td>
                            <td class="max-w-56 truncate px-3 py-3 text-muted" title="{{ $certificate?->issuerLabel() }}">{{ $certificate?->issuerLabel() ?? '—' }}</td>
                            <td class="num px-3 py-3 whitespace-nowrap">
                                @if ($certificate?->isInstalled())
                                    {{ $date($certificate->not_after) }}
                                    <span class="text-muted">· {{ $certificate->isExpired() ? __('doldu') : __(':days_left gün', ['days_left' => $certificate->daysLeft()]) }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-muted">
                                @if ($certificate === null)
                                    —
                                @elseif (! $certificate->isLetsEncrypt())
                                    {{ __('Elle (yüklendi)') }}
                                @else
                                    {{ $certificate->auto_renew ? __('Açık') : __('Kapalı') }}
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 text-muted">{{ $site->hasHttps() ? ($site->https_redirect ? __('Açık') : __('Kapalı')) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center">
                                @if ($search !== '' || $filter !== '')
                                    <p class="text-muted">{{ __('Aramanızla eşleşen site yok.') }}</p>
                                @else
                                    <p class="font-medium">{{ __('Henüz site yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('Site eklediğinizde Servik, alan adı bu sunucuyu gösteriyorsa Let\'s Encrypt sertifikasını otomatik alır.') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($sites->total() > 0)
            <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
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
</div>
