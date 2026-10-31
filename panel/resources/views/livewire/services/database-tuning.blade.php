@php
    use App\Support\Bytes;
    use App\Support\Num;
    use App\Support\When;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $readOnly = auth()->user()->isReadOnly();
    $windowLevel = $level === \App\Services\Tuning\TuningSettings::WINDOW;
@endphp
<div>
    @if ($error)
        <p class="mx-5 mt-4 rounded-lg bg-crit-soft px-3 py-2 text-[13px] text-crit" role="alert">{{ $error }}</p>
    @endif

    @if ($report === null)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-5 text-[13.5px]">
            <x-icon name="info" class="text-muted" />
            <span class="flex-1">{{ __('Optimizasyon kapalı: ölçüm yapılmıyor, öneri gösterilmiyor.') }}</span>
            <a href="{{ route('services.index', ['tab' => 'optimization']) }}" wire:navigate class="font-medium text-accent hover:underline">{{ __('Optimizasyon ayarları') }}</a>
        </div>
    @elseif (! $report['measured'])
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-5 text-[13.5px]">
            <x-icon name="gauge" class="text-muted" />
            <span class="flex-1">{{ __('Henüz ölçüm yok. Ölçüm her saat yapılır; öneriler en az bir saatlik veriyle daha doğru olur.') }}</span>
            @unless ($readOnly)
                <button type="button" wire:click="measure" wire:loading.attr="disabled" wire:target="measure" class="{{ $btn }}"><x-icon name="refresh-cw" wire:loading.class="animate-spin" wire:target="measure" />{{ __('Şimdi ölç') }}</button>
            @endunless
        </div>
    @else
        @php $machine = $report['machine']; @endphp
        <div class="space-y-4 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-[13px] text-muted">
                    {{ __('Son :period ölçümüne göre', ['period' => $report['hours'] >= 48 ? __(':n günün', ['n' => Num::format(round($report['hours'] / 24))]) : __(':n saatin', ['n' => Num::format(max(1, round($report['hours'])))])]) }}
                    · {{ __('sunucu :ram RAM, :cpus çekirdek', ['ram' => Bytes::format(((int) ($machine['memory_mb'] ?? 0)) << 20), 'cpus' => $machine['cpus'] ?? '—']) }}@if ($machine['disk'] ?? null), {{ ['nvme' => 'NVMe', 'ssd' => 'SSD', 'hdd' => __('döner disk')][$machine['disk']] ?? '' }}@endif
                    · {{ __('son ölçüm :when', ['when' => mb_strtolower(When::withTime($report['measured_at']))]) }}
                </p>
                @unless ($readOnly)
                    <button type="button" wire:click="measure" wire:loading.attr="disabled" wire:target="measure" class="flex items-center gap-1.5 text-[13px] text-accent hover:underline">
                        <x-icon name="refresh-cw" class="size-3.5" wire:loading.class="animate-spin" wire:target="measure" />{{ __('Şimdi ölç') }}
                    </button>
                @endunless
            </div>
            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line lg:grid-cols-4">
                @foreach ($report['cells'] as [$label, $value, $note, $tone])
                    <div class="bg-surface px-4 py-3">
                        <p class="text-2xs text-muted">{{ $label }}</p>
                        <p @class(['num mt-1 text-[18px] font-semibold', 'text-warn' => $tone === 'warn'])>{{ $value }}</p>
                        <p class="text-2xs text-muted">{{ $note }}</p>
                    </div>
                @endforeach
            </div>
            @if ($aiOn)
                <p class="flex items-center gap-1.5 text-2xs text-muted"><x-icon name="sparkles" class="size-3.5 text-accent" />{{ __('Açıklamalar Serfex AI\'dan. Değerleri ölçüm kuralları hesaplar; Serfex AI yalnızca güvenli aralıktan seçer.') }}</p>
            @else
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg bg-surface-2 px-3 py-2.5 text-[13px]">
                    <x-icon name="info" class="text-muted" />
                    <span class="flex-1">{{ __('Öneriler ölçüm kurallarından. Yavaş sorguların hangi index\'le hızlanacağını yorumlaması için Serfex AI\'ı açın.') }}</span>
                    @if (auth()->user()->isFullAdmin())
                        <a href="{{ route('ai.settings') }}" wire:navigate class="font-medium text-accent hover:underline">{{ __('Serfex AI ayarları') }}</a>
                    @endif
                </div>
            @endif
        </div>

        <h3 class="flex h-11 items-center gap-2 border-t border-b border-line px-5 text-[14px] font-semibold">{{ __('Öneriler') }} <span class="font-normal text-muted">· {{ $report['count'] }}</span></h3>
        @if ($report['cards'] === [])
            <p class="flex items-center gap-2 px-5 py-4 text-[13.5px]"><x-icon name="circle-check" class="text-ok" />{{ __('Şu an bir öneri yok; ayarlar ölçümlere uygun.') }}</p>
        @endif
        <div class="divide-y divide-line">
            @foreach ($report['cards'] as $card)
                @php $change = $card['change'] ?? null; @endphp
                <div class="px-5 py-4" wire:key="card-{{ $card['key'] }}-{{ $card['state'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[14px] font-semibold">{{ $card['title'] }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
                                <span class="font-mono text-muted">{{ $card['setting'] }}</span>
                                <span class="font-mono"><span class="text-muted line-through decoration-muted/50">{{ $card['from_label'] }}</span> → <b class="font-semibold">{{ $card['to_label'] }}</b></span>
                            </p>
                        </div>
                        @unless ($readOnly)
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($card['state'] === 'new' && $card['blocked'] === null)
                                    <button type="button" wire:click="confirm('{{ $card['key'] }}')" class="{{ $primary }}">{{ __('Uygula') }}</button>
                                    @unless ($windowLevel)
                                        <button type="button" wire:click="applyTonight('{{ $card['key'] }}')" class="{{ $btn }}"><x-icon name="calendar-clock" />{{ __('Bu gece uygula') }}</button>
                                    @endunless
                                    <button type="button" wire:click="dismiss('{{ $card['key'] }}')" class="flex h-9 items-center px-2 text-[13px] text-muted hover:text-fg">{{ __('Yoksay') }}</button>
                                @elseif ($card['state'] === 'new')
                                    <button type="button" wire:click="dismiss('{{ $card['key'] }}')" class="flex h-9 items-center px-2 text-[13px] text-muted hover:text-fg">{{ __('Yoksay') }}</button>
                                @elseif ($card['state'] === 'watching' && $tuner->canUndo($change))
                                    <button type="button" wire:click="undo({{ $change->id }})" class="{{ $btn }}"><x-icon name="undo-2" />{{ __('Geri al') }}</button>
                                @elseif ($card['state'] === 'scheduled')
                                    <button type="button" wire:click="cancel({{ $change->id }})" class="{{ $btn }}"><x-icon name="x" />{{ __('Vazgeç') }}</button>
                                @endif
                            </div>
                        @endunless
                    </div>
                    @if (in_array($card['state'], ['new', 'scheduled'], true))
                        @if ($card['why_ai'])
                            <div class="mt-2.5 flex gap-2.5 rounded-lg bg-accent-soft/60 px-3 py-2.5 text-[13px] leading-relaxed"><x-icon name="sparkles" class="mt-0.5 text-accent" /><p>{{ $card['why_ai'] }}</p></div>
                        @else
                            <p class="mt-2 text-[13px] leading-relaxed">{{ $card['why'] }}</p>
                        @endif
                        <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-2xs text-muted">
                            <span class="flex items-center gap-1"><x-icon name="zap" class="size-3.5" />{{ __('Kesintisiz uygulanır') }}</span>
                            <span>{{ __('Ölçüm: :measure', ['measure' => $card['measure']]) }}</span>
                            @if ($card['memory_mb'] > 0)
                                <span>{{ __('Bellek: +:size', ['size' => Bytes::format($card['memory_mb'] << 20)]) }}</span>
                            @endif
                        </p>
                        @if ($card['blocked'] !== null)
                            <div class="mt-3 flex items-start gap-2 rounded-lg border border-warn/30 bg-warn-soft px-3 py-2 text-[13px]">
                                <x-icon name="triangle-alert" class="mt-0.5 text-warn" />
                                <span>{{ __('Bellek bütçesi yetmiyor: bunun için :size daha gerekir. Bellek sınırını yükseltin ya da başka bir hizmetten yer açın.', ['size' => Bytes::format($card['blocked'] << 20)]) }}</span>
                            </div>
                        @endif
                    @endif
                    @if ($card['state'] === 'watching')
                        <div class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-info/30 bg-info-soft px-3 py-2 text-[13px]">
                            <x-icon name="loader-circle" class="animate-spin text-info" />
                            <span><b class="font-medium">{{ __('Uygulandı, gözleniyor:') }}</b> {{ __(':minutes dakika kaldı. Hizmet durursa ya da sunucu takas kullanmaya başlarsa eski değere kendiliğinden döner.', ['minutes' => max(1, (int) ceil(now()->diffInMinutes($change->watch_until)))]) }}</span>
                        </div>
                    @elseif ($card['state'] === 'scheduled')
                        <div class="mt-3 flex items-center gap-2 rounded-lg bg-surface-2 px-3 py-2 text-[13px]">
                            <x-icon name="calendar-clock" class="text-muted" />
                            {{ __(':when uygulanacak (bakım penceresi :start–:end).', ['when' => When::withTime($change->scheduled_for), 'start' => $window[0], 'end' => $window[1]]) }}
                        </div>
                    @elseif ($card['state'] === 'rolled')
                        <div class="mt-3 flex items-start gap-2 rounded-lg border border-warn/30 bg-warn-soft px-3 py-2 text-[13px]">
                            <x-icon name="triangle-alert" class="mt-0.5 text-warn" />
                            <span><b class="font-medium">{{ __('Kendiliğinden geri alındı (:when).', ['when' => When::withTime($change->finished_at)]) }}</b> {{ $change->note }} {{ __('Değer yeniden :value yapıldı. Bu öneri 7 gün tekrar gösterilmez.', ['value' => $change->from_label]) }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($report['fine'] !== [])
            <details class="group border-t border-line">
                <summary class="flex h-11 cursor-pointer list-none items-center gap-2 px-5 text-[13.5px] font-medium">
                    <x-icon name="circle-check" class="text-ok" />{{ __('Yerinde olan :count ayar', ['count' => count($report['fine'])]) }}
                    <span class="ml-auto text-2xs font-normal text-muted group-open:hidden">{{ __('Göster') }}</span>
                    <span class="ml-auto hidden text-2xs font-normal text-muted group-open:inline">{{ __('Gizle') }}</span>
                </summary>
                <div class="overflow-x-auto border-t border-line">
                    <table class="w-full text-[13.5px]">
                        <tbody class="divide-y divide-line">
                            @foreach ($report['fine'] as [$label, $key, $value, $note])
                                <tr>
                                    <td class="py-2 pr-3 pl-5 align-top">{{ $label }}<span class="block font-mono text-2xs text-muted">{{ $key }}</span></td>
                                    <td class="num px-3 py-2 align-top font-mono text-[13px] whitespace-nowrap">{{ $value }}</td>
                                    <td class="px-3 py-2 pr-5 text-[13px] text-muted">{{ $note }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    @endif

    @if ($report !== null && $report['recent']->isNotEmpty())
        <h3 class="flex h-11 items-center border-t border-b border-line px-5 text-[14px] font-semibold">{{ __('Son 7 günde uygulananlar') }}</h3>
        <div class="divide-y divide-line">
            @foreach ($report['recent'] as $c)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-2.5 text-[13.5px]" wire:key="recent-{{ $c->id }}">
                    <span class="min-w-0 flex-1">{{ $c->title }} <span class="font-mono text-[13px] text-muted">{{ $c->from_label }} → {{ $c->to_label }}</span></span>
                    <span class="text-[13px] text-muted">{{ When::withTime($c->applied_at) }}@if ($c->user) · {{ $c->user->name }}@endif</span>
                    @if (! $readOnly && $tuner->canUndo($c))
                        <button type="button" wire:click="undo({{ $c->id }})" wire:confirm="{{ __('Önceki değer (:value) geri yüklensin mi?', ['value' => $c->from_label]) }}" class="text-[13px] text-accent hover:underline">{{ __('Geri al') }}</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($report !== null)
        @include('livewire.services.partials.slow-queries', ['slow' => $report['slow'], 'showSite' => true])
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Her değişiklik önce ayar dosyasına yazılır ve çalışan sunucuya yeniden başlatmadan uygulanır. Sonraki :minutes dakika gözlenir; hizmet durursa ya da sunucu takas kullanmaya başlarsa eski değere kendiliğinden döner. Uygulananlar :days gün Geri al ile geri alınabilir.', ['minutes' => \App\Services\Tuning\Tuner::WATCH_MINUTES, 'days' => \App\Services\Tuning\Tuner::UNDO_DAYS]) }}</p>
    @endif

    <x-modal :title="$confirm['title'] ?? __('Uygula')" show="confirming" size="max-w-xl" close="$wire.set('confirming', null)">
        @if ($confirm)
            @php $budget = $report['budget']; @endphp
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $confirm['title'] }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-[160px_1fr]">
                    <dt class="text-muted">{{ __('Ayar') }}</dt><dd class="font-mono text-[13px]">{{ $confirm['setting'] }}</dd>
                    <dt class="text-muted">{{ __('Değişiklik') }}</dt><dd class="font-mono text-[13px]">{{ $confirm['from_label'] }} → {{ $confirm['to_label'] }}</dd>
                    <dt class="text-muted">{{ __('Kesinti') }}</dt><dd>{{ __('Yok. Çalışan sunucuya yeniden başlatmadan uygulanır.') }}</dd>
                    @if ($confirm['memory_mb'] > 0)
                        <dt class="text-muted">{{ __('Bellek bütçesi') }}</dt>
                        <dd>{{ Bytes::format($budget['used'] << 20) }} → {{ Bytes::format(($budget['used'] + $confirm['memory_mb']) << 20) }} <span class="text-muted">/ {{ __(':size sınır', ['size' => Bytes::format($budget['limit'] << 20)]) }}</span></dd>
                    @endif
                </dl>
                @if ($windowLevel)
                    <p class="flex items-center gap-2"><x-icon name="calendar-clock" class="text-muted" />{{ __('Bakım penceresinde uygulanacak (:when).', ['when' => When::withTime($nextWindow)]) }}</p>
                @else
                    <fieldset class="space-y-2">
                        <legend class="mb-1 font-medium">{{ __('Ne zaman') }}</legend>
                        <label class="flex items-center gap-2.5"><input type="radio" wire:model="when" value="now" class="size-4 accent-accent">{{ __('Şimdi') }}</label>
                        <label class="flex items-center gap-2.5"><input type="radio" wire:model="when" value="window" class="size-4 accent-accent">{{ __('Bakım penceresinde (:when)', ['when' => When::withTime($nextWindow)]) }}</label>
                    </fieldset>
                @endif
                <p class="rounded-lg bg-surface-2 px-3 py-2.5 text-[13px]">{{ __('Sonraki :minutes dakika gözlenir; hizmet durursa ya da sunucu takas kullanmaya başlarsa :value değerine kendiliğinden döner. :days gün boyunca tek tıkla geri alabilirsiniz.', ['minutes' => \App\Services\Tuning\Tuner::WATCH_MINUTES, 'value' => $confirm['from_label'], 'days' => \App\Services\Tuning\Tuner::UNDO_DAYS]) }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply" class="{{ $primary }}"><x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="apply" />{{ __('Uygula') }}</button>
            </div>
        @endif
    </x-modal>
</div>
