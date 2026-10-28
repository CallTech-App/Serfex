@props(['current'])
{{-- The signed-in account's own pages: its details, its sign-in and its Git accounts. --}}
<nav class="scroll-thin flex w-fit max-w-full gap-0.5 overflow-x-auto rounded-lg border border-line bg-surface p-1 text-[13.5px]" aria-label="{{ __('Hesap bölümleri') }}">
    @foreach ([['account.profile', 'user', __('Hesap bilgileri')], ['account.security', 'lock', __('Hesap güvenliği')], ['account.git', 'git-branch', __('Git hesapları')]] as [$route, $icon, $label])
        @php $on = $current === $route; @endphp
        <a href="{{ route($route) }}" wire:navigate @if ($on) aria-current="page" @endif
           class="flex h-8 shrink-0 items-center gap-2 rounded-md px-3 whitespace-nowrap {{ $on ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">
            <x-icon :name="$icon" class="size-4" />{{ $label }}
        </a>
    @endforeach
</nav>
