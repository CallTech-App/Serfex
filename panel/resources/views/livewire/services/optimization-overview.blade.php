@php
    use App\Services\Tuning\TuningSettings;
    use App\Support\Bytes;
    use App\Support\Num;
    use App\Support\When;
    $field = 'h-9 rounded-md border bg-surface px-2.5 font-mono text-[13px] outline-none focus:border-accent';
    $readOnly = auth()->user()->isReadOnly();
    $parts = [
        'system' => [__('Sistem ve Serfex'), 'bg-muted/50'],
        'mysql' => [__('Veritabanı'), 'bg-accent'],
        'php-fpm' => [__('PHP-FPM (en yoğun anda)'), 'bg-info'],
        'cache' => ['Redis, Memcached', 'bg-warn/70'],
    ];
    $gb = fn (int $mb) => Bytes::format($mb << 20);
@endphp
<div>
    @if ($budget)
        <div class="space-y-4 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-[14.5px] font-semibold">{{ __('Bellek bütçesi') }}</h2>
                <p class="text-[13px] text-muted">
                    <x-t text=":ram RAM · hizmetlere en çok :limit · gerisi işletim sisteminin dosya önbelleğine">
                        <x-slot:ram>{{ $gb($budget['total']) }}</x-slot:ram>
                        <x-slot:limit><b class="font-medium text-fg">{{ Num::percent($budget['percent']) }} ({{ $gb($budget['limit']) }})</b></x-slot:limit>
                    </x-t>
                </p>
            </div>
            @foreach (['now' => [__('Şimdi'), $budget['used']], 'after' => [__('Önerilerle'), $budget['used_after']]] as $which => [$label, $total])
                <div>
                    <div class="mb-1.5 flex items-center justify-between text-[13px]"><span class="font-medium">{{ $label }}</span><span class="num font-mono text-muted">{{ $gb($total) }}</span></div>
                    <div class="relative flex h-6 overflow-hidden rounded-md bg-surface-2" role="img" aria-label="{{ $label }}: {{ $gb($total) }} / {{ $gb($budget['limit']) }}">
                        @foreach ($parts as $key => [$name, $color])
                            @if (($budget[$which][$key] ?? 0) > 0)
                                <div class="{{ $color }} h-full border-r border-surface" style="width: {{ min(100, $budget[$which][$key] / max(1, $budget['total']) * 100) }}%" title="{{ $name }}: {{ $gb($budget[$which][$key]) }}"></div>
                            @endif
                        @endforeach
                        <div class="absolute inset-y-0 border-l-2 border-dashed border-fg/60" style="left: {{ $budget['percent'] }}%"></div>
                    </div>
                </div>
            @endforeach
            <div class="flex flex-wrap gap-x-5 gap-y-1.5 text-[13px]">
                @foreach ($parts as $key => [$name, $color])
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm {{ $color }}"></i>{{ $name }}
                        <span class="num font-mono text-muted">{{ $budget['now'][$key] === $budget['after'][$key] ? $gb($budget['now'][$key]) : $gb($budget['now'][$key]).' → '.$gb($budget['after'][$key]) }}</span></span>
                @endforeach
                <span class="flex items-center gap-1.5"><i class="h-3 border-l-2 border-dashed border-fg/60"></i>{{ __(':percent sınırı', ['percent' => Num::percent($budget['percent'])]) }}</span>
            </div>
            <p class="text-2xs text-muted">{{ __('Bir öneri bu sınırı aşacaksa uygulanmaz; kartta ne kadar yer gerektiği yazar. PHP-FPM payı son 7 günün en yoğun anındaki bellektir.') }}</p>
        </div>
    @endif

    <div @class(['overflow-x-auto', 'border-t border-line' => $budget])>
        <table class="w-full text-[13.5px] sm:min-w-[640px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Hizmet') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                    <th class="px-3 py-2.5 font-medium">{{ __('Son ölçüm') }}</th>
                    <th class="py-2.5 pr-5 pl-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($servers as $s)
                    @php $r = $s['report']; @endphp
                    <tr>
                        <td class="py-2.5 pr-3 pl-5 font-medium">{{ $s['name'] }}<span class="block font-mono text-2xs font-normal text-muted">{{ $s['unit'] }}</span></td>
                        <td class="px-3 py-2.5 text-[13px]">
                            @if (! $enabled)
                                <span class="text-muted">{{ __('Optimizasyon kapalı') }}</span>
                            @elseif (! ($r['measured'] ?? true))
                                <span class="text-muted">{{ __('Henüz ölçüm yok') }}</span>
                            @else
                                {{ __(':count öneri', ['count' => $r['count']]) }} · {{ __(':count yavaş sorgu', ['count' => count($r['slow']['rows'])]) }}
                            @endif
                        </td>
                        <td class="px-3 py-2.5 text-[13px] text-muted">{{ isset($r['measured_at']) ? When::withTime($r['measured_at']) : '—' }}</td>
                        <td class="py-2.5 pr-5 pl-3 text-right">
                            @if ($enabled)
                                <a href="{{ route('services.show', ['unit' => $s['unit'], 'tab' => 'recs']) }}" wire:navigate class="text-[13px] text-accent hover:underline">{{ __('Önerileri gör') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-4 text-[13px] text-muted">{{ __('Çalışan bir MySQL ya da MariaDB sunucusu yok.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-line px-5 py-2.5 text-2xs text-muted">{{ __('Şimdilik MySQL ve MariaDB inceleniyor. Ölçüm her saat yapılır.') }}</p>

    <h2 class="flex h-12 items-center border-t border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Optimizasyon ayarları') }}</h2>
    <form wire:submit="save" class="max-w-3xl px-5 py-2 text-[13.5px]">
        <div class="divide-y divide-line">
            <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                <span class="sm:pt-0.5">{{ __('Seviye') }}</span>
                <div class="space-y-2.5">
                    @foreach ([
                        TuningSettings::OFF => [__('Kapalı'), __('Ölçüm yapılmaz, öneri gösterilmez.')],
                        TuningSettings::SUGGEST => [__('Yalnızca öneri'), __('Öneriler Hizmetler\'de görünür; siz uygulayana kadar hiçbir şey değişmez.')],
                        TuningSettings::WINDOW => [__('Onayladıklarımı bakım penceresinde uygula'), __('Uygula dediğiniz değişiklik hemen değil, bakım penceresinde yapılır.')],
                    ] as $value => [$label, $hint])
                        <label class="flex items-start gap-2.5">
                            <input type="radio" wire:model="level" value="{{ $value }}" @disabled($readOnly) class="mt-0.5 size-4 accent-accent">
                            <span>{{ $label }}@if ($value === TuningSettings::SUGGEST) <span class="text-muted">· {{ __('varsayılan') }}</span>@endif<span class="block text-2xs text-muted">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                <label for="tw-start" class="sm:pt-2">{{ __('Bakım penceresi') }}</label>
                <div>
                    <div class="flex items-center gap-2">
                        <input id="tw-start" type="time" wire:model="windowStart" @disabled($readOnly) class="{{ $field }} w-28 {{ $errors->has('windowStart') ? 'border-crit' : 'border-line' }}">
                        <span class="text-muted">–</span>
                        <input type="time" wire:model="windowEnd" @disabled($readOnly) aria-label="{{ __('pencere bitişi') }}" class="{{ $field }} w-28 {{ $errors->has('windowEnd') ? 'border-crit' : 'border-line' }}">
                    </div>
                    @error('windowStart') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    @error('windowEnd') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Sunucu saati (:tz).', ['tz' => config('serfex.timezone')]) }}</p>
                </div>
            </div>
            <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                <label for="tw-mem" class="sm:pt-2">{{ __('Bellek sınırı') }}</label>
                <div>
                    <div class="flex items-center gap-2">
                        <input id="tw-mem" wire:model="memoryPercent" inputmode="numeric" @disabled($readOnly) class="{{ $field }} w-24 {{ $errors->has('memoryPercent') ? 'border-crit' : 'border-line' }}">
                        <span class="text-muted">{{ __('% RAM') }}</span>
                    </div>
                    @error('memoryPercent') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Hizmetlerin toplamı bunu aşamaz (:min–:max).', ['min' => TuningSettings::MEMORY_MIN, 'max' => TuningSettings::MEMORY_MAX]) }}</p>
                </div>
            </div>
            <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                <span>Serfex AI</span>
                <div>
                    @if ($aiOn)
                        <p class="flex items-center gap-1.5"><x-icon name="sparkles" class="text-accent" />{{ __('Açık: önerilerin gerekçesini yazar ve yavaş sorguları yorumlar.') }}</p>
                    @else
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">{{ __('Kapalı: öneriler ölçüm kurallarından gelir, yavaş sorgu yorumu yok.') }}
                            @if (auth()->user()->isFullAdmin())<a href="{{ route('ai.settings') }}" wire:navigate class="text-accent hover:underline">{{ __('Serfex AI ayarları') }}</a>@endif</p>
                    @endif
                    <p class="mt-1 text-2xs text-muted">{{ __('Değerleri ölçüm kuralları hesaplar; Serfex AI yalnızca güvenli aralıktan seçebilir ve hiçbir ayarı kendisi değiştirmez. Haftada bir ya da ölçümler değişince çalışır, aylık harcama sınırına uyar.') }}</p>
                </div>
            </div>
        </div>
        @unless ($readOnly)
            <div class="py-4"><button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button></div>
        @endunless
    </form>
</div>
