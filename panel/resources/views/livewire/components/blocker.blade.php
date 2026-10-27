{{-- Why the install, update and remove buttons are off: what runs now, with its progress. --}}
@if ($blocker && ! $operation)
    <div class="flex flex-wrap items-start gap-x-3 gap-y-1 rounded-xl border border-line bg-info-soft px-5 py-3 text-[13.5px]" role="status">
        <x-icon name="loader-circle" class="mt-0.5 shrink-0 animate-spin text-info" />
        <p class="min-w-0 flex-1">{{ $blocker['text'] }}</p>
        @if ($blocker['operation'])
            <button type="button" wire:click="show({{ $blocker['operation'] }})" class="text-[13px] text-accent hover:underline">{{ __('İlerlemeyi göster') }}</button>
        @endif
    </div>
@endif
