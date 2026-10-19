@php
    use App\Models\Backup;
    use App\Models\BackupItem;
    use App\Models\BackupTarget;
    use App\Services\Backups\BackupSettings;
    use App\Support\Badge;
    use App\Support\Bytes;
    use Illuminate\Support\Str;
    $admin = auth()->user()->is_admin;
    $field = 'h-9 rounded-md border border-line bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $link = 'text-[13px] text-accent hover:underline whitespace-nowrap disabled:opacity-40 disabled:no-underline';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $statusBadge = fn (string $status) => match ($status) {
        'queued' => new Badge('Sırada', 'info', true),
        'running' => new Badge('Sürüyor', 'info', true),
        'partial' => new Badge('Kısmen alındı', 'warn'),
        'failed' => new Badge('Başarısız', 'crit'),
        default => null,
    };
    $when = fn ($t) => $t?->timezone(config('servik.timezone'))->isToday() ? 'Bugün '.$t->format('H:i') : ($t?->timezone(config('servik.timezone'))->isYesterday() ? 'Dün '.$t->format('H:i') : $t?->format('d.m.Y H:i'));
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            @if ($filterSite)
                <nav class="mb-2 flex items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
                    <a href="{{ route('sites.show', $filterSite) }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" /><span class="font-mono">{{ $filterSite->domain }}</span></a>
                    <x-icon name="chevron-right" class="size-3.5" />
                    <span class="text-fg">Yedekler</span>
                </nav>
            @endif
            <h1 class="text-[22px] font-semibold tracking-tight">Yedekler</h1>
            <p class="mt-1 max-w-3xl text-[13px] text-muted">
                @if ($filterSite)
                    <span class="font-mono">{{ $filterSite->domain }}</span> sitesinin yedekleri: dosyalar, veritabanları, e-posta, DNS ve SSL.
                    <button type="button" wire:click="$set('siteId', 0)" class="text-accent hover:underline">Tümünü göster</button>
                @elseif ($admin)
                    Sunucu ve site yedeklerini alın, geri yükleyin ve yedekleme hedeflerini yönetin.
                @else
                    Sitelerinizin yedekleri. Dosyaları, veritabanlarını ve e-postaları buradan geri yükleyebilir ya da indirebilirsiniz.
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($admin)
                <button type="button" wire:click="$set('tab', 'zamanlama')" class="{{ $btn }}"><x-icon name="calendar-clock" />Zamanlama</button>
            @endif
            <button type="button" wire:click="openBackup" @disabled($busy || ($sites->isEmpty() && ! $admin)) class="{{ $primary }}">
                <x-icon name="archive" />{{ $admin ? 'Şimdi yedekle' : 'Yedek al' }}
            </button>
        </div>
    </div>

    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="flex-1">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="Kapat"><x-icon name="x" /></button>
        </div>
    @endif

    @if (($problems ?? []) !== [])
        <div class="rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="status">
            @foreach ($problems as $p)
                <p class="flex items-start gap-2.5 py-0.5"><x-icon name="triangle-alert" @class(['mt-0.5 shrink-0', 'text-crit' => $p['level'] === 'crit', 'text-warn' => $p['level'] !== 'crit']) />{{ $p['text'] }}</p>
            @endforeach
        </div>
    @endif

    @if ($busy && ! $running)
        <div wire:poll.5s hidden></div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        @if ($admin)
            <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
                @foreach ($this::TABS as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                            @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($tab === 'yedekler')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <select wire:model.live="scope" aria-label="Kapsam" class="{{ $field }} px-2.5">
                    <option value="">Tüm yedekler</option>
                    <option value="server">Tüm sunucu</option>
                    <option value="site">Site yedekleri</option>
                    @if ($admin)
                        <option value="panel">Panel (güncelleme öncesi)</option>
                    @endif
                </select>
                @if ($admin)
                    <button type="button" wire:click="openImport" class="{{ $btn }}"><x-icon name="upload" />İçe aktar</button>
                @endif
                <span class="ml-auto text-2xs text-muted num">
                    @if ($lastBackup)
                        {{ $admin ? 'Son sunucu yedeği' : 'Son yedek' }} {{ $when($lastBackup->finished_at) }}
                    @endif
                    @if ($admin)
                        {{ $lastBackup ? '·' : '' }} {{ $next ? 'sonraki '.$when($next) : $summary }}
                    @endif
                </span>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[760px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Tarih</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Tür</th>
                            <th class="px-3 py-2.5 font-medium">Kapsam</th>
                            <th class="hidden px-3 py-2.5 text-right font-medium sm:table-cell">Boyut</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">Hedef</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($rows as $row)
                            @php
                                $b = $itemRows ? $row->backup : $row;
                                $status = $itemRows && $row->status === 'failed' ? 'failed' : $b->status;
                                $badge = $statusBadge($status);
                                $setSites = $itemRows ? null : $row->items->where('name', '!=', 'panel');
                            @endphp
                            <tr wire:key="b-{{ $itemRows ? 'i'.$row->id : $row->id }}" class="hover:bg-surface-2/60">
                                <td class="num py-3 pr-3 pl-5 whitespace-nowrap">
                                    {{ $when($b->created_at) }}
                                    <span class="mt-0.5 block text-2xs text-muted sm:hidden">{{ $b->typeLabel() }} · {{ $itemRows ? ($row->size ? Bytes::format($row->size) : '—') : ($b->size ? Bytes::format($b->size) : '—') }}</span>
                                </td>
                                <td class="hidden px-3 py-3 sm:table-cell">
                                    <span @class(['rounded-full px-2 py-0.5 text-2xs font-medium', 'bg-info-soft text-info' => $b->type === 'full', 'bg-surface-2 text-muted' => $b->type !== 'full'])>{{ $b->typeLabel() }}</span>
                                    <span class="mt-1 block text-2xs text-muted">{{ $b->triggerLabel() }}{{ $b->source_host ? ' · '.$b->source_host : '' }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    @if (! $itemRows && $b->scope === 'panel')
                                        Panel <span class="text-2xs text-muted">· veritabanı ve ayarlar</span>
                                    @elseif ($itemRows || $b->scope === 'site')
                                        <span class="font-mono text-[13px] break-all sm:break-normal">{{ $itemRows ? $row->name : $setSites->first()?->name }}</span>
                                    @else
                                        Tüm sunucu <span class="text-2xs text-muted">· {{ $setSites->count() }} site{{ $row->items->contains('name', 'panel') ? ' + panel' : '' }}</span>
                                    @endif
                                    @if ($badge)
                                        <span class="mt-1 block"><x-status-badge :status="$badge" /></span>
                                    @endif
                                    @if ($b->status === 'failed' && $b->error)
                                        <span class="mt-1 block max-w-md text-2xs break-words text-crit">{{ Str::limit($b->error, 160) }}</span>
                                    @elseif ($itemRows && $row->status === 'failed')
                                        <span class="mt-1 block max-w-md text-2xs break-words text-crit">{{ Str::limit($row->error, 160) }}</span>
                                    @endif
                                </td>
                                <td class="num hidden px-3 py-3 text-right whitespace-nowrap sm:table-cell">{{ ($itemRows ? $row->size : $b->size) ? Bytes::format($itemRows ? $row->size : $b->size) : '—' }}</td>
                                <td class="hidden px-3 py-3 whitespace-nowrap text-muted md:table-cell">
                                    {{ $b->destinationLabel() }}
                                    @if ($b->encrypted)
                                        <x-icon name="lock" class="ml-1 inline size-3.5" title="Uzak kopya şifreli" />
                                    @endif
                                </td>
                                <td class="py-3 pr-5 pl-3 text-right">
                                    <span class="inline-flex flex-col items-end gap-y-1.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end sm:gap-x-4 sm:gap-y-1">
                                        @if ($b->isRunning())
                                            <button type="button" wire:click="showRun('backup', {{ $b->id }})" class="{{ $link }}">İlerleme</button>
                                        @elseif ($b->isUsable() && $b->scope !== 'panel' && (! $itemRows || $row->status === 'succeeded'))
                                            <button type="button" wire:click="openRestore({{ $b->id }}{{ $itemRows ? ', '.$row->id : '' }})" @disabled($busy) class="{{ $link }}">Geri yükle</button>
                                        @endif
                                        @if ($itemRows && $b->isUsable() && $row->status === 'succeeded')
                                            @if ($b->onServer())
                                                <a href="{{ route('backups.download', $row) }}" class="{{ $link }}">İndir</a>
                                            @else
                                                <button type="button" wire:click="fetch({{ $b->id }})" wire:loading.attr="disabled" @disabled($busy) class="{{ $link }}" title="Yedek yalnızca uzak hedefte; indirmek için önce bu sunucuya alınır">Sunucuya al</button>
                                            @endif
                                        @endif
                                        @if (! $itemRows || ! $b->isRunning())
                                            <button type="button" wire:click="openDetail({{ $b->id }})" class="{{ $link }}">Ayrıntı</button>
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center">
                                    <p class="font-medium">Henüz yedek yok</p>
                                    <p class="mt-1 text-[13px] text-muted">
                                        @if ($admin)
                                            Zamanlanmış yedek {{ $next ? $when($next) : 'kapalı' }}. Hemen almak için "Şimdi yedekle"yi kullanın.
                                        @else
                                            Sunucunun zamanlanmış yedeklerine siteleriniz de girer. Kendiniz almak için "Yedek al"ı kullanın.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($rows->total() > 0)
                <div class="num flex flex-wrap items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                    <span>{{ $rows->total() }} {{ $itemRows ? 'arşiv' : 'yedek' }} · toplam {{ Bytes::format($total) }}@isset($quota) · elle alınan yedek {{ $quota['text'] }}@endisset</span>
                    @if ($rows->hasPages())
                        <span class="ml-auto flex items-center gap-1">
                            <button type="button" wire:click="previousPage" @disabled($rows->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Önceki</button>
                            <span class="px-2">Sayfa {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                            <button type="button" wire:click="nextPage" @disabled(! $rows->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Sonraki</button>
                        </span>
                    @endif
                </div>
            @endif
        @elseif ($tab === 'hedefler')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openTarget" class="{{ $primary }}"><x-icon name="plus" />Hedef ekle</button>
                <span class="ml-auto text-2xs text-muted">Bir hedef kaydedilmeden önce bağlantı ve yazma testi yapılır.</span>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[720px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Hedef</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Adres</th>
                            <th class="hidden px-3 py-2.5 text-right font-medium sm:table-cell">Kullanılan</th>
                            <th class="px-3 py-2.5 font-medium">Durum</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5 whitespace-nowrap">Yerel disk<span class="mt-0.5 block text-2xs text-muted sm:hidden">/var/lib/servik/backups</span></td>
                            <td class="hidden px-3 py-3 font-mono text-[12.5px] text-muted sm:table-cell">{{ $status['path'] ?? '/var/lib/servik/backups' }}</td>
                            <td class="num hidden px-3 py-3 text-right whitespace-nowrap sm:table-cell">
                                @if ($status)
                                    {{ Bytes::format((int) $status['used']) }} <span class="text-muted">· {{ Bytes::format((int) $status['free']) }} boş</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3"><x-status-badge :status="$status ? new Badge('Bağlı', 'ok') : new Badge('Ajana ulaşılamıyor', 'crit')" /></td>
                            <td class="py-3 pr-5 pl-3"></td>
                        </tr>
                        @foreach ($targets as $t)
                            <tr wire:key="t-{{ $t->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 whitespace-nowrap">
                                    {{ $t->name }}
                                    <span class="mt-0.5 block text-2xs text-muted">{{ $t->typeLabel() }}</span>
                                </td>
                                <td class="hidden max-w-[320px] truncate px-3 py-3 font-mono text-[12.5px] text-muted sm:table-cell" title="{{ $t->address() }}">{{ $t->address() }}</td>
                                <td class="num hidden px-3 py-3 text-right whitespace-nowrap sm:table-cell">{{ isset($usage[$t->id]) ? Bytes::format((int) $usage[$t->id]) : '—' }}</td>
                                <td class="px-3 py-3">
                                    <x-status-badge :status="match ($t->status) { 'ok' => new Badge('Bağlı', 'ok'), 'failed' => new Badge('Son test başarısız', 'warn'), default => new Badge('Test edilmedi', 'muted') }" />
                                    @if ($t->status === 'failed' && $t->last_error)
                                        <span class="mt-1 block max-w-xs text-2xs break-words text-muted">{{ Str::limit($t->last_error, 140) }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-5 pl-3 text-right">
                                    <span class="inline-flex items-center gap-4">
                                        <button type="button" wire:click="testTarget({{ $t->id }})" wire:loading.attr="disabled" wire:target="testTarget({{ $t->id }})" class="h-8 rounded-md border border-line bg-surface px-3 text-[13px] whitespace-nowrap hover:bg-surface-2">Test et</button>
                                        <button type="button" wire:click="openTarget({{ $t->id }})" class="{{ $link }}">Düzenle</button>
                                        <button type="button" wire:click="deleteTarget({{ $t->id }})" class="text-[13px] text-crit hover:underline">Kaldır</button>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">Uzak hedeflere giden yedekler varsayılan olarak AES-256 ile şifrelenir. Birden fazla Servik sunucusu aynı bucket'ı kullanabilir; her sunucu kendi klasörüne yazar.</p>
        @else
            <form wire:submit="saveSchedule" class="max-w-3xl px-5 py-5">
                <div class="divide-y divide-line text-[13.5px]">
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>Otomatik yedekleme</span>
                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model.live="sEnabled" class="size-4 accent-accent">Açık · tüm sunucu, panel dahil</label>
                    </div>
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="s-freq">Sıklık</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <select id="s-freq" wire:model.live="sFrequency" class="{{ $field }} w-full px-2.5 sm:w-48">
                                @foreach (BackupSettings::FREQUENCIES as $k => $l)
                                    <option value="{{ $k }}">{{ $l }}</option>
                                @endforeach
                            </select>
                            @if ($sFrequency !== 'monthly')
                                <select wire:model="sWeekday" aria-label="{{ $sFrequency === 'daily' ? 'Tam yedek günü' : 'Gün' }}" class="{{ $field }} px-2.5">
                                    @foreach (BackupSettings::WEEKDAYS as $k => $l)
                                        <option value="{{ $k }}">{{ $l }}</option>
                                    @endforeach
                                </select>
                                <span class="text-[13px] text-muted">{{ $sFrequency === 'daily' ? 'tam yedek günü' : '' }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="s-time">Başlangıç saati</label>
                        <input id="s-time" type="time" wire:model="sTime" class="{{ $field }} num w-32">
                        @error('sTime') <p class="text-[13px] text-crit sm:col-start-2">{{ $message }}</p> @enderror
                    </div>
                    @if ($sFrequency !== 'monthly')
                        <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>Artımlı yedek</span>
                            <label class="flex items-start gap-2.5"><input type="checkbox" wire:model="sIncremental" class="mt-0.5 size-4 accent-accent">
                                <span>{{ $sFrequency === 'daily' ? 'Tam yedek gününden sonraki günlerde yalnızca değişenleri al' : 'Ayın ilk yedeği tam, diğerleri yalnızca değişenler' }}<span class="block text-[12.5px] text-muted">Dosyalar ve e-posta için. Veritabanları her seferinde tam alınır.</span></span>
                            </label>
                        </div>
                    @endif
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>Saklama</span>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <label class="flex items-center gap-2"><input wire:model="sKeepDaily" inputmode="numeric" class="{{ $field }} num w-16">günlük</label>
                            <label class="flex items-center gap-2"><input wire:model="sKeepWeekly" inputmode="numeric" class="{{ $field }} num w-16">haftalık</label>
                            <label class="flex items-center gap-2"><input wire:model="sKeepMonthly" inputmode="numeric" class="{{ $field }} num w-16">aylık</label>
                        </div>
                        @foreach (['sKeepDaily', 'sKeepWeekly', 'sKeepMonthly'] as $f)
                            @error($f) <p class="text-[13px] text-crit sm:col-start-2">{{ $message }}</p> @enderror
                        @endforeach
                        <p class="text-[12.5px] text-muted sm:col-start-2">Elle alınan yedekler siz silene kadar kalır.</p>
                    </div>
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="s-target">Hedef</label>
                        <div class="space-y-2">
                            <select id="s-target" wire:model.live="sTarget" class="{{ $field }} w-full px-2.5 sm:w-72">
                                <option value="">Yerel disk</option>
                                @foreach ($targets as $t)
                                    <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->typeLabel() }})</option>
                                @endforeach
                            </select>
                            @if ($sTarget !== '')
                                <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="sKeepLocal" class="size-4 accent-accent">En yeni yedeği bu sunucuda da sakla</label>
                            @endif
                            @if ($targets->isEmpty())
                                <p class="text-[12.5px] text-muted">Uzak bir hedef için <button type="button" wire:click="$set('tab', 'hedefler')" class="text-accent hover:underline">Hedefler</button> sekmesinden S3 ya da SFTP ekleyin.</p>
                            @endif
                        </div>
                    </div>
                </div>
                <details class="mt-4 text-[13.5px]" @if ($errors->has('sExclude')) open @endif>
                    <summary class="cursor-pointer text-accent">Gelişmiş</summary>
                    <div class="mt-2 divide-y divide-line">
                        <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>Şifreleme</span>
                            <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="sEncrypt" class="size-4 accent-accent">Uzak hedefe giden yedekleri AES-256 ile şifrele</label>
                        </div>
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span class="sm:pt-1.5">Şifreleme parolası</span>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <code class="rounded-md bg-surface-2 px-2.5 py-1.5 font-mono text-[13px]">{{ $passphrase ?? '••••••••-••••••••-••••••••-••••••••' }}</code>
                                    <button type="button" wire:click="togglePassphrase" class="{{ $link }}">{{ $passphrase ? 'Gizle' : 'Göster' }}</button>
                                </div>
                                <p class="mt-1.5 text-[12.5px] text-muted">Bu sunucu kaybolursa yedekleri başka bir sunucuda açmak için gerekir. Panelin dışında güvenli bir yere not edin.</p>
                            </div>
                        </div>
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <label for="s-exclude" class="sm:pt-1.5">Hariç tutulan dosyalar</label>
                            <div>
                                <textarea id="s-exclude" wire:model="sExclude" rows="3" class="w-full rounded-md border border-line bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent sm:w-96"></textarea>
                                <p class="mt-1 text-[12.5px] text-muted">Her satıra bir desen, örneğin <span class="font-mono">*/cache/*</span>.</p>
                                @error('sExclude') <p class="mt-1 text-[13px] text-crit">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        @if ($allSites->isNotEmpty())
                            <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                                <span>Yedeğe girmeyen siteler</span>
                                <div class="grid max-h-48 gap-1.5 overflow-y-auto sm:grid-cols-2">
                                    @foreach ($allSites as $domain)
                                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="sSkip" value="{{ $domain }}" class="size-4 accent-accent"><span class="font-mono text-[13px]">{{ $domain }}</span></label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </details>
                <div class="mt-5 flex gap-2">
                    <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Kaydet</button>
                </div>
            </form>
        @endif
    </section>

    {{-- Şimdi yedekle --}}
    <x-modal title="{{ $admin ? 'Şimdi yedekle' : 'Yedek al' }}" show="backingUp" size="max-w-md">
        <form wire:submit="startBackup">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $admin ? 'Şimdi yedekle' : 'Yedek al' }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                @if ($admin)
                    <fieldset class="grid gap-2">
                        @foreach (['server' => ['Tüm sunucu', 'Bütün siteler, veritabanları, e-postalar, DNS ve panelin kendi ayarları'], 'site' => ['Tek site', 'Bir sitenin dosyaları, veritabanları, e-postası, DNS kayıtları ve sertifikaları']] as $k => [$l, $hint])
                            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 {{ $backupScope === $k ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2' }}">
                                <input type="radio" wire:model.live="backupScope" value="{{ $k }}" class="mt-0.5 size-4 accent-accent">
                                <span><span class="block font-medium">{{ $l }}</span><span class="mt-0.5 block text-[12.5px] text-muted">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif
                @if (! $admin || $backupScope === 'site')
                    <div>
                        <label for="b-site" class="mb-1.5 block">Site</label>
                        <select id="b-site" wire:model="backupSite" class="{{ $field }} w-full px-2.5 font-mono text-[13px]">
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}">{{ $s->domain }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @error('backupSite') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
                <p class="text-[13px] text-muted">Yedek, sitelerin çalışmasını yavaşlatmamak için düşük öncelikle alınır. Bu pencereyi kapatabilirsiniz.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('backingUp', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Yedeklemeyi başlat</button>
            </div>
        </form>
    </x-modal>

    {{-- Geri yükle --}}
    <x-modal title="Yedeği geri yükle" show="restoring">
        @isset($restoreItem)
            @php $parts = $restoreItem->restorable(); @endphp
            <form wire:submit="restore">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Yedeği geri yükle</h2>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p class="text-[13px] text-muted">Yedek: <span class="num text-fg">{{ $when($restoreBackup->created_at) }} · {{ mb_strtolower($restoreBackup->typeLabel()) }} · {{ $restoreBackup->scope === 'server' ? 'tüm sunucu' : $restoreItem->name }}</span></p>
                    <div>
                        <label for="r-site" class="mb-1.5 block">Site</label>
                        <select id="r-site" wire:model.live="restoreItemId" @disabled($restoreItems->count() < 2) class="{{ $field }} w-full px-2.5 font-mono text-[13px]">
                            @foreach ($restoreItems as $i)
                                <option value="{{ $i->id }}">{{ $i->name }}</option>
                            @endforeach
                        </select>
                        @if ($restoreSite === null)
                            <p class="mt-1.5 text-[13px] text-warn">Bu site artık yok. Yedekteki tanımıyla hesabı{{ isset($restoreItem->meta['account']['name']) ? ' ('.$restoreItem->meta['account']['name'].')' : '' }} ve ayarlarıyla yeniden oluşturulur.</p>
                        @endif
                    </div>
                    <fieldset>
                        <legend class="mb-1.5">Geri yüklenecekler</legend>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (Backup::RESTORE_PARTS as $k => $l)
                                <label @class(['flex items-center gap-2.5', 'opacity-40' => ! in_array($k, $parts, true)])>
                                    <input type="checkbox" wire:model="restoreParts" value="{{ $k }}" @disabled(! in_array($k, $parts, true)) class="size-4 accent-accent">{{ $l }}
                                </label>
                            @endforeach
                        </div>
                        @error('restoreParts') <p class="mt-2 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </fieldset>
                    @if ($restoreSite !== null)
                        <label class="flex items-start gap-2.5"><input type="checkbox" wire:model="safety" class="mt-0.5 size-4 accent-accent"><span>Önce şimdiki hâli yedekle<span class="block text-[12.5px] text-muted">Geri yükleme bitince "Geri al" ile bu hâle dönebilirsiniz.</span></span></label>
                    @endif
                    <p class="text-[13px] text-muted">Seçilenlerin şimdiki hâli yedektekiyle değiştirilir: yedekten sonra eklenen dosyalar ve e-postalar silinir, veritabanları yedekteki hâline döner.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('restoring', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                    <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit disabled:opacity-40">Geri yükle</button>
                </div>
            </form>
        @endisset
    </x-modal>

    {{-- Ayrıntı --}}
    @isset($detail)
        @if ($detail)
            <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" wire:key="detail-{{ $detail->id }}" @keydown.escape.window="$wire.closeDetail()">
                <div class="w-full max-w-2xl rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Yedek ayrıntısı" @click.outside="$wire.closeDetail()">
                    <div class="flex h-12 items-center justify-between border-b border-line px-5">
                        <h2 class="text-[14.5px] font-semibold">{{ match ($detail->scope) { 'server' => 'Tüm sunucu yedeği', 'panel' => 'Panel yedeği', default => 'Site yedeği' } }} · <span class="num">{{ $when($detail->created_at) }}</span></h2>
                        <button type="button" wire:click="closeDetail" class="text-muted hover:text-fg" aria-label="Kapat"><x-icon name="x" /></button>
                    </div>
                    <div class="space-y-3 p-5 text-[13.5px]">
                        <dl class="grid grid-cols-[140px_1fr] gap-x-4 gap-y-1.5 text-[13px]">
                            <dt class="text-muted">Tür</dt><dd>{{ $detail->typeLabel() }} · {{ $detail->triggerLabel() }}{{ $detail->base ? ' · '.$when($detail->base->created_at).' yedeğinin devamı' : '' }}</dd>
                            <dt class="text-muted">Hedef</dt><dd>{{ $detail->destinationLabel() }}{{ $detail->encrypted ? ' · şifreli' : '' }}</dd>
                            <dt class="text-muted">Boyut ve süre</dt><dd class="num">{{ $detail->size ? Bytes::format($detail->size) : '—' }}{{ $detail->seconds !== null ? ' · '.\Carbon\CarbonInterval::seconds($detail->seconds)->cascade()->forHumans(['short' => true]) : '' }}</dd>
                            @if ($detail->source_host)
                                <dt class="text-muted">Alındığı sunucu</dt><dd class="font-mono">{{ $detail->source_host }}</dd>
                            @endif
                            @if ($detail->error)
                                <dt class="text-muted">Sorun</dt><dd class="break-words text-warn">{{ $detail->error }}</dd>
                            @endif
                        </dl>
                        <div class="max-h-80 overflow-y-auto rounded-lg border border-line">
                            <table class="w-full text-[13px]">
                                <tbody class="divide-y divide-line">
                                    @foreach ($detailItems as $i)
                                        <tr>
                                            <td class="px-3 py-2">
                                                <span @class(['font-mono' => ! $i->isPanel()])>{{ $i->isPanel() ? 'Panel (veritabanı ve ayarlar)' : $i->name }}</span>
                                                <span class="block text-2xs text-muted">{{ $i->partsLabel() ?: '—' }}{{ $i->isIncremental() ? ' · artımlı' : '' }}</span>
                                                @foreach (array_merge(array_map(fn ($w) => $w, $i->warnings ?? []), array_map(fn ($k, $v) => (BackupItem::PARTS[$k] ?? $k).': '.$v, array_keys($i->skipped ?? []), $i->skipped ?? [])) as $w)
                                                    <span class="block text-2xs break-words text-warn">{{ $w }}</span>
                                                @endforeach
                                                @if ($i->status === 'failed')
                                                    <span class="block text-2xs break-words text-crit">{{ $i->error }}</span>
                                                @endif
                                            </td>
                                            <td class="num px-3 py-2 text-right whitespace-nowrap">{{ $i->size ? Bytes::format($i->size) : '—' }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                @if ($i->status === 'succeeded' && $detail->isUsable())
                                                    @if ($detail->onServer())
                                                        <a href="{{ route('backups.download', $i) }}" class="{{ $link }}">İndir</a>
                                                    @endif
                                                    @if (! $i->isPanel())
                                                        <button type="button" wire:click="openRestore({{ $detail->id }}, {{ $i->id }})" @disabled($busy) class="ml-3 {{ $link }}">Geri yükle</button>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($detail->isUsable() && ! $detail->onServer())
                            <p class="text-[13px] text-muted">Bu yedek yalnızca {{ $detail->target?->name ?? 'uzak hedefte' }}. İndirmek için önce <button type="button" wire:click="fetch({{ $detail->id }})" wire:loading.attr="disabled" class="text-accent hover:underline">sunucuya alın</button>; geri yükleme bunu kendisi yapar.</p>
                        @endif
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
                        @if ($admin || ($detail->customer_id === auth()->user()->customer_id && $detail->trigger === 'manual'))
                            @if (! $detail->isRunning())
                                <button type="button" wire:click="askDelete({{ $detail->id }})" class="text-[13.5px] text-crit hover:underline">Yedeği sil</button>
                            @endif
                        @else
                            <span></span>
                        @endif
                        <button type="button" wire:click="closeDetail" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
                    </div>
                </div>
            </div>
        @endif
    @endisset

    {{-- Sil --}}
    @isset($deleting)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.set('deleteId', null)">
            <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="Yedeği sil">
                <p class="font-semibold">{{ $when($deleting->created_at) }} yedeği silinsin mi?</p>
                <p class="mt-1 text-[13px] text-muted">
                    Yedek bu sunucudan{{ $deleting->remote ? ' ve '.($deleting->target?->name ?? 'uzak hedeften') : '' }} silinir.
                    @if ($deletingDependents > 0)
                        Bu yedeğin devamı olan {{ $deletingDependents }} artımlı yedek de onunla birlikte silinir.
                    @endif
                    {{ \App\Services\Backups\Backups::UNDO_MINUTES }} dakika içinde geri alabilirsiniz.
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('deleteId', null)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">Vazgeç</button>
                    <button type="button" wire:click="delete" class="h-9 rounded-md bg-crit px-3.5 font-medium text-on-crit">Sil</button>
                </div>
            </div>
        </div>
    @endisset

    {{-- Hedef ekle / düzenle --}}
    @if ($admin)
        <x-modal title="{{ $targetId ? 'Hedefi düzenle' : 'Hedef ekle' }}" show="editingTarget">
            <form wire:submit="saveTarget">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $targetId ? 'Hedefi düzenle' : 'Hedef ekle' }}</h2>
                <div class="max-h-[70vh] space-y-3.5 overflow-y-auto p-5 text-[13.5px]">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (BackupTarget::TYPES as $k => $l)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 {{ $tType === $k ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2' }}">
                                <input type="radio" wire:model.live="tType" value="{{ $k }}" @disabled($targetId) class="size-4 accent-accent">{{ $l }}
                            </label>
                        @endforeach
                    </div>
                    <div class="grid items-center gap-1.5 sm:grid-cols-[130px_1fr] sm:gap-3">
                        <label for="t-name">Ad</label>
                        <input id="t-name" wire:model="tName" placeholder="{{ $tType === 's3' ? 'Wasabi yedekleri' : 'Yedek sunucusu' }}" class="{{ $field }} w-full">
                    </div>
                    @if ($tType === 's3')
                        <div class="grid items-center gap-1.5 sm:grid-cols-[130px_1fr] sm:gap-3">
                            <label for="t-provider">Sağlayıcı</label>
                            <select id="t-provider" wire:model.live="tProvider" class="{{ $field }} w-full px-2.5">
                                @foreach (BackupTarget::PROVIDERS as $k => [$l])
                                    <option value="{{ $k }}">{{ $l }}</option>
                                @endforeach
                            </select>
                            <label for="t-endpoint">Uç nokta</label>
                            <input id="t-endpoint" wire:model="tEndpoint" placeholder="{{ $tProvider === 'aws' ? 's3.'.$tRegion.'.amazonaws.com (boş bırakılabilir)' : 'https://minio.ornek.com:9000' }}" class="{{ $field }} w-full font-mono text-[13px]">
                            <label for="t-region">Bölge</label>
                            <input id="t-region" wire:model.live.debounce.400ms="tRegion" class="{{ $field }} w-full font-mono text-[13px]">
                            <label for="t-bucket">Bucket</label>
                            <input id="t-bucket" wire:model="tBucket" class="{{ $field }} w-full font-mono text-[13px]">
                            <label for="t-ak">Erişim anahtarı</label>
                            <input id="t-ak" wire:model="tAccessKey" autocomplete="off" class="{{ $field }} w-full font-mono text-[13px]">
                            <label for="t-sk">Gizli anahtar</label>
                            <input id="t-sk" type="password" wire:model="tSecretKey" autocomplete="new-password" placeholder="{{ $targetId ? 'Değiştirmek için yazın' : '' }}" class="{{ $field }} w-full font-mono text-[13px]">
                            <span></span>
                            <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="tPathStyle" class="size-4 accent-accent">Bucket adresin yolunda (MinIO gibi)</label>
                        </div>
                    @else
                        <div class="grid items-center gap-1.5 sm:grid-cols-[130px_1fr] sm:gap-3">
                            <label for="t-host">Sunucu</label>
                            <div class="flex gap-2">
                                <input id="t-host" wire:model="tHost" placeholder="yedek.ornek.com" class="{{ $field }} min-w-0 flex-1 font-mono text-[13px]">
                                <input wire:model="tPort" inputmode="numeric" aria-label="Port" class="{{ $field }} num w-20">
                            </div>
                            <label for="t-user">Kullanıcı</label>
                            <input id="t-user" wire:model="tUser" class="{{ $field }} w-full font-mono text-[13px]">
                        </div>
                        @if ($publicKey)
                            <div class="rounded-lg border border-line bg-surface-2/60 p-3">
                                <p class="text-[13px]">Servik bu sunucuya kendi SSH anahtarıyla bağlanır. Aşağıdaki satırı hedef sunucudaki kullanıcının <span class="font-mono">~/.ssh/authorized_keys</span> dosyasına ekleyin:</p>
                                <div x-data class="mt-2 flex items-start gap-2">
                                    <code x-ref="k" class="min-w-0 flex-1 rounded bg-surface px-2 py-1.5 font-mono text-[12px] break-all">{{ $publicKey }}</code>
                                    <button type="button" @click="navigator.clipboard.writeText($refs.k.textContent); $dispatch('toast', { message: 'Anahtar kopyalandı.' })" class="text-[13px] text-accent hover:underline">Kopyala</button>
                                </div>
                                @if ($editing?->config['fingerprint'] ?? null)
                                    <p class="mt-2 text-2xs text-muted">Sunucunun anahtarı: <span class="font-mono">{{ $editing->config['fingerprint'] }}</span></p>
                                @else
                                    <p class="mt-2 text-2xs text-muted">Sunucunun kendi anahtarı ilk testte öğrenilir ve sonraki her bağlantıda denetlenir.</p>
                                @endif
                            </div>
                        @endif
                    @endif
                    <div class="grid items-center gap-1.5 sm:grid-cols-[130px_1fr] sm:gap-3">
                        <label for="t-folder">Klasör</label>
                        <input id="t-folder" wire:model="tFolder" placeholder="servik" class="{{ $field }} w-full font-mono text-[13px]">
                        <span></span>
                        <p class="text-[12.5px] text-muted">Yedekler <span class="font-mono">{{ ($tFolder !== '' ? $tFolder.'/' : '').BackupTarget::serverName() }}/</span> altına yazılır.</p>
                    </div>
                    @foreach (['tName', 'tType', 'tBucket', 'tRegion', 'tEndpoint', 'tAccessKey', 'tSecretKey', 'tHost', 'tPort', 'tUser', 'tFolder'] as $f)
                        @error($f) <p class="text-[13px] break-words text-crit">{{ $message }}</p> @enderror
                    @endforeach
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-line px-5 py-3">
                    <span wire:loading wire:target="saveTarget" class="mr-auto text-[13px] text-muted">Bağlantı test ediliyor…</span>
                    <button type="button" wire:click="$set('editingTarget', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                    <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Test et ve kaydet</button>
                </div>
            </form>
        </x-modal>

        {{-- İçe aktar --}}
        <x-modal title="Yedek içe aktar" show="importing">
            <div>
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Yedek içe aktar</h2>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p class="text-[13px] text-muted">Başka bir Servik sunucusunun yedeğini buraya taşıyın ya da bu sunucuyu yeniden kurun. cPanel ve Plesk yedekleri içe aktarılamaz.</p>
                    <div class="flex gap-4 border-b border-line text-[13.5px]" role="tablist">
                        @foreach (['upload' => 'Dosya yükle', 'folder' => 'İçe aktarma klasörü', 'target' => 'Hedeften'] as $k => $l)
                            <button type="button" role="tab" wire:click="$set('importFrom', '{{ $k }}')" @class(['-mb-px h-9 border-b-2 whitespace-nowrap', 'border-accent font-medium' => $importFrom === $k, 'border-transparent text-muted hover:text-fg' => $importFrom !== $k])>{{ $l }}</button>
                        @endforeach
                    </div>
                    <div>
                        <label for="i-pass" class="mb-1.5 block">Şifreleme parolası <span class="text-muted">(şifreli yedekler için)</span></label>
                        <input id="i-pass" type="password" wire:model="importPassphrase" autocomplete="off" class="{{ $field }} w-full font-mono text-[13px]">
                    </div>
                    @if ($importFrom === 'upload')
                        <div x-data="{
                                file: null, sent: 0, busy: false, error: '',
                                async send() {
                                    if (! this.file) return;
                                    this.busy = true; this.error = ''; this.sent = 0;
                                    const id = await $wire.newUpload();
                                    const size = {{ \App\Services\Backups\BackupImports::UPLOAD_CHUNK }};
                                    while (this.sent < this.file.size || this.sent === 0) {
                                        const part = this.file.slice(this.sent, this.sent + size);
                                        const r = await fetch(@js(url('/yedekler/yukle')) + '/' + id + '?offset=' + this.sent, { method: 'POST', body: part, headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Content-Type': 'application/octet-stream' } });
                                        const j = await r.json().catch(() => ({}));
                                        if (! r.ok) { this.error = j.error || 'Yükleme durdu.'; this.busy = false; return; }
                                        this.sent = j.size;
                                        if (this.file.size === 0) break;
                                    }
                                    await $wire.importUpload(id);
                                    this.busy = false;
                                },
                             }">
                            <input type="file" accept=".tar,.enc" @change="file = $event.target.files[0]" class="block w-full text-[13px] file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-surface file:px-3 file:text-[13px] hover:file:bg-surface-2">
                            <div x-show="busy" x-cloak class="mt-3">
                                <div class="h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full bg-accent" :style="`width: ${file ? Math.round(sent / Math.max(file.size, 1) * 100) : 0}%`"></div></div>
                                <p class="num mt-1 text-2xs text-muted" x-text="`${Math.round(sent / 1048576)} / ${Math.round((file?.size ?? 0) / 1048576)} MB`"></p>
                            </div>
                            <p x-show="error" x-text="error" x-cloak class="mt-2 text-[13px] text-crit"></p>
                            <p class="mt-2 text-[12.5px] text-muted">Bir Servik site arşivi (<span class="font-mono">.tar</span> ya da şifreli <span class="font-mono">.tar.enc</span>). Çok büyük arşivleri sunucuya kopyalayıp "İçe aktarma klasörü"nden alın.</p>
                            <div class="mt-3 flex justify-end">
                                <button type="button" @click="send()" :disabled="! file || busy" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Yükle ve içe aktar</button>
                            </div>
                        </div>
                    @elseif ($importFrom === 'folder')
                        <p class="text-[13px] text-muted">Arşivleri <span class="font-mono">/var/lib/servik/backups/import</span> klasörüne kopyalayın (örneğin <span class="font-mono">scp</span> ile), sonra burada seçin.</p>
                        @isset($importError)
                            <p class="text-[13px] text-crit">{{ $importError }}</p>
                        @endisset
                        <div class="divide-y divide-line rounded-lg border border-line">
                            @forelse ($importFiles ?? [] as $f)
                                <div class="flex items-center gap-3 px-3 py-2 text-[13px]">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-mono">{{ $f['file'] }}</span>
                                        <span class="block text-2xs text-muted">{{ $f['encrypted'] ? 'Şifreli' : ($f['name'] ?? '').($f['server'] ? ' · '.$f['server'] : '') }}</span>
                                    </span>
                                    <button type="button" wire:click="importFile(@js($f['file']))" wire:loading.attr="disabled" class="{{ $link }}">İçe aktar</button>
                                </div>
                            @empty
                                <p class="px-3 py-4 text-center text-[13px] text-muted">Klasörde arşiv yok.</p>
                            @endforelse
                        </div>
                    @else
                        @if ($importTargets->isEmpty())
                            <p class="text-[13px] text-muted">Önce <button type="button" wire:click="$set('importing', false); $set('tab', 'hedefler')" class="text-accent hover:underline">Hedefler</button> sekmesinden yedeklerin bulunduğu S3 ya da SFTP hedefini ekleyin.</p>
                        @else
                            <div class="flex gap-2">
                                <select wire:model="scanTargetId" aria-label="Hedef" class="{{ $field }} min-w-0 flex-1 px-2.5">
                                    @foreach ($importTargets as $t)
                                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="scanTarget" wire:loading.attr="disabled" class="{{ $btn }}"><x-icon name="search" wire:loading.class="animate-pulse" wire:target="scanTarget" />Tara</button>
                            </div>
                            @error('scanTargetId') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
                            @if ($scanResults !== null)
                                <div class="max-h-64 divide-y divide-line overflow-y-auto rounded-lg border border-line">
                                    @forelse ($scanResults as $f)
                                        <div class="flex items-center gap-3 px-3 py-2 text-[13px]">
                                            <span class="min-w-0 flex-1">
                                                <span class="num block">{{ \Illuminate\Support\Carbon::parse($f['created'])->timezone(config('servik.timezone'))->format('d.m.Y H:i') }} · {{ $f['scope'] === 'server' ? 'tüm sunucu' : ($f['names'][0] ?? 'site') }}</span>
                                                <span class="block text-2xs text-muted"><span class="font-mono">{{ $f['server'] ?: $f['path'] }}</span> · {{ $f['incremental'] ? 'artımlı' : 'tam' }} · {{ Bytes::format((int) $f['size']) }}{{ $f['encrypted'] ? ' · şifreli' : '' }}</span>
                                            </span>
                                            @if ($f['known'])
                                                <span class="text-2xs text-muted">Listede</span>
                                            @else
                                                <button type="button" wire:click="adopt(@js($f['key']))" wire:loading.attr="disabled" class="{{ $link }}">Listeye ekle</button>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="px-3 py-4 text-center text-[13px] text-muted">Bu hedefte Servik yedeği bulunamadı.</p>
                                    @endforelse
                                </div>
                            @endif
                        @endif
                    @endif
                    @error('importPassphrase') <p class="text-[13px] break-words text-crit">{{ $message }}</p> @enderror
                    <p class="text-2xs text-muted">Sunucunun tamamını yeniden kurmak için: aynı veritabanı sunucularını kurun, e-postayı ayarlayın, yedeği listeye ekleyin ve sunucuda <span class="font-mono">php artisan servik:restore-server &lt;yedek&gt; --panel --sites</span> çalıştırın.</p>
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('importing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- İlerleme --}}
    @isset($run)
        @if ($run)
            @php
                $isRestore = $runKind === 'restore';
                $title = $isRestore ? $run->domain.' geri yükleniyor' : match ($run->scope) { 'server' => 'Tüm sunucu yedekleniyor', 'panel' => 'Panel yedekleniyor', default => $run->items->first()?->name.' yedekleniyor' };
                $doneTitle = $isRestore ? $run->domain.' geri yüklendi' : 'Yedek alındı';
            @endphp
            <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" wire:key="run-{{ $runKind }}-{{ $run->id }}">
                <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ $title }}">
                    <div class="flex h-12 items-center border-b border-line px-5">
                        <h2 class="text-[14.5px] font-semibold">
                            @if ($run->isRunning())
                                {{ $title }}
                            @elseif (in_array($run->status, ['succeeded'], true))
                                {{ $doneTitle }}
                            @elseif ($run->status === 'partial')
                                Yedek kısmen alındı
                            @else
                                {{ $isRestore ? 'Geri yükleme tamamlanamadı' : 'Yedek alınamadı' }}
                            @endif
                        </h2>
                    </div>
                    <div class="space-y-3 p-5 text-[13.5px]">
                        @if ($run->status === 'queued')
                            <p class="flex items-center gap-2.5 text-[13px] text-muted"><x-icon name="loader-circle" class="size-4 animate-spin text-accent" />Sırada bekliyor</p>
                        @endif
                        <x-run-log :run="$run->run" class="h-64" />
                        @if (in_array($run->status, ['failed', 'partial'], true) && $run->error)
                            <div class="rounded-lg border border-line {{ $run->status === 'failed' ? 'bg-crit-soft' : 'bg-warn-soft' }} p-3 text-[13px]">
                                <p class="break-words">{{ $run->error }}</p>
                            </div>
                        @endif
                        @if ($isRestore && $run->status === 'succeeded')
                            @foreach ((array) ($run->result['skipped'] ?? []) as $part => $why)
                                <p class="text-[13px] text-warn">{{ BackupItem::PARTS[$part] ?? $part }}: {{ $why }}</p>
                            @endforeach
                            @if ($run->recreated)
                                <p class="text-[13px] text-muted">Site yeniden oluşturuldu.@if ($run->site) <a href="{{ route('sites.show', $run->site_id) }}" wire:navigate class="text-accent hover:underline">Siteye git</a>@endif</p>
                            @endif
                        @endif
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
                        @if ($run->isRunning())
                            <p class="text-2xs text-muted">Bu pencereyi kapatabilirsiniz; işlem arka planda sürer.</p>
                        @elseif ($isRestore && $run->status === 'succeeded' && $run->safetyBackup)
                            <button type="button" wire:click="undoRestore({{ $run->id }})" class="text-[13.5px] text-accent hover:underline">Geri al</button>
                        @else
                            <span></span>
                        @endif
                        <button type="button" wire:click="closeRun" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
                    </div>
                </div>
            </div>
        @endif
    @endisset
</div>
