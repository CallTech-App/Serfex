{{-- One action card: a proposal waiting for approval, or what was done. --}}
@php
    $at = fn ($d) => $d?->timezone(config('app.timezone'))->format('H:i');
    $details = \App\Services\Ai\Actions::details($action);
    $pending = $action->isPending();
    $expired = $pending && $action->created_at->lt(now()->subHours(\App\Services\Ai\Actions::PROPOSAL_HOURS));
    $auto = $action->kind === 'auto';
@endphp
<div wire:key="action-{{ $action->id }}" @class([
    'rounded-lg border p-3.5',
    'border-accent/40 bg-accent-soft/50' => $pending && ! $expired,
    'border-line bg-surface' => ! $pending || $expired,
])>
    <div class="flex items-start gap-3">
        <x-icon :name="$auto ? 'shield-check' : 'sparkles'" class="mt-0.5 text-accent" />
        <div class="min-w-0 flex-1">
            @if ($pending && ! $expired)
                <p class="text-2xs font-semibold tracking-[.08em] text-accent uppercase">Onayınızı bekliyor</p>
            @elseif ($auto)
                <p class="text-2xs font-semibold tracking-[.08em] text-muted uppercase">Servik AI kendisi yaptı</p>
            @endif
            <p class="mt-0.5 font-medium">{{ $action->title }}</p>
            @if ($action->reason)
                <p class="mt-1 text-[13px] text-muted">{{ $action->reason }}</p>
            @endif
            @if ($details)
                <details class="mt-2 text-[12.5px]">
                    <summary class="cursor-pointer text-accent">Değişikliği göster</summary>
                    <pre class="mt-2 overflow-x-auto rounded border border-line bg-surface p-2.5 font-mono text-[11.5px]">@foreach ($details as $line)<span @class(['text-ok' => str_starts_with($line, '+'), 'text-crit' => str_starts_with($line, '−')])>{{ $line }}</span>
@endforeach</pre>
                </details>
            @endif
            @unless (\App\Services\Ai\Actions::undoable($action->action) || ! $pending)
                <p class="mt-1.5 text-2xs text-muted">Bu işlem geri alınamaz, ama bir şeyi silmez.</p>
            @endunless
        </div>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2 text-[13px]">
        @if ($pending && $expired)
            <span class="text-muted">Bu önerinin süresi doldu; durum değişmiş olabilir. Yeniden sorun.</span>
        @elseif ($pending)
            <button type="button" wire:click="apply({{ $action->id }})" wire:loading.attr="disabled" class="h-8 rounded-md bg-accent px-3 font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">Onayla ve uygula</button>
            <button type="button" wire:click="reject({{ $action->id }})" class="h-8 rounded-md border border-line bg-surface px-3 hover:bg-surface-2">Vazgeç</button>
        @elseif ($action->status === 'applied')
            <x-status-badge :status="new \App\Support\Badge('Uygulandı · '.$at($action->decided_at), 'ok')" />
            @if ($actions->canUndo($action))
                <button type="button" wire:click="undo({{ $action->id }})" class="ml-auto text-muted hover:text-fg">Geri al</button>
            @endif
        @elseif ($action->status === 'undone')
            <span class="text-muted">Geri alındı · {{ $at($action->undone_at) }}</span>
        @elseif ($action->status === 'rejected')
            <span class="text-muted">Vazgeçildi. Değişiklik yapılmadı.</span>
        @elseif ($action->status === 'failed')
            <x-status-badge :status="new \App\Support\Badge('Uygulanamadı', 'crit')" />
            <span class="min-w-0 text-muted">{{ $action->error }}</span>
        @endif
    </div>
</div>
