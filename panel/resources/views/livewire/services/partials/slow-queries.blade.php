{{-- The slow statements of the database server (or of one site's
     databases): $slow from Optimization::slowRows(), $showSite. --}}
@php
    use App\Support\Num;
    $ms = fn (float $v) => $v >= 1000 ? Num::format($v / 1000, 2).' '.__('sn') : Num::format($v, 0).' ms';
    $total = function (float $v) {
        $minutes = (int) round($v / 60000);
        return $minutes >= 60 ? __(':h sa :m dk', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60]) : __(':m dk', ['m' => max(1, $minutes)]);
    };
@endphp
<h3 class="flex min-h-11 flex-wrap items-center gap-x-2 border-t border-b border-line px-5 py-2 text-[14px] font-semibold">
    {{ __('Yavaş sorgular') }}
    <span class="font-normal text-muted">· {{ $slow['hours'] >= 23 && $slow['hours'] <= 25 ? __('toplam süreye göre, son 24 saat') : __('toplam süreye göre, son :hours saat', ['hours' => Num::format(round($slow['hours']))]) }}</span>
</h3>
@if ($slow['rows'] === [])
    <p class="px-5 py-4 text-[13px] text-muted">{{ $slow['available'] ? __('Yavaş sorgu yok.') : __('Henüz ölçüm yok; ilk ölçüm bir saat içinde yapılır.') }}</p>
@else
    <div class="divide-y divide-line">
        @foreach ($slow['rows'] as $q)
            <div class="px-5 py-4" x-data="{ code: false }">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
                    @if ($showSite && $q['site'])
                        <span class="font-mono font-medium">{{ $q['site'] }}</span><span class="text-muted">·</span>
                    @endif
                    <span class="font-mono text-muted">{{ $q['schema'] }}</span>
                    @if ($q['app'])
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted"><i class="size-1.5 rounded-full bg-muted"></i>{{ $q['app'] }}</span>
                    @endif
                </div>
                <pre class="mt-2 overflow-x-auto rounded-md border border-line bg-surface-2 px-3 py-2 font-mono text-[12.5px] whitespace-pre-wrap">{{ $q['text'] }}</pre>
                <div class="mt-2.5 grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
                    <div><p class="text-2xs text-muted">{{ __('Günde') }}</p><p class="num font-mono text-[13px]">{{ __(':count kez', ['count' => Num::format($q['per_day'])]) }}</p></div>
                    <div><p class="text-2xs text-muted">{{ __('Ortalama') }}</p><p class="num font-mono text-[13px]">{{ $ms($q['avg_ms']) }}</p></div>
                    <div><p class="text-2xs text-muted">{{ __('Günde toplam') }}</p><p class="num font-mono text-[13px]">{{ $total($q['total_ms_per_day']) }}</p></div>
                    <div><p class="text-2xs text-muted">{{ __('Taranan / dönen satır') }}</p><p class="num font-mono text-[13px]">{{ Num::format($q['examined']) }} / {{ Num::format($q['sent']) }}</p></div>
                </div>
                @if ($q['ai'] && ($q['ai']['text'] ?? null))
                    <div class="mt-3 flex gap-2.5 rounded-lg bg-accent-soft/60 px-3 py-2.5 text-[13px] leading-relaxed">
                        <x-icon name="sparkles" class="mt-0.5 text-accent" />
                        <div class="min-w-0 space-y-2">
                            <p class="whitespace-pre-line">{{ $q['ai']['text'] }}</p>
                            @if ($q['ai']['code'] ?? null)
                                <div x-show="code" x-cloak class="space-y-2">
                                    <pre class="overflow-x-auto rounded-md bg-[#0f1a1c] p-3 font-mono text-[12px] text-[#d7e4e4]">{{ $q['ai']['code'] }}</pre>
                                    <p class="text-2xs text-muted">{{ __('Serfex uygulamanın tablolarını değiştirmez. Migration\'ı uygulamanıza ekleyip dağıtın; etkisi bu kartta görünür.') }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                    @if ($q['ai']['code'] ?? null)
                        <button type="button" x-on:click="code = !code" class="mt-2 inline-flex items-center gap-1 text-[13px] text-accent hover:underline">
                            <x-icon name="chevron-down" class="size-3.5 transition" ::class="code && 'rotate-180'" /><span x-text="code ? @js(__('Migration örneğini gizle')) : @js(__('Migration örneğini göster'))"></span>
                        </button>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
@endif
