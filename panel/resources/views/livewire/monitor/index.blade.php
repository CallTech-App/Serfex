@php
    use App\Livewire\Monitor\LiveMonitor;
    use App\Services\Monitor\Health;
    use App\Services\Monitor\IpBlocks;
    use App\Services\Monitor\ResourceLimits;
    use App\Support\Badge;
    use App\Support\Bytes;
    use App\Support\Load;
    use Illuminate\Support\Carbon;
    $field = 'h-9 w-full rounded-md border border-line bg-surface px-2.5 text-[13.5px] outline-none focus:border-accent';
    $tz = config('app.timezone');
    $hasDb = ($snap['databases'] ?? []) !== [];
    $blockedBy = function (string $ip) use ($blocks) {
        foreach ($blocks as $b) {
            if (IpBlocks::contains($b->address, $ip)) {
                return $b;
            }
        }

        return null;
    };
    $cpuTone = fn (float $v) => $v >= 100 ? 'bg-crit' : ($v >= Health::CPU_WARN ? 'bg-warn' : 'bg-ok');
@endphp
<div class="space-y-6">
    @if ($poll)
        <div wire:poll.{{ $poll }} hidden></div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-[22px] font-semibold tracking-tight">Canlı İzleme</h1>
                @if ($long)
                    <x-status-badge :status="new Badge('Ortalama', 'muted')" />
                @elseif ($snap)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-2xs font-medium text-ok">
                        <i class="size-1.5 animate-pulse rounded-full bg-ok"></i>Canlı
                    </span>
                @endif
            </div>
            <p class="mt-1 text-[13px] text-muted">Hangi sitenin sunucuyu ne kadar kullandığını ve saldırı altında olup olmadığını izleyin, gerekirse tek tıkla müdahale edin.</p>
        </div>
        <label class="flex items-center gap-2 text-[13px] text-muted">
            Aralık
            <select wire:model.live="range" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px] text-fg">
                @foreach (LiveMonitor::RANGES as $key => [$label])
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($error)
        <div class="relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <p>{{ $error }}</p>
        </div>
    @endif

    @if ($actionError && ! $acting)
        <div class="relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <p class="min-w-0 flex-1">{{ $actionError }}</p>
            <button type="button" wire:click="$set('actionError', null)" class="text-muted hover:text-fg" aria-label="Kapat"><x-icon name="x" /></button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (LiveMonitor::TABS as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px flex h-12 items-center gap-2 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">
                    {{ $label }}
                    @if ($key === 'saldirilar' && $openCount > 0)
                        <span class="num min-w-5 rounded-full bg-crit px-1.5 text-center text-2xs leading-5 font-semibold text-white">{{ $openCount }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Siteler --}}
        @if ($tab === 'siteler')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <p class="text-[13px] text-muted">
                    {{ $long ? LiveMonitor::RANGES[$range][0].' ortalaması.' : 'Son 10 saniye.' }}
                    Sorunlu siteler üstte. Müdahale etmek için bir siteye tıklayın.
                </p>
                <label class="relative ml-auto w-full sm:w-60">
                    <span class="sr-only">Site ara</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Site ara"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[980px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Site</th>
                            <th class="w-40 px-3 py-2.5 font-medium">CPU</th>
                            <th class="px-3 py-2.5 text-right font-medium">Bellek</th>
                            <th class="px-3 py-2.5 text-right font-medium">PHP işçisi</th>
                            <th class="px-3 py-2.5 text-right font-medium">İstek/sn</th>
                            <th class="px-3 py-2.5 text-right font-medium">Disk G/Ç</th>
                            <th class="px-3 py-2.5 text-right font-medium">Veritabanı</th>
                            <th class="px-3 py-2.5 text-right font-medium">Posta/sa</th>
                            <th class="py-2.5 pr-5 pl-3 font-medium">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($rows as $row)
                            @php $u = $row['u']; $php = $u['php']; @endphp
                            <tr wire:key="site-{{ $row['site']->id }}" wire:click="open({{ $row['site']->id }})" class="cursor-pointer hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5">
                                    <button type="button" class="text-left font-mono text-[13px] font-medium hover:text-accent">{{ $row['site']->domain }}</button>
                                    <span class="block font-mono text-2xs text-muted">{{ $u['user'] }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-1.5 w-16 overflow-hidden rounded-full bg-surface-2"><span class="block h-full rounded-full {{ $cpuTone($u['cpu']) }}" style="width: {{ min(100, max($u['cpu'], 1)) }}%"></span></span>
                                        <span class="num">{{ Load::cpu($u['cpu']) }}</span>
                                    </div>
                                </td>
                                <td class="num px-3 py-3 text-right whitespace-nowrap {{ $u['memory'] >= Health::MEMORY_WARN ? 'text-warn' : '' }}">{{ $u['memory'] > 0 ? Bytes::format($u['memory']) : '—' }}</td>
                                <td class="num px-3 py-3 text-right whitespace-nowrap">
                                    @if ($php['known'])
                                        <span @class(['font-medium text-crit' => $php['max_reached'] || $php['queue'] > 0])>{{ Load::number($php['busy'], $long ? 1 : 0) }} / {{ $php['max'] }}</span>
                                        @if ($php['queue'] > 0)
                                            <span class="block text-2xs text-crit">{{ $php['queue'] }} istek sırada</span>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="num px-3 py-3 text-right">
                                    {{ Load::rate($u['requests']) }}
                                    @if ($u['server_errors'] > 0)
                                        <span class="block text-2xs {{ $u['server_errors'] >= Health::ERRORS_WARN ? 'text-crit' : 'text-muted' }}">{{ Load::rate($u['server_errors']) }} 5xx</span>
                                    @endif
                                </td>
                                <td class="num px-3 py-3 text-right whitespace-nowrap text-muted">{{ Load::io($u['io']) }}</td>
                                <td class="num px-3 py-3 text-right whitespace-nowrap">
                                    @if (! $hasDb)
                                        <span class="text-muted">—</span>
                                    @elseif ($u['db']['connections'] < 0.5)
                                        <span class="text-muted">0</span>
                                    @else
                                        {{ Load::number($u['db']['connections']) }} bağlantı
                                        @if ($u['db']['active'] >= 0.5)
                                            <span class="block text-2xs text-muted">{{ Load::number($u['db']['active']) }} sorgu çalışıyor</span>
                                        @endif
                                        @if ($u['db']['longest'] >= Health::SLOW_QUERY)
                                            <span class="block text-2xs text-warn">en uzun {{ $u['db']['longest'] }} sn</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="num px-3 py-3 text-right {{ $u['mail_per_hour'] >= Health::MAIL_WARN ? 'font-medium text-warn' : '' }}">{{ Load::number($u['mail_per_hour']) }}</td>
                                <td class="py-3 pr-5 pl-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($row['badges'] as $badge)
                                            <x-status-badge :status="$badge" />
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-5 py-12 text-center">
                                    @if ($error)
                                        <p class="text-muted">Ölçümler sunucu ajanından gelir; ajan çalışınca burada görünür.</p>
                                    @elseif ($search !== '')
                                        <p class="text-muted">Aramanızla eşleşen site yok.</p>
                                    @else
                                        <p class="font-medium">Henüz izlenecek site yok</p>
                                        <p class="mt-1 text-[13px] text-muted">Bir site oluşturduğunuzda kullanımı burada görünür.</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                        @if ($search === '')
                            @foreach ($accounts as $a)
                                <tr wire:key="account-{{ $a['user'] }}" class="bg-surface-2/40">
                                    <td class="py-3 pr-3 pl-5">
                                        <span class="font-mono text-[13px]">{{ $a['user'] }}</span>
                                        <span class="block text-2xs text-muted">Hesabın sitelerin dışındaki işlemleri ({{ count($a['sites']) }} site)</span>
                                    </td>
                                    <td class="num px-3 py-3">{{ Load::cpu($a['cpu']) }}</td>
                                    <td class="num px-3 py-3 text-right">{{ $a['memory'] > 0 ? Bytes::format($a['memory']) : '—' }}</td>
                                    <td class="px-3 py-3 text-right text-muted">—</td>
                                    <td class="px-3 py-3 text-right text-muted">—</td>
                                    <td class="num px-3 py-3 text-right text-muted">{{ Load::io($a['io']) }}</td>
                                    <td class="num px-3 py-3 text-right">{{ $hasDb ? Load::number($a['db']['connections']).' bağlantı' : '—' }}</td>
                                    <td class="num px-3 py-3 text-right">{{ Load::number($a['mail_per_hour']) }}</td>
                                    <td class="py-3 pr-5 pl-3"></td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>
            @if ($snap)
                <p class="border-t border-line px-5 py-3 text-2xs text-muted">
                    CPU bir çekirdeğin yüzdesidir; %100 bir çekirdeği tamamen kullanmak demektir. Bellek, sitenin işlemlerinin kullandığı fiziksel bellektir.
                    @if (! $hasDb)
                        Bu sunucuda veritabanı sunucusu bulunmadığı için veritabanı yükü ölçülmüyor.
                    @endif
                </p>
            @endif

        {{-- Saldırılar --}}
        @elseif ($tab === 'saldirilar')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openBlock" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="ban" />IP engelle
                </button>
                <p class="text-[13px] text-muted sm:ml-2">Kurallardan birini aşan adresler. Panel kendiliğinden engellemez; Servik AI'a izin verdiyseniz en fazla 24 saat engelleyebilir.</p>
                @if ($openCount > 0)
                    <x-ask-ai question="Canlı İzleme'de engellenmemiş saldırgan adresler görünüyor. Hangileri gerçekten saldırı, ne yapmalıyım?" class="ml-auto" />
                @endif
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[820px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">IP adresi</th>
                            <th class="px-3 py-2.5 font-medium">Hedef</th>
                            <th class="px-3 py-2.5 font-medium">Kural</th>
                            <th class="px-3 py-2.5 text-right font-medium">Yoğunluk</th>
                            <th class="px-3 py-2.5 font-medium">Başlangıç</th>
                            <th class="px-3 py-2.5 font-medium">Durum</th>
                            <th class="w-28 py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($suspects as $s)
                            @php
                                $blocked = $blockedBy($s['ip']);
                                $target = $s['target'] !== '' ? ($sitesByDomain[$s['target']] ?? null) : null;
                                $perTen = in_array($s['kind'], ['mail', 'ssh'], true);
                            @endphp
                            <tr wire:key="suspect-{{ $s['ip'] }}-{{ $s['target'] }}-{{ $s['kind'] }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">{{ $s['ip'] }}</td>
                                <td class="px-3 py-3 font-mono text-[13px]">
                                    @if ($target)
                                        <button type="button" wire:click="open({{ $target->id }})" class="hover:text-accent">{{ $s['target'] }}</button>
                                    @else
                                        <span class="font-sans text-muted">{{ $s['target'] !== '' ? $s['target'] : 'Sunucu' }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3">{{ implode(', ', $s['rules']) }}</td>
                                <td class="num px-3 py-3 text-right whitespace-nowrap">
                                    {{ Load::number(max($s['count'], $s['peak'])) }}<span class="text-muted">{{ $perTen ? ' / 10 dk' : ' / dk' }}</span>
                                </td>
                                <td class="num px-3 py-3 whitespace-nowrap text-muted">{{ Carbon::parse($s['since'])->timezone($tz)->format('H:i') }}</td>
                                <td class="px-3 py-3">
                                    @if ($blocked)
                                        <x-status-badge :status="new Badge('Engellendi', 'info')" :title="Load::remaining($blocked->expires_at)" />
                                    @elseif ($s['count'] > 0)
                                        <x-status-badge :status="new Badge('Sürüyor', 'crit')" />
                                    @else
                                        <x-status-badge :status="new Badge('Durdu', 'muted')" />
                                    @endif
                                </td>
                                <td class="py-3 pr-5 pl-3 text-right">
                                    @unless ($blocked)
                                        <button type="button" wire:click="openBlock(@js($s['ip']), {{ $target?->id ?? 'null' }}, @js($s['kind']), @js(implode(', ', $s['rules'])))"
                                                class="h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">Engelle</button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center">
                                    <p class="font-medium">Şu an saldırı görünmüyor</p>
                                    <p class="mt-1 text-[13px] text-muted">Kurallardan birini aşan adresler burada listelenir ve sessizleştikten sonra 10 dakika daha görünür.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="border-t border-line px-5 py-3 text-2xs text-muted">Kurallar: bir adresten dakikada 300 istek, giriş sayfasına dakikada 20 deneme, dakikada 60 bulunamayan sayfa ya da 10 dakikada 10 başarısız posta veya SSH girişi.</p>

            <div class="border-t border-line">
                <h2 class="px-5 pt-4 pb-2 text-[14px] font-semibold">Engellenen adresler</h2>
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[760px] text-[13.5px]">
                        <thead>
                            <tr class="border-y border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">Adres</th>
                                <th class="px-3 py-2.5 font-medium">Neden</th>
                                <th class="px-3 py-2.5 font-medium">Engelleyen</th>
                                <th class="px-3 py-2.5 font-medium">Süre</th>
                                <th class="w-28 py-2.5 pr-5 pl-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($blocks as $b)
                                <tr wire:key="block-{{ $b->id }}" class="hover:bg-surface-2/60">
                                    <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">{{ $b->address }}</td>
                                    <td class="px-3 py-3">
                                        {{ $b->reason !== '' && $b->reason !== null ? $b->reason : ($b->source === 'fail2ban' ? \App\Services\Security\Fail2ban::jailLabel($b->kind) : '—') }}
                                        @if ($b->site)
                                            <span class="block font-mono text-2xs text-muted">{{ $b->site->domain }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-muted">{{ $b->byLabel() }} · <span class="num">{{ $b->created_at->timezone($tz)->format('d.m H:i') }}</span></td>
                                    <td class="num px-3 py-3 whitespace-nowrap">{{ Load::remaining($b->expires_at) }}</td>
                                    <td class="py-3 pr-5 pl-3 text-right">
                                        <button type="button" wire:click="unblock({{ $b->id }})" class="text-[13px] text-muted hover:text-fg">Engeli kaldır</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-8 text-center text-[13px] text-muted">Engellenen adres yok.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-line px-5 py-3 text-2xs text-muted">Engel bütün sunucuyu kapsar: adresten gelen hiçbir bağlantı (web, posta, SSH) kabul edilmez. Fail2ban engelleri yalnızca saldırılan hizmetin portunu kapatır. Süresi dolan engel kendiliğinden kalkar.</p>
            </div>

        {{-- Posta kuyruğu --}}
        @elseif ($tab === 'posta')
            @if ($mailError)
                <p class="px-5 py-10 text-center text-[13.5px] text-muted">{{ $mailError }}</p>
            @elseif (! ($mail['installed'] ?? false))
                <div class="px-5 py-10 text-center">
                    <p class="font-medium">Posta sunucusu kurulu değil</p>
                    <p class="mt-1 text-[13px] text-muted">Kuyruğu görmek için önce <a href="{{ route('mail.settings') }}" wire:navigate class="text-accent hover:underline">E-posta ayarlarından</a> posta sunucusunu kurun.</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-line px-5 py-3 text-[13.5px]">
                    <span><span class="num font-semibold">{{ $mail['active'] }}</span> <span class="text-muted">gönderiliyor</span></span>
                    <span><span class="num font-semibold {{ $mail['deferred'] > 0 ? 'text-warn' : '' }}">{{ $mail['deferred'] }}</span> <span class="text-muted">ertelendi</span></span>
                    <span><span class="num font-semibold">{{ $mail['hold'] }}</span> <span class="text-muted">bekletiliyor</span></span>
                    <button type="button" wire:click="flushQueue" @disabled($mail['deferred'] === 0) class="ml-auto flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40">
                        <x-icon name="refresh-cw" />Kuyruğu şimdi dene
                    </button>
                </div>
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[820px] text-[13.5px]">
                        <thead>
                            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">Gönderen alan adı</th>
                                <th class="px-3 py-2.5 text-right font-medium">Gönderiliyor</th>
                                <th class="px-3 py-2.5 text-right font-medium">Ertelendi</th>
                                <th class="px-3 py-2.5 text-right font-medium">Bekletiliyor</th>
                                <th class="px-3 py-2.5 font-medium">En eski</th>
                                <th class="px-3 py-2.5 font-medium">Teslim sorunu</th>
                                <th class="w-32 py-2.5 pr-5 pl-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($mail['domains'] as $d)
                                <tr wire:key="queue-{{ $d['domain'] }}" class="hover:bg-surface-2/60">
                                    <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">{{ $d['domain'] !== '' ? $d['domain'] : 'Geri dönen iletiler' }}</td>
                                    <td class="num px-3 py-3 text-right">{{ $d['active'] }}</td>
                                    <td class="num px-3 py-3 text-right {{ $d['deferred'] > 0 ? 'text-warn' : '' }}">{{ $d['deferred'] }}</td>
                                    <td class="num px-3 py-3 text-right">{{ $d['hold'] }}</td>
                                    <td class="num px-3 py-3 whitespace-nowrap text-muted">{{ Carbon::parse($d['oldest'])->timezone($tz)->diffForHumans() }}</td>
                                    <td class="max-w-72 truncate px-3 py-3 font-mono text-[12.5px] text-muted" title="{{ $d['reason'] }}">{{ $d['reason'] !== '' ? $d['reason'] : '—' }}</td>
                                    <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                        @if ($d['deferred'] > 0)
                                            <button type="button" wire:click="hold(@js($d['domain']))" class="text-[13px] text-muted hover:text-fg" title="Bu alan adının bekleyen iletileri siz serbest bırakana kadar gönderilmez">Beklet</button>
                                        @endif
                                        @if ($d['hold'] > 0)
                                            <button type="button" wire:click="release(@js($d['domain']))" class="ml-3 text-[13px] text-muted hover:text-fg">Serbest bırak</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-10 text-center">
                                        <p class="font-medium">Kuyruk boş</p>
                                        <p class="mt-1 text-[13px] text-muted">Bütün iletiler teslim edildi.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-line px-5 py-3 text-2xs text-muted">Ertelenen iletiler Postfix tarafından aralıklarla yeniden denenir. Spam gönderen bir siteyi durdurmak için alan adının iletilerini bekletin; bekletilen iletiler siz serbest bırakana kadar gönderilmez.</p>
            @endif

        {{-- Sistem --}}
        @else
            @if ($snap)
                @php $srv = $snap['server']; @endphp
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 border-b border-line px-5 py-4 text-[13.5px] sm:grid-cols-4">
                    <div><dt class="text-2xs text-muted">İşlemci</dt><dd class="num mt-0.5 font-medium">{{ Load::cpu($srv['cpu']) }} <span class="font-normal text-muted">· {{ $srv['cpus'] }} çekirdek</span></dd></div>
                    <div><dt class="text-2xs text-muted">Bellek</dt><dd class="num mt-0.5 font-medium">{{ Bytes::format($srv['memory']) }} <span class="font-normal text-muted">/ {{ Bytes::format($srv['memory_total']) }}</span></dd></div>
                    <div><dt class="text-2xs text-muted">Yük ortalaması</dt><dd class="num mt-0.5 font-medium">{{ implode('  ', array_map(fn ($l) => Load::number($l, 2), $srv['load'])) }}</dd></div>
                    <div><dt class="text-2xs text-muted">Çalışma süresi</dt><dd class="num mt-0.5 font-medium">{{ Load::uptime($srv['uptime']) }}</dd></div>
                </dl>
            @endif
            @php
                $groups = collect($snap['services'] ?? [])->groupBy('group');
                $groupLabels = [
                    'servik' => ['Servik\'in kendi hizmetleri', 'Panelin çalışması için gereken hizmetler; müşteri siteleri bunları kullanmaz.'],
                    'shared' => ['Paylaşılan hizmetler', 'Bütün sitelerin birlikte kullandığı web, PHP, veritabanı ve posta sunucuları.'],
                    'system' => ['Diğer sistem hizmetleri', 'İşletim sisteminin kendi hizmetleri.'],
                ];
            @endphp
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[680px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Hizmet</th>
                            <th class="px-3 py-2.5 font-medium">Birim</th>
                            <th class="px-3 py-2.5 text-right font-medium">CPU</th>
                            <th class="px-3 py-2.5 text-right font-medium">Bellek</th>
                            <th class="py-2.5 pr-5 pl-3 text-right font-medium">Disk G/Ç</th>
                        </tr>
                    </thead>
                    @foreach ($groupLabels as $group => [$title, $hint])
                        @continue(! $groups->has($group))
                        <tbody class="divide-y divide-line border-b border-line">
                            <tr class="bg-surface-2/50">
                                <td colspan="5" class="px-5 py-2"><span class="font-medium">{{ $title }}</span> <span class="text-[13px] text-muted">· {{ $hint }}</span></td>
                            </tr>
                            @foreach ($groups[$group] as $svc)
                                <tr wire:key="svc-{{ $svc['unit'] }}" class="hover:bg-surface-2/60">
                                    <td class="py-2.5 pr-3 pl-5">{{ $svc['name'] }}</td>
                                    <td class="px-3 py-2.5 font-mono text-[12.5px] text-muted">{{ $svc['unit'] }}</td>
                                    <td class="num px-3 py-2.5 text-right">{{ Load::cpu($svc['cpu']) }}</td>
                                    <td class="num px-3 py-2.5 text-right">{{ $svc['memory'] > 0 ? Bytes::format($svc['memory']) : '—' }}</td>
                                    <td class="num py-2.5 pr-5 pl-3 text-right text-muted">{{ Load::io($svc['io']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                    @if ($groups->isEmpty())
                        <tbody><tr><td colspan="5" class="px-5 py-10 text-center text-[13px] text-muted">{{ $error ? 'Ölçümler sunucu ajanından gelir.' : 'Henüz ölçüm yok.' }}</td></tr></tbody>
                    @endif
                </table>
            </div>
        @endif
    </section>

    {{-- Site dialog: what the site uses and the interventions. --}}
    <x-modal title="Siteye müdahale" show="acting" size="max-w-2xl">
        @if ($site)
            <div class="flex h-12 items-center gap-3 border-b border-line px-5">
                <h2 class="min-w-0 truncate text-[14.5px] font-semibold"><span class="font-mono">{{ $site->domain }}</span> için müdahale</h2>
                @php
                    $problem = $current ? \App\Services\Monitor\Health::site($current, count($siteAttackers), false)[0][0]->label() : null;
                    $question = $problem && $problem !== 'Normal'
                        ? "{$site->domain} sitesinde Canlı İzleme \"{$problem}\" gösteriyor. Nedenini bul ve ne yapmam gerektiğini söyle."
                        : "{$site->domain} sitesinin şu anki kaynak kullanımı normal mi? Dikkat etmem gereken bir şey var mı?";
                @endphp
                <x-ask-ai :question="$question" before="$wire.close();" class="ml-auto" />
                <a href="{{ route('sites.show', $site) }}" wire:navigate class="shrink-0 text-[13px] text-muted hover:text-fg">Siteye git</a>
                <button type="button" wire:click="close" class="text-muted hover:text-fg" aria-label="Kapat"><x-icon name="x" /></button>
            </div>
            <div class="max-h-[75vh] divide-y divide-line overflow-y-auto text-[13.5px]">
                @if ($actionError)
                    <p class="flex items-start gap-2 bg-crit-soft px-5 py-3 text-crit"><x-icon name="triangle-alert" class="mt-0.5 shrink-0" />{{ $actionError }}</p>
                @endif

                @if ($current)
                    <div class="grid gap-5 px-5 py-4 sm:grid-cols-2">
                        <div>
                            <div class="flex items-baseline justify-between"><span class="text-muted">CPU</span><span class="num font-medium">{{ Load::cpu($current['cpu']) }}</span></div>
                            <x-sparkline :points="array_column($history, 'cpu')" :max="100" class="mt-1 text-accent" />
                        </div>
                        <div>
                            <div class="flex items-baseline justify-between"><span class="text-muted">İstek/sn</span><span class="num font-medium">{{ Load::rate($current['requests']) }}</span></div>
                            <x-sparkline :points="array_column($history, 'requests')" class="mt-1 text-accent" />
                        </div>
                        <dl class="grid grid-cols-3 gap-3 sm:col-span-2">
                            <div><dt class="text-2xs text-muted">Bellek</dt><dd class="num font-medium">{{ $current['memory'] > 0 ? Bytes::format($current['memory']) : '—' }}</dd></div>
                            <div><dt class="text-2xs text-muted">PHP işçisi</dt><dd class="num font-medium {{ $current['php']['max_reached'] ? 'text-crit' : '' }}">{{ $current['php']['known'] ? Load::number($current['php']['busy']).' / '.$current['php']['max'] : '—' }}</dd></div>
                            <div><dt class="text-2xs text-muted">Posta/sa</dt><dd class="num font-medium">{{ Load::number($current['mail_per_hour']) }}</dd></div>
                        </dl>
                        <p class="text-2xs text-muted sm:col-span-2">Grafikler {{ $long ? LiveMonitor::RANGES[$range][0] : 'son 10 dakika' }}; değerler şu anki.</p>
                    </div>
                @else
                    <p class="px-5 py-4 text-muted">Bu site için henüz ölçüm yok.</p>
                @endif

                {{-- Attack --}}
                @if ($siteAttackers !== [])
                    <div class="px-5 py-4">
                        <p class="flex items-center gap-2 font-medium text-crit"><x-icon name="shield-alert" />{{ count($siteAttackers) }} adres bu siteye saldırıyor</p>
                        <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                            @foreach ($siteAttackers as $a)
                                <li class="flex items-center gap-3 px-3 py-2">
                                    <span class="font-mono text-[13px]">{{ $a['ip'] }}</span>
                                    <span class="min-w-0 truncate text-muted">{{ implode(', ', $a['rules']) }}</span>
                                    <span class="num ml-auto shrink-0 text-muted">{{ Load::number($a['count']) }} / dk</span>
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <select wire:model="attackMinutes" aria-label="Engel süresi" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                                @foreach (IpBlocks::DURATIONS as $m => $label)
                                    <option value="{{ $m }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="blockAttackers" wire:loading.attr="disabled" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                                <x-icon name="ban" />Hepsini engelle
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Busiest addresses --}}
                @if (($current['top_ips'] ?? []) !== [])
                    <div class="px-5 py-4">
                        <h3 class="font-medium">En çok istek gönderen adresler <span class="font-normal text-muted">· son 1 dakika</span></h3>
                        <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                            @foreach (array_slice($current['top_ips'], 0, 5) as $ip)
                                @php $blocked = $blockedBy($ip['ip']); @endphp
                                <li class="flex items-center gap-3 px-3 py-2">
                                    <span class="font-mono text-[13px]">{{ $ip['ip'] }}</span>
                                    <span class="num ml-auto text-muted">{{ Load::number($ip['requests']) }} istek</span>
                                    @if ($blocked)
                                        <x-status-badge :status="new Badge('Engellendi', 'info')" />
                                    @else
                                        <button type="button" wire:click="openBlock(@js($ip['ip']), {{ $site->id }})" class="text-[13px] text-muted hover:text-fg">Engelle</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Temporary limit --}}
                <form wire:submit="applyLimit" class="px-5 py-4">
                    <h3 class="font-medium">Geçici kaynak sınırı</h3>
                    @if ($currentLimit)
                        <div class="mt-2 flex flex-wrap items-center gap-3 rounded-md bg-info-soft px-3 py-2">
                            <span class="text-info">Sınırlı: {{ $currentLimit->summary() }} · <span class="num">{{ Load::remaining($currentLimit->expires_at) }}</span></span>
                            <button type="button" wire:click="liftLimit({{ $currentLimit->id }})" class="ml-auto text-[13px] font-medium text-info hover:underline">Sınırı kaldır</button>
                        </div>
                    @else
                        <p class="mt-1 text-[13px] text-muted">Siteyi bir süreliğine yavaşlatır, diğer siteler etkilenmez. Süre dolunca sınır kendiliğinden kalkar.</p>
                    @endif
                    <div class="mt-3 grid gap-3 sm:grid-cols-4">
                        <label class="block"><span class="mb-1 block text-2xs text-muted">CPU</span>
                            <select wire:model="limit.cpu" title="%100 bir çekirdeğin tamamıdır" class="{{ $field }}">@foreach (LiveMonitor::CPU_CHOICES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="mb-1 block text-2xs text-muted">Bellek</span>
                            <select wire:model="limit.memory" class="{{ $field }}">@foreach (LiveMonitor::MEMORY_CHOICES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="mb-1 block text-2xs text-muted">PHP işçisi</span>
                            <select wire:model="limit.php" class="{{ $field }}">@foreach (LiveMonitor::PHP_CHOICES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="mb-1 block text-2xs text-muted">Süre</span>
                            <select wire:model="limit.minutes" class="{{ $field }}">@foreach (ResourceLimits::DURATIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        </label>
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="applyLimit" class="mt-3 h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                        {{ $currentLimit ? 'Sınırı güncelle' : 'Sınırı uygula' }}
                    </button>
                </form>

                {{-- PHP restart --}}
                <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <h3 class="font-medium">PHP'yi yeniden başlat</h3>
                        <p class="mt-0.5 text-[13px] text-muted">Takılan istekleri keser. Site birkaç saniye içinde yeni PHP işçileriyle yanıt vermeye devam eder.</p>
                    </div>
                    <button type="button" wire:click="restartPhp" wire:loading.attr="disabled" wire:target="restartPhp" class="flex h-9 items-center gap-2 rounded-md border border-line px-3.5 hover:bg-surface-2 disabled:opacity-40">
                        <x-icon name="rotate-cw" />Yeniden başlat
                    </button>
                </div>
            </div>
        @endif
    </x-modal>

    {{-- IP engelle --}}
    <x-modal title="IP engelle" show="blocking">
        <form wire:submit="saveBlock">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">IP engelle</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="block-address" class="mb-1.5 block">IP adresi veya aralığı</label>
                    <input id="block-address" wire:model="block.address" required placeholder="203.0.113.7 veya 203.0.113.0/24" autocomplete="off" spellcheck="false"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('block.address') ? 'border-crit' : 'border-line' }}">
                    @error('block.address')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-2xs text-muted">Kendi adresiniz ve sunucunun adresleri engellenemez. Aralık en fazla /16 (IPv6'da /48) olabilir.</p>
                    @enderror
                </div>
                <div>
                    <label for="block-minutes" class="mb-1.5 block">Süre</label>
                    <select id="block-minutes" wire:model="block.minutes" class="{{ $field }}">
                        @foreach (IpBlocks::DURATIONS as $m => $label)
                            <option value="{{ $m }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="block-reason" class="mb-1.5 block">Neden <span class="text-muted">(isteğe bağlı)</span></label>
                    <input id="block-reason" wire:model="block.reason" maxlength="200" class="{{ $field }}">
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('blocking', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="saveBlock" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Engelle</button>
            </div>
        </form>
    </x-modal>
</div>
