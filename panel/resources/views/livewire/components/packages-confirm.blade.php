{{-- In an Ubuntu package upgrade or restart confirmation: how many packages, how many security updates and which Serfex components are checked afterwards. --}}
@php($packages = $confirm['packages'] ?? null)
@if ($packages !== null && isset($packages['count']))
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-lg border border-line bg-surface-2/60 px-3 py-2.5 text-[13px]">
        <dt class="text-muted">{{ __('Güncellenecek') }}</dt>
        <dd>
            {{ trans_choice(':count paket', $packages['count']) }}@if ($packages['security']) · <span class="text-warn">{{ trans_choice(':count güvenlik güncellemesi', $packages['security']) }}</span>@endif
            @if ($packages['new']) · {{ trans_choice(':count yeni paket gelir', $packages['new']) }}@endif
        </dd>
        @if ($packages['list'] !== [] && count($packages['list']) <= 12)
            <dt class="text-muted">{{ __('Seçilen') }}</dt>
            <dd class="font-mono text-[12.5px] break-words">{{ implode(', ', $packages['list']) }}</dd>
        @endif
        @if ($packages['components'] !== [])
            <dt class="text-muted">{{ __('Sonra denetlenir') }}</dt>
            <dd>{{ implode(', ', $packages['components']) }}</dd>
        @endif
    </dl>
@elseif ($packages !== null && ($packages['reboot'] ?? []) !== [])
    <p class="mt-3 rounded-lg border border-line bg-surface-2/60 px-3 py-2.5 text-[13px]">
        <span class="text-muted">{{ __('Yeniden başlatmayı isteyen paketler:') }}</span>
        <span class="font-mono text-[12.5px] break-words">{{ implode(', ', $packages['reboot']) }}</span>
    </p>
@endif
