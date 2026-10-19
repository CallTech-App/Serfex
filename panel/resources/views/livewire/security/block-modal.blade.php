{{-- "IP'yi engelle" dialog of BlocksAddresses. --}}
@php
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
@endphp
<x-modal title="Adresi engelle" show="blocking">
    <form wire:submit="saveBlock">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Adresi engelle</h2>
        <div class="grid items-start gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[6rem_minmax(0,1fr)]">
            <label for="b-addr" class="sm:pt-2">Adres</label>
            <div>
                <input id="b-addr" wire:model="block.address" spellcheck="false" class="{{ $field }} w-full font-mono text-[13px] {{ $errors->has('block.address') ? 'border-crit' : 'border-line' }}">
                @error('block.address') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            </div>
            <label for="b-min" class="sm:pt-2">Süre</label>
            <select id="b-min" wire:model="block.minutes" class="{{ $field }} border-line w-fit">
                @foreach (\App\Services\Monitor\IpBlocks::DURATIONS as $m => $l)
                    <option value="{{ $m }}">{{ $l }}</option>
                @endforeach
            </select>
            <label for="b-reason" class="sm:pt-2">Neden</label>
            <input id="b-reason" wire:model="block.reason" maxlength="200" class="{{ $field }} border-line w-full">
        </div>
        <p class="px-5 pb-4 text-2xs text-muted">Adresten gelen bütün bağlantılar (web, posta, SSH) reddedilir.</p>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('blocking', false)" class="{{ $btn }}">Vazgeç</button>
            <button type="submit" class="flex h-9 items-center gap-2 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110"><x-icon name="ban" />Engelle</button>
        </div>
    </form>
</x-modal>
