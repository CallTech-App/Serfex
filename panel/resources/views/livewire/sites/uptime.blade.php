@php
    use App\Support\Num;
    use App\Support\When;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $pct = fn (?float $share) => $share === null ? '—' : Num::percent(round($share * 100, 2), $share >= 1 ? 0 : 2);
    $barTone = ['ok' => 'bg-ok', 'warn' => 'bg-warn', 'crit' => 'bg-crit', 'none' => 'bg-surface-2'];
@endphp
<section class="overflow-hidden rounded-xl border border-line bg-surface text-[13px]" wire:poll.60s>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 pt-4">
        <h2 class="text-[14.5px] font-semibold">{{ __('Erişilebilirlik') }}</h2>
        @if ($summary['skipped'])
            <span class="rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted">{{ $summary['skipped'] }}</span>
        @elseif ($site->isDown())
            <span class="inline-flex items-center gap-1.5 rounded-full bg-crit-soft px-2 py-0.5 text-2xs font-medium text-crit"><i class="size-1.5 rounded-full bg-crit"></i>{{ __('Yanıt vermiyor · :minutes dakikadır', ['minutes' => max(1, (int) round($site->uptime_since?->diffInSeconds(now()) / 60))]) }}</span>
        @elseif ($site->uptime_state === null)
            <span class="rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted">{{ __('İlk yanıt bekleniyor') }}</span>
        @endif
        <button type="button" wire:click="openSettings" class="ml-auto text-[13px] font-medium text-accent hover:underline">{{ __('Ayarla') }}</button>
    </div>

    @if ($site->isDown() && ($site->uptime_error || $site->uptime_cause))
        <p class="mx-5 mt-3 rounded-md bg-crit-soft px-3 py-2 text-[13px] text-crit">{{ $site->uptime_error }}@if ($site->uptime_cause)<span class="text-fg"> · {{ $site->uptime_cause }}</span>@endif</p>
    @elseif ($site->uptime_state === null && $site->uptime_error && ! $summary['skipped'])
        <p class="mx-5 mt-3 text-[13px] text-muted">{{ __('Site henüz hiç yanıt vermedi: :error. DNS bu sunucuya işaret edip site ilk kez açılınca izleme başlar; o zamana kadar bildirim gönderilmez.', ['error' => $site->uptime_error]) }}</p>
    @endif

    <div class="px-5 pt-4 pb-4">
        <div class="flex h-9 items-stretch gap-px" role="img" aria-label="{{ __('Son 24 saatin erişilebilirliği') }}">
            @foreach ($summary['bars'] as $b)
                <span @class(['min-w-0 flex-1 rounded-[2px]', $barTone[$b['tone']]])
                      title="{{ When::time($b['at']) }} · {{ $b['checks'] ? ($b['failures'] ? __(':failures / :checks kontrol başarısız', ['failures' => $b['failures'], 'checks' => $b['checks']]) : __(':checks kontrol, hepsi başarılı', ['checks' => $b['checks']])) : __('Kontrol yok') }}"></span>
            @endforeach
        </div>
        <div class="mt-1.5 flex justify-between text-2xs text-muted"><span>{{ __('24 saat önce') }}</span><span>{{ __('şimdi') }}</span></div>
    </div>

    <div class="grid grid-cols-2 border-t border-line xl:grid-cols-4 [&>*]:min-w-0 [&>*]:border-line [&>*]:px-5 [&>*]:py-3.5 [&>*:nth-child(odd)]:border-r max-xl:[&>*:nth-child(-n+2)]:border-b xl:[&>*:not(:last-child)]:border-r">
        <div>
            <p class="text-2xs text-muted">{{ __('Son 24 saat') }}</p>
            <p class="num mt-1 text-[17px] font-semibold">{{ $pct($summary['day']) }}</p>
            <p class="mt-0.5 truncate text-2xs text-muted">{{ $summary['day_minutes'] ? __(':minutes dakika kesinti', ['minutes' => $summary['day_minutes']]) : __('Kesinti yok') }}</p>
        </div>
        <div>
            <p class="text-2xs text-muted">{{ __('Son 30 gün') }}</p>
            <p class="num mt-1 text-[17px] font-semibold">{{ $pct($summary['month']) }}</p>
            <p class="mt-0.5 truncate text-2xs text-muted">{{ $summary['month_count'] ? __(':count kesinti, toplam :minutes dakika', ['count' => $summary['month_count'], 'minutes' => $summary['month_minutes']]) : __('Kesinti yok') }}</p>
        </div>
        <div>
            <p class="text-2xs text-muted">{{ __('Yanıt süresi') }}</p>
            <p class="num mt-1 text-[17px] font-semibold">{{ $summary['ms'] !== null ? Num::format($summary['ms']).' ms' : '—' }}</p>
            <p class="mt-0.5 truncate text-2xs text-muted">{{ __('son 24 saatin ortalaması') }}</p>
        </div>
        <div>
            <p class="text-2xs text-muted">{{ __('Kontrol') }}</p>
            <p class="mt-1 truncate font-mono text-[13px]" title="{{ $summary['url'] }}">{{ $summary['url'] }}</p>
            <p class="mt-0.5 truncate text-2xs text-muted">{{ __('dakikada bir · :seconds sn zaman aşımı', ['seconds' => \App\Services\Sites\Uptime::TIMEOUT]) }}</p>
        </div>
    </div>

    @if ($summary['outages']->isNotEmpty())
        <h3 class="border-t border-line px-5 pt-3.5 pb-2 text-[13.5px] font-semibold">{{ __('Kesintiler') }}</h3>
        <ul class="divide-y divide-line border-t border-line">
            @foreach ($summary['outages'] as $o)
                <li class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:gap-6">
                    <div class="shrink-0 sm:w-44">
                        <p class="whitespace-nowrap">{{ When::withTime($o->started_at) }} – {{ $o->ended_at ? When::time($o->ended_at) : __('sürüyor') }}</p>
                        <p class="text-2xs text-muted">{{ __(':minutes dakika', ['minutes' => $o->minutes()]) }}</p>
                    </div>
                    <div class="min-w-0">
                        <p class="break-words">{{ $o->error }}</p>
                        @if ($o->cause)<p class="mt-0.5 text-[12.5px] break-words text-muted">{{ $o->cause }}</p>@endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <x-modal :title="__('Erişilebilirlik izleme')" show="editing">
        <form wire:submit="save">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Erişilebilirlik izleme') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model.live="enabled" class="mt-0.5 size-4 accent-accent">
                    <span>
                        <span class="block">{{ __('Siteyi dakikada bir denetle') }}</span>
                        <span class="block text-2xs text-muted">{{ __('Site iki kontrol üst üste yanıt vermezse yöneticilere bildirim gider (Araçlar ve Ayarlar → Bildirimler → Siteler).') }}</span>
                    </span>
                </label>
                <div @class(['opacity-50' => ! $enabled])>
                    <label for="uptime-path" class="block">{{ __('Denetlenen yol') }}</label>
                    <input id="uptime-path" wire:model="path" @disabled(! $enabled) placeholder="{{ $defaultPath }}" spellcheck="false" autocomplete="off" class="{{ $field }} mt-1.5 font-mono {{ $errors->has('path') ? 'border-crit' : 'border-line' }}">
                    @error('path')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-2xs text-muted">{{ __('Boş bırakılırsa :path. Yanıt 5xx ya da zaman aşımıysa site çökmüş sayılır; 4xx (ör. 404) yanıt sayılır.', ['path' => $defaultPath]) }}</p>
                    @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-4 text-[13px] font-medium hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-4 text-[13px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>
</section>
