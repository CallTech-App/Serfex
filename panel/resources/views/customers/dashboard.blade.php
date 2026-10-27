@php
    use App\Services\Customers\Quotas;
    $user = auth()->user();
    $worst = Quotas::worst($report);
    $sslAttention = \App\Models\Certificate::attentionCount($user);
    $securityAttention = $user->allows('security') ? \App\Services\Security\Scans::attentionCount($user) : 0;
    $securitySite = $securityAttention ? \App\Models\SecurityFinding::open()->visibleTo($user)->whereIn('severity', ['critical', 'high'])->value('site_id') : null;
    $rows = array_filter([
        ['sites.index', [], 'globe', __('Siteler'), trans_choice(':count site', $report['sites']['used'])],
        $user->allows('mail') ? ['mail.index', [], 'mail', __('E-posta'), trans_choice(':count posta kutusu', $report['mailboxes']['used'])] : null,
        $user->allows('laravel') ? ['laravel.index', [], 'layers', 'Laravel', trans_choice(':count uygulama', $report['laravel_apps']['used'])] : null,
        $user->allows('backups') ? ['backups.index', [], 'archive', __('Yedekler'), ($last = \App\Models\BackupItem::visibleTo($user)->where('status', 'succeeded')->whereHas('backup', fn ($q) => $q->usable())->latest('id')->first())
            ? __('Son yedek :when', ['when' => \App\Support\When::dateTime($last->backup->created_at)]) : __('Henüz yedek yok')] : null,
    ]);
@endphp
<x-layouts.app :title="__('Genel Bakış · Serfex')">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Genel Bakış') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __(':customer · barındırma paketiniz ve kullanımınız.', ['customer' => $customer->displayName()]) }}</p>
    </div>

    @if ($worst !== 'ok')
        <div class="relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span @class(['absolute inset-y-0 left-0 w-1', 'bg-warn' => $worst === 'warn', 'bg-crit' => $worst === 'crit'])></span>
            <x-icon name="triangle-alert" @class(['text-warn' => $worst === 'warn', 'text-crit' => $worst === 'crit']) />
            <p class="min-w-0 flex-1">
                @if ($worst === 'crit')
                    {{ __('Paketinizin bir sınırı doldu. Yeni site, posta kutusu ya da uygulama açamazsınız; mevcutlar çalışmaya devam eder. Yer açın ya da paketinizi yükseltmek için barındırma sağlayıcınıza yazın.') }}
                @else
                    {{ __('Paketinizin bir sınırına yaklaştınız.') }}
                @endif
            </p>
        </div>
    @endif

    <section class="rounded-xl border border-line bg-surface">
        <div class="flex items-center gap-3 border-b border-line px-5 py-3.5">
            <x-icon name="package" class="text-muted" />
            <p class="font-medium">{{ __(':name paketi', ['name' => $customer->package->name]) }}</p>
            <a href="{{ route('usage') }}" wire:navigate class="ml-auto text-[13px] text-accent hover:underline">{{ __('Kullanım raporu') }}</a>
        </div>
        @include('livewire.customers.usage', ['report' => $report])
    </section>

    <section class="divide-y divide-line rounded-xl border border-line bg-surface text-[13.5px]">
        @foreach ($rows as [$route, $params, $icon, $label, $value])
            <a href="{{ route($route, $params) }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
                <x-icon :name="$icon" class="text-muted" />
                <span>{{ $label }}</span>
                <span class="num ml-auto text-muted">{{ $value }}</span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
        @endforeach
        <a href="{{ route('ssl.index', $sslAttention ? ['durum' => 'problem'] : []) }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
            <x-icon name="shield-check" @class(['text-muted' => ! $sslAttention, 'text-warn' => $sslAttention]) />
            <span>{{ __('SSL sertifikaları') }}</span>
            <span @class(['num ml-auto text-right', 'text-muted' => ! $sslAttention, 'font-medium text-warn' => $sslAttention])>
                {{ $sslAttention ? trans_choice(':count sertifika ilgilenmenizi bekliyor', $sslAttention) : __('Sorun yok') }}
            </span>
            <x-icon name="chevron-right" class="text-muted" />
        </a>
        @if ($securityAttention)
            <a href="{{ route('sites.scan', $securitySite) }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
                <x-icon name="shield-alert" class="text-crit" />
                <span>{{ __('Güvenlik') }}</span>
                <span class="num ml-auto text-right font-medium text-crit">{{ trans_choice(':count ciddi güvenlik bulgusu var', $securityAttention) }}</span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
        @endif
        @if (! $user->hasTwoFactor())
            <a href="{{ route('account.security') }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
                <x-icon name="key-round" class="text-info" />
                <span>{{ __('Hesabınız') }}</span>
                <span class="num ml-auto truncate text-right font-medium text-info">{{ __('İki adımlı doğrulama kapalı; açmanızı öneririz') }}</span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
        @endif
    </section>
</x-layouts.app>
