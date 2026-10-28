@php
    $tones = ['read' => 'muted', 'auto' => 'info', 'proposed' => 'info', 'applied' => 'ok', 'rejected' => 'muted', 'undone' => 'warn', 'failed' => 'crit'];
    $at = fn ($d) => $d->timezone(config('serfex.timezone'));
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('ai.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Serfex AI</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Kayıt') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Serfex AI kaydı') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Serfex AI\'ın okuduğu, önerdiği ve yaptığı her şey ile önerilerin akıbeti.') }}</p>
    </div>

    <section class="rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <select wire:model.live="type" aria-label="{{ __('Tür') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                <option value="">{{ __('Tümü') }}</option>
                <option value="changes">{{ __('Yalnızca değişiklikler') }}</option>
                @foreach (\App\Models\AiEvent::TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <label class="relative ml-auto w-full sm:w-72">
                <span class="sr-only">{{ __('Kayıtlarda ara') }}</span>
                <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Alan adı, IP ya da işlem ara') }}"
                       class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="px-5 py-2.5 font-medium">{{ __('Zaman') }}</th>
                        <th class="px-5 py-2.5 font-medium">{{ __('Tür') }}</th>
                        <th class="px-5 py-2.5 font-medium">{{ __('Ayrıntı') }}</th>
                        <th class="px-5 py-2.5 font-medium">{{ __('Kişi') }}</th>
                        <th class="px-5 py-2.5 font-medium"><span class="sr-only">{{ __('Sohbet') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($events as $e)
                        <tr wire:key="e-{{ $e->id }}">
                            <td class="num px-5 py-2.5 whitespace-nowrap text-muted">{{ $at($e->created_at)->isToday() ? \App\Support\When::time($e->created_at, true) : \App\Support\When::dateTime($e->created_at) }}</td>
                            <td class="px-5 py-2.5"><x-status-badge :status="new \App\Support\Badge(\App\Models\AiEvent::TYPES[$e->type] ?? $e->type, $tones[$e->type] ?? 'muted')" /></td>
                            <td class="px-5 py-2.5">{{ $e->summary }}</td>
                            <td class="px-5 py-2.5 whitespace-nowrap text-muted">{{ in_array($e->type, ['applied', 'rejected', 'undone'], true) ? ($e->user->name ?? '—') : 'Serfex AI' }}</td>
                            <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                @if ($e->conversation && ! $e->conversation->trashed() && $e->conversation->user_id === auth()->id())
                                    <a href="{{ route('ai.index', ['chat' => $e->ai_conversation_id]) }}" wire:navigate class="text-accent hover:underline">{{ __('Sohbet') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-muted">{{ $search !== '' || $type !== '' ? __('Bu aramaya uyan kayıt yok.') : __('Serfex AI henüz bir şey okumadı ya da yapmadı.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($events->total() > 0)
            <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                <span>{{ __(':events–:events2 / :events3 kayıt', ['events' => $events->firstItem(), 'events2' => $events->lastItem(), 'events3' => $events->total()]) }}</span>
                @if ($events->hasPages())
                    <span class="ml-auto flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($events->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
                        <span class="px-2">{{ __('Sayfa :events / :events2', ['events' => $events->currentPage(), 'events2' => $events->lastPage()]) }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $events->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
                    </span>
                @endif
            </div>
        @endif
    </section>
</div>
