@props(['question', 'label' => "Bunu AI'ya sor", 'before' => ''])
{{-- Hands a problem to Servik AI: opens the drawer on a new conversation with $question. --}}
@if (auth()->user()?->is_admin)
    <button type="button" x-data x-on:click="{{ $before }} Livewire.dispatch('ai-ask', { text: @js($question) })"
            {{ $attributes->class('flex h-8 shrink-0 items-center gap-1.5 rounded-md bg-accent-soft px-2.5 text-[13px] font-medium text-accent hover:bg-accent/15') }}>
        <x-icon name="sparkles" class="size-3.5" />{{ $label }}
    </button>
@endif
