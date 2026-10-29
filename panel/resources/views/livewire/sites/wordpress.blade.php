@php
    use App\Support\When;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $dl = 'grid gap-1 py-3 sm:grid-cols-[11rem_1fr] sm:gap-4';
    $link = 'text-[13px] whitespace-nowrap text-accent hover:underline disabled:opacity-40';
    $active = $wp?->isActive();
    $hardening = (array) ($state['hardening'] ?? []);
    $pluginUpdates = collect($plugins)->filter(fn ($i) => ($i['update'] ?? '') !== '')->count();
    $themeUpdates = collect($themes)->filter(fn ($i) => ($i['update'] ?? '') !== '')->count();
    $recommendedOff = collect(\App\Services\Sites\WordPressSites::RECOMMENDED)->contains(fn ($r) => ! ($hardening[$r] ?? false));
    $statusLabels = ['active' => __('Etkin'), 'inactive' => __('Devre dışı'), 'must-use' => __('Her zaman açık'), 'dropin' => __('Drop-in'), 'parent' => __('Üst tema'), 'active-network' => __('Ağda etkin')];
    $kindLabels = ['core' => 'WordPress', 'plugin' => __('eklenti'), 'theme' => __('tema')];
    // Titles come from plugin headers as HTML; shown as plain text.
    $plain = fn (array $item) => html_entity_decode(strip_tags($item['title'] ?: $item['name']), ENT_QUOTES | ENT_HTML5);
    $titles = collect($plugins)->merge($themes)->mapWithKeys(fn ($i) => [$i['name'] => $plain($i)]);
@endphp
<div class="space-y-6" @if (! $loaded) wire:init="loadStatus" @endif>
    @if ($busy || $wp?->status === \App\Models\WordPressInstall::INSTALLING)
        <div wire:poll.3s hidden></div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
                <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <span class="text-fg">WordPress</span>
            </nav>
            <h1 class="mt-2 text-[22px] font-semibold tracking-tight">WordPress</h1>
            <p class="mt-1 text-[13px] text-muted">{{ __('Sitenin WordPress\'ini kurun, güncel tutun ve koruyun. Her güncellemeden önce geri dönüş noktası alınır.') }}</p>
        </div>
        @if ($active)
            <div class="flex items-center gap-2">
                @unless (auth()->user()->isReadOnly())
                    <form method="POST" action="{{ route('sites.wordpress.login', $site) }}" target="_blank">
                        @csrf
                        <button type="submit" class="{{ $primary }}"><x-icon name="log-in" />{{ __('WordPress\'e giriş yap') }}</button>
                    </form>
                @endunless
                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="grid size-9 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Diğer işlemler') }}"><x-icon name="ellipsis-vertical" /></button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full right-0 z-20 mt-1 w-56 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                        <button type="button" wire:click="refreshStatus" @click="open = false" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Şimdi denetle') }}</button>
                        <button type="button" wire:click="$set('detaching', true)" @click="open = false" @disabled($busy) class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2 disabled:opacity-40">{{ __('Yönetimden çıkar') }}</button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($agentError)
        <div class="relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-warn"></span>
            <x-icon name="triangle-alert" class="text-warn" />
            <p class="min-w-0 flex-1">{{ __('WordPress okunamadı: :reason', ['reason' => $agentError]) }}</p>
        </div>
    @endif

    @if ($wp === null || $wp->status === \App\Models\WordPressInstall::FAILED)
        {{-- Not managed yet --}}
        @if ($installOp)
            <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
                <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
                <x-icon name="triangle-alert" class="text-crit" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ __('WordPress kurulamadı. Kurulan dosyalar kaldırıldı ve veritabanı silindi.') }}</p>
                    <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $installOp->error }}</p>
                </div>
                <button type="button" wire:click="showLog({{ $installOp->id }})" class="{{ $btn }} h-8 text-[13px]">{{ __('Günlük') }}</button>
            </div>
        @endif
        <section class="rounded-xl border border-line bg-surface">
            <div class="p-5 text-[13.5px]">
                <p class="font-medium">{{ __('Bu site Serfex\'ten yönetilen bir WordPress\'e sahip değil.') }}</p>
                @if ($found === null)
                    <p class="mt-1 flex items-center gap-2 text-muted"><x-icon name="loader-circle" class="size-3.5 animate-spin" />{{ __('Sitede WordPress aranıyor…') }}</p>
                @elseif ($found !== [])
                    <p class="mt-1 text-muted">{{ __('Sitede WordPress bulundu. Yönetmeye başladığınızda dosyalarına dokunulmaz.') }}</p>
                    <ul class="mt-3 divide-y divide-line rounded-lg border border-line">
                        @foreach ($found as $i => $f)
                            <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                                <x-icon name="blocks" class="text-muted" />
                                <span class="font-mono">{{ $f['path'] }}</span>
                                <span class="text-muted">WordPress {{ $f['version'] ?: '?' }}</span>
                                <button type="button" wire:click="adopt({{ $i }})" class="{{ $btn }} ml-auto h-8 text-[13px]">{{ __('Yönet') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-1 text-muted"><x-t text="Sitenin web kökünde (:path) WordPress bulunamadı. Tek tıkla kurabilirsiniz: WordPress, veritabanı ve yönetici hesabı birlikte hazırlanır."><x-slot:path><span class="font-mono text-fg">{{ $site->web_root ?? 'public_html' }}</span></x-slot:path></x-t></p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-3">
                <button type="button" wire:click="openInstall" @disabled($refusal !== null) class="{{ $primary }}"><x-icon name="download" />{{ __('WordPress\'i kur') }}</button>
                @if ($refusal)
                    <span class="text-[13px] text-muted">{{ $refusal }}</span>
                @endif
            </div>
        </section>
    @elseif ($wp->status === \App\Models\WordPressInstall::INSTALLING)
        <section class="rounded-xl border border-line bg-surface">
            <h2 class="flex h-12 items-center gap-3 border-b border-line px-5 text-[14.5px] font-semibold">
                {{ __('WordPress kuruluyor') }}
                @if ($installOp)<x-status-badge :status="$installOp->status" />@endif
            </h2>
            <div class="p-5">
                @if ($installOp)
                    <x-run-log :run="$installOp->run" class="h-80" />
                @endif
            </div>
        </section>
    @else
        @if ($wp->problem)
            <div class="relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
                <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
                <x-icon name="triangle-alert" class="text-crit" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ __('WordPress bir eklenti ya da tema yüzünden açılamıyor.') }}</p>
                    <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $wp->problem }}</p>
                    <p class="mt-1 text-[13px] text-muted">{{ __('Sorunlu eklentiyi Eklentiler sekmesinden devre dışı bırakabilirsiniz.') }}</p>
                </div>
            </div>
        @endif

        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <dl class="divide-y divide-line px-5 py-1 text-[13.5px]">
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Sürüm') }}</dt>
                    <dd class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-medium">WordPress {{ $wp->version ?? '?' }}</span>
                        @if ($wp->core_update)
                            <span class="rounded-full bg-warn-soft px-2 py-0.5 text-2xs font-medium text-warn">{{ __(':version çıktı', ['version' => $wp->core_update]) }}</span>
                            <button type="button" wire:click="confirmUpdate('core')" @disabled($busy) class="{{ $link }}">{{ __('Güncelle') }}</button>
                        @elseif ($wp->checked_at)
                            <span class="text-[13px] text-muted">{{ __('Güncel') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Adres ve klasör') }}</dt>
                    <dd class="min-w-0">
                        @if ($state['home_url'] ?? null)
                            <a href="{{ $state['home_url'] }}" target="_blank" rel="noopener" class="font-mono break-all text-accent hover:underline">{{ $state['home_url'] }}</a> ·
                        @endif
                        <span class="font-mono text-[13px] text-muted">{{ $wp->path }}</span>
                    </dd>
                </div>
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Veritabanı ve yönetici') }}</dt>
                    <dd class="min-w-0">
                        <span class="font-mono">{{ ($state['database'] ?? '') ?: '—' }}</span>
                        @if ($state['admins'] ?? [])
                            <span class="text-muted">·</span> <span class="font-mono">{{ implode(', ', $state['admins']) }}</span>
                        @endif
                    </dd>
                </div>
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Bakım modu') }}</dt>
                    <dd>
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" @checked($wp->maintenance) wire:change="setMaintenance($event.target.checked)" @disabled($busy) class="size-4 accent-[rgb(var(--accent))]">
                            {{ __('Ziyaretçiler "kısa süreli bakım" sayfasını görür') }}
                        </label>
                    </dd>
                </div>
                <div class="{{ $dl }}">
                    <dt class="text-muted"><label for="wp-auto">{{ __('Otomatik güncelleme') }}</label></dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        <select id="wp-auto" wire:change="setAutoUpdate($event.target.value)" @disabled($busy) class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px] outline-none focus:border-accent">
                            @foreach (\App\Livewire\Sites\SiteWordPress::autoUpdateLabels() as $value => $label)
                                <option value="{{ $value }}" @selected(($state['auto_update_core'] ?? 'minor') === $value)>{{ $label }}{{ $value === 'minor' ? ' ('.__('önerilen').')' : '' }}</option>
                            @endforeach
                        </select>
                        <span class="text-2xs text-muted">{{ __('WordPress\'in kendisi için; eklentiler satırlarından.') }}</span>
                    </dd>
                </div>
                @if ($mailHost)
                    <div class="{{ $dl }}">
                        <dt class="text-muted">{{ __('E-posta') }}</dt>
                        <dd class="min-w-0">
                            @if ($smtpPlugin)
                                {{ __('SMTP eklentisiyle: :plugin', ['plugin' => html_entity_decode(strip_tags($smtpPlugin), ENT_QUOTES | ENT_HTML5)]) }}
                            @else
                                <span class="flex items-start gap-2 text-warn"><x-icon name="triangle-alert" class="mt-0.5 size-3.5 shrink-0" />{{ __('Gönderemiyor: WordPress PHP mail() kullanıyor, bu sunucuda kapalı.') }}</span>
                                <p class="mt-1 text-2xs text-muted"><x-t text="Bir SMTP eklentisi kurun (WP Mail SMTP, FluentSMTP) ve bir posta kutusuyla giriş yapın: sunucu :host, port :port (STARTTLS), kullanıcı adı posta kutusunun adresi."><x-slot:host><span class="font-mono">{{ $mailHost }}</span></x-slot:host><x-slot:port><span class="font-mono">587</span></x-slot:port></x-t></p>
                                <a href="{{ $site->mailDomain ? route('mail.show', $site->mailDomain) : route('mail.index') }}" wire:navigate class="mt-1 inline-flex items-center gap-1.5 text-[13px] text-accent hover:underline"><x-icon name="mail" class="size-3.5" />{{ __('Posta kutuları') }}</a>
                            @endif
                        </dd>
                    </div>
                @endif
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Son denetim') }}</dt>
                    <dd class="text-muted">
                        @if (! $loaded)
                            <span class="flex items-center gap-2"><x-icon name="loader-circle" class="size-3.5 animate-spin" />{{ __('Denetleniyor…') }}</span>
                        @else
                            {{ $wp->checked_at ? When::withTime($wp->checked_at) : __('Henüz yok') }}
                        @endif
                    </dd>
                </div>
            </dl>

            {{-- Tabs --}}
            <div class="flex items-center gap-1 overflow-x-auto border-t border-b border-line px-3 text-[13.5px]" role="tablist">
                @foreach (['plugins' => __('Eklentiler (:count)', ['count' => count($plugins)]), 'themes' => __('Temalar (:count)', ['count' => count($themes)]), 'security' => __('Güvenlik'), 'history' => __('Geçmiş')] as $key => $label)
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" @class(['-mb-px h-11 border-b-2 px-3 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>{{ $label }}</button>
                @endforeach
                @if ($wp->update_count > 0)
                    <button type="button" wire:click="confirmUpdate('all')" @disabled($busy) class="{{ $primary }} my-1.5 ml-auto h-8 shrink-0 text-[13px]"><x-icon name="refresh-cw" class="size-3.5" />{{ trans_choice('Tümünü güncelle (:count)', $wp->update_count) }}</button>
                @endif
            </div>

            @if ($tab === 'plugins' || $tab === 'themes')
                @php $kind = $tab === 'plugins' ? 'plugin' : 'theme'; $items = $tab === 'plugins' ? $plugins : $themes; @endphp
                <div class="relative overflow-x-auto">
                    <table class="w-full text-[13.5px] sm:min-w-[640px]">
                        <thead>
                            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">{{ $kind === 'plugin' ? __('Eklenti adı') : __('Tema adı') }}</th>
                                <th class="px-3 py-2.5 font-medium">{{ __('Sürüm') }}</th>
                                <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Durum') }}</th>
                                <th class="px-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($items as $item)
                                <tr wire:key="{{ $kind }}-{{ $item['name'] }}" class="hover:bg-surface-2/60">
                                    <td class="max-w-0 py-3 pr-3 pl-5 sm:w-1/2">
                                        <span class="block truncate font-medium">{{ $plain($item) }}</span>
                                        <span class="block truncate font-mono text-2xs text-muted">{{ $item['name'] }}</span>
                                    </td>
                                    <td class="px-3 py-3 whitespace-nowrap">
                                        <span class="num">{{ $item['version'] ?: '—' }}</span>
                                        @if ($item['update'] ?? '')
                                            <span class="ml-1 rounded-full bg-warn-soft px-2 py-0.5 text-2xs font-medium text-warn">→ {{ $item['update'] }}</span>
                                        @endif
                                    </td>
                                    <td class="hidden px-3 py-3 whitespace-nowrap sm:table-cell">
                                        <span @class(['text-ok' => in_array($item['status'], ['active', 'active-network', 'must-use'], true), 'text-muted' => ! in_array($item['status'], ['active', 'active-network', 'must-use'], true)])>{{ $statusLabels[$item['status']] ?? $item['status'] }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap">
                                        <div class="flex flex-col items-end gap-1.5 sm:block sm:space-x-3">
                                            @if ($item['update'] ?? '')
                                                <button type="button" wire:click="confirmUpdate('{{ $kind }}', @js($item['name']))" @disabled($busy) class="{{ $link }}">{{ __('Güncelle') }}</button>
                                            @endif
                                            @if ($kind === 'plugin' && $item['status'] === 'active')
                                                <button type="button" wire:click="switchItem('plugin', @js($item['name']), 'deactivate')" @disabled($busy) class="{{ $link }}">{{ __('Devre dışı bırak') }}</button>
                                            @elseif ($item['status'] === 'inactive')
                                                <button type="button" wire:click="switchItem('{{ $kind }}', @js($item['name']), 'activate')" @disabled($busy) class="{{ $link }}">{{ __('Etkinleştir') }}</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-muted">{{ $loaded || $wp->checked_at ? ($kind === 'plugin' ? __('Eklenti yok.') : __('Tema yok.')) : __('Denetleniyor…') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($kind === 'plugin')
                    <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Eklenti eklemek ve ayarlamak için WordPress\'e giriş yapın. Bir eklenti siteyi bozarsa buradan devre dışı bırakabilirsiniz.') }}</p>
                @endif
            @elseif ($tab === 'security')
                <ul class="divide-y divide-line text-[13.5px]">
                    @foreach ($rules as $rule => [$name, $description])
                        @php $on = (bool) ($hardening[$rule] ?? false); $web = in_array($rule, \App\Services\Sites\WordPressSites::NGINX_RULES, true); @endphp
                        <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <x-icon :name="$on ? 'shield-check' : 'shield'" @class(['shrink-0', 'text-ok' => $on, 'text-muted' => ! $on]) />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ $name }} @if (in_array($rule, \App\Services\Sites\WordPressSites::RECOMMENDED, true))<span class="ml-1 text-2xs font-normal text-muted">{{ __('önerilen') }}</span>@endif</p>
                                <p class="text-[13px] text-muted">{{ $description }}</p>
                            </div>
                            @if ($web && $wp->webPath() === null)
                                <span class="text-2xs text-muted">{{ __('Klasör web kökünün dışında') }}</span>
                            @elseif ($on)
                                <span class="text-[13px] text-ok">{{ __('Açık') }}</span>
                                <button type="button" wire:click="setRule('{{ $rule }}', false)" @disabled($busy) class="{{ $link }}">{{ __('Kapat') }}</button>
                            @else
                                <button type="button" wire:click="setRule('{{ $rule }}', true)" @disabled($busy) class="{{ $btn }} h-8 text-[13px]">{{ __('Aç') }}</button>
                            @endif
                        </li>
                    @endforeach
                    @if (in_array('admin', $state['admins'] ?? [], true))
                        <li class="flex items-center gap-3 px-5 py-3">
                            <x-icon name="triangle-alert" class="shrink-0 text-warn" />
                            <p class="min-w-0 flex-1 text-[13px]"><x-t text="Bir yönetici hesabının adı :name: parola deneme saldırıları ilk bu adı dener. WordPress'te yeni bir yönetici açıp bunu silmeniz önerilir."><x-slot:name><span class="font-mono">admin</span></x-slot:name></x-t></p>
                        </li>
                    @endif
                </ul>
                @if ($recommendedOff)
                    <div class="border-t border-line px-5 py-3">
                        <button type="button" wire:click="hardenRecommended" @disabled($busy) class="{{ $primary }}"><x-icon name="shield-check" />{{ __('Önerilenleri uygula') }}</button>
                    </div>
                @endif
            @else
                <div class="relative overflow-x-auto">
                    <table class="w-full text-[13.5px] sm:min-w-[640px]">
                        <thead>
                            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('İşlem') }}</th>
                                <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Kim') }}</th>
                                <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Durum') }}</th>
                                <th class="px-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($operations as $op)
                                <tr wire:key="op-{{ $op->id }}" class="hover:bg-surface-2/60">
                                    <td class="max-w-0 py-3 pr-3 pl-5 sm:w-[55%]">
                                        <span class="block truncate font-medium">{{ match ($op->kind) { 'install' => __('Kurulum'), 'restore' => __('Güncelleme öncesine dönüş'), default => __('Güncelleme') } }}</span>
                                        @if ($op->changes)
                                            <span class="block truncate text-[13px] text-muted">{{ collect($op->changes)->map(fn ($c) => ($c['kind'] === 'core' ? 'WordPress' : ($titles[$c['name']] ?? $c['name'])).' '.$c['from'].' → '.$c['to'])->join(', ') }}</span>
                                        @elseif ($op->kind === 'update' && $op->status === \App\Enums\WordPressOperationStatus::Succeeded && $op->point === null)
                                            <span class="block text-[13px] text-muted">{{ __('Güncellenecek bir şey yoktu') }}</span>
                                        @endif
                                        <span class="block text-2xs text-muted">{{ When::withTime($op->started_at ?? $op->created_at) }}@if ($op->restored_at) · {{ __('geri alındı') }}@endif</span>
                                        <x-status-badge :status="$op->status" class="mt-1 sm:hidden" />
                                    </td>
                                    <td class="hidden px-3 py-3 whitespace-nowrap md:table-cell">{{ $op->triggered_by ?? 'Serfex' }}</td>
                                    <td class="hidden px-3 py-3 sm:table-cell">
                                        <x-status-badge :status="$op->status" />
                                        @if ($op->error)<p class="mt-1 max-w-xs truncate text-2xs text-muted" title="{{ $op->error }}">{{ $op->error }}</p>@endif
                                    </td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap">
                                        <div class="flex flex-col items-end gap-1.5 sm:block sm:space-x-3">
                                            <button type="button" wire:click="showLog({{ $op->id }})" class="{{ $link }}">{{ __('Günlük') }}</button>
                                            @if (! $busy && $restorable?->id === $op->id)
                                                <button type="button" wire:click="confirmRestore({{ $op->id }})" class="{{ $link }}">{{ __('Geri al') }}</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-10 text-center text-muted">{{ __('Henüz işlem yok.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Son güncellemenin geri dönüş noktası :days gün saklanır; "Geri al" güncellenen dosyaları ve veritabanını güncelleme öncesine döndürür.', ['days' => \App\Models\WordPressOperation::RESTORE_DAYS]) }}</p>
            @endif
        </section>
    @endif

    {{-- WordPress'i kur --}}
    <x-modal :title="__('WordPress\'i kur')" show="installing" size="max-w-xl">
        <form wire:submit="install">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('WordPress\'i kur') }}</h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                @foreach ([['title', __('Site adı'), 'text', ''], ['adminUser', __('Yönetici kullanıcı adı'), 'text', 'font-mono'], ['adminEmail', __('Yönetici e-postası'), 'email', '']] as [$field, $label, $type, $class])
                    <div class="grid gap-1 sm:grid-cols-[10rem_1fr] sm:items-center sm:gap-3">
                        <label for="wp-{{ $field }}" class="text-muted">{{ $label }}</label>
                        <input id="wp-{{ $field }}" type="{{ $type }}" wire:model="{{ $field }}" autocomplete="off" class="h-9 w-full rounded-md border border-line bg-surface px-3 outline-none focus:border-accent {{ $class }}">
                        @error($field) <p class="text-[13px] text-crit sm:col-start-2">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <div class="grid gap-1 sm:grid-cols-[10rem_1fr] sm:gap-3" x-data="{ copied: false }">
                    <label for="wp-password" class="text-muted sm:pt-2">{{ __('Yönetici parolası') }}</label>
                    <div>
                        <div class="flex gap-2">
                            <input id="wp-password" type="text" wire:model="adminPassword" autocomplete="new-password" spellcheck="false" class="h-9 min-w-0 flex-1 rounded-md border border-line bg-surface px-3 font-mono outline-none focus:border-accent">
                            <button type="button" @click="navigator.clipboard.writeText($wire.adminPassword); copied = true; setTimeout(() => copied = false, 2000)" class="h-9 shrink-0 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span></button>
                            <button type="button" wire:click="newPassword" class="grid size-9 shrink-0 place-items-center rounded-md border border-line hover:bg-surface-2" aria-label="{{ __('Yeni parola') }}"><x-icon name="refresh-cw" class="size-3.5" /></button>
                        </div>
                        <p class="mt-1 text-2xs text-muted">{{ __('Serfex bu parolayı saklamaz; şimdi kopyalayın. Panelden "WordPress\'e giriş yap" ile parolasız da girebilirsiniz.') }}</p>
                        @error('adminPassword') <p class="mt-1 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid gap-1 sm:grid-cols-[10rem_1fr] sm:items-center sm:gap-3">
                    <label for="wp-locale" class="text-muted">{{ __('Dil') }}</label>
                    <select id="wp-locale" wire:model="locale" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 outline-none focus:border-accent sm:w-56">
                        <option value="tr_TR">Türkçe</option>
                        <option value="en_US">English</option>
                    </select>
                </div>
                <div class="grid gap-1 sm:grid-cols-[10rem_1fr] sm:gap-3">
                    <span class="text-muted">{{ __('Kurulacak yer') }}</span>
                    <p><span class="font-mono">{{ $site->web_root ?? 'public_html' }}</span> · <x-t text="yeni MySQL veritabanı :name"><x-slot:name><span class="font-mono">{{ $databaseName }}</span></x-slot:name></x-t></p>
                </div>
                <label class="flex items-start gap-2 pt-1 sm:pl-[10.75rem]">
                    <input type="checkbox" wire:model="harden" class="mt-0.5 size-4 shrink-0 accent-[rgb(var(--accent))]">
                    <span>{{ __('Önerilen güvenlik ayarlarını uygula') }}<span class="block text-[12.5px] text-muted">{{ __('Dosya düzenleyiciyi kapatır, wp-config.php\'yi korur, yüklemeler klasöründe PHP çalıştırmayı ve sürüm bilgisi veren dosyaları engeller.') }}</span></span>
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('installing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="download" />{{ __('Kur') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Güncelle --}}
    @if ($pending && $wp)
        <div x-data @keydown.escape.window="$wire.set('pending', null)" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-xl rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Güncelle') }}">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Güncellensin mi?') }}</h2>
                <div class="space-y-3 p-5 text-[13.5px]">
                    <ul class="max-h-56 divide-y divide-line overflow-y-auto rounded-lg border border-line">
                        @if ($pending['core'])
                            <li class="flex items-center gap-3 px-3 py-2"><span class="min-w-0 flex-1 truncate font-medium">WordPress</span><span class="num text-muted">{{ $wp->version }} → {{ $wp->core_update }}</span></li>
                        @endif
                        @foreach ([['plugins', $plugins], ['themes', $themes]] as [$key, $list])
                            @foreach (collect($list)->whereIn('name', $pending[$key]) as $item)
                                <li class="flex items-center gap-3 px-3 py-2"><span class="min-w-0 flex-1 truncate">{{ $plain($item) }} <span class="text-2xs text-muted">{{ $key === 'plugins' ? __('eklenti') : __('tema') }}</span></span><span class="num text-muted">{{ $item['version'] }} → {{ $item['update'] }}</span></li>
                            @endforeach
                        @endforeach
                    </ul>
                    <p class="text-muted">{{ __('Önce bir geri dönüş noktası alınır: veritabanı ve güncellenecek dosyalar. Site güncellemeden sonra hata verirse kendiliğinden eski hâline döner; :days gün içinde Geçmiş\'ten elle de geri alabilirsiniz.', ['days' => \App\Models\WordPressOperation::RESTORE_DAYS]) }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('pending', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="update" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="refresh-cw" class="size-3.5" />{{ __('Güncelle') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- İşlem günlüğü --}}
    @if ($log)
        <div x-data @keydown.escape.window="$wire.closeLog()" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-3xl min-w-0 rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('İşlem günlüğü') }}">
                <h2 class="flex h-12 items-center gap-3 border-b border-line px-5 text-[14.5px] font-semibold">
                    {{ match ($log->kind) { 'install' => __('WordPress kurulumu'), 'restore' => __('Güncelleme öncesine dönüş'), default => __('WordPress güncellemesi') } }}
                    <x-status-badge :status="$log->status" />
                </h2>
                <div class="p-5">
                    <x-run-log :run="$log->run" class="h-96" />
                    @if ($log->status === \App\Enums\WordPressOperationStatus::RolledBack)
                        <p class="mt-3 text-[13px] text-warn">{{ __('Site güncellemeden sonra hata verdiği için güncelleme geri alındı; site önceki hâliyle çalışıyor.') }}</p>
                    @endif
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="closeLog" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Geri al --}}
    @if ($restoreOp)
        <div x-data @keydown.escape.window="$wire.set('restoreId', null)" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-xl rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Geri al') }}">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Güncelleme öncesine dönülsün mü?') }}</h2>
                <div class="space-y-2 p-5 text-[13.5px] text-muted">
                    <p>{{ __(':time güncellemesinde değişen dosyalar ve veritabanı, güncelleme anındaki hâline döner.', ['time' => When::withTime($restoreOp->started_at ?? $restoreOp->created_at)]) }}</p>
                    <p class="rounded-md bg-warn-soft px-3 py-2 text-warn">{{ __('Güncellemeden sonra sitede yapılan değişiklikler (yeni yazılar, yorumlar, siparişler) kaybolur.') }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('restoreId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="restore" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Geri al') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Yönetimden çıkar --}}
    @if ($active)
        <x-modal :title="__('Yönetimden çıkar')" show="detaching" size="max-w-xl">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('WordPress yönetimden çıkarılsın mı?') }}</h2>
            <p class="p-5 text-[13.5px] text-muted"><x-t text="WordPress, dosyaları ve veritabanı olduğu gibi kalır (:path). Serfex'in giriş eklentisi, Nginx güvenlik kuralları ve geri dönüş noktaları kaldırılır; wp-config.php ayarları kalır." :with="['path' => $wp->path]" /></p>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('detaching', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="detach" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Yönetimden çıkar') }}</button>
            </div>
        </x-modal>
    @endif
</div>
