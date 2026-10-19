<x-layouts.app title="Genel Bakış · Servik">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">Genel Bakış</h1>
        <p class="mt-1 text-[13px] text-muted">Sunucunun ve sitelerin durumu.</p>
    </div>

    <section class="divide-y divide-line rounded-xl border border-line bg-surface text-[13.5px]">
        <div class="flex items-center gap-3 px-5 py-4">
            <livewire:agent-status />
        </div>
        @php $monitorAttention = (int) \Illuminate\Support\Facades\Cache::get(\App\Services\Monitor\LiveStats::ATTENTION_KEY, 0); @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('monitor.index') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $monitorAttention ? '' : 'pr-5' }}">
                <x-icon name="activity" @class(['text-muted' => ! $monitorAttention, 'text-warn' => $monitorAttention]) />
                <span>Canlı izleme</span>
                <span @class(['ml-auto num text-right', 'text-muted' => ! $monitorAttention, 'font-medium text-warn' => $monitorAttention])>
                    {{ $monitorAttention ? $monitorAttention.' site veya saldırı ilgilenmenizi bekliyor' : 'Sorun yok' }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($monitorAttention)
                <x-ask-ai question="Canlı İzleme'de ilgilenmem gereken siteler ya da saldırılar var. Neler oluyor, ne yapmalıyım?" label="AI'ya sor" class="mr-5" />
            @endif
        </div>
        <a href="{{ route('sites.index') }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
            <x-icon name="globe" class="text-muted" />
            <span>Siteler</span>
            <span class="ml-auto text-muted num">{{ \App\Models\Site::count() }} site</span>
            <x-icon name="chevron-right" class="text-muted" />
        </a>
        @php $customerAttention = app(\App\Services\Customers\Quotas::class)->attentionCount(); @endphp
        <a href="{{ route('customers.index') }}" wire:navigate class="flex items-center gap-3 px-5 py-4 hover:bg-surface-2/60">
            <x-icon name="users" @class(['text-muted' => ! $customerAttention, 'text-warn' => $customerAttention]) />
            <span>Müşteriler</span>
            <span @class(['num ml-auto text-right', 'text-muted' => ! $customerAttention, 'font-medium text-warn' => $customerAttention])>
                {{ $customerAttention ? $customerAttention.' müşteri paket sınırına yaklaştı ya da aştı' : \App\Models\Customer::count().' müşteri' }}
            </span>
            <x-icon name="chevron-right" class="text-muted" />
        </a>
        @php $sslAttention = \App\Models\Certificate::attentionCount(); @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('ssl.index', $sslAttention ? ['durum' => 'problem'] : []) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $sslAttention ? '' : 'pr-5' }}">
                <x-icon name="shield-check" @class(['text-muted' => ! $sslAttention, 'text-warn' => $sslAttention]) />
                <span>SSL sertifikaları</span>
                <span @class(['ml-auto num text-right', 'text-muted' => ! $sslAttention, 'font-medium text-warn' => $sslAttention])>
                    {{ $sslAttention ? $sslAttention.' sertifika ilgilenmenizi bekliyor' : 'Sorun yok' }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($sslAttention)
                <x-ask-ai question="İlgilenmem gereken SSL sertifikaları var. Hangileri, neden ve nasıl düzeltirim?" label="AI'ya sor" class="mr-5" />
            @endif
        </div>
        @php
            $backupProblems = app(\App\Services\Backups\Backups::class)->problems();
            $lastBackup = \App\Models\Backup::where('scope', 'server')->usable()->latest('finished_at')->value('finished_at');
        @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('backups.index') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 {{ $backupProblems ? '' : 'pr-5' }}">
                <x-icon name="archive" @class(['text-muted' => ! $backupProblems, 'text-warn' => $backupProblems]) />
                <span>Yedekler</span>
                <span @class(['ml-auto num truncate text-right', 'text-muted' => ! $backupProblems, 'font-medium text-warn' => $backupProblems])>
                    @if ($backupProblems)
                        {{ count($backupProblems) > 1 ? count($backupProblems).' yedekleme sorunu: ' : '' }}{{ $backupProblems[0]['text'] }}
                    @else
                        {{ $lastBackup ? 'Son sunucu yedeği '.\Illuminate\Support\Carbon::parse($lastBackup)->diffForHumans() : 'Henüz sunucu yedeği yok' }}
                    @endif
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
            @if ($backupProblems)
                <x-ask-ai question="Yedeklemede sorun var: {{ $backupProblems[0]['text'] }} Neden olmuş olabilir, nasıl düzeltirim?" label="AI'ya sor" class="mr-5" />
            @endif
        </div>
        @php $updateNotices = app(\App\Services\Updates\Updates::class)->notices(); @endphp
        <div class="flex items-center gap-2 hover:bg-surface-2/60">
            <a href="{{ route('settings.updates') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 py-4 pl-5 pr-5">
                <x-icon name="refresh-cw" @class(['text-muted' => ! $updateNotices, 'text-warn' => ($updateNotices[0]['level'] ?? '') === 'warn', 'text-info' => ($updateNotices[0]['level'] ?? '') === 'info']) />
                <span>Servik sürümü</span>
                <span @class(['ml-auto num truncate text-right', 'text-muted' => ! $updateNotices, 'font-medium text-warn' => ($updateNotices[0]['level'] ?? '') === 'warn', 'font-medium text-info' => ($updateNotices[0]['level'] ?? '') === 'info'])>
                    {{ $updateNotices ? collect($updateNotices)->pluck('text')->join(' · ') : 'Servik '.config('servik.version') }}
                </span>
                <x-icon name="chevron-right" class="text-muted" />
            </a>
        </div>
    </section>
</x-layouts.app>
