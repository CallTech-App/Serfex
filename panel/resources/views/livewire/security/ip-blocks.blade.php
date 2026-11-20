@php
    use App\Support\Num;
    $btn = 'flex h-9 items-center gap-2 whitespace-nowrap rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 whitespace-nowrap rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $danger = 'flex h-9 items-center gap-2 whitespace-nowrap rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40';
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $th = 'px-3 py-2.5 font-medium';
    $thead = 'border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase';
    $rowAction = 'text-[13px] text-muted hover:text-fg';
    $ticked = array_map('strval', $selected);
    $pageTicked = $rows->isNotEmpty() && ($allMatching || $rows->getCollection()->every(fn ($r) => in_array((string) $r->id, $ticked, true)));
    $styles = compact('btn', 'primary', 'danger', 'field', 'th', 'thead', 'rowAction', 'ticked', 'pageTicked');
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('security.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Güvenlik') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('IP engelleri') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('IP engelleri') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Sunucunun yanıt vermediği adresler. Elle engellediklerinizi, Fail2ban\'ın ve Serfex AI\'ın engellediklerini tek listeden yönetin.') }}</p>
    </div>

    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="min-w-0 flex-1 break-words">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach ($this::TABS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px flex h-12 items-center gap-2 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}
                    @isset($counts[$key])<span class="num rounded-full bg-surface-2 px-1.5 text-2xs leading-5 font-medium text-muted">{{ Num::format($counts[$key]) }}</span>@endisset
                </button>
            @endforeach
        </div>

        @include('livewire.security.ip-blocks.'.$tab, $styles)
    </section>

    @if ($tab === 'blocked')
        @include('livewire.security.ip-blocks.paste-modal', $styles)
    @elseif ($tab === 'allowed')
        @include('livewire.security.ip-blocks.allow-modal', $styles)
    @endif
</div>
