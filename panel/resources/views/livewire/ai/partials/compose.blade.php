{{-- The question box with suggestions; $compact for the drawer. --}}
@php
    $compact ??= false;
    $busy = $conversation?->isRunning();
    $chips = $items === []
        ? ['Sunucuda şu an bir sorun var mı?', 'Hangi site en çok kaynak kullanıyor?', 'Süresi dolmak üzere sertifika var mı?', 'Son başarısız dağıtım neden başarısız oldu?']
        : ['Başka bir sebep var mı?', "Engellenen IP'leri göster", 'Müşteriye ne söyleyeyim?'];
@endphp
<div @class(['border-t border-line', 'px-5 py-4' => ! $compact, 'p-3' => $compact])>
    @unless ($compact)
        <div class="mb-3 flex flex-wrap gap-2">
            @foreach ($chips as $chip)
                <button type="button" wire:click="$set('question', @js($chip))" @disabled($busy)
                        class="h-8 shrink-0 whitespace-nowrap rounded-full border border-line px-3 text-[13px] hover:bg-surface-2 disabled:opacity-50">{{ $chip }}</button>
            @endforeach
        </div>
    @endunless
    <form wire:submit="send" x-data
          class="flex items-end gap-2 rounded-lg border bg-surface px-3 py-2 focus-within:border-accent {{ $errors->has('question') ? 'border-crit' : 'border-line' }}">
        <label for="{{ $compact ? 'aiDrawerInput' : 'aiInput' }}" class="sr-only">Mesaj</label>
        <textarea id="{{ $compact ? 'aiDrawerInput' : 'aiInput' }}" wire:model="question" rows="1" maxlength="4000"
                  placeholder="{{ $compact ? 'Sunucunuz hakkında bir şey sorun' : "Servik AI'a sorun" }}"
                  x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'"
                  x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                  class="min-w-0 flex-1 resize-none bg-transparent py-1.5 text-[13.5px] outline-none placeholder:text-muted"></textarea>
        <button type="submit" aria-label="Gönder" @disabled($busy) wire:loading.attr="disabled"
                class="grid size-8 shrink-0 place-items-center rounded-md bg-accent text-accent-fg hover:brightness-110 disabled:opacity-50"><x-icon name="arrow-up" /></button>
    </form>
    @error('question')
        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
    @enderror
    <p class="mt-2 text-2xs text-muted">IP engelleme ve geçici sınırlar dışında her işlem onayınızla yapılır · <a href="{{ route('ai.settings') }}" wire:navigate class="text-accent hover:underline">Servik AI ayarları</a></p>
</div>
