<x-layouts.app title="{{ __('Genel Bakış · Servik') }}">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Genel Bakış') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Sunucunun ve sitelerin durumu.') }}</p>
    </div>

    <section class="divide-y divide-line rounded-xl border border-line bg-surface text-[13.5px]">
        <div class="flex items-center gap-3 px-5 py-4">
            <livewire:agent-status />
        </div>
        @php $monitorAttention = (int) \Illuminate\Support\Facades\Cache::get(\App\Services\Monitor\LiveStats::ATTENTION_KEY, 0); @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('monitor.index') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $monitorAttention ? '' : 'pr-5' }}">
                <x-icon name="activity" @class(['text-muted' => ! $monitorAttention, 'text-warn' => $monitorAttention]) />
                <span>{{ __('Canlı izleme') }}</span>
                <span @class(['ml-auto num text-right', 'text-muted' => ! $monitorAttention, 'font-medium text-warn' => $monitorAttention])>
                    {{ $monitorAttention ? trans_choice(':count site veya saldırı ilgilenmenizi bekliyor', $monitorAttention) : __('Sorun yok') }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($monitorAttention)
                <x-ask-ai question="{{ __('Canlı İzleme\'de ilgilenmem gereken siteler ya da saldırılar var. Neler oluyor, ne yapmalıyım?') }}" label="{{ __('AI\'ya sor') }}" class="mr-5" />
            @endif
        </div>
        @php
            $serviceProblems = \App\Services\System\SystemServices::problems();
            $serviceNames = collect($serviceProblems)->pluck('name')->join(', ');
        @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ $serviceProblems && count($serviceProblems) === 1 ? route('services.show', $serviceProblems[0]['unit']) : route('services.index') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $serviceProblems ? '' : 'pr-5' }}">
                <x-icon name="server-cog" @class(['text-muted' => ! $serviceProblems, 'text-crit' => $serviceProblems]) />
                <span>{{ __('Hizmetler') }}</span>
                <span @class(['ml-auto num truncate text-right', 'text-muted' => ! $serviceProblems, 'font-medium text-crit' => $serviceProblems])>
                    {{ $serviceProblems ? (count($serviceProblems) === 1 ? __(':name çalışmıyor', ['name' => $serviceNames]) : __(':count hizmet çalışmıyor: :names', ['count' => count($serviceProblems), 'names' => $serviceNames])) : (\App\Services\System\SystemServices::checked() ? __('Tüm hizmetler çalışıyor') : __('Henüz denetlenmedi')) }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($serviceProblems)
                <x-ask-ai question="{{ __('Sunucuda çalışmayan hizmetler var: :service_names. Neden durmuş olabilir, nasıl düzeltirim?', ['service_names' => $serviceNames]) }}" label="{{ __('AI\'ya sor') }}" class="mr-5" />
            @endif
        </div>
        @php
            $month = app(\App\Services\Stats\SiteStats::class)->thisMonth();
            $monthBytes = array_sum(array_column($month, 'bytes'));
            $monthVisitors = array_sum(array_column($month, 'visitors'));
        @endphp
        <a href="{{ route('sites.index', $monthBytes ? ['sirala' => 'trafik'] : []) }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
            <x-icon name="globe" class="text-muted" />
            <span>{{ __('Siteler') }}</span>
            <span class="ml-auto truncate text-right text-muted num">
                {{ trans_choice(':count site', \App\Models\Site::count()) }}{{ $monthBytes ? ' · '.__('bu ay :bytes trafik, :visitors ziyaretçi', ['bytes' => \App\Support\Bytes::format($monthBytes), 'visitors' => \App\Support\Num::format($monthVisitors, 0)]) : '' }}
            </span>
            <x-icon name="chevron-right" class="text-muted" />
        </a>
        @php
            $week = app(\App\Services\Stats\ServerHistory::class)->series('7g');
            $weekSum = \App\Services\Stats\ServerHistory::summary($week);
            $diskRatio = $weekSum['disk_total'] ? $weekSum['disk_used'] / $weekSum['disk_total'] : null;
        @endphp
        @if (count($week) > 1)
            <a href="{{ route('monitor.index', ['sekme' => 'sistem', 'gecmis' => '7g']) }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
                <x-icon name="gauge" @class(['text-muted' => $diskRatio < 0.9, 'text-warn' => $diskRatio >= 0.9]) />
                <span>{{ __('Sunucu kaynakları') }}</span>
                <span @class(['ml-auto truncate text-right num', 'text-muted' => $diskRatio < 0.9, 'font-medium text-warn' => $diskRatio >= 0.9])>
                    {{ __('Son 7 gün: işlemci ort. :cpu, bellek ort. :memory', ['cpu' => \App\Support\Load::cpu($weekSum['cpu']), 'memory' => $weekSum['memory_total'] ? \App\Support\Num::percent(round($weekSum['memory'] / $weekSum['memory_total'] * 100)) : \App\Support\Bytes::format($weekSum['memory'])]) }}{{ $diskRatio !== null ? ', '.__('disk :percent dolu', ['percent' => \App\Support\Num::percent(round($diskRatio * 100))]) : '' }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
        @endif
        @php $customerAttention = app(\App\Services\Customers\Quotas::class)->attentionCount(); @endphp
        <a href="{{ route('customers.index') }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
            <x-icon name="users" @class(['text-muted' => ! $customerAttention, 'text-warn' => $customerAttention]) />
            <span>{{ __('Müşteriler') }}</span>
            <span @class(['num ml-auto text-right', 'text-muted' => ! $customerAttention, 'font-medium text-warn' => $customerAttention])>
                {{ $customerAttention ? trans_choice(':count müşteri paket sınırına yaklaştı ya da aştı', $customerAttention) : trans_choice(':count müşteri', \App\Models\Customer::count()) }}
            </span>
            <x-icon name="chevron-right" class="text-muted" />
        </a>
        @php $sslAttention = \App\Models\Certificate::attentionCount(); @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('ssl.index', $sslAttention ? ['durum' => 'problem'] : []) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $sslAttention ? '' : 'pr-5' }}">
                <x-icon name="shield-check" @class(['text-muted' => ! $sslAttention, 'text-warn' => $sslAttention]) />
                <span>{{ __('SSL sertifikaları') }}</span>
                <span @class(['ml-auto num text-right', 'text-muted' => ! $sslAttention, 'font-medium text-warn' => $sslAttention])>
                    {{ $sslAttention ? trans_choice(':count sertifika ilgilenmenizi bekliyor', $sslAttention) : __('Sorun yok') }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($sslAttention)
                <x-ask-ai question="{{ __('İlgilenmem gereken SSL sertifikaları var. Hangileri, neden ve nasıl düzeltirim?') }}" label="{{ __('AI\'ya sor') }}" class="mr-5" />
            @endif
        </div>
        @php
            $securityAttention = \App\Services\Security\Scans::attentionCount();
            $lastScan = \App\Models\SecurityScan::where('scope', 'server')->whereNotNull('finished_at')->latest('id')->value('finished_at');
        @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('security.index', ['sekme' => 'tarama']) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $securityAttention ? '' : 'pr-5' }}">
                <x-icon name="shield-alert" @class(['text-muted' => ! $securityAttention, 'text-crit' => $securityAttention]) />
                <span>{{ __('Güvenlik') }}</span>
                <span @class(['ml-auto num truncate text-right', 'text-muted' => ! $securityAttention, 'font-medium text-crit' => $securityAttention])>
                    {{ $securityAttention ? trans_choice(':count ciddi güvenlik bulgusu var', $securityAttention) : ($lastScan ? __('Son taramada ciddi sorun yok · :when', ['when' => \Illuminate\Support\Carbon::parse($lastScan)->diffForHumans()]) : __('Henüz tarama yapılmadı')) }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($securityAttention)
                <x-ask-ai question="{{ __('Güvenlik taramasında ciddi bulgular var. Hangileri, ne kadar tehlikeli ve nasıl düzeltirim?') }}" label="{{ __('AI\'ya sor') }}" class="mr-5" />
            @endif
        </div>
        @if (! auth()->user()->hasTwoFactor())
            <a href="{{ route('account.security') }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
                <x-icon name="key-round" class="text-info" />
                <span>{{ __('Hesabınız') }}</span>
                <span class="num ml-auto truncate text-right font-medium text-info">{{ __('İki adımlı doğrulama kapalı; açmanızı öneririz') }}</span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
        @endif
        @php
            $backupProblems = app(\App\Services\Backups\Backups::class)->problems();
            $lastBackup = \App\Models\Backup::where('scope', 'server')->usable()->latest('finished_at')->value('finished_at');
        @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('backups.index') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $backupProblems ? '' : 'pr-5' }}">
                <x-icon name="archive" @class(['text-muted' => ! $backupProblems, 'text-warn' => $backupProblems]) />
                <span>{{ __('Yedekler') }}</span>
                <span @class(['ml-auto num truncate text-right', 'text-muted' => ! $backupProblems, 'font-medium text-warn' => $backupProblems])>
                    @if ($backupProblems)
                        {{ count($backupProblems) > 1 ? __(':count yedekleme sorunu:', ['count' => count($backupProblems)]).' ' : '' }}{{ $backupProblems[0]['text'] }}
                    @else
                        {{ $lastBackup ? __('Son sunucu yedeği :when', ['when' => \Illuminate\Support\Carbon::parse($lastBackup)->diffForHumans()]) : __('Henüz sunucu yedeği yok') }}
                    @endif
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($backupProblems)
                <x-ask-ai question="{{ __('Yedeklemede sorun var: :text Neden olmuş olabilir, nasıl düzeltirim?', ['text' => $backupProblems[0]['text']]) }}" label="{{ __('AI\'ya sor') }}" class="mr-5" />
            @endif
        </div>
        @php $updateNotices = app(\App\Services\Updates\Updates::class)->notices(); @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a @if (auth()->user()->isFullAdmin()) href="{{ route('settings.updates') }}" wire:navigate @endif class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 pr-5">
                <x-icon name="refresh-cw" @class(['text-muted' => ! $updateNotices, 'text-warn' => ($updateNotices[0]['level'] ?? '') === 'warn', 'text-info' => ($updateNotices[0]['level'] ?? '') === 'info']) />
                <span>{{ __('Servik sürümü') }}</span>
                <span @class(['ml-auto num truncate text-right', 'text-muted' => ! $updateNotices, 'font-medium text-warn' => ($updateNotices[0]['level'] ?? '') === 'warn', 'font-medium text-info' => ($updateNotices[0]['level'] ?? '') === 'info'])>
                    {{ $updateNotices ? collect($updateNotices)->pluck('text')->join(' · ') : 'Servik '.config('servik.version') }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
        </div>
    </section>
</x-layouts.app>
