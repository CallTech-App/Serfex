{{-- In an install confirmation: the version that will be installed and where it comes from. --}}
@if (($confirm['candidate'] ?? null) !== null)
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border border-line bg-surface-2/60 px-3 py-2.5 text-[13px]">
        <dt class="text-muted">{{ __('Kurulacak sürüm') }}</dt>
        @if ($confirm['candidate']['version'])
            <dd class="min-w-0 font-mono break-words">{{ $confirm['candidate']['version'] }}</dd>
        @else
            <dd class="min-w-0 text-muted">{{ __('Henüz bilinmiyor; "Güncellemeleri denetle" ile öğrenilir.') }}</dd>
        @endif
        @if ($confirm['candidate']['source'])
            <dt class="text-muted">{{ __('Kaynak') }}</dt>
            <dd class="min-w-0 break-words">{{ $confirm['candidate']['source'] }}</dd>
        @endif
    </dl>
@endif
