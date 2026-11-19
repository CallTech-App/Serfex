@php
    use App\Services\Monitor\IpBlocks;
    use App\Support\Load;
    use App\Support\Num;
    use App\Support\When;
    $btn = 'flex h-9 items-center gap-2 whitespace-nowrap rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40';
    $danger = 'flex h-9 items-center gap-2 whitespace-nowrap rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110 disabled:opacity-40';
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $select = 'h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px] outline-none focus:border-accent';
    $th = 'px-3 py-2.5 font-medium';
    $thead = 'border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase';
    $counts = ['sites' => $report !== null ? $sites->count() : null, 'blocks' => $history->count() ?: null];
    $chip = fn (int $s) => $s >= 500 ? 'bg-crit-soft text-crit' : ($s >= 400 ? 'bg-warn-soft text-warn' : 'bg-surface-2 text-muted');
    $isRange = str_contains($address, '/');
@endphp
<div class="space-y-6" wire:init="load">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('security.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Güvenlik') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('security.blocks') }}" wire:navigate class="hover:text-accent">{{ __('IP engelleri') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="font-mono break-all text-fg">{{ $address }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                <h1 class="min-w-0 font-mono text-[22px] font-semibold tracking-tight break-all">{{ $address }}</h1>
                <x-status-badge :status="$badge" />
                @if ($isMine)
                    <span class="rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted">{{ __('Şu anki bağlantınız') }}</span>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($block)
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="{{ $btn }}" aria-haspopup="true" :aria-expanded="open">{{ __('Süreyi değiştir') }}<x-icon name="chevron-down" class="size-3.5 text-muted" /></button>
                        <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full left-0 z-20 mt-1 w-44 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg sm:right-0 sm:left-auto">
                            @foreach (IpBlocks::DURATIONS as $m => $label)
                                <button type="button" wire:click="change({{ $m }})" @click="open = false" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @elseif (! $range && ! $allow)
                    <button type="button" wire:click="openBlock" class="{{ $btn }}"><x-icon name="ban" />{{ __('Engelle') }}</button>
                @endif
                @if (! $allow)
                    <button type="button" wire:click="allow" wire:loading.attr="disabled" class="{{ $btn }}"
                            wire:confirm="{{ __('Bu adres izinlilere eklensin mi? Engeli kalkar ve bir daha engellenmez.') }}">{{ __('İzinlilere ekle') }}</button>
                @elseif ($allow->address === $address)
                    <button type="button" wire:click="unallow" wire:loading.attr="disabled" class="{{ $btn }}">{{ __('İzinlilerden kaldır') }}</button>
                @endif
                @if ($block || $ban)
                    <button type="button" wire:click="unblock" wire:loading.attr="disabled" class="{{ $btn }}">{{ __('Engeli kaldır') }}</button>
                @endif
            </div>
        </div>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">
            @if ($block)
                {{ $block->reason
                    ? __(':by :when engelledi: :reason', ['by' => $block->byLabel(), 'when' => When::inSentence($block->created_at), 'reason' => $block->reason])
                    : __(':by :when engelledi.', ['by' => $block->byLabel(), 'when' => When::inSentence($block->created_at)]) }}
            @elseif ($range)
                <x-t text=":range aralığıyla engelli."><x-slot:range><a href="{{ route('security.blocks.address', $range->address) }}" wire:navigate class="font-mono text-fg hover:text-accent">{{ $range->address }}</a></x-slot:range></x-t>
            @elseif ($ban)
                {{ __('Fail2ban :when engelledi: :reason (:reach).', ['when' => When::inSentence($ban->created_at), 'reason' => $ban->reason ?: \App\Services\Security\Fail2ban::jailLabel($ban->kind), 'reach' => mb_strtolower($ban->reach())]) }}
            @elseif ($allow && $allow->address !== $address)
                <x-t text=":range aralığıyla izinli."><x-slot:range><span class="font-mono text-fg">{{ $allow->address }}</span></x-slot:range></x-t>
                <a href="{{ route('security.blocks', ['tab' => 'allowed']) }}" wire:navigate class="text-accent hover:underline">{{ __('İzinli adresler') }}</a>
            @elseif ($allow)
                {{ $allow->note ? __('Hiçbir zaman engellenmez: :note', ['note' => $allow->note]) : __('Hiçbir zaman engellenmez.') }}
            @else
                {{ __('Bu adresten gelen bağlantılar kabul ediliyor.') }}
            @endif
        </p>
    </div>

    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="min-w-0 flex-1 break-words">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach ($this::TABS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px flex h-12 items-center gap-2 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}
                    @isset($counts[$key])<span class="num rounded-full bg-surface-2 px-1.5 text-2xs leading-5 font-medium text-muted">{{ Num::format($counts[$key]) }}</span>@endisset
                </button>
            @endforeach
        </div>

        {{-- What is known about the address --}}
        <div class="grid grid-cols-2 gap-px overflow-hidden border-b border-line bg-line lg:grid-cols-4">
            <div class="min-w-0 bg-surface px-5 py-3">
                <p class="text-2xs text-muted">{{ __('Ülke') }}</p>
                <div class="mt-1 text-[13.5px]"><x-country :code="$country" /></div>
                <p class="mt-0.5 text-2xs text-muted">{{ __('Adresin kayıtlı olduğu ülke') }}</p>
            </div>
            <div class="min-w-0 bg-surface px-5 py-3">
                <p class="text-2xs text-muted">{{ __('Ters DNS') }}</p>
                <div class="mt-1 text-[13.5px]">
                    @if ($report && ($report['hostname'] ?? '') !== '')
                        <span class="block truncate font-mono text-[13px]" title="{{ $report['hostname'] }}">{{ $report['hostname'] }}</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
                <p class="mt-0.5 text-2xs text-muted">{{ $isRange ? __('Aralıklar için sorulmaz') : __('Adresin sahibinin verdiği ad') }}</p>
            </div>
            <div class="min-w-0 bg-surface px-5 py-3">
                <p class="text-2xs text-muted">{{ __('Sitelere gelen istekler') }}</p>
                <div class="mt-1 text-[13.5px]">
                    @if ($report)
                        <x-t text=":requests istek · :sites site"><x-slot:requests><span class="num">{{ Num::format((int) ($report['requests'] ?? 0)) }}</span></x-slot:requests><x-slot:sites><span class="num">{{ Num::format($sites->count()) }}</span></x-slot:sites></x-t>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
                <p class="mt-0.5 truncate text-2xs text-muted">
                    @if ($first && $last)
                        {{ __('İlk :first · son :last', ['first' => When::inSentence($first), 'last' => When::inSentence($last)]) }}
                    @else
                        {{ __('Erişim günlüklerinden') }}
                    @endif
                </p>
            </div>
            <div class="min-w-0 bg-surface px-5 py-3">
                <p class="text-2xs text-muted">{{ __('Giriş denemeleri') }}</p>
                <div class="mt-1 text-[13.5px]">
                    @if ($report)
                        <x-t text=":logins site girişi"><x-slot:logins><span class="num">{{ Num::format((int) ($report['logins'] ?? 0)) }}</span></x-slot:logins></x-t>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
                <p class="mt-0.5 text-2xs text-muted">{{ trans_choice('Fail2ban bu adresi :count kez engelledi', $bans, ['count' => Num::format($bans)]) }}</p>
            </div>
        </div>

        @if ($tab === 'blocks')
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[820px] text-[13.5px]">
                    <thead>
                        <tr class="{{ $thead }}">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Engelleyen') }}</th>
                            <th class="{{ $th }}">{{ __('Neden') }}</th>
                            <th class="{{ $th }}">{{ __('Süre') }}</th>
                            <th class="{{ $th }} pr-5">{{ __('Durum') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($history as $row)
                            <tr wire:key="history-{{ $row->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 whitespace-nowrap">{{ $row->byLabel() }}<span class="num block text-2xs text-muted">{{ When::withTime($row->created_at) }}</span></td>
                                <td class="px-3 py-3">{{ $row->reason ?: '—' }}<span @class(['block text-2xs text-muted', 'font-mono' => $row->source !== 'fail2ban' && $row->site !== null])>{{ $row->reach() }}</span></td>
                                <td class="num px-3 py-3 whitespace-nowrap">{{ $row->durationLabel() }}</td>
                                <td class="py-3 pr-5 pl-3 whitespace-nowrap">
                                    @if ($row->isActive())
                                        {{ __('Yürürlükte') }}<span class="num block text-2xs text-muted">{{ Load::remaining($row->expires_at) }}</span>
                                    @else
                                        {{ $row->endedLabel() }}<span class="num block text-2xs text-muted">{{ When::withTime($row->endedAt()) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-12 text-center text-muted">{{ __('Bu adres hiç engellenmemiş.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Bu adresin son 90 gündeki engelleri, en yenisi üstte.') }}</p>
        @elseif (! $ready)
            <p class="flex items-center justify-center gap-2 px-5 py-12 text-[13.5px] text-muted"><x-icon name="loader-circle" class="animate-spin" />{{ __('Erişim günlükleri okunuyor…') }}</p>
        @elseif ($failed !== null)
            <div class="px-5 py-12 text-center text-[13.5px]">
                <p class="font-medium">{{ __('Erişim günlükleri okunamadı') }}</p>
                <p class="mx-auto mt-1 max-w-xl text-[13px] break-words text-muted">{{ $failed }}</p>
                <button type="button" wire:click="reload" wire:loading.attr="disabled" class="{{ $btn }} mx-auto mt-3"><x-icon name="rotate-ccw" />{{ __('Yeniden dene') }}</button>
            </div>
        @elseif ($sites->isEmpty() && $recent->isEmpty())
            <div class="px-5 py-12 text-center text-[13.5px]">
                <p class="font-medium">{{ __('Bu adresten sitelere istek gelmemiş.') }}</p>
                <p class="mx-auto mt-1 max-w-xl text-[13px] text-muted">{{ $since ? __('Sitelerin erişim günlüklerinde bu adres yok. Günlükler :since gününden başlıyor; SSH, posta ve panel girişleri burada görünmez.', ['since' => When::dayMonth($since)]) : __('Sitelerin erişim günlüklerinde bu adres yok. SSH, posta ve panel girişleri burada görünmez.') }}</p>
            </div>
        @elseif ($tab === 'requests')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <select wire:model.live="site" aria-label="{{ __('Site') }}" class="{{ $select }} max-w-full">
                    <option value="">{{ __('Tüm siteler') }}</option>
                    @foreach ($recent->pluck('domain')->unique()->sort() as $domain)
                        <option value="{{ $domain }}">{{ $domain }}</option>
                    @endforeach
                </select>
                <select wire:model.live="status" aria-label="{{ __('Yanıt') }}" class="{{ $select }}">
                    <option value="">{{ __('Bütün yanıtlar') }}</option>
                    @foreach ($this::REPLIES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <label class="relative w-full sm:ml-auto sm:w-64">
                    <span class="sr-only">{{ __('İstenen adreste ara') }}</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="path" placeholder="{{ __('İstenen adreste ara') }}" spellcheck="false"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[900px] text-[13.5px]">
                    <thead>
                        <tr class="{{ $thead }}">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Zaman') }}</th>
                            <th class="{{ $th }}">{{ __('Site') }}</th>
                            <th class="{{ $th }}">{{ __('İstek') }}</th>
                            <th class="{{ $th }}">{{ __('Yanıt') }}</th>
                            <th class="{{ $th }} pr-5">{{ __('Tarayıcı') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($shown as $i => $r)
                            <tr wire:key="request-{{ $i }}" class="hover:bg-surface-2/60">
                                <td class="num py-2.5 pr-3 pl-5 whitespace-nowrap text-muted">{{ When::withTime($r['at']) }}</td>
                                <td class="px-3 py-2.5 font-mono text-[13px] whitespace-nowrap">{{ $r['domain'] }}</td>
                                <td class="max-w-80 px-3 py-2.5 font-mono text-[13px]"><span class="block truncate" title="{{ $r['path'] }}"><span class="text-muted">{{ $r['method'] }}</span> {{ $r['path'] }}</span></td>
                                <td class="px-3 py-2.5"><span class="num rounded px-1.5 py-0.5 font-mono text-2xs font-medium {{ $chip($r['status']) }}">{{ $r['status'] }}</span></td>
                                <td class="max-w-72 py-2.5 pr-5 pl-3 text-[13px] text-muted"><span class="block truncate" title="{{ $r['agent'] }}">{{ $r['agent'] !== '' ? $r['agent'] : '—' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-12 text-center text-muted">{{ __('Süzgece uyan istek yok.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-2xs text-muted">
                <p class="min-w-0 flex-1 basis-full sm:basis-0">{{ trans_choice('Bu adresin son :count isteği, en yenisi üstte. Tamamı sitelerin kendi günlük sayfasında.', $recent->count(), ['count' => Num::format($recent->count())]) }}
                    @if ($report['truncated'] ?? false) {{ __('Günlükler çok büyük olduğu için tamamı okunamadı; sayılar eksik olabilir.') }} @endif</p>
                <button type="button" wire:click="reload" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 text-[13px] hover:text-fg"><x-icon name="rotate-ccw" class="size-3.5" />{{ __('Yenile') }}</button>
            </div>
        @else
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[940px] text-[13.5px]">
                    <thead>
                        <tr class="{{ $thead }}">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Site') }}</th>
                            <th class="{{ $th }} text-right">{{ __('İstek') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Reddedilen') }}</th>
                            <th class="{{ $th }}">{{ __('En çok istenen adres') }}</th>
                            <th class="{{ $th }}">{{ __('Yanıtlar') }}</th>
                            <th class="{{ $th }}">{{ __('Son istek') }}</th>
                            <th class="w-32"><span class="sr-only">{{ __('İşlemler') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($sites as $s)
                            <tr wire:key="site-{{ $s['domain'] }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">
                                    @if (isset($known[$s['domain']]))
                                        <a href="{{ route('sites.show', $known[$s['domain']]) }}" wire:navigate class="hover:text-accent">{{ $s['domain'] }}</a>
                                    @else
                                        {{ $s['domain'] }}
                                    @endif
                                </td>
                                <td class="num px-3 py-3 text-right">{{ Num::format($s['requests']) }}</td>
                                <td class="num px-3 py-3 text-right">{{ Num::format($s['denied']) }}</td>
                                <td class="max-w-72 px-3 py-3"><span class="flex items-baseline gap-2"><span class="truncate font-mono text-[13px]" title="{{ $s['top_path'] }}">{{ $s['top_path'] !== '' ? $s['top_path'] : '—' }}</span><span class="num shrink-0 text-2xs text-muted">{{ trans_choice(':count istek', $s['top_count'], ['count' => Num::format($s['top_count'])]) }}</span></span></td>
                                <td class="px-3 py-3">
                                    <span class="num inline-flex flex-wrap gap-x-3 gap-y-0.5 text-[13px]">
                                        @foreach ($s['statuses'] as $code => $count)
                                            <span><span class="font-mono text-2xs text-muted">{{ $code }}</span> {{ Num::format($count) }}</span>
                                        @endforeach
                                    </span>
                                </td>
                                <td class="num px-3 py-3 whitespace-nowrap text-muted">{{ When::withTime($s['last']) }}</td>
                                <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="showRequests(@js($s['domain']))" class="text-[13px] text-muted hover:text-fg">{{ __('İstekleri gör') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-2xs text-muted">
                <p class="min-w-0 flex-1 basis-full sm:basis-0">{{ $since ? __('Sitelerin erişim günlüklerinden, :since gününden bu yana.', ['since' => When::dayMonth($since)]) : __('Sitelerin erişim günlüklerinden.') }}
                    {{ __('Cloudflare vekili arkasındaki sitelerde ziyaretçinin gerçek adresi sayılır. Reddedilen: engel, WAF ya da site kuralı yüzünden 403 alan istekler.') }}
                    @if ($report['truncated'] ?? false) {{ __('Günlükler çok büyük olduğu için tamamı okunamadı; sayılar eksik olabilir.') }} @endif</p>
                <button type="button" wire:click="reload" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 text-[13px] hover:text-fg"><x-icon name="rotate-ccw" class="size-3.5" />{{ __('Yenile') }}</button>
            </div>
        @endif
    </section>

    {{-- Engelle --}}
    <x-modal :title="__('Adresi engelle')" show="blocking" size="max-w-xl">
        <form wire:submit="saveBlock">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold"><x-t text=":address adresini engelle"><x-slot:address><span class="mr-1.5 font-mono">{{ $address }}</span></x-slot:address></x-t></h2>
            <div class="grid gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[110px_1fr]">
                <span id="block-duration" class="sm:pt-2">{{ __('Süre') }}</span>
                <div>
                    <div class="inline-flex flex-wrap divide-x divide-line overflow-hidden rounded-md border border-line text-[13.5px]" role="radiogroup" aria-labelledby="block-duration">
                        @foreach (IpBlocks::DURATIONS as $m => $label)
                            <button type="button" role="radio" aria-checked="{{ $minutes === $m ? 'true' : 'false' }}" wire:click="$set('minutes', {{ $m }})"
                                    @class(['flex h-9 items-center px-3.5', 'bg-accent-soft font-medium text-accent' => $minutes === $m, 'hover:bg-surface-2' => $minutes !== $m])>{{ $label }}</button>
                        @endforeach
                    </div>
                    @error('minutes') <p class="mt-1.5 text-[13px] break-words text-crit" role="alert">{{ $message }}</p> @enderror
                </div>
                <label for="block-reason" class="sm:pt-2">{{ __('Neden') }}</label>
                <div>
                    <input id="block-reason" wire:model="reason" maxlength="200" class="{{ $field }} {{ $errors->has('reason') ? 'border-crit' : 'border-line' }}">
                    @error('reason') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line px-5 py-3">
                <span class="mr-auto min-w-0 basis-full text-2xs text-muted sm:flex-1 sm:basis-0">{{ __('Adresten gelen bütün bağlantılar (web, posta, SSH) reddedilir.') }}</span>
                <button type="button" wire:click="$set('blocking', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="saveBlock" class="{{ $danger }}"><x-icon name="ban" />{{ __('Engelle') }}</button>
            </div>
        </form>
    </x-modal>
</div>
