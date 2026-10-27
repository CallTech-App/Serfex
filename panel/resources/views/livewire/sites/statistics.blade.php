@php
    use App\Livewire\Sites\SiteStatistics;
    use App\Services\Stats\SiteStats;
    use App\Support\Bytes;
    $num = fn (int $n) => \App\Support\Num::format($n, 0);
    $field = 'h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px] outline-none focus:border-accent';
    $fmt = fn (string $metric, int $v) => $metric === 'bytes' ? Bytes::format($v) : $num($v);
    $points = array_map(fn ($p) => [$p['label'], $p[$metric], $p['label'].': '.$fmt($metric, $p[$metric]).' '.mb_strtolower(SiteStatistics::METRICS[$metric])], $series);
    $botShare = $totals['requests'] > 0 ? (int) round($totals['bot_requests'] / $totals['requests'] * 100) : 0;
    $empty = $totals['requests'] === 0;
    [$tabLabel, $heading] = SiteStats::TABS[$tab];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
                <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <span class="text-fg">{{ __('İstatistikler') }}</span>
            </nav>
            <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('İstatistikler') }}</h1>
            <p class="mt-1 text-[13px] text-muted">{{ __('Ziyaretçiler, sayfalar ve bant genişliği. Serfex bunları sitenin erişim günlüğünden sayar; ziyaretçi bilgisi sunucudan çıkmaz.') }}</p>
        </div>
        <label class="flex items-center gap-2 text-[13px] text-muted">
            {{ __('Dönem') }}
            <select wire:model.live="period" class="{{ $field }} text-fg">
                @foreach (SiteStats::PERIODS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line px-5 py-3 text-[13.5px]">
            <p class="num w-full min-w-0 text-muted sm:w-auto sm:flex-1">
                <x-t text=":visitors ziyaretçi · :pages sayfa görüntüleme · :requests istek · :bytes bant genişliği"><x-slot:visitors><span class="font-semibold text-fg">{{ $num($totals['visitors']) }}</span></x-slot:visitors><x-slot:pages><span class="font-semibold text-fg">{{ $num($totals['pages']) }}</span></x-slot:pages><x-slot:requests><span class="font-semibold text-fg">{{ $num($totals['requests']) }}</span></x-slot:requests><x-slot:bytes><span class="font-semibold text-fg">{{ Bytes::format($totals['bytes']) }}</span></x-slot:bytes></x-t>
                @if ($totals['requests'] > 0)
                    {{ __('· botlar isteklerin %:bot_share\'i', ['bot_share' => $botShare]) }}
                @endif
            </p>
            <select wire:model.live="metric" aria-label="{{ __('Grafikte gösterilen') }}" class="{{ $field }} h-8 text-[13px]">
                @foreach (SiteStatistics::METRICS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if ($empty)
            <div class="px-5 py-14 text-center">
                <p class="font-medium">{{ __('Bu dönem için istatistik yok') }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ __('Site ziyaret aldıkça sayılar burada görünür; dakikada bir güncellenir.') }}</p>
            </div>
        @else
            <div class="border-b border-line px-5 pt-5 pb-4">
                <x-bar-chart :points="$points" :unit="SiteStatistics::METRICS[$metric]" />
            </div>

            <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
                @foreach (SiteStats::TABS as $key => [$label])
                    <button type="button" role="tab" wire:click="switchTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
                @endforeach
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ $heading }}</th>
                            @if ($tab === 'country')
                                <th class="px-3 py-2.5 text-right font-medium">{{ __('Ziyaretçi') }}</th>
                                <th class="px-3 py-2.5 text-right font-medium">{{ __('İstek') }}</th>
                            @elseif ($tab === 'referrer')
                                <th class="px-3 py-2.5 text-right font-medium">{{ __('Gelen ziyaret') }}</th>
                            @elseif ($tab === 'page')
                                <th class="px-3 py-2.5 text-right font-medium">{{ __('Görüntüleme') }}</th>
                                <th class="px-3 py-2.5 text-right font-medium">{{ __('Bant genişliği') }}</th>
                            @else
                                <th class="px-3 py-2.5 text-right font-medium">{{ __('İstek') }}</th>
                                <th class="px-3 py-2.5 text-right font-medium">{{ __('Bant genişliği') }}</th>
                            @endif
                            <th class="w-44 py-2.5 pr-5 pl-3 font-medium">{{ __('Pay') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($items as $row)
                            <tr wire:key="item-{{ $tab }}-{{ md5($row['value']) }}" class="hover:bg-surface-2/60">
                                <td class="max-w-[22rem] truncate py-2.5 pr-3 pl-5 {{ in_array($tab, ['page', 'referrer'], true) ? 'font-mono text-[12.5px]' : '' }}" title="{{ $row['label'] }}">
                                    {{ $row['label'] }}
                                    @if ($tab === 'country' && $row['value'] !== '-')
                                        <span class="font-mono text-2xs text-muted">{{ $row['value'] }}</span>
                                    @endif
                                </td>
                                @if ($tab === 'country')
                                    <td class="num px-3 py-2.5 text-right">{{ $num($row['visitors']) }}</td>
                                    <td class="num px-3 py-2.5 text-right text-muted">{{ $num($row['requests']) }}</td>
                                @elseif ($tab === 'referrer')
                                    <td class="num px-3 py-2.5 text-right">{{ $num($row['requests']) }}</td>
                                @else
                                    <td class="num px-3 py-2.5 text-right">{{ $num($row['requests']) }}</td>
                                    <td class="num px-3 py-2.5 text-right text-muted">{{ Bytes::format($row['bytes']) }}</td>
                                @endif
                                <td class="py-2.5 pr-5 pl-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-2"><span class="block h-full rounded-full bg-muted" style="width: {{ max(1, min(100, round($row['share'] * 100))) }}%"></span></span>
                                        <span class="num text-[12.5px] text-muted">{{ \App\Support\Num::percent($row['share'] * 100, $row['share'] < 0.1 ? 1 : 0) }}</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-[13px] text-muted">
                                    @if ($tab === 'referrer')
                                        {{ __('Bu dönemde başka bir siteden gelen ziyaret yok.') }}
                                    @elseif ($tab === 'bot')
                                        {{ __('Bu dönemde bot ziyareti yok.') }}
                                    @else
                                        {{ __('Bu dönem için kayıt yok.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">
            @if ($tab === 'country' && ! $countriesReady)
                {{ __('Ülke bilgisi hazırlanıyor: sunucu, bölgesel İnternet kayıt kuruluşlarının adres listelerini indirince ülkeler görünür.') }}
            @elseif ($tab === 'country')
                {{ __('Ülke, adresin kayıtlı olduğu yerdir (bölgesel İnternet kayıt kuruluşlarının listeleri, ayda bir güncellenir).') }}
            @elseif ($tab === 'bot')
                {{ __('Arama motorları, SEO araçları ve otomatik istemciler. Ziyaretçi, sayfa ve ülke sayılarına katılmaz.') }}
            @else
                {{ __('Ziyaretçi her gün ayrı sayılır: üç gün gelen biri dönemde üç ziyaretçidir. Botlar ziyaretçi ve sayfa sayılarına katılmaz.') }}
            @endif
        </p>
    </section>
</div>
