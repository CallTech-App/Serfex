{{-- The security scan's findings, for Güvenlik → Tarama and a site's Tarama page. --}}
<div class="relative overflow-x-auto">
    <table class="w-full text-[13.5px] sm:min-w-[760px]">
        <thead>
            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                <th class="w-24 py-2.5 pr-3 pl-5 font-medium">{{ __('Önem') }}</th>
                <th class="px-3 py-2.5 font-medium">{{ __('Sorun') }}</th>
                @if ($showSite)
                    <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Site') }}</th>
                @endif
                <th class="hidden py-2.5 pr-5 pl-3 sm:table-cell"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse ($findings as $f)
                <tr wire:key="finding-{{ $f->id }}" class="align-top hover:bg-surface-2/60">
                    <td class="py-3 pr-3 pl-5"><x-status-badge :status="$f->severityBadge()" /></td>
                    <td class="max-w-[15rem] py-3 pr-5 pl-3 sm:max-w-[34rem] sm:px-3">
                        <span class="font-medium">{{ $f->title() }}</span>
                        @if ($f->path !== '')
                            <span class="mt-0.5 block truncate font-mono text-[12.5px] text-muted" title="{{ $f->path }}">{{ $f->path }}</span>
                        @endif
                        @if ($f->detailText())
                            <span class="mt-0.5 block text-[12.5px] text-muted">{{ $f->detailText() }}</span>
                        @endif
                        @if ($f->status !== 'open')
                            <span class="mt-0.5 block text-2xs text-muted">{{ $f->statusLabel() }}{{ $f->resolver ? ' · '.$f->resolver->name : '' }}{{ $f->resolved_at ? ' · '.\App\Support\When::dateTime($f->resolved_at) : '' }}</span>
                        @endif
                        @if ($showSite && $f->site)
                            <span class="mt-0.5 block font-mono text-2xs text-muted md:hidden">{{ $f->site->domain }}</span>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 sm:hidden">
                            @include('livewire.security.finding-actions', ['f' => $f])
                        </div>
                    </td>
                    @if ($showSite)
                        <td class="hidden px-3 py-3 md:table-cell">
                            @if ($f->site)
                                <a href="{{ route('sites.scan', $f->site) }}" wire:navigate class="font-mono text-[13px] hover:text-accent">{{ $f->site->domain }}</a>
                            @else
                                <span class="text-muted">{{ __('Sunucu') }}</span>
                            @endif
                        </td>
                    @endif
                    <td class="hidden py-3 pr-5 pl-3 text-right whitespace-nowrap sm:table-cell">
                        <div class="flex justify-end gap-3">
                        @include('livewire.security.finding-actions', ['f' => $f])
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showSite ? 4 : 3 }}" class="px-5 py-12 text-center">
                        <p class="font-medium">{{ __('Bulgu yok') }}</p>
                        <p class="mt-1 text-[13px] text-muted">{{ __('Tarama zararlı dosya, açıkta kalan .env ve yedek dosyası, yanlış izin ve eski yazılım arar.') }}</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="num flex flex-wrap items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
    <span>{{ __('Karantinaya alınan dosyalar çalıştırılamaz ve indirilemez; Dosya Yöneticisi\'nin çöp kutusunda :quarantine_days gün bekler ve oradan geri yüklenebilir.', ['quarantine_days' => \App\Services\Security\Scans::QUARANTINE_DAYS]) }}</span>
    @if ($findings->hasPages())
        <span class="ml-auto flex items-center gap-1">
            <button type="button" wire:click="previousPage" @disabled($findings->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
            <span class="px-2">{{ __('Sayfa :findings / :findings2', ['findings' => $findings->currentPage(), 'findings2' => $findings->lastPage()]) }}</span>
            <button type="button" wire:click="nextPage" @disabled(! $findings->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
        </span>
    @endif
</div>
