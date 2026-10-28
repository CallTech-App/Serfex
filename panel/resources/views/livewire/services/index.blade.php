@php
    use App\Livewire\Services\ServiceIndex;
    use App\Services\System\SystemServices;
    use App\Support\Badge;
    use App\Support\Bytes;
    $link = 'text-[13px] text-accent hover:underline disabled:opacity-40 disabled:no-underline';
    $phpBusy = ['install' => __('Kuruluyor'), 'update' => __('Güncelleniyor'), 'remove' => __('Kaldırılıyor'), 'ext-install' => __('Eklenti kuruluyor'), 'ext-remove' => __('Eklenti kaldırılıyor')];
    $phpBadge = fn (array $r) => match (true) {
        $r['busy'] !== null => new Badge($phpBusy[$r['busy']] ?? __('Çalışıyor'), 'info', true),
        ! $r['installed'] => new Badge(__('Kurulu değil'), 'muted'),
        ! $r['running'] && $r['units'] !== [] => new Badge(__('Durdu'), 'crit'),
        $r['latest'] !== null => new Badge(__('Güncelleme var'), 'warn'),
        default => new Badge(__('Kurulu'), 'ok'),
    };
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Hizmetler') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Sunucuda çalışan hizmetler ve PHP sürümleri. Durdurmak siteleri etkileyecekse önce sorulur; ayar dosyası hatalıysa yeniden başlatma yapılmaz, hizmet eski ayarlarıyla çalışmaya devam eder.') }}</p>
    </div>

    @if ($busy && ! $operation)
        <div wire:poll.3s hidden></div>
    @elseif ($tab === 'services' && ! $agentError)
        <div wire:poll.10s hidden></div>
    @endif

    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-4 text-[13.5px]">
            <p class="font-medium text-crit">{{ $agentError }}</p>
        </div>
    @endif
    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="flex-1">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif
    @if ($tab === 'php')
        @include('livewire.components.blocker')
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (ServiceIndex::TABS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'services')
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[760px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Hizmet') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Süre') }}</th>
                            <th class="hidden px-3 py-2.5 text-right font-medium md:table-cell">{{ __('İşlemci') }}</th>
                            <th class="hidden px-3 py-2.5 text-right font-medium md:table-cell">{{ __('Bellek') }}</th>
                            <th class="hidden py-2.5 pr-5 pl-3 sm:table-cell"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($groups as $group => $rows)
                            <tr class="bg-surface-2/60"><td colspan="6" class="px-5 py-2 text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ SystemServices::GROUPS[$group] }}</td></tr>
                            @foreach ($rows as $s)
                                @php $running = SystemServices::running($s); @endphp
                                <tr wire:key="s-{{ $s['unit'] }}" class="hover:bg-surface-2/60">
                                    <td class="py-3 pr-3 pl-5">
                                        <a href="{{ route('services.show', $s['unit']) }}" wire:navigate class="font-medium hover:text-accent">{{ $s['name'] }}</a>
                                        <span class="mt-0.5 block font-mono text-2xs text-muted">{{ $s['unit'] }}</span>
                                        <div class="mt-2 sm:hidden">@include('livewire.services.actions')</div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <x-status-badge :status="SystemServices::badge($s)" />
                                        @if (($s['enabled'] ?? '') === 'disabled' && $running)
                                            <span class="mt-1 block text-2xs text-muted">{{ __('Açılışta başlamaz') }}</span>
                                        @endif
                                    </td>
                                    <td class="hidden px-3 py-3 text-[13px] whitespace-nowrap text-muted md:table-cell">{{ ServiceIndex::uptime($s['since'] ?? null) }}</td>
                                    <td class="hidden px-3 py-3 text-right font-mono text-[13px] whitespace-nowrap md:table-cell">{{ $running ? \App\Support\Num::format((float) $s['cpu'], 1).' %' : '—' }}</td>
                                    <td class="hidden px-3 py-3 text-right font-mono text-[13px] whitespace-nowrap md:table-cell">{{ $running && $s['memory'] ? Bytes::format((int) $s['memory']) : '—' }}</td>
                                    <td class="hidden py-3 pr-5 pl-3 text-right whitespace-nowrap sm:table-cell">@include('livewire.services.actions')</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Serfex\'in kendi hizmetleri (panel, ajan, panelin PostgreSQL\'i) müşteri hizmetlerinden ayrı çalışır; ajan ve panel durdurulamaz. Hizmetin günlüğü ve ayarları için adına tıklayın.') }}</p>
        @else
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-line px-5 py-3">
                <p class="text-[13px] text-muted">{{ __('PHP\'nin yama sürümleri Ubuntu ve ondrej/php paket depolarından gelir; paket listeleri her gece denetlenir.') }}</p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    @if ($checkedAt)
                        <span class="text-2xs text-muted" title="{{ \App\Support\When::dateTime(\Illuminate\Support\Carbon::parse($checkedAt)) }}">{{ __('Son denetim :checked_at', ['checked_at' => \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans()]) }}</span>
                    @endif
                    <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" @disabled($busy || $agentError) title="{{ $blocker['text'] ?? '' }}" class="flex h-9 items-center gap-2 whitespace-nowrap rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40">
                        <x-icon name="refresh-cw" wire:loading.class="animate-spin" wire:target="check" />{{ __('Güncellemeleri denetle') }}
                    </button>
                </div>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[720px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Sürüm') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Paket') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Kullanan siteler') }}</th>
                            <th class="hidden py-2.5 pr-5 pl-3 sm:table-cell"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($phpRows as $r)
                            <tr wire:key="p-{{ $r['id'] }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5">
                                    @if ($r['installed'])
                                        <a href="{{ route('services.php', $r['number']) }}" wire:navigate class="font-medium hover:text-accent">PHP {{ $r['number'] }}</a>
                                    @else
                                        <span class="font-medium">PHP {{ $r['number'] }}</span>
                                    @endif
                                    @if ($r['default'])
                                        <span class="ml-1.5 rounded bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ __('yeni sitelerde') }}</span>
                                    @endif
                                    <span class="mt-0.5 block text-2xs text-muted">{{ $r['description'] }}</span>
                                    <span class="mt-0.5 block font-mono text-2xs text-muted sm:hidden">{{ $r['installed'] ? ($r['version'] ?? '') : ($r['candidate'] ? __('Kurulacak: :version', ['version' => $r['candidate']]) : '') }}</span>
                                    <div class="mt-2 sm:hidden">@include('livewire.services.php-actions')</div>
                                </td>
                                <td @class(['hidden px-3 py-3 font-mono text-[13px] whitespace-nowrap sm:table-cell', 'text-muted' => ! $r['installed']])>
                                    @include('livewire.components.version')
                                </td>
                                <td class="px-3 py-3"><x-status-badge :status="$phpBadge($r)" /></td>
                                <td class="hidden px-3 py-3 text-[13px] text-muted md:table-cell">
                                    @if ($r['sites'] === [])
                                        —
                                    @else
                                        <span title="{{ implode(', ', $r['sites']) }}">{{ __(':sites site', ['sites' => count($r['sites'])]) }}</span>
                                    @endif
                                </td>
                                <td class="hidden max-w-[240px] py-3 pr-5 pl-3 text-right sm:table-cell">@include('livewire.services.php-actions')</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form wire:submit="saveDefaults" class="grid grid-cols-1 gap-x-6 gap-y-5 border-t border-line px-5 py-4 text-[13.5px] md:grid-cols-[220px_1fr]">
                <label for="def-php" class="font-medium md:pt-2">{{ __('Yeni siteler için sürüm') }}</label>
                <div class="max-w-xl">
                    <select id="def-php" wire:model="defaultVersion" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-56" @disabled($installed === [])>
                        @foreach ($installed as $v)
                            <option value="{{ $v }}">PHP {{ $v }}</option>
                        @endforeach
                    </select>
                    @error('defaultVersion') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-[13px] text-muted">{{ __('Müşterinin paketi bu sürüme izin vermiyorsa paketteki en yeni sürüm seçilir.') }}</p>
                </div>

                <label for="dis-fn" class="font-medium md:pt-2">{{ __('Müşteri sitelerinde kapalı fonksiyonlar') }}</label>
                <div class="max-w-xl">
                    <textarea id="dis-fn" wire:model="disabledFunctions" rows="2" class="w-full rounded-md border border-line bg-surface px-2.5 py-2 font-mono text-[13px]"></textarea>
                    @error('disabledFunctions') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-[13px] text-muted">{{ __('Virgülle ayırın. Sitelerin sunucuda komut çalıştırmasını engeller. Yönetici siteleri etkilenmez; bir müşteri sitesine site sayfasından izin verilebilir.') }}</p>
                </div>

                <span class="hidden md:block"></span>
                <div><button type="submit" wire:loading.attr="disabled" wire:target="saveDefaults" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40">{{ __('Kaydet') }}</button></div>
            </form>
        @endif
    </section>

    {{-- Onay --}}
    <x-modal title="{{ $confirm['title'] ?? __('Onay') }}" show="confirming" size="max-w-xl">
        @if ($confirm)
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold">{{ $confirm['title'] }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ $confirm['text'] }}</p>
                @include('livewire.components.candidate')
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" @class(['h-9 rounded-md px-3.5 font-medium disabled:opacity-40', 'bg-crit text-on-crit' => $confirm['danger'], 'bg-accent text-accent-fg hover:brightness-110' => ! $confirm['danger']])>{{ $confirm['button'] }}</button>
                </div>
            </div>
        @endif
    </x-modal>

    {{-- İlerleme --}}
    @include('livewire.components.progress')
</div>
