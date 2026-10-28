@php
    use App\Support\Bytes;
    use App\Support\Load;
    use App\Support\Num;
    use App\Support\When;

    $tones = [
        'ok' => ['dot' => 'bg-ok', 'text' => 'text-muted', 'icon' => 'text-muted', 'strip' => 'bg-ok'],
        'muted' => ['dot' => 'bg-muted/50', 'text' => 'text-muted', 'icon' => 'text-muted', 'strip' => 'bg-muted'],
        'info' => ['dot' => 'bg-info', 'text' => 'font-medium text-info', 'icon' => 'text-info', 'strip' => 'bg-info'],
        'warn' => ['dot' => 'bg-warn', 'text' => 'font-medium text-warn', 'icon' => 'text-warn', 'strip' => 'bg-warn'],
        'crit' => ['dot' => 'bg-crit', 'text' => 'font-medium text-crit', 'icon' => 'text-crit', 'strip' => 'bg-crit'],
    ];
    $mbit = fn (float $bytes) => __(':rate Mbit/sn', ['rate' => Num::format($bytes * 8 / 1e6, $bytes * 8 < 1e7 ? 1 : 0)]);
    $readOnly = auth()->user()->isReadOnly();
@endphp

<div class="space-y-6" wire:poll.30s.visible>
    {{-- Başlık --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Genel Bakış') }}</h1>
            <p class="num mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-muted">
                <span class="inline-flex items-center gap-1.5" title="{{ $online ? __('Sunucu ajanı bağlı') : __('Sunucu ajanına ulaşılamıyor') }}">
                    <i @class(['size-1.5 rounded-full', 'bg-ok' => $online, 'bg-crit' => ! $online])></i><span class="font-mono text-fg">{{ $server['name'] }}</span>
                </span>
                @if ($server['os'])
                    <span aria-hidden="true">·</span><span>{{ $server['os'] }}</span>
                @endif
                @if ($server['cpus'])
                    <span aria-hidden="true">·</span><span>{{ trans_choice(':count çekirdek', $server['cpus']) }}, {{ Bytes::format($server['memory']) }}</span>
                @endif
                @if ($server['uptime'])
                    <span aria-hidden="true">·</span><span>{{ __(':uptime açık', ['uptime' => Load::uptime($server['uptime'])]) }}</span>
                @endif
            </p>
        </div>
        @unless ($readOnly)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('laravel.index') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="layers" class="text-muted" />{{ __('Laravel uygulaması kur') }}</a>
                <a href="{{ route('sites.index') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="plus" />{{ __('Site ekle') }}</a>
            </div>
        @endunless
    </div>

    @if (auth()->user()->isFullAdmin() && ! app(\App\Services\Setup\FirstRun::class)->completed())
        <x-setup-checklist />
    @endif

    {{-- Sunucu durumu: özet, denetimler ve ilgilenilmesi gerekenler --}}
    <section aria-labelledby="health-title" class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="flex flex-col lg:flex-row lg:items-stretch">
            <div class="flex items-center gap-4 border-b border-line px-5 py-5 lg:w-[320px] lg:shrink-0 lg:border-r lg:border-b-0">
                @if ($problems)
                    <span @class(['grid size-12 shrink-0 place-items-center rounded-full', 'bg-crit-soft text-crit' => collect($problems)->contains('tone', 'crit'), 'bg-warn-soft text-warn' => ! collect($problems)->contains('tone', 'crit')])><x-icon name="triangle-alert" class="size-6" /></span>
                @else
                    <span class="grid size-12 shrink-0 place-items-center rounded-full bg-ok-soft text-ok"><x-icon name="circle-check" class="size-6" /></span>
                @endif
                <div class="min-w-0">
                    <p class="text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Sunucu durumu') }}</p>
                    <h2 id="health-title" class="mt-0.5 text-[17px] font-semibold tracking-tight">
                        {{ $problems ? trans_choice(':count konu ilgilenmenizi bekliyor', count($problems)) : __('Her şey yolunda') }}
                    </h2>
                    <p class="num mt-0.5 text-[12.5px] text-muted">{{ __(':ok / :total denetim sorunsuz · :time', ['ok' => $okCount, 'total' => count($checks), 'time' => When::time(now())]) }}</p>
                </div>
            </div>
            <ul class="grid flex-1 grid-cols-1 gap-px bg-line sm:grid-cols-3" aria-label="{{ __('Denetimler') }}">
                @foreach ($checks as $check)
                    <li class="bg-surface">
                        <a @if ($check['url']) href="{{ $check['url'] }}" wire:navigate @endif @class(['flex h-full items-center gap-3 px-4 py-3', 'hover:bg-surface-2/60' => $check['url']])>
                            <x-icon :name="$check['icon']" @class([$tones[$check['tone']]['icon']]) />
                            <span class="min-w-0 flex-1 leading-tight">
                                <span class="block text-[13px] font-medium">{{ $check['name'] }}</span>
                                <span @class(['num mt-0.5 block truncate text-[12.5px]', $tones[$check['tone']]['text']]) title="{{ $check['text'] }}">{{ $check['text'] }}</span>
                            </span>
                            <i @class(['size-2 shrink-0 rounded-full', $tones[$check['tone']]['dot']]) aria-hidden="true"></i>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($problems)
            <ul class="divide-y divide-line border-t border-line">
                @foreach ($problems as $p)
                    <li class="relative flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center md:gap-6">
                        <span @class(['absolute inset-y-0 left-0 w-1', $tones[$p['tone']]['strip']])></span>
                        <div class="flex min-w-0 flex-1 gap-3">
                            <x-icon :name="$p['icon']" @class(['mt-0.5 size-5', $tones[$p['tone']]['icon']]) />
                            <div class="min-w-0">
                                <p class="font-medium">{{ $p['problem']['title'] }}</p>
                                <p class="mt-0.5 text-[13px] break-words text-muted">{{ $p['problem']['text'] }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2 pl-8 md:pl-0">
                            @if ($p['problem']['ai'])
                                <x-ask-ai :question="$p['problem']['ai']" :label="__('AI\'ya sor')" />
                            @endif
                            @if ($p['problem']['refresh'])
                                <button type="button" wire:click="$refresh" class="h-8 rounded-md border border-line px-3 text-[13px] font-medium hover:bg-surface-2">{{ __('Yeniden dene') }}</button>
                            @elseif ($p['problem']['action'])
                                <a href="{{ $p['problem']['action'][1] }}" wire:navigate class="flex h-8 items-center rounded-md border border-line px-3 text-[13px] font-medium hover:bg-surface-2">{{ $p['problem']['action'][0] }}</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($ai['enabled'])
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line bg-accent-soft/60 px-5 py-3 text-[13px]">
                <x-icon name="sparkles" class="text-accent" />
                <p class="min-w-0 flex-1">
                    <b class="font-medium">Serfex AI</b>
                    @if ($ai['count'])
                        {{ trans_choice('son 24 saatte :count adresi kendiliğinden engelledi; en son :address (:time).', $ai['count'], ['address' => $ai['last']->address, 'time' => When::short($ai['last']->created_at)]) }}
                    @else
                        {{ __('sunucuyu sizinle birlikte izliyor. Bir sorunu anlamak ya da çözmek için ona sorun.') }}
                    @endif
                </p>
                @if ($ai['count'] && ! $readOnly)
                    <a href="{{ route('monitor.index', ['tab' => 'attacks']) }}" wire:navigate class="font-medium text-accent hover:underline">{{ __('Engellere bak') }}</a>
                @else
                    <x-ask-ai :question="__('Sunucumun şu anki durumunu kısaca özetler misin? Dikkat etmem gereken bir şey var mı?')" :label="__('Durumu özetlet')" class="bg-transparent px-0 hover:bg-transparent hover:underline" />
                @endif
            </div>
        @endif
    </section>

    {{-- Sunucu kaynakları --}}
    @if ($resources)
        @php $r = $resources; $day = $r['day']; @endphp
        <section aria-labelledby="resources-title" class="space-y-3">
            <div class="flex items-center gap-3">
                <h2 id="resources-title" class="text-[14.5px] font-semibold">{{ __('Sunucu kaynakları') }}</h2>
                <span class="hidden text-2xs text-muted sm:inline">{{ __('şu an · grafikler son 24 saat') }}</span>
                <a href="{{ route('monitor.index', ['tab' => 'system']) }}" wire:navigate class="ml-auto text-[13px] text-accent hover:underline">{{ __('Canlı izlemede aç') }}</a>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="min-w-0 rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-2 text-[13px] text-muted"><x-icon name="cpu" />{{ __('İşlemci') }}</div>
                    <div class="mt-2 flex flex-wrap items-baseline gap-x-2"><span class="num text-[26px] font-semibold tracking-tight">{{ Load::cpu($r['cpu']) }}</span><span class="num text-[12.5px] text-muted">{{ __('yük :load', ['load' => implode(' · ', array_map(fn ($l) => Load::number($l, 2), $r['load']))]) }}</span></div>
                    <x-sparkline :points="$day['cpu'] ?? []" :max="100" class="mt-3 h-12 text-accent" />
                    <p class="num mt-2 text-2xs text-muted">{{ $day ? __('ort. :cpu · en yüksek :cpu_max', ['cpu' => Load::cpu($day['summary']['cpu']), 'cpu_max' => Load::cpu($day['summary']['cpu_max'])]) : __('Geçmiş birkaç dakika içinde birikir.') }}</p>
                </div>
                <div class="min-w-0 rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-2 text-[13px] text-muted"><x-icon name="memory-stick" />{{ __('Bellek') }}</div>
                    <div class="mt-2 flex flex-wrap items-baseline gap-x-2"><span class="num text-[26px] font-semibold tracking-tight">{{ Bytes::format($r['memory']) }}</span><span class="num text-[12.5px] text-muted">/ {{ Bytes::format($r['memory_total']) }}{{ $r['memory_total'] ? ' · '.Num::percent(round($r['memory'] / $r['memory_total'] * 100)) : '' }}</span></div>
                    <x-sparkline :points="$day['memory'] ?? []" :max="$r['memory_total'] ?: null" class="mt-3 h-12 text-info" />
                    <p class="num mt-2 text-2xs text-muted">{{ $day ? __('ort. :memory · en yüksek :memory_max:memory_total', ['memory' => Bytes::format($day['summary']['memory']), 'memory_max' => Bytes::format($day['summary']['memory_max']), 'memory_total' => '']) : __('Geçmiş birkaç dakika içinde birikir.') }}</p>
                </div>
                <div class="min-w-0 rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-2 text-[13px] text-muted"><x-icon name="hard-drive" />{{ __('Disk') }}</div>
                    @if ($r['disk'])
                        @php [$used, $total] = $r['disk']; $ratio = $used / $total; @endphp
                        <div class="mt-2 flex flex-wrap items-baseline gap-x-2"><span class="num text-[26px] font-semibold tracking-tight">{{ Bytes::format($used) }}</span><span class="num text-[12.5px] text-muted">/ {{ Bytes::format($total) }} · {{ Num::percent(round($ratio * 100)) }}</span></div>
                        <div class="mt-3 flex h-12 flex-col justify-center gap-2">
                            <div class="h-2.5 overflow-hidden rounded-full bg-surface-2"><span @class(['block h-full', 'bg-accent' => $ratio < 0.9, 'bg-warn' => $ratio >= 0.9 && $ratio < 0.97, 'bg-crit' => $ratio >= 0.97]) style="width: {{ round($ratio * 100, 1) }}%"></span></div>
                            <span class="num text-2xs text-muted">{{ __(':free boş', ['free' => Bytes::format(max(0, $total - $used))]) }}</span>
                        </div>
                        <p class="num mt-2 text-2xs text-muted">{{ $r['days_left'] !== null ? __('Bu hızla yaklaşık :days gün sonra dolar', ['days' => Num::format($r['days_left'])]) : __('Son 30 günde büyümedi') }}</p>
                    @else
                        <p class="mt-2 text-[13px] text-muted">{{ __('Ölçülmedi') }}</p>
                    @endif
                </div>
                <div class="min-w-0 rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-2 text-[13px] text-muted"><x-icon name="arrow-down-up" />{{ __('Ağ') }}</div>
                    <div class="mt-2 flex flex-wrap items-baseline gap-x-2"><span class="num text-[26px] font-semibold tracking-tight">{{ $mbit($r['net_tx']) }}</span><span class="num text-[12.5px] text-muted">{{ __('giden · :rate gelen', ['rate' => Num::format($r['net_rx'] * 8 / 1e6, $r['net_rx'] * 8 < 1e7 ? 1 : 0)]) }}</span></div>
                    <x-sparkline :points="$day['net'] ?? []" class="mt-3 h-12 text-accent" />
                    <p class="num mt-2 text-2xs text-muted">{{ $day ? __('Son 24 saat: :net_tx giden, :net_rx gelen', ['net_tx' => Bytes::format($day['summary']['net_tx']), 'net_rx' => Bytes::format($day['summary']['net_rx'])]) : __('Geçmiş birkaç dakika içinde birikir.') }}</p>
                </div>
            </div>
        </section>
    @endif

    {{-- Barındırma --}}
    <section aria-labelledby="hosting-title" class="min-w-0 overflow-hidden rounded-xl border border-line bg-surface">
        <div class="flex h-12 items-center gap-3 border-b border-line px-5">
            <h2 id="hosting-title" class="text-[14.5px] font-semibold">{{ __('Barındırma') }}</h2>
        </div>
        <ul class="grid grid-cols-2 gap-px bg-line sm:grid-cols-3 xl:grid-cols-6">
            @foreach ($inventory as $item)
                <li class="min-w-0 bg-surface">
                    <a href="{{ $item['url'] }}" wire:navigate class="block px-5 py-4 hover:bg-surface-2/60">
                        <span class="flex items-center gap-2 text-[13px] text-muted"><x-icon :name="$item['icon']" />{{ $item['name'] }}</span>
                        <span class="num mt-1.5 block text-[24px] font-semibold tracking-tight">{{ Num::format($item['count']) }}</span>
                        <span @class(['num mt-0.5 block truncate text-[12.5px]', $item['tone'] ? $tones[$item['tone']]['text'] : 'text-muted']) title="{{ $item['note'] }}">{{ $item['note'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Siteler ve hizmetler --}}
    @if ($sites !== null)
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <section aria-labelledby="sites-title" class="min-w-0 rounded-xl border border-line bg-surface xl:col-span-2">
                <div class="flex h-12 items-center gap-3 border-b border-line px-5">
                    <h2 id="sites-title" class="text-[14.5px] font-semibold">{{ __('En çok kaynak kullanan siteler') }}</h2>
                    <span class="hidden text-2xs text-muted sm:inline">{{ __('son 1 dakika') }}</span>
                    <a href="{{ route('monitor.index') }}" wire:navigate class="ml-auto text-[13px] whitespace-nowrap text-accent hover:underline">{{ __('Tüm siteler') }}</a>
                </div>
                @if ($sites)
                    <div class="relative overflow-x-auto">
                        <table class="w-full min-w-[600px] text-[13px]">
                            <thead>
                                <tr class="text-left text-2xs tracking-[.06em] text-muted uppercase">
                                    <th class="px-5 py-2.5 font-medium">{{ __('Site') }}</th>
                                    <th class="w-44 px-3 py-2.5 font-medium">CPU</th>
                                    <th class="px-3 py-2.5 text-right font-medium">{{ __('Bellek') }}</th>
                                    <th class="px-3 py-2.5 text-right font-medium">{{ __('İstek/sn') }}</th>
                                    <th class="py-2.5 pr-5 pl-3 font-medium">{{ __('Durum') }}</th>
                                </tr>
                            </thead>
                            <tbody class="num divide-y divide-line whitespace-nowrap">
                                @foreach ($sites as $row)
                                    @php $cpu = (float) $row['u']['cpu']; @endphp
                                    <tr wire:key="busy-{{ $row['site']->id }}" class="hover:bg-surface-2/60">
                                        <td class="px-5 py-3">
                                            <a href="{{ route('sites.show', $row['site']) }}" wire:navigate class="font-mono font-medium hover:text-accent">{{ $row['site']->domain }}</a>
                                            <div class="mt-0.5 text-2xs text-muted">{{ $row['site']->stackLabel() }}</div>
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="flex items-center gap-2">
                                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-2"><span @class(['block h-full', 'bg-crit' => $row['badge']->tone() === 'crit', 'bg-warn' => $row['badge']->tone() === 'warn', 'bg-accent' => ! in_array($row['badge']->tone(), ['crit', 'warn'], true)]) style="width: {{ min(100, round($cpu)) }}%"></span></div>
                                                <span class="w-10 text-right">{{ Load::cpu($cpu) }}</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3 text-right">{{ Bytes::format((int) $row['u']['memory']) }}</td>
                                        <td class="px-3 py-3 text-right">{{ Load::rate((float) $row['u']['requests']) }}</td>
                                        <td class="py-3 pr-5 pl-3"><x-status-badge :status="$row['badge']" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="px-5 py-10 text-center text-[13px] text-muted">
                        <p>{{ __('Henüz site yok. İlk siteyi eklediğinizde kullanımı burada görünür.') }}</p>
                        @unless ($readOnly)
                            <a href="{{ route('sites.index') }}" wire:navigate class="mt-2 inline-block font-medium text-accent hover:underline">{{ __('Site ekle') }}</a>
                        @endunless
                    </div>
                @endif
            </section>

            <section aria-labelledby="services-title" class="min-w-0 rounded-xl border border-line bg-surface">
                @php $downUnits = array_column($services['down'], 'unit'); @endphp
                <div class="flex h-12 items-center gap-3 border-b border-line px-5">
                    <h2 id="services-title" class="text-[14.5px] font-semibold">{{ __('Hizmetler') }}</h2>
                    @if ($services['down'])
                        <x-status-badge :status="new \App\Support\Badge(trans_choice(':count hizmet çalışmıyor', count($services['down'])), 'crit')" />
                    @endif
                    <a href="{{ route('services.index') }}" wire:navigate class="ml-auto text-[13px] text-accent hover:underline">{{ __('Tümü') }}</a>
                </div>
                <ul class="divide-y divide-line px-5 py-2 text-[13px]">
                    @foreach ($services['down'] as $s)
                        <li class="flex items-center gap-2.5 py-2"><i class="size-2 shrink-0 rounded-full bg-crit"></i><span class="font-medium">{{ $s['name'] }}</span><span class="ml-auto text-2xs font-medium text-crit">{{ __('çalışmıyor') }}</span></li>
                    @endforeach
                    @foreach ($services['list'] as $s)
                        @continue(in_array($s['unit'], $downUnits, true))
                        <li class="flex items-center gap-2.5 py-2">
                            <i class="size-2 shrink-0 rounded-full bg-ok"></i>
                            <span class="min-w-0 truncate font-medium">{{ $s['name'] }}</span>
                            <span class="num ml-auto font-mono text-[12px] whitespace-nowrap text-muted">{{ Bytes::format((int) $s['memory']) }}</span>
                        </li>
                    @endforeach
                    @if ($services['more'])
                        <li class="py-2"><a href="{{ route('services.index') }}" wire:navigate class="text-muted hover:text-accent">{{ trans_choice('ve :count hizmet daha', $services['more']) }}</a></li>
                    @endif
                </ul>
            </section>
        </div>
    @endif

    {{-- Laravel ve son etkinlik --}}
    @if ($apps->isNotEmpty() || $activity)
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <section aria-labelledby="apps-title" @class(['min-w-0 rounded-xl border border-line bg-surface', 'xl:col-span-2' => $activity !== null, 'xl:col-span-3' => $activity === null])>
                <div class="flex h-12 items-center gap-3 border-b border-line px-5">
                    <h2 id="apps-title" class="text-[14.5px] font-semibold">{{ __('Laravel uygulamaları') }}</h2>
                    <span class="hidden text-2xs text-muted sm:inline">{{ __('son dağıtımlar') }}</span>
                    <a href="{{ route('laravel.index') }}" wire:navigate class="ml-auto text-[13px] whitespace-nowrap text-accent hover:underline">{{ __('Tümü') }}</a>
                </div>
                @if ($apps->isNotEmpty())
                    <ul class="divide-y divide-line">
                        @foreach ($apps as $app)
                            @php $deploy = $app->latestDeployment; @endphp
                            <li wire:key="app-{{ $app->id }}">
                                <a href="{{ route('laravel.show', $app) }}" wire:navigate class="flex items-center gap-3 px-5 py-3.5 hover:bg-surface-2/60">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono font-medium">{{ $app->site->domain }}</span>
                                            @if ($app->status !== \App\Enums\LaravelAppStatus::Active)
                                                <x-status-badge :status="$app->status" />
                                            @elseif ($deploy)
                                                <x-status-badge :status="new \App\Support\Badge($deploy->status->isBusy() ? __('Dağıtılıyor') : ($deploy->status->isLive() ? __('Yayında') : __('Başarısız')), $deploy->status->isBusy() ? 'info' : $deploy->status->tone(), $deploy->status->isBusy())" />
                                            @endif
                                        </div>
                                        <p class="mt-1 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-0.5 text-[12.5px] text-muted">
                                            @if ($deploy)
                                                @if ($deploy->ref)
                                                    <span class="flex items-center gap-1"><x-icon name="git-branch" class="size-3.5" /><span class="font-mono">{{ $deploy->ref }}</span></span>
                                                @endif
                                                @if ($deploy->shortCommit() || $deploy->message)
                                                    <span class="max-w-full truncate sm:max-w-[26rem]"><span class="font-mono">{{ $deploy->shortCommit() }}</span> {{ $deploy->message }}</span>
                                                @endif
                                                <span class="num">{{ When::short($deploy->finished_at ?? $deploy->created_at) }}</span>
                                            @else
                                                <span>{{ __('Henüz dağıtım yok') }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <x-icon name="chevron-right" class="text-muted" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="px-5 py-10 text-center text-[13px] text-muted">
                        <p>{{ __('Henüz Laravel uygulaması yok. Git deposundan ya da yeni bir projeyle birkaç adımda kurabilirsiniz.') }}</p>
                        @unless ($readOnly)
                            <a href="{{ route('laravel.index') }}" wire:navigate class="mt-2 inline-block font-medium text-accent hover:underline">{{ __('Laravel uygulaması kur') }}</a>
                        @endunless
                    </div>
                @endif
            </section>

            @if ($activity !== null)
                <section aria-labelledby="activity-title" class="min-w-0 rounded-xl border border-line bg-surface">
                    <div class="flex h-12 items-center gap-3 border-b border-line px-5">
                        <h2 id="activity-title" class="text-[14.5px] font-semibold">{{ __('Son etkinlik') }}</h2>
                        <a href="{{ route('settings.audit') }}" wire:navigate class="ml-auto text-[13px] text-accent hover:underline">{{ __('İşlem günlüğü') }}</a>
                    </div>
                    @if ($activity->isNotEmpty())
                        <ol class="space-y-4 px-5 py-4 text-[13px]">
                            @foreach ($activity as $entry)
                                <li class="flex gap-3">
                                    <span @class(['grid size-7 shrink-0 place-items-center rounded-full bg-surface-2', 'text-accent' => $entry->via === 'ai', 'text-muted' => $entry->via !== 'ai'])>
                                        <x-icon :name="match ($entry->via) { 'ai' => 'sparkles', 'api' => 'plug', 'system' => 'cog', default => 'user' }" class="size-3.5" />
                                    </span>
                                    <div class="min-w-0 pt-0.5">
                                        <p class="break-words"><b class="font-medium">{{ $entry->actor }}</b> · {{ \App\Support\Locales::reword($entry->message) }}</p>
                                        <p class="num mt-0.5 text-2xs text-muted">{{ When::short($entry->created_at) }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="px-5 py-10 text-center text-[13px] text-muted">{{ __('Panelden yapılan işlemler burada görünür.') }}</p>
                    @endif
                </section>
            @endif
        </div>
    @endif
</div>
