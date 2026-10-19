{{-- The security scan's findings, for Güvenlik → Tarama and a site's Tarama page. --}}
@php
    use App\Models\SecurityFinding;
    $fixLink = 'text-[13px] text-accent hover:underline whitespace-nowrap';
@endphp
<div class="relative overflow-x-auto">
    <table class="w-full text-[13.5px] sm:min-w-[760px]">
        <thead>
            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                <th class="w-24 py-2.5 pr-3 pl-5 font-medium">Önem</th>
                <th class="px-3 py-2.5 font-medium">Sorun</th>
                @if ($showSite)
                    <th class="hidden px-3 py-2.5 font-medium md:table-cell">Site</th>
                @endif
                <th class="py-2.5 pr-5 pl-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse ($findings as $f)
                <tr wire:key="finding-{{ $f->id }}" class="align-top hover:bg-surface-2/60">
                    <td class="py-3 pr-3 pl-5"><x-status-badge :status="$f->severityBadge()" /></td>
                    <td class="max-w-[34rem] px-3 py-3">
                        <span class="font-medium">{{ $f->title() }}</span>
                        @if ($f->path !== '')
                            <span class="mt-0.5 block truncate font-mono text-[12.5px] text-muted" title="{{ $f->path }}">{{ $f->path }}</span>
                        @endif
                        @if ($f->detailText())
                            <span class="mt-0.5 block text-[12.5px] text-muted">{{ $f->detailText() }}</span>
                        @endif
                        @if ($f->status !== 'open')
                            <span class="mt-0.5 block text-2xs text-muted">{{ $f->statusLabel() }}{{ $f->resolver ? ' · '.$f->resolver->name : '' }}{{ $f->resolved_at ? ' · '.$f->resolved_at->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}</span>
                        @endif
                        @if ($showSite && $f->site)
                            <span class="mt-0.5 block font-mono text-2xs text-muted md:hidden">{{ $f->site->domain }}</span>
                        @endif
                    </td>
                    @if ($showSite)
                        <td class="hidden px-3 py-3 md:table-cell">
                            @if ($f->site)
                                <a href="{{ route('sites.scan', $f->site) }}" wire:navigate class="font-mono text-[13px] hover:text-accent">{{ $f->site->domain }}</a>
                            @else
                                <span class="text-muted">Sunucu</span>
                            @endif
                        </td>
                    @endif
                    <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                        @if ($f->status === 'open')
                            @foreach ($f->fixes ?? [] as $fix)
                                @if (isset(SecurityFinding::FIXES[$fix]))
                                    <button type="button" wire:click="fixFinding({{ $f->id }}, '{{ $fix }}')" wire:loading.attr="disabled" class="{{ $fixLink }} {{ $loop->first ? '' : 'ml-3' }}">{{ SecurityFinding::FIXES[$fix][0] }}</button>
                                @endif
                            @endforeach
                            @if ($f->isServer() && $f->settingsLink())
                                <a href="{{ $f->settingsLink() }}" wire:navigate class="{{ $fixLink }}">Ayara git</a>
                            @endif
                            @if (! $f->isServer())
                                <button type="button" wire:click="ignoreFinding({{ $f->id }})" class="ml-3 text-[13px] text-muted hover:text-fg">Yok say</button>
                            @endif
                        @elseif ($f->status === 'fixed')
                            <button type="button" wire:click="undoFinding({{ $f->id }})" class="text-[13px] text-muted hover:text-fg">Geri al</button>
                        @elseif ($f->status === 'ignored')
                            <button type="button" wire:click="reopenFinding({{ $f->id }})" class="text-[13px] text-muted hover:text-fg">Yeniden aç</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showSite ? 4 : 3 }}" class="px-5 py-12 text-center">
                        <p class="font-medium">Bulgu yok</p>
                        <p class="mt-1 text-[13px] text-muted">Tarama zararlı dosya, açıkta kalan .env ve yedek dosyası, yanlış izin ve eski yazılım arar.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="num flex flex-wrap items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
    <span>Karantinaya alınan dosyalar çalıştırılamaz ve indirilemez; Dosya Yöneticisi'nin çöp kutusunda {{ \App\Services\Security\Scans::QUARANTINE_DAYS }} gün bekler ve oradan geri yüklenebilir.</span>
    @if ($findings->hasPages())
        <span class="ml-auto flex items-center gap-1">
            <button type="button" wire:click="previousPage" @disabled($findings->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Önceki</button>
            <span class="px-2">Sayfa {{ $findings->currentPage() }} / {{ $findings->lastPage() }}</span>
            <button type="button" wire:click="nextPage" @disabled(! $findings->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Sonraki</button>
        </span>
    @endif
</div>
