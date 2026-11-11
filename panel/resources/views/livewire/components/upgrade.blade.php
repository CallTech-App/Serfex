{{-- In an upgrade confirmation: from which version to which, what is checked, what people notice and what is kept. --}}
@php($upgrade = $confirm['upgrade'] ?? null)
@if ($upgrade !== null)
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-lg border border-line bg-surface-2/60 px-3 py-2.5 text-[13px]">
        <dt class="text-muted">{{ __('Şimdiki sürüm') }}</dt>
        <dd class="min-w-0">{{ $upgrade['from'] }}</dd>
        <dt class="text-muted">{{ __('Yeni sürüm') }}</dt>
        <dd class="min-w-0 font-medium">{{ $upgrade['to'] }}</dd>
        @foreach ($upgrade['items'] as [$label, $value])
            <dt class="text-muted">{{ $label }}</dt>
            <dd class="min-w-0">{{ $value }}</dd>
        @endforeach
    </dl>
    <p class="mt-3 text-2xs text-muted">{{ $upgrade['note'] }}</p>
@endif
