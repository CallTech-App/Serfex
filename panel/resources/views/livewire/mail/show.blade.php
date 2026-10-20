@php
    use App\Enums\MailDomainStatus;
    use App\Support\Bytes;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $closed = $domain->status === MailDomainStatus::Deleting;
    $quotaChoices = $quotas;
    if (! isset($quotaChoices[$mbQuota])) {
        $quotaChoices = [$mbQuota => Bytes::format($mbQuota * 1048576)] + $quotaChoices;
    }
    $needsRecords = collect($records)->contains(fn ($r) => in_array($r['zone'], ['missing', 'different'], true));
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on the domain. --}}
    @if ($domain->status->isBusy())
        <div wire:poll.2s="refreshStatus" hidden></div>
    @endif

    {{-- Title --}}
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('mail.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('E-posta') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="font-mono text-fg">{{ $domain->name }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $domain->name }}</h1>
                <x-status-badge :status="$domain->status" />
            </div>
            @if ($domain->site)
                <a href="{{ route('sites.show', $domain->site) }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                    <x-icon name="globe" />{{ __('Siteye git') }}
                </a>
            @endif
        </div>
        <p class="mt-1 text-[13px] text-muted">{{ __('Bu alan adının posta kutularını, yönlendiricilerini ve posta ayarlarını yönetin.') }}</p>
    </div>

    @if (auth()->user()->is_admin)
        @include('livewire.mail.server-state')
    @endif

    @if ($closed)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
            <x-icon name="loader-circle" class="animate-spin text-info" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Bu alan adı için e-posta kapatıldı') }}</p>
                <p class="mt-0.5 text-[13px] text-muted">{{ __('Posta artık alınmıyor. Posta kutuları ve içindeki postalar :undo_seconds sularında silinir.', ['undo_seconds' => \App\Support\When::time($domain->disabled_at?->addSeconds(\App\Services\Mail\MailDomains::UNDO_SECONDS))]) }}</p>
            </div>
            <button type="button" wire:click="undoDisable" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="rotate-ccw" class="size-3.5" />{{ __('Geri al') }}
            </button>
        </div>
    @elseif ($domain->status === MailDomainStatus::Failed)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Son değişiklik posta sunucusuna uygulanamadı. Sunucu önceki ayarlarla çalışmaya devam ediyor.') }}</p>
                <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $domain->last_error }}</p>
            </div>
            <button type="button" wire:click="retry" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="refresh-cw" class="size-3.5" />{{ __('Tekrar dene') }}
            </button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (['kutular' => __('Posta kutuları'), 'yonlendiriciler' => __('Yönlendiriciler'), 'trafik' => __('Posta trafiği'), 'ayarlar' => __('Posta ayarları')] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'kutular')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openCreate" @disabled($closed) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                    <x-icon name="plus" />{{ __('Posta kutusu oluştur') }}
                </button>
                <button type="button" wire:click="deleteSelected" @disabled($selected === [])
                        class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                    {{ __('Sil') }}{{ $selected ? ' ('.count($selected).')' : '' }}
                </button>
                @if ($webmail)
                    <a href="{{ $webmail }}" target="_blank" rel="noopener" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2">
                        <x-icon name="external-link" />{{ __('Web posta') }}
                    </a>
                @endif
                <label class="relative ml-auto w-full sm:w-56">
                    <span class="sr-only">{{ __('Posta kutusu ara') }}</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Ara') }}"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[760px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="w-10 py-2.5 pl-5"><span class="sr-only">{{ __('Seç') }}</span></th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Adres') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Kullanım') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Yönlendirme') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Otomatik yanıt') }}</th>
                            <th class="w-28 px-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($mailboxes as $m)
                            @php $pct = $m->usagePercent(); @endphp
                            <tr wire:key="box-{{ $m->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pl-5"><input type="checkbox" wire:model.live="selected" value="{{ $m->id }}" aria-label="{{ __(':m seç', ['m' => $m->address()]) }}" class="size-4 accent-accent"></td>
                                <td class="px-3 py-3 font-mono text-[13px]">
                                    <button type="button" wire:click="openEdit({{ $m->id }})" class="text-left font-medium hover:text-accent">{{ $m->name }}<span class="font-normal text-muted">{{ '@'.$domain->name }}</span></button>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2.5">
                                        @if ($pct !== null)
                                            <span class="h-1.5 w-20 overflow-hidden rounded-full bg-surface-2"><span @class(['block h-full rounded-full', 'bg-ok' => $pct < 80, 'bg-warn' => $pct >= 80 && $pct < 95, 'bg-crit' => $pct >= 95]) style="width: {{ max($pct, 2) }}%"></span></span>
                                        @endif
                                        <span class="num text-[13px] whitespace-nowrap text-muted">
                                            {{ $m->usage_bytes === null ? '—' : Bytes::format($m->usage_bytes) }} / {{ $m->quota_mb === 0 ? __('sınırsız') : Bytes::format($m->quota_mb * 1048576) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="max-w-56 truncate px-3 py-3 font-mono text-[13px] text-muted" title="{{ implode(', ', $m->forward_to) }}">
                                    {{ $m->forward_to ? implode(', ', $m->forward_to) : '—' }}
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap text-muted">
                                    @if ($m->autoreplyActive())
                                        {{ __('Açık:until', ['until' => ! empty($m->autoreply['until']) ? __(', :until tarihine kadar', ['until' => \App\Support\When::date(\Illuminate\Support\Carbon::parse($m->autoreply['until']))]) : '']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right whitespace-nowrap">
                                    @if ($webmail && ! $closed)
                                        <a href="{{ route('mail.webmail', $m) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[13px] text-muted hover:text-fg" title="{{ __(':m olarak web postayı aç', ['m' => $m->address()]) }}">
                                            {{ __('Web posta') }}<x-icon name="external-link" class="size-3.5" />
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center">
                                    @if ($search !== '')
                                        <p class="text-muted">{{ __('Aramanızla eşleşen posta kutusu yok.') }}</p>
                                    @else
                                        <p class="font-medium">{{ __('Henüz posta kutusu yok') }}</p>
                                        <p class="mt-1 text-[13px] text-muted"><x-t text="İlk posta kutusunu oluşturun, örneğin :name."><x-slot:name><span class="font-mono">{{ __('bilgi') }}{{ '@'.$domain->name }}</span></x-slot:name></x-t></p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="num border-t border-line px-5 py-3 text-2xs text-muted">{{ __(':mailbox_count posta kutusu', ['mailbox_count' => $mailboxCount]) }}</p>
        @elseif ($tab === 'yonlendiriciler')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openForwarder" @disabled($closed) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                    <x-icon name="plus" />{{ __('Yönlendirici ekle') }}
                </button>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Gelen adres') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Gönderilen adres') }}</th>
                            <th class="w-36 px-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($forwarders as $f)
                            <tr wire:key="fwd-{{ $f->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 font-mono text-[13px]">
                                    @if ($f->isCatchAll())
                                        *{{ '@'.$domain->name }} <span class="font-sans text-2xs text-muted">{{ __('bilinmeyen tüm adresler') }}</span>
                                    @else
                                        {{ $domain->address($f->name) }}
                                    @endif
                                </td>
                                <td class="px-3 py-3 font-mono text-[13px]">{{ implode(', ', $f->destinations) }}</td>
                                <td class="px-3 py-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="openForwarder({{ $f->id }})" class="text-[13px] text-muted hover:text-fg">{{ __('Düzenle') }}</button>
                                    <button type="button" wire:click="deleteForwarder({{ $f->id }})" class="ml-3 text-[13px] text-muted hover:text-crit">{{ __('Sil') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-10 text-center">
                                    <p class="font-medium">{{ __('Yönlendirici yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('Yönlendirici, bir adrese gelen postayı posta kutusu açmadan başka adreslere iletir.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Bir posta kutusunun postasını da iletmek için posta kutusunu açıp "Yönlendirme" alanını doldurun.') }}</p>
        @elseif ($tab === 'trafik')
            @php $num = fn (int $n) => \App\Support\Num::format($n, 0); @endphp
            <div class="border-b border-line px-5 py-3 text-[13.5px]">
                <p class="num text-muted">
                    <x-t text="Son 30 gün: :received gelen · :sent giden · :rejected reddedilen · :bounced teslim edilemeyen"><x-slot:received><span class="font-semibold text-fg">{{ $num($traffic['totals']['received']) }}</span></x-slot:received><x-slot:sent><span class="font-semibold text-fg">{{ $num($traffic['totals']['sent']) }}</span></x-slot:sent><x-slot:rejected><span @class(['font-semibold', 'text-fg' => ! $traffic['totals']['rejected'], 'text-warn' => $traffic['totals']['rejected'] > 0])>{{ $num($traffic['totals']['rejected']) }}</span></x-slot:rejected><x-slot:bounced><span @class(['font-semibold', 'text-fg' => ! $traffic['totals']['bounced'], 'text-warn' => $traffic['totals']['bounced'] > 0])>{{ $num($traffic['totals']['bounced']) }}</span></x-slot:bounced></x-t>
                </p>
            </div>
            <div class="max-h-[60vh] overflow-auto">
                <table class="w-full min-w-[560px] text-[13.5px]">
                    <thead class="sticky top-0 bg-surface">
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Gün') }}</th>
                            @foreach (\App\Services\Stats\MailTraffic::COUNTS as $label)
                                <th class="px-3 py-2.5 text-right font-medium last:pr-5">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($traffic['days'] as $day)
                            <tr wire:key="mail-day-{{ $day['day']->toDateString() }}" class="hover:bg-surface-2/60">
                                <td class="num py-2.5 pr-3 pl-5">{{ \App\Support\When::date($day['day']) }} <span class="text-2xs text-muted">{{ $day['day']->locale(app()->getLocale())->isoFormat('dddd') }}</span></td>
                                @foreach (array_keys(\App\Services\Stats\MailTraffic::COUNTS) as $c)
                                    <td @class(['num px-3 py-2.5 text-right last:pr-5', 'text-muted' => $day[$c] === 0])>{{ $num($day[$c]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Posta sunucusunun günlüğünden sayılır, dakikada bir güncellenir. Reddedilen: spam ya da bilinmeyen alıcı yüzünden sunucunun kabul etmediği iletiler. Teslim edilemeyen: karşı sunucunun geri çevirdiği giden iletiler.') }}</p>
        @else
            <form wire:submit="saveSettings" class="max-w-3xl px-5 py-5 text-[13.5px]">
                <div class="divide-y divide-line">
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>{{ __('Spam filtresi') }}</span>
                        <div>
                            <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="spamFilter" @disabled($closed) class="size-4 accent-accent">{{ __('Spam olarak işaretlenen postaları Önemsiz klasörüne taşı') }}</label>
                            <p class="mt-1 ml-[26px] text-2xs text-muted">{{ __('Rspamd puanı 6 ve üstü spam sayılır. 15 ve üstü her durumda reddedilir.') }}</p>
                        </div>
                    </div>
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>{{ __('DNS kayıtları') }}</span>
                        <div>
                            <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="manageDns" @disabled($closed) class="size-4 accent-accent">{{ __('MX, SPF, DKIM ve DMARC kayıtlarını Servik yönetsin') }}</label>
                            <p class="mt-1 ml-[26px] text-2xs text-muted">{{ __('Eksik kayıtlar alan adının bu sunucudaki DNS bölgesine yazılır; sizin değiştirdiğiniz kayıtlara dokunulmaz.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="mt-5">
                    <button type="submit" @disabled($closed) class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Kaydet') }}</button>
                </div>
            </form>

            <div class="border-t border-line">
                <div class="flex flex-wrap items-center gap-2 px-5 py-3">
                    <h2 class="text-[14px] font-semibold">{{ __('Posta için DNS kayıtları') }}</h2>
                    <span class="text-[13px] text-muted">
                        @if ($zone)
                            <x-t text="· :name bölgesinde"><x-slot:name><a href="{{ route('dns.show', $zone) }}" wire:navigate class="font-mono hover:text-accent">{{ $zone->name }}</a></x-slot:name></x-t>
                        @else
                            {{ __('· DNS bu sunucuda değil; kayıtları alan adının DNS sağlayıcısına ekleyin') }}
                        @endif
                    </span>
                    <div class="ml-auto flex gap-2">
                        <button type="button" wire:click="recheckDns" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                            <x-icon name="refresh-cw" class="size-3.5" />{{ __('Yeniden denetle') }}
                        </button>
                        @if ($zone && $needsRecords && ! $closed)
                            <button type="button" wire:click="fixDns" class="flex h-8 items-center gap-2 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110">{{ __('Önerilen kayıtları yaz') }}</button>
                        @endif
                    </div>
                </div>
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[760px] table-fixed text-[13.5px]">
                        <thead>
                            <tr class="border-y border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="w-56 py-2.5 pr-3 pl-5 font-medium">{{ __('Kayıt') }}</th>
                                <th class="w-[28%] px-3 py-2.5 font-medium">{{ __('Ad') }}</th>
                                <th class="w-20 px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                                <th class="px-3 py-2.5 font-medium">{{ __('Değer') }}</th>
                                <th class="w-28 py-2.5 pr-5 pl-3 font-medium">{{ __('Durum') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($records as $r)
                                <tr wire:key="dns-{{ $r['key'] }}">
                                    <td class="py-3 pr-3 pl-5">{{ $r['label'] }}</td>
                                    <td class="truncate px-3 py-3 font-mono text-[13px]" title="{{ $r['name'] }}">{{ $r['name'] }}</td>
                                    <td class="px-3 py-3"><span class="rounded border border-line bg-surface-2 px-2 py-0.5 font-mono text-2xs font-medium">{{ $r['type'] }}</span></td>
                                    <td class="px-3 py-3">
                                        <div class="flex items-center gap-2" x-data>
                                            <span class="block min-w-0 truncate font-mono text-[13px]" title="{{ $r['value'] }}">{{ $r['value'] }}</span>
                                            <button type="button" @click="navigator.clipboard?.writeText(@js($r['value']))" class="shrink-0 text-muted hover:text-fg" aria-label="{{ __('Değeri kopyala') }}"><x-icon name="copy" class="size-3.5" /></button>
                                        </div>
                                        @if ($r['zone'] === 'different')
                                            <p class="mt-0.5 truncate text-2xs text-muted" title="{{ implode(', ', $r['current']) }}"><x-t text="Bölgedeki: :current"><x-slot:current><span class="font-mono">{{ implode(', ', $r['current']) }}</span></x-slot:current></x-t></p>
                                        @elseif ($r['zone'] === 'written' && $r['public'] === false)
                                            <p class="mt-0.5 text-2xs text-muted">{{ __('Bölgede var; dış DNS henüz görmüyor.') }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-5 pl-3"><x-status-badge :status="$r['badge']" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-line px-5 py-3 text-2xs text-muted">
                    {{ __('Yazıldı: kayıt bu sunucunun DNS\'inde. Yayıldı: dış DNS de aynı değeri görüyor. Farklı: aynı ad için sizin girdiğiniz başka bir değer var.') }}
                </p>
            </div>

            <details class="border-t border-line px-5 py-3 text-[13.5px]">
                <summary class="cursor-pointer text-[13px] text-accent">{{ __('E-posta istemcisi ayarları') }}</summary>
                @if ($hostname)
                    <dl class="mt-3 grid max-w-xl grid-cols-[110px_1fr] gap-y-2 text-[13px]">
                        <dt class="text-muted">{{ __('Kullanıcı adı') }}</dt><dd><x-t text="Tam e-posta adresi, örneğin :name"><x-slot:name><span class="font-mono">{{ __('bilgi') }}{{ '@'.$domain->name }}</span></x-slot:name></x-t></dd>
                        <dt class="text-muted">{{ __('IMAP') }}</dt><dd class="font-mono">{{ $hostname }} · 993 · SSL/TLS</dd>
                        <dt class="text-muted">{{ __('POP3') }}</dt><dd class="font-mono">{{ $hostname }} · 995 · SSL/TLS</dd>
                        <dt class="text-muted">{{ __('SMTP') }}</dt><dd class="font-mono">{{ $hostname }} · 465 · SSL/TLS <span class="font-sans text-muted">{{ __('ya da 587 · STARTTLS') }}</span></dd>
                    </dl>
                    <p class="mt-2 text-2xs text-muted">{{ __('Thunderbird ve Outlook bu ayarları adresten kendileri bulur.') }}</p>
                @else
                    <p class="mt-2 text-[13px] text-muted">{{ __('Posta sunucusunun adı henüz girilmedi.') }}</p>
                @endif
            </details>

            @unless ($closed)
                <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-4 text-[13.5px]">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ __('E-postayı kapat') }}</p>
                        <p class="text-[13px] text-muted">{{ __('Bu alan adına posta gelmez; posta kutuları :undo_seconds dakika sonra içindeki postalarla birlikte silinir.', ['undo_seconds' => intdiv(\App\Services\Mail\MailDomains::UNDO_SECONDS, 60)]) }}</p>
                    </div>
                    <button type="button" wire:click="$set('disabling', true)" class="h-9 rounded-md border border-line px-3.5 text-crit hover:bg-surface-2">{{ __('E-postayı kapat') }}</button>
                </div>
            @endunless
        @endif
    </section>

    {{-- Posta kutusu --}}
    <x-modal title="{{ __('Posta kutusu') }}" show="editingMailbox">
        <form wire:submit="saveMailbox">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $mailboxId ? __('Posta kutusunu düzenle') : __('Posta kutusu oluştur') }}</h2>
            <div class="max-h-[70vh] space-y-4 overflow-y-auto p-5 text-[13.5px]">
                <div>
                    <label for="mbName" class="mb-1.5 block">{{ __('E-posta adresi') }}</label>
                    @if ($mailboxId)
                        <p class="font-mono text-[13px]">{{ $mbName }}{{ '@'.$domain->name }}</p>
                    @else
                        <div class="flex">
                            <input id="mbName" wire:model="mbName" required placeholder="{{ __('ad') }}" autocomplete="off" spellcheck="false"
                                   class="h-9 min-w-0 flex-1 rounded-l-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('mbName') ? 'border-crit' : 'border-line' }}">
                            <span class="flex h-9 items-center rounded-r-md border border-l-0 border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ '@'.$domain->name }}</span>
                        </div>
                        @error('mbName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    @endif
                </div>
                <div>
                    <label for="mbPassword" class="mb-1.5 block">{{ $mailboxId ? __('Yeni parola') : __('Parola') }}</label>
                    <div class="flex gap-2">
                        <input id="mbPassword" wire:model="mbPassword" type="text" autocomplete="new-password" spellcheck="false" @if (! $mailboxId) required @endif
                               placeholder="{{ $mailboxId ? __('Değiştirmek istemiyorsanız boş bırakın') : '' }}"
                               class="{{ $field }} font-mono {{ $errors->has('mbPassword') ? 'border-crit' : 'border-line' }}">
                        <button type="button" wire:click="generatePassword" class="h-9 shrink-0 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">{{ __('Oluştur') }}</button>
                    </div>
                    @error('mbPassword')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-2xs text-muted">{{ __('Parolayı şimdi not edin; Servik parolaları saklamaz, yalnızca doğrular.') }}</p>
                    @enderror
                </div>
                <div>
                    <label for="mbQuota" class="mb-1.5 block">{{ __('Kota') }}</label>
                    <select id="mbQuota" wire:model.number="mbQuota" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                        @foreach ($quotaChoices as $mb => $label)
                            <option value="{{ $mb }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($mailboxId)
                    <div>
                        <label for="mbForward" class="mb-1.5 block">{{ __('Yönlendirme') }}</label>
                        <textarea id="mbForward" wire:model="mbForward" rows="2" spellcheck="false" placeholder="{{ __('baska@ornek.com') }}"
                                  class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('mbForward') ? 'border-crit' : 'border-line' }}"></textarea>
                        @error('mbForward')
                            <p class="mt-1 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1 text-2xs text-muted">{{ __('Gelen her postanın bir kopyası bu adreslere gönderilir. Her satıra bir adres.') }}</p>
                        @enderror
                        <label class="mt-2 flex items-center gap-2.5 text-[13px]"><input type="checkbox" wire:model="mbKeepCopy" class="size-4 accent-accent">{{ __('Postaların bir kopyasını bu kutuda da tut') }}</label>
                    </div>
                    <div x-data="{ on: $wire.entangle('mbAutoreply') }">
                        <label class="flex items-center gap-2.5"><input type="checkbox" x-model="on" class="size-4 accent-accent">{{ __('Otomatik yanıt') }}</label>
                        <div x-show="on" x-cloak class="mt-3 space-y-3 border-l-2 border-line pl-4">
                            <div>
                                <label for="mbSubject" class="mb-1 block text-[13px]">{{ __('Konu') }}</label>
                                <input id="mbSubject" wire:model="mbSubject" placeholder="{{ __('İzindeyim') }}" class="{{ $field }} {{ $errors->has('mbSubject') ? 'border-crit' : 'border-line' }}">
                            </div>
                            <div>
                                <label for="mbBody" class="mb-1 block text-[13px]">{{ __('Yanıt') }}</label>
                                <textarea id="mbBody" wire:model="mbBody" rows="4" class="w-full rounded-md border bg-surface px-3 py-2 text-[13px] outline-none focus:border-accent {{ $errors->has('mbBody') ? 'border-crit' : 'border-line' }}"></textarea>
                                @error('mbBody') <p class="mt-1 text-[13px] text-crit">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="mbUntil" class="mb-1 block text-[13px]">{{ __('Son gün') }}</label>
                                <input id="mbUntil" type="date" wire:model="mbUntil" class="h-9 rounded-md border bg-surface px-3 text-[13px] {{ $errors->has('mbUntil') ? 'border-crit' : 'border-line' }}">
                                @error('mbUntil')
                                    <p class="mt-1 text-[13px] text-crit">{{ $message }}</p>
                                @else
                                    <p class="mt-1 text-2xs text-muted">{{ __('Boş bırakılırsa kapatana kadar yanıtlar. Aynı gönderene günde en fazla bir yanıt gider.') }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editingMailbox', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="saveMailbox" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ $mailboxId ? __('Kaydet') : __('Oluştur') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Yönlendirici --}}
    <x-modal title="{{ __('Yönlendirici') }}" show="editingForwarder">
        <form wire:submit="saveForwarder">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $forwarderId ? __('Yönlendiriciyi düzenle') : __('Yönlendirici ekle') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="fwName" class="mb-1.5 block">{{ __('Gelen adres') }}</label>
                    @if ($forwarderId)
                        <p class="font-mono text-[13px]">{{ $fwCatchAll ? '*' : $fwName }}{{ '@'.$domain->name }}</p>
                    @else
                        <div class="flex">
                            <input id="fwName" wire:model="fwName" placeholder="{{ __('satis') }}" autocomplete="off" spellcheck="false" @disabled($fwCatchAll)
                                   class="h-9 min-w-0 flex-1 rounded-l-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent disabled:opacity-40 {{ $errors->has('fwName') ? 'border-crit' : 'border-line' }}">
                            <span class="flex h-9 items-center rounded-r-md border border-l-0 border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ '@'.$domain->name }}</span>
                        </div>
                        <label class="mt-2 flex items-center gap-2.5 text-[13px]"><input type="checkbox" wire:model.live="fwCatchAll" class="size-4 accent-accent">{{ __('Bu alan adındaki bilinmeyen tüm adresler (tümünü yakala)') }}</label>
                        @error('fwName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    @endif
                </div>
                <div>
                    <label for="fwTo" class="mb-1.5 block">{{ __('Gönderilecek adresler') }}</label>
                    <textarea id="fwTo" wire:model="fwTo" rows="3" spellcheck="false" placeholder="ali{{ '@'.$domain->name }}"
                              class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('fwTo') ? 'border-crit' : 'border-line' }}"></textarea>
                    @error('fwTo')
                        <p class="mt-1 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1 text-2xs text-muted">{{ __('Her satıra bir adres. Başka alan adlarındaki adresler de olabilir.') }}</p>
                    @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editingForwarder', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- E-postayı kapat --}}
    <x-modal title="{{ __('E-postayı kapat') }}" show="disabling" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold"><x-t text=":name için e-posta kapatılsın mı?"><x-slot:name><span class="font-mono">{{ $domain->name }}</span></x-slot:name></x-t></p>
            <p class="mt-1 text-[13px] text-muted">
                {{ __('Bu alan adına posta gelmez ve :mailbox_count posta kutusu oturum açamaz. Posta kutuları ve içindeki postalar :undo_seconds dakika içinde geri alınabilir, sonra silinir. Servik\'in yazdığı MX, SPF, DKIM ve DMARC kayıtları da kaldırılır.', ['mailbox_count' => $mailboxCount, 'undo_seconds' => intdiv(\App\Services\Mail\MailDomains::UNDO_SECONDS, 60)]) }}
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('disabling', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="disable" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('E-postayı kapat') }}</button>
            </div>
        </div>
    </x-modal>
</div>
