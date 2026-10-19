{{-- A finding's buttons: its fixes, ignore, undo or reopen. --}}
@php use App\Models\SecurityFinding; @endphp
@if ($f->status === 'open')
    @foreach ($f->fixes ?? [] as $fix)
        @if (isset(SecurityFinding::FIXES[$fix]))
            <button type="button" wire:click="fixFinding({{ $f->id }}, '{{ $fix }}')" wire:loading.attr="disabled" class="text-[13px] text-accent hover:underline whitespace-nowrap">{{ SecurityFinding::FIXES[$fix][0] }}</button>
        @endif
    @endforeach
    @if ($f->isServer() && $f->settingsLink())
        <a href="{{ $f->settingsLink() }}" wire:navigate class="text-[13px] text-accent hover:underline whitespace-nowrap">Ayara git</a>
    @endif
    @if (! $f->isServer())
        <button type="button" wire:click="ignoreFinding({{ $f->id }})" class="text-[13px] text-muted hover:text-fg">Yok say</button>
    @endif
@elseif ($f->status === 'fixed')
    <button type="button" wire:click="undoFinding({{ $f->id }})" class="text-[13px] text-muted hover:text-fg">Geri al</button>
@elseif ($f->status === 'ignored')
    <button type="button" wire:click="reopenFinding({{ $f->id }})" class="text-[13px] text-muted hover:text-fg">Yeniden aç</button>
@endif
