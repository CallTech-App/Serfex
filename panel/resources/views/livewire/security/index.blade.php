@php
    use App\Models\SecurityFinding;
    use App\Services\Monitor\IpBlocks;
    use App\Services\Security\Fail2ban;
    use App\Services\Security\Firewall;
    use App\Services\Security\Ssh;
    use App\Services\Security\TwoFactor;
    use App\Services\Security\Waf;
    use App\Support\Badge;
    $tz = config('serfex.timezone');
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $link = 'text-[13px] text-accent hover:underline whitespace-nowrap';
    $when = fn ($t) => \App\Support\When::withTime($t);
    $row = 'grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6';
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Güvenlik') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Sunucunun hangi portlarda yanıt verdiği, web saldırılarına karşı güvenlik duvarı, sitelerdeki zararlı dosyaların taraması ve panele, SSH\'a nasıl girildiği.') }}</p>
    </div>

    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="flex-1">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach ($this::TABS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Güvenlik duvarı --}}
        @if ($tab === 'firewall')
            <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-[13.5px]">
                @if ($firewall['enabled'])
                    <x-status-badge :status="$active ? new Badge(__('Açık'), 'ok') : new Badge(__('Açık, uygulanmayı bekliyor'), 'warn')" />
                    <span class="text-muted">{{ __('Yalnızca aşağıda izin verilen portlar yanıt veriyor.') }}</span>
                    <button type="button" wire:click="setFirewall(false)" class="{{ $btn }} ml-auto">{{ __('Kapat') }}</button>
                @else
                    <x-status-badge :status="new Badge(__('Kapalı'), 'muted')" />
                    <span class="text-muted">{{ __('Bütün portlar açık. Açtığınızda yalnızca izin verilen portlar yanıt verir.') }}</span>
                    <button type="button" wire:click="$set('confirmingFirewall', true)" class="{{ $primary }} ml-auto"><x-icon name="shield" />{{ __('Aç') }}</button>
                @endif
                <button type="button" wire:click="openRule(null)" class="{{ $btn }}"><x-icon name="plus" />{{ __('Kural ekle') }}</button>
            </div>
            @if ($agentError)
                <p class="border-b border-line bg-warn-soft px-5 py-2.5 text-[13px]">{{ $agentError }}</p>
            @endif
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[720px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Kural') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Portlar') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Kimden') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('İşlem') }}</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($services as $s)
                            <tr wire:key="svc-{{ $s['id'] }}" @class(['hover:bg-surface-2/60', 'text-muted' => ! $s['enabled']])>
                                <td class="py-3 pr-3 pl-5">
                                    <span class="font-medium">{{ $s['name'] }}</span>
                                    <span class="mt-0.5 block text-[12.5px] text-muted">{{ $s['help'] }}</span>
                                </td>
                                <td class="px-3 py-3 font-mono text-[13px]">{{ $s['ports'] }} <span class="text-muted">{{ $s['protocol'] === 'both' ? 'tcp/udp' : $s['protocol'] }}</span></td>
                                <td class="hidden px-3 py-3 md:table-cell">{{ $s['id'] === 'panel' ? __('Herkes; sizin adresiniz hiç kapanmaz') : __('Herkes') }}</td>
                                <td class="px-3 py-3">
                                    @if ($s['enabled'])
                                        <x-status-badge :status="new Badge(__('İzin ver'), 'ok')" />
                                    @else
                                        <x-status-badge :status="new Badge(__('Kapalı'), 'muted')" />
                                    @endif
                                </td>
                                <td class="py-3 pr-5 pl-3 text-right">
                                    @if ($s['locked'])
                                        <span class="text-2xs text-muted" title="{{ __('Panel portu kapatılamaz') }}">{{ __('Kilitli') }}</span>
                                    @else
                                        <button type="button" wire:click="toggleService('{{ $s['id'] }}')" class="{{ $link }}">{{ $s['enabled'] ? __('Kapat') : __('Aç') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($firewall['rules'] as $r)
                            <tr wire:key="rule-{{ $r['id'] }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 font-medium">{{ $r['name'] }}</td>
                                <td class="px-3 py-3 font-mono text-[13px]">{{ $r['ports'] }} <span class="text-muted">{{ $r['protocol'] === 'both' ? 'tcp/udp' : $r['protocol'] }}</span></td>
                                <td class="hidden px-3 py-3 font-mono text-[13px] md:table-cell">{{ $r['sources'] ? implode(', ', array_slice($r['sources'], 0, 3)).(count($r['sources']) > 3 ? ' +'.(count($r['sources']) - 3) : '') : __('Herkes') }}</td>
                                <td class="px-3 py-3">
                                    <x-status-badge :status="$r['action'] === 'deny' ? new Badge(__('Engelle'), 'crit') : new Badge(__('İzin ver'), 'ok')" />
                                </td>
                                <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="openRule('{{ $r['id'] }}')" class="{{ $link }}">{{ __('Düzenle') }}</button>
                                    <button type="button" wire:click="deleteRule('{{ $r['id'] }}')" class="ml-3 text-[13px] text-crit hover:underline">{{ __('Sil') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($uncovered->isNotEmpty())
                <p class="border-t border-line px-5 py-3 text-[13px]">
                    <x-icon name="triangle-alert" class="mr-1 inline size-3.5 text-warn" />
                    {{ __('Bu portlarda çalışan hizmetler var ama hiçbir kural açmıyor:enabled:', ['enabled' => $firewall['enabled'] ? __('; dışarıdan ulaşılamıyorlar') : __('; güvenlik duvarı açılınca kapanırlar')]) }}
                    @foreach ($uncovered as $l)
                        <span class="font-mono">{{ $l['port'] }}/{{ $l['protocol'] }}</span>{{ $l['process'] ? ' ('.$l['process'].')' : '' }}{{ $loop->last ? '.' : ',' }}
                    @endforeach
                </p>
            @endif
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">
                <x-t text="Sizin adresiniz :my_ip. Panelin portunu (:panel_port) bu adrese kapatacak bir değişiklik kabul edilmez. Tek tek adresleri engellemek için :link." :with="['panel_port' => $panelPort]"><x-slot:my_ip><span class="font-mono">{{ $myIp }}</span></x-slot:my_ip><x-slot:link><a href="{{ route('monitor.index', ['tab' => 'attacks']) }}" wire:navigate class="text-accent hover:underline">{{ __('Canlı İzleme → Saldırılar') }}</a></x-slot:link></x-t>
            </p>

        {{-- WAF --}}
        @elseif ($tab === 'waf')
            @if (! $installed)
                <div class="px-5 py-12 text-center text-[13.5px]">
                    <p class="font-medium">{{ __('Web uygulaması güvenlik duvarı kurulu değil') }}</p>
                    <p class="mx-auto mt-1 max-w-lg text-muted">{{ __('ModSecurity ve OWASP Core Rule Set, sitelere gelen SQL enjeksiyonu, XSS ve benzeri saldırıları yakalar. Nginx ve Apache kullanan bütün sitelerin önünde çalışır.') }}</p>
                    <a href="{{ route('components.index', ['tab' => 'other']) }}" wire:navigate class="{{ $primary }} mx-auto mt-4 w-fit"><x-icon name="package-plus" />{{ __('Yazılım Yönetimi\'nden kur') }}</a>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-[13.5px]">
                    <span><x-t text="Sunucu: :mode"><x-slot:mode><strong class="font-medium">{{ Waf::MODES[$server['mode']] }}</strong></x-slot:mode></x-t> <span class="text-muted">· {{ __('paranoya seviyesi :paranoia', ['paranoia' => $server['paranoia']]) }}{{ $ownModes ? __(' · :own_modes site kendi kipinde', ['own_modes' => $ownModes]) : '' }}</span></span>
                    <select wire:model.live="wafSite" class="{{ $field }} border-line ml-auto max-w-56" aria-label="{{ __('Site') }}">
                        <option value="0">{{ __('Bütün siteler') }}</option>
                        @foreach ($sites as $s)
                            <option value="{{ $s->id }}">{{ $s->domain }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="openWafSettings" class="{{ $btn }}"><x-icon name="settings-2" />{{ __('Ayarlar') }}</button>
                </div>
                @if ($wafError)
                    <p class="px-5 py-10 text-center text-[13.5px] text-muted">{{ $wafError }}</p>
                @else
                    <div class="relative overflow-x-auto">
                        <table class="w-full text-[13.5px] sm:min-w-[860px]">
                            <thead>
                                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Zaman') }}</th>
                                    <th class="px-3 py-2.5 font-medium">{{ __('Adres') }}</th>
                                    <th class="px-3 py-2.5 font-medium">{{ __('İstek') }}</th>
                                    <th class="hidden px-3 py-2.5 font-medium lg:table-cell">{{ __('Kural') }}</th>
                                    <th class="px-3 py-2.5 font-medium">{{ __('Sonuç') }}</th>
                                    <th class="py-2.5 pr-5 pl-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @forelse ($events as $e)
                                    @php
                                        $hit = collect($e['rules'])->first();
                                        $time = \Illuminate\Support\Carbon::parse($e['time']);
                                        $path = parse_url((string) $e['uri'], PHP_URL_PATH) ?: '/';
                                    @endphp
                                    <tr wire:key="ev-{{ $e['id'] }}" class="align-top hover:bg-surface-2/60">
                                        <td class="num py-3 pr-3 pl-5 whitespace-nowrap text-muted">{{ $when($time) }}</td>
                                        <td class="px-3 py-3 font-mono text-[13px]">{{ $e['ip'] }}</td>
                                        <td class="max-w-[20rem] px-3 py-3">
                                            <span class="block truncate font-mono text-[13px]" title="{{ $e['method'] }} {{ $e['uri'] }}">{{ $e['method'] }} {{ $e['uri'] }}</span>
                                            <span class="block font-mono text-2xs text-muted">{{ $e['site']?->domain ?? $e['host'] }}</span>
                                        </td>
                                        <td class="hidden max-w-[18rem] px-3 py-3 lg:table-cell">
                                            @if ($hit)
                                                <span class="font-mono text-[13px]">{{ $hit['id'] }}</span>
                                                <span class="block truncate text-2xs text-muted" title="{{ $hit['message'] }}">{{ $hit['message'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            <x-status-badge :status="$e['blocked'] ? new Badge(__('Engellendi'), 'crit') : new Badge(__('Kaydedildi'), 'warn')" />
                                            @if ($e['score'])
                                                <span class="num mt-0.5 block text-2xs text-muted">{{ __('puan :score', ['score' => $e['score']]) }}</span>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                            <div class="flex flex-col items-end gap-1">
                                            @if (in_array($e['ip'], $blocked, true))
                                                <span class="text-2xs text-muted">{{ __('Engelli') }}</span>
                                            @else
                                                <button type="button" wire:click="openBlock(@js($e['ip']), {{ $e['site']?->id ?? 'null' }}, @js('WAF: '.($hit ? $hit['id'].' '.$hit['message'] : $e['uri'])))" class="{{ $link }}">{{ __('IP\'yi engelle') }}</button>
                                            @endif
                                            @if ($e['site'] && $hit)
                                                <a href="{{ route('sites.waf', ['site' => $e['site']->id, 'rule' => $hit['id'], 'path' => $path]) }}" wire:navigate class="{{ $link }}" title="{{ __('Bu kural sitenin meşru isteklerini engelliyorsa') }}">{{ __('İstisna ekle') }}</a>
                                            @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-5 py-12 text-center">
                                            <p class="font-medium">{{ __('Yakalanan saldırı yok') }}</p>
                                            <p class="mt-1 text-[13px] text-muted">{{ __('WAF\'ın kaydettiği ya da engellediği istekler burada görünür.') }}</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('En yeni 100 olay. Sitelerin kendi kipi ve kural istisnaları sitenin sayfasında: Güvenlik → WAF.') }}</p>
                @endif
            @endif

        {{-- Tarama --}}
        @elseif ($tab === 'scan')
            @if ($running)
                <div wire:poll.3s hidden></div>
            @endif
            <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-[13.5px]">
                @if ($running)
                    <x-status-badge :status="new Badge(__('Taranıyor'), 'info', true)" />
                    <span class="num text-muted">{{ __(':sites_done / :sites_total site · :files dosya', ['sites_done' => $running->sites_done, 'sites_total' => $running->sites_total, 'files' => \App\Support\Num::format($running->files, 0)]) }}</span>
                @elseif ($last)
                    <span class="text-muted">{{ __('Son tarama :when', ['when' => $when($last->finished_at)]) }} · {{ $last->triggerLabel() }} · <x-t text=":files dosya" :count="$last->files"><x-slot:files><span class="num">{{ \App\Support\Num::format($last->files, 0) }}</span></x-slot:files></x-t>{{ $last->clamav ? __(' · ClamAV ile') : '' }}</span>
                    @if ($last->status !== 'succeeded')
                        <x-status-badge :status="$last->status === 'failed' ? new Badge(__('Başarısız'), 'crit') : new Badge(__('Bazı siteler taranamadı'), 'warn')" title="{{ $last->error }}" />
                    @endif
                @else
                    <span class="text-muted">{{ __('Henüz tarama yapılmadı. Sunucu her pazar 03:20\'de taranır.') }}</span>
                @endif
                <select wire:model.live="findingStatus" class="{{ $field }} border-line ml-auto" aria-label="{{ __('Durum') }}">
                    <option value="open">{{ __('Açık (:open)', ['open' => $statusCounts['open'] ?? 0]) }}</option>
                    <option value="fixed">{{ __('Düzeltilen (:fixed)', ['fixed' => $statusCounts['fixed'] ?? 0]) }}</option>
                    <option value="ignored">{{ __('Yok sayılan (:ignored)', ['ignored' => $statusCounts['ignored'] ?? 0]) }}</option>
                </select>
                <button type="button" wire:click="scanServer" @disabled($running) class="{{ $primary }}"><x-icon name="scan-search" />{{ __('Sunucuyu tara') }}</button>
            </div>
            @include('livewire.security.findings', ['findings' => $findings, 'showSite' => true])

        {{-- Giriş güvenliği --}}
        @else
            <div class="max-w-4xl divide-y divide-line px-5 text-[13.5px]">
                <div class="{{ $row }}">
                    <span>{{ __('Panel girişi') }}</span>
                    <div class="space-y-1.5">
                        <p>{{ __('Bir hesaba 5 hatalı denemede giriş bir dakika kilitlenir; bir adres 10 dakikada en fazla :value kez yanlış deneyebilir.', ['value' => 30]) }}</p>
                        @if ($fail2banInstalled)
                            <p>
                                <x-status-badge :status="($fail2ban['running'] ?? false) ? new Badge(__('Fail2ban çalışıyor'), 'ok') : new Badge(__('Fail2ban çalışmıyor'), 'warn')" />
                                <span class="ml-1 text-muted">{{ __('15 dakikada 10 hatalı giriş yapan adres panele bir saat ulaşamaz. SSH ve e-posta girişleri de korunuyor.') }}</span>
                            </p>
                            <p class="text-muted"><x-t text="Şu an :bans Fail2ban tarafından engelli."><x-slot:bans><a href="{{ route('monitor.index', ['tab' => 'attacks']) }}" wire:navigate class="text-accent hover:underline">{{ __(':bans adres', ['bans' => $bans]) }}</a></x-slot:bans></x-t></p>
                        @else
                            <p class="text-muted"><x-t text="Fail2ban kurulu değil; kurarsanız panel, SSH ve e-posta girişlerine kaba kuvvet saldırısı yapan adresler kendiliğinden engellenir. :link"><x-slot:link><a href="{{ route('components.index', ['tab' => 'other']) }}" wire:navigate class="text-accent hover:underline">{{ __('Yazılım Yönetimi\'nden kur') }}</a></x-slot:link></x-t></p>
                        @endif
                    </div>
                </div>

                <form wire:submit="saveTwoFactor" class="{{ $row }}">
                    <label for="require2fa" class="sm:pt-2">{{ __('İki adımlı doğrulama') }}</label>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select id="require2fa" wire:model="require2fa" class="{{ $field }} border-line">
                                @foreach (TwoFactor::REQUIRE as $k => $l)
                                    <option value="{{ $k }}">{{ $l }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="{{ $btn }}">{{ __('Kaydet') }}</button>
                        </div>
                        <p class="mt-1.5 text-2xs text-muted"><x-t text="Herkes kendi hesabını :link sayfasından korur. Zorunlu yaparsanız kurmamış hesaplar ilk girişte kurar."><x-slot:link><a href="{{ route('account.security') }}" wire:navigate class="text-accent hover:underline">{{ __('Hesap güvenliği') }}</a></x-slot:link></x-t></p>
                        <table class="mt-3 w-full text-[13px]">
                            <tbody class="divide-y divide-line border-y border-line">
                                @foreach ($users as $u)
                                    <tr wire:key="u-{{ $u->id }}">
                                        <td class="py-2 pr-3">{{ $u->name }} <span class="text-muted">· {{ $u->is_admin ? __('Yönetici') : ($u->customer_id ? __('Müşteri') : __('Laravel ekibi')) }}</span></td>
                                        <td class="py-2 pr-3">
                                            @if ($u->two_factor_confirmed_at)
                                                <x-status-badge :status="new Badge(__('Açık'), 'ok')" />
                                            @else
                                                <x-status-badge :status="new Badge(__('Kapalı'), 'muted')" />
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            @if ($u->two_factor_confirmed_at && $u->id !== auth()->id())
                                                <button type="button" wire:click="confirmReset({{ $u->id }})" class="text-[13px] text-muted hover:text-fg" title="{{ __('Telefonunu kaybeden ve kurtarma kodu olmayan biri için') }}">{{ __('Sıfırla') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>

                <form wire:submit="saveSsh" class="{{ $row }}">
                    <span class="sm:pt-2">SSH</span>
                    <div>
                        @if ($sshError)
                            <p class="pt-2 text-muted">{{ $sshError }}</p>
                        @elseif (! ($ssh['installed'] ?? false))
                            <p class="pt-2 text-muted">{{ __('Sunucuda SSH sunucusu kurulu değil.') }}</p>
                        @else
                            <div class="grid items-center gap-x-4 gap-y-3 sm:grid-cols-[10rem_minmax(0,1fr)]">
                                <label for="rootLogin">{{ __('root girişi') }}</label>
                                <select id="rootLogin" wire:model="rootLogin" class="{{ $field }} w-fit {{ $errors->has('rootLogin') ? 'border-crit' : 'border-line' }}">
                                    @foreach (Ssh::ROOT_LOGIN as $k => $l)
                                        <option value="{{ $k }}">{{ $l }}</option>
                                    @endforeach
                                </select>
                                <span>{{ __('Parolayla giriş') }}</span>
                                <label class="flex items-center gap-2.5">
                                    <input type="checkbox" wire:model="passwordAuth" class="size-4 accent-accent">
                                    {{ __('Açık') }}
                                </label>
                            </div>
                            @error('rootLogin')
                                <p class="mt-2 text-[13px] text-crit">{{ $message }}</p>
                            @enderror
                            <p class="mt-2 text-2xs text-muted">
                                {{ __('Port :ports.', ['ports' => implode(', ', $ssh['ports'] ?? [22])]) }}
                                @if ($ssh['key_accounts'] ?? [])
                                    <x-t text="SSH anahtarı olan yönetici hesapları: :key_accounts."><x-slot:key_accounts><span class="font-mono">{{ implode(', ', $ssh['key_accounts']) }}</span></x-slot:key_accounts></x-t>
                                @else
                                    {{ __('Hiçbir yönetici hesabında SSH anahtarı yok; parolayla giriş kapatılamaz.') }}
                                @endif
                                {{ __('FTP (SFTP) hesapları her durumda parolayla bağlanır.') }}
                            </p>
                            <button type="submit" class="{{ $btn }} mt-3">{{ __('Uygula') }}</button>
                        @endif
                    </div>
                </form>
            </div>
        @endif
    </section>

    {{-- Güvenlik duvarını aç --}}
    <x-modal :title="__('Güvenlik duvarını aç')" show="confirmingFirewall">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Güvenlik duvarını aç') }}</h2>
        <div class="space-y-3 p-5 text-[13.5px]">
            <p>{{ __('Sunucu yalnızca listedeki açık kurallarla izin verilen portlarda yanıt verecek; diğer portlara gelen bağlantılar düşürülecek.') }}</p>
            <p class="text-muted"><x-t text="Panelin portu sizin adresinize (:request) her zaman açık kalır. Değişikliği bildirimdeki Geri al ile geri alabilirsiniz."><x-slot:request><span class="font-mono">{{ request()->ip() }}</span></x-slot:request></x-t></p>
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('confirmingFirewall', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
            <button type="button" wire:click="setFirewall(true)" class="{{ $primary }}">{{ __('Aç') }}</button>
        </div>
    </x-modal>

    {{-- Kural --}}
    <x-modal title="{{ $ruleId ? __('Kuralı düzenle') : __('Kural ekle') }}" show="editingRule">
        <form wire:submit="saveRule">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $ruleId ? __('Kuralı düzenle') : __('Kural ekle') }}</h2>
            <div class="grid items-start gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[7rem_minmax(0,1fr)]">
                <label for="r-name" class="sm:pt-2">{{ __('Ad') }}</label>
                <div>
                    <input id="r-name" wire:model="rule.name" maxlength="80" placeholder="{{ __('Örneğin Node uygulaması') }}" class="{{ $field }} w-full {{ $errors->has('rule.name') ? 'border-crit' : 'border-line' }}">
                    @error('rule.name') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <label for="r-ports" class="sm:pt-2">{{ __('Portlar') }}</label>
                <div>
                    <div class="flex gap-2">
                        <input id="r-ports" wire:model="rule.ports" placeholder="3000, 8080-8090" spellcheck="false" class="{{ $field }} min-w-0 flex-1 font-mono text-[13px] {{ $errors->has('rule.ports') ? 'border-crit' : 'border-line' }}">
                        <select wire:model="rule.protocol" class="{{ $field }} border-line" aria-label="{{ __('Protokol') }}">
                            @foreach (Firewall::PROTOCOLS as $k => $l)
                                <option value="{{ $k }}">{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('rule.ports') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <span class="sm:pt-2">{{ __('İşlem') }}</span>
                <div class="flex gap-4 pt-2">
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="rule.action" value="allow" class="accent-accent">{{ __('İzin ver') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="rule.action" value="deny" class="accent-accent">{{ __('Engelle') }}</label>
                </div>
                <label for="r-src" class="sm:pt-2">{{ __('Kimden') }}</label>
                <div>
                    <textarea id="r-src" wire:model="rule.sources" rows="3" spellcheck="false" placeholder="{{ $rule['action'] === 'deny' ? '203.0.113.7' : __('boş: herkes') }}&#10;198.51.100.0/24" class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('rule.sources') ? 'border-crit' : 'border-line' }}"></textarea>
                    @error('rule.sources')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-2xs text-muted">{{ $rule['action'] === 'deny' ? __('Bu adreslerden gelen bağlantılar, başka bir kural izin verse de reddedilir.') : __('Satır başına bir adres ya da aralık. Boş bırakılırsa port herkese açılır.') }}</p>
                    @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editingRule', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="submit" class="{{ $primary }}">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- WAF ayarları --}}
    <x-modal :title="__('WAF ayarları')" show="configuringWaf">
        <form wire:submit="saveWafSettings">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('WAF ayarları') }}</h2>
            <div class="grid items-start gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[8rem_minmax(0,1fr)]">
                <span class="sm:pt-0.5">{{ __('Sunucu kipi') }}</span>
                <div class="space-y-2">
                    <label class="flex items-start gap-2"><input type="radio" wire:model="wafMode" value="detect" class="mt-1 accent-accent"><span>{{ __('Yalnızca izle') }}<span class="block text-2xs text-muted">{{ __('Saldırılar kaydedilir, istekler geçer. Yeni kurulumlarda önerilir.') }}</span></span></label>
                    <label class="flex items-start gap-2"><input type="radio" wire:model="wafMode" value="block" class="mt-1 accent-accent"><span>{{ __('Engelle') }}<span class="block text-2xs text-muted">{{ __('Saldırı sayılan istekler 403 ile reddedilir.') }}</span></span></label>
                    @error('wafMode') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <label for="paranoia" class="sm:pt-2">{{ __('Paranoya seviyesi') }}</label>
                <div>
                    <select id="paranoia" wire:model="wafParanoia" class="{{ $field }} border-line">
                        <option value="1">{{ __('1 · Az yanlış alarm (önerilen)') }}</option>
                        <option value="2">{{ __('2 · Daha sıkı') }}</option>
                        <option value="3">{{ __('3 · Çok sıkı') }}</option>
                        <option value="4">{{ __('4 · En sıkı, çok yanlış alarm') }}</option>
                    </select>
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Kendi kipi olmayan bütün siteler bu ayarı kullanır.') }}</p>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('configuringWaf', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="submit" class="{{ $primary }}">{{ __('Uygula') }}</button>
            </div>
        </form>
    </x-modal>

    @include('livewire.security.block-modal')

    {{-- 2FA sıfırla --}}
    <x-modal :title="__('İki adımlı doğrulamayı sıfırla')" show="resetting">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('İki adımlı doğrulamayı sıfırla') }}</h2>
        <p class="p-5 text-[13.5px]">{{ __('Hesap yalnızca parolayla girebilecek ve doğrulama uygulamasını yeniden kurması gerekecek. Telefonunu ve kurtarma kodlarını kaybeden biri için kullanın; geri alınamaz.') }}</p>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('resetting', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
            <button type="button" wire:click="resetTwoFactor" class="flex h-9 items-center rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110">{{ __('Sıfırla') }}</button>
        </div>
    </x-modal>
</div>
