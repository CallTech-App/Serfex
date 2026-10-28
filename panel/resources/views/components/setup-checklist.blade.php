{{-- Genel Bakış: the first-run steps still open, until the setup wizard is finished. --}}
@php
    $firstRun = app(\App\Services\Setup\FirstRun::class);
    $items = $firstRun->checklist();
    $done = count(array_filter($items, fn ($i) => $i['state'] === 'done')) + 1;
    $total = count($items) + 1;
@endphp
<section class="rounded-xl border border-line bg-surface text-[13.5px]" aria-labelledby="setup-title">
    <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4">
        <x-icon name="list-checks" class="text-accent" />
        <div class="min-w-0 flex-1">
            <h2 id="setup-title" class="font-semibold">{{ __('İlk kurulum') }}</h2>
            <p class="text-[13px] text-muted">{{ __(':done / :total adım tamam. Sunucuya ilk siteyi eklemeden önce bunları bitirmenizi öneririz.', ['done' => $done, 'total' => $total]) }}</p>
        </div>
        <a href="{{ route('setup', ['step' => collect($items)->firstWhere('state', '!=', 'done')['step'] ?? 'summary']) }}" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] leading-9 font-medium text-accent-fg hover:brightness-110">{{ __('Sihirbaza devam et') }}</a>
    </div>
    <ul class="divide-y divide-line">
        @foreach ($items as $item)
            <li>
                <a href="{{ route('setup', ['step' => $item['step']]) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-surface-2/60">
                    <x-icon :name="match ($item['state']) { 'done' => 'circle-check', 'skipped' => 'circle-dashed', default => 'circle' }" @class(['size-4', 'text-ok' => $item['state'] === 'done', 'text-warn' => $item['state'] === 'skipped', 'text-muted' => $item['state'] === 'open']) />
                    <span>{{ $item['name'] }}</span>
                    <span @class(['ml-auto truncate text-right text-[13px]', 'text-muted' => $item['state'] === 'done', 'text-warn' => $item['state'] !== 'done'])>
                        {{ $item['state'] === 'done' ? ($item['detail'] ?? __('Tamam')) : ($item['state'] === 'skipped' ? __('Sonraya bırakıldı') : __('Yapılmadı')) }}
                    </span>
                    <x-icon name="chevron-right" class="text-muted" />
                </a>
            </li>
        @endforeach
    </ul>
</section>
