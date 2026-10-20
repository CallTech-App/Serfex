@php
    use App\Livewire\Sites\SiteLogs;
    use App\Support\Bytes;
    $field = 'h-9 rounded-md border border-line bg-surface px-3 text-[13px] outline-none focus:border-accent';
@endphp
<div class="space-y-6">
    @if ($live)
        <div wire:poll.3s="refresh" hidden></div>
    @endif

    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Günlükler') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Günlükler') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Sitenin, alt alan adlarının ve yönlendirilen alan adlarının istekleri ile hataları. En yeni satır en altta.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach ($kinds as $key => [$label])
                <button type="button" role="tab" wire:click="switchTab('{{ $key }}')" aria-selected="{{ $kind === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $kind === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <label class="relative w-full sm:w-64">
                <span class="sr-only">{{ __('Süz') }}</span>
                <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.300ms="filter" placeholder="{{ $kind === 'access' ? __('IP, yol ya da tarayıcı') : __('Hata metni') }}" class="{{ $field }} w-full pl-9">
            </label>
            <label class="flex h-9 items-center gap-2 text-[13px]">
                <input type="checkbox" wire:model.live="problems" class="size-4 accent-accent">
                {{ $kind === 'access' ? __('Yalnızca hatalı istekler') : __('Yalnızca hatalar') }}
            </label>
            <select wire:model.live="count" aria-label="{{ __('Satır sayısı') }}" class="{{ $field }}">
                @foreach (SiteLogs::COUNTS as $n)
                    <option value="{{ $n }}">{{ __('Son :n satır', ['n' => $n]) }}</option>
                @endforeach
            </select>
            <div class="flex gap-2 sm:ml-auto">
                <button type="button" wire:click="$toggle('live')" @class(['flex h-9 items-center gap-2 rounded-md border px-3.5 text-[13.5px]', 'border-accent bg-accent-soft text-accent' => $live, 'border-line bg-surface hover:bg-surface-2' => ! $live])>
                    <x-icon :name="$live ? 'pause' : 'play'" class="size-3.5" />{{ $live ? __('Canlı izleniyor') : __('Canlı izle') }}
                </button>
                @if ($log && $log['exists'])
                    <a href="{{ route('sites.logs.download', ['site' => $site, 'tur' => $kind]) }}" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="download" class="size-3.5" />{{ __('İndir') }}</a>
                @endif
            </div>
        </div>

        @if ($error)
            <p class="px-5 py-10 text-center text-[13px] text-crit">{{ $error }}</p>
        @elseif (! $log['exists'])
            <div class="px-5 py-12 text-center">
                <p class="font-medium">{{ __('Bu günlük henüz yok') }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ __('Site ilk isteği ya da ilk hatayı aldığında oluşur.') }}</p>
            </div>
        @elseif ($lines === [])
            <div class="px-5 py-12 text-center">
                <p class="font-medium">{{ $filter !== '' || $problems ? __('Eşleşen satır yok') : __('Günlük boş') }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ $filter !== '' || $problems ? __('Süzgeci değiştirin; arama günlüğün son 512 KB\'ında yapılır.') : __('Yeni satırlar geldikçe burada görünür.') }}</p>
            </div>
        @else
            <div wire:key="log-{{ $kind }}-{{ $log['size'] }}-{{ count($lines) }}-{{ md5($filter) }}" x-data x-init="$el.scrollTop = $el.scrollHeight"
                 class="max-h-[60vh] overflow-auto bg-surface-2/50 px-5 py-3 font-mono text-[12px] leading-[1.6]">
                @foreach ($lines as $line)
                    <div @class(['break-all whitespace-pre-wrap', 'text-crit' => $line['tone'] === 'crit', 'text-warn' => $line['tone'] === 'warn'])>{{ $line['text'] }}</div>
                @endforeach
            </div>
        @endif
        @if ($log && $log['exists'])
            <p class="num border-t border-line px-5 py-3 text-2xs text-muted">
                {{ __(':lines satır gösteriliyor · Dosya :size · :kind:live', ['lines' => count($lines), 'size' => Bytes::format($log['size']), 'kind' => $kinds[$kind][1], 'live' => $live ? __(' · 3 saniyede bir yenileniyor') : '']) }}
            </p>
        @endif
    </section>
</div>
