{{-- In an upgrade confirmation: from which version to which, what customers notice and what is kept. --}}
@php($upgrade = $confirm['upgrade'] ?? null)
@if ($upgrade !== null)
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-lg border border-line bg-surface-2/60 px-3 py-2.5 text-[13px]">
        <dt class="text-muted">{{ __('Şimdiki sürüm') }}</dt>
        <dd class="min-w-0">{{ $upgrade['from'] }}</dd>
        <dt class="text-muted">{{ __('Yeni sürüm') }}</dt>
        <dd class="min-w-0 font-medium">{{ $upgrade['to'] }}</dd>
        <dt class="text-muted">{{ __('Veritabanları') }}</dt>
        <dd class="min-w-0">{{ $upgrade['databases'] ? __(':n veritabanı yedeklenip denetlenecek', ['n' => $upgrade['databases']]) : __('Henüz veritabanı yok') }}</dd>
        <dt class="text-muted">{{ __('Kesinti') }}</dt>
        <dd class="min-w-0">{{ $upgrade['downtime'] }}</dd>
        <dt class="text-muted">{{ __('Eski sürüm') }}</dt>
        <dd class="min-w-0">{{ $upgrade['kept'] }}</dd>
    </dl>
    <p class="mt-3 text-2xs text-muted">{{ __('Müşteriler veritabanı sayfalarında yükseltme sürdüğünü görür. Yükseltmeyi site trafiğinin az olduğu bir saatte başlatın.') }}</p>
@endif
