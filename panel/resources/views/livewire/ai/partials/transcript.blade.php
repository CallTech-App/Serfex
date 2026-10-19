{{-- The open conversation's messages; $compact for the drawer. --}}
@php $compact ??= false; @endphp
@foreach ($items as $i => $item)
    @if ($item['type'] === 'user')
        <div class="flex justify-end" wire:key="m-{{ $i }}">
            <div class="max-w-[85%] whitespace-pre-line break-words rounded-2xl rounded-br-md bg-surface-2 px-4 py-2.5 {{ $compact ? '' : 'sm:max-w-[70%]' }}">{{ $item['text'] }}</div>
        </div>
    @else
        <div class="flex max-w-3xl gap-3" wire:key="m-{{ $i }}">
            @unless ($compact)
                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-accent-soft text-accent"><x-icon name="sparkles" class="size-3.5" /></span>
            @endunless
            <div class="min-w-0 flex-1 space-y-3">
                @foreach ($item['parts'] as $part)
                    @if ($part['type'] === 'text')
                        <div class="ai-prose">{!! $part['html'] !!}</div>
                    @elseif ($part['type'] === 'steps')
                        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-2xs text-muted">
                            <x-icon name="scan-search" class="size-3.5" />
                            <span>Baktığı yerler:</span>
                            @foreach (array_unique($part['steps']) as $step)
                                <span class="rounded bg-surface-2 px-1.5 py-0.5">{{ $step }}</span>
                            @endforeach
                        </p>
                    @else
                        @include('livewire.ai.partials.action', ['action' => $part['action']])
                    @endif
                @endforeach
                @if ($item['stop'] === 'max_tokens')
                    <p class="text-2xs text-muted">Yanıt uzunluk sınırında kesildi; "devam et" yazabilirsiniz.</p>
                @elseif ($item['stop'] === 'refusal')
                    <p class="text-[13px] text-muted">Claude bu isteğe yanıt vermedi. Soruyu başka bir şekilde sorabilirsiniz.</p>
                @endif
            </div>
        </div>
    @endif
@endforeach

@if ($conversation?->isRunning())
    <div wire:poll.1s hidden></div>
    <div class="flex items-center gap-3 text-[13px] text-muted">
        @unless ($compact)
            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-accent-soft text-accent"><x-icon name="sparkles" class="size-3.5 animate-pulse" /></span>
        @endunless
        <span class="flex items-center gap-2"><x-icon name="loader-circle" class="size-3.5 animate-spin" />Servik AI sunucuya bakıyor…</span>
    </div>
@elseif ($conversation?->status === 'failed')
    <div class="flex items-start gap-3 rounded-lg border border-crit/30 bg-crit-soft/60 px-4 py-3 text-[13px]">
        <x-icon name="triangle-alert" class="mt-0.5 text-crit" />
        <div class="min-w-0 flex-1">
            <p>{{ $conversation->error }}</p>
            <button type="button" wire:click="retry" class="mt-2 font-medium text-accent hover:underline">Yeniden dene</button>
        </div>
    </div>
@endif
