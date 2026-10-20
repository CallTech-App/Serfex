@php
    use App\Livewire\Customers\UsageReport;
    use App\Services\Stats\DiskBreakdown;
    use App\Services\Stats\SiteStats;
    use App\Support\Bytes;
    $admin = auth()->user()->is_admin;
    $num = fn (int $n) => number_format($n, 0, ',', '.');
@endphp
<div class="space-y-6">
    <div>
        @if ($admin)
            <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
                <a href="{{ route('customers.index') }}" wire:navigate class="hover:text-accent">Müşteriler ve Paketler</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <a href="{{ route('customers.show', $customer) }}" wire:navigate class="hover:text-accent">{{ $customer->displayName() }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <span class="text-fg">Kullanım</span>
            </nav>
        @endif
        <h1 class="{{ $admin ? 'mt-2' : '' }} text-[22px] font-semibold tracking-tight">Kullanım</h1>
        <p class="mt-1 text-[13px] text-muted">{{ $customer->package->name }} paketinin sınırları, diskin neyle dolduğu ve aylık trafik.</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (UsageReport::TABS as $key => $label)
                <button type="button" role="tab" wire:click="switchTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'kota')
            @include('livewire.customers.usage', ['report' => $report])
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">
                Disk ve trafik %85'te turuncu olur ve e-postayla haber verilir; sınıra gelince yeni site, posta kutusu ya da uygulama açılmaz.
                Trafik sınırı aşılsa da siteler çalışmaya devam eder; trafik her ayın başında sıfırlanır.
            </p>
        @elseif ($tab === 'disk')
            @php $total = max($disk['total'], 1); @endphp
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Site</th>
                            @foreach (DiskBreakdown::PARTS as [$label])
                                <th class="px-3 py-2.5 text-right font-medium">{{ $label }}</th>
                            @endforeach
                            <th class="py-2.5 pr-5 pl-3 text-right font-medium">Toplam</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($disk['sites'] as $row)
                            <tr wire:key="disk-{{ $row['site']->id }}" class="hover:bg-surface-2/60">
                                <td class="py-2.5 pr-3 pl-5"><a href="{{ route('sites.disk', $row['site']) }}" wire:navigate class="font-mono text-[13px] hover:text-accent">{{ $row['site']->domain }}</a></td>
                                @foreach (array_keys(DiskBreakdown::PARTS) as $key)
                                    <td @class(['num px-3 py-2.5 text-right whitespace-nowrap', 'text-muted' => ! $row['parts'][$key]])>{{ $row['parts'][$key] === null ? '—' : Bytes::format($row['parts'][$key]) }}</td>
                                @endforeach
                                <td class="num py-2.5 pr-5 pl-3 text-right font-medium whitespace-nowrap">{{ Bytes::format($row['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-[13px] text-muted">Henüz site yok.</td></tr>
                        @endforelse
                        @if ($disk['other'] > 0)
                            <tr class="text-muted">
                                <td class="py-2.5 pr-3 pl-5">Site klasörleri dışındaki dosyalar</td>
                                <td class="num px-3 py-2.5 text-right whitespace-nowrap">{{ Bytes::format($disk['other']) }}</td>
                                <td colspan="4"></td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-line">
                            <td class="py-3 pr-3 pl-5 font-semibold">Toplam</td>
                            @foreach (array_keys(DiskBreakdown::PARTS) as $key)
                                <td class="num px-3 py-3 text-right whitespace-nowrap">{{ Bytes::format($disk['parts'][$key]) }} <span class="block text-2xs text-muted">%{{ round($disk['parts'][$key] / $total * 100) }}</span></td>
                            @endforeach
                            <td class="num py-3 pr-5 pl-3 text-right font-semibold whitespace-nowrap">{{ Bytes::format($disk['total']) }}
                                <span class="block text-2xs font-normal text-muted">{{ $report['disk']['limit'] ? Bytes::format($report['disk']['limit']).' paketten' : 'Sınırsız' }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">Web dosyaları ev klasörünün tamamıdır; site satırları her sitenin kendi klasörünü gösterir. Dosyalar 30 dakikada bir ölçülür.</p>
        @else
            @php
                $limit = $report['traffic']['limit'];
                $points = array_map(fn ($m) => [SiteStats::monthName($m['at']), $m['bytes'], SiteStats::monthName($m['at']).': '.Bytes::format($m['bytes'])], $months);
            @endphp
            <div class="border-b border-line px-5 pt-4 pb-4">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2 text-[13px]">
                    <span class="font-medium">Son 12 ay</span>
                    <span class="num text-muted">Bu ay {{ $report['traffic']['text'] }}{{ $limit ? ' · aylık sınır '.Bytes::format($limit) : '' }}</span>
                </div>
                <x-bar-chart :points="$points" unit="Aylık trafik" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Site</th>
                            <th class="px-3 py-2.5 text-right font-medium">Ziyaretçi (bu ay)</th>
                            <th class="px-3 py-2.5 text-right font-medium">Trafik (bu ay)</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($sites as $row)
                            <tr wire:key="traffic-{{ $row['site']->id }}" class="hover:bg-surface-2/60">
                                <td class="py-2.5 pr-3 pl-5 font-mono text-[13px]">{{ $row['site']->domain }}</td>
                                <td class="num px-3 py-2.5 text-right">{{ $num($row['visitors']) }}</td>
                                <td class="num px-3 py-2.5 text-right">{{ Bytes::format($row['bytes']) }}</td>
                                <td class="py-2.5 pr-5 pl-3 text-right"><a href="{{ route('sites.stats', $row['site']) }}" wire:navigate class="text-[13px] text-accent hover:underline">İstatistikler</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-10 text-center text-[13px] text-muted">Henüz site yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">Trafik, sitelerin ziyaretçilere gönderdiği veridir (alt alan adları ve ek alan adları dahil). Sınır aşılsa da siteler kapanmaz.</p>
        @endif
    </section>
</div>
