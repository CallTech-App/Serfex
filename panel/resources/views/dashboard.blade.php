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
    </section>
</x-layouts.app>
