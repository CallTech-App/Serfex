{{-- Kayıt ekle / düzenle. Needs $suffix (shown after the host name) and the EditsRecords trait. --}}
@php
    [$valueLabel, $valueExample] = \App\Support\DnsRecords::valueField($recordType);
    $ttls = \App\Support\DnsRecords::TTLS;
    if (! isset($ttls[$recordTtl])) {
        $ttls = [$recordTtl => \App\Support\DnsRecords::ttlLabel($recordTtl)] + $ttls;
    }
@endphp
<x-modal title="{{ $recordKey === null ? 'Kayıt ekle' : 'Kaydı düzenle' }}" show="editingRecord">
    <form wire:submit="saveRecord">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $recordKey === null ? 'Kayıt ekle' : 'Kaydı düzenle' }}</h2>
        <div class="space-y-4 p-5 text-[13.5px]">
            <div>
                <label for="recordType" class="mb-1.5 block">Kayıt türü</label>
                <select id="recordType" wire:model.live="recordType" @disabled($recordKey !== null) class="h-9 w-full rounded-md border border-line bg-surface px-2.5 disabled:bg-surface-2 disabled:text-muted">
                    @foreach (\App\Support\DnsRecords::TYPES as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
                @error('recordType') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="recordName" class="mb-1.5 block">Ana makine</label>
                <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('recordName') ? 'border-crit' : 'border-line' }}">
                    <input id="recordName" wire:model="recordName" placeholder="@" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                    <span class="grid max-w-[55%] place-items-center truncate border-l border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ $suffix }}</span>
                </div>
                @error('recordName')
                    <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                @else
                    <p class="mt-1.5 text-2xs text-muted">Alan adının kendisi için boş bırakın.</p>
                @enderror
            </div>
            <div class="grid grid-cols-[1fr_auto] gap-3">
                <div class="min-w-0">
                    <label for="recordValue" class="mb-1.5 block">{{ $valueLabel }}</label>
                    @if ($recordType === 'TXT')
                        <textarea id="recordValue" wire:model="recordValue" rows="3" placeholder="{{ $valueExample }}" spellcheck="false"
                                  class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('recordValue') ? 'border-crit' : 'border-line' }}"></textarea>
                    @else
                        <input id="recordValue" wire:model="recordValue" placeholder="{{ $valueExample }}" autocomplete="off" spellcheck="false"
                               class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('recordValue') ? 'border-crit' : 'border-line' }}">
                    @endif
                    @error('recordValue') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                @if (in_array($recordType, \App\Support\DnsRecords::WITH_PRIORITY, true))
                    <div>
                        <label for="recordPriority" class="mb-1.5 block">Öncelik</label>
                        <input id="recordPriority" wire:model="recordPriority" inputmode="numeric" class="num h-9 w-20 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent {{ $errors->has('recordPriority') ? 'border-crit' : 'border-line' }}">
                        @error('recordPriority') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
            <div>
                <label for="recordTtl" class="mb-1.5 block">TTL</label>
                <select id="recordTtl" wire:model.number="recordTtl" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                    @foreach ($ttls as $seconds => $label)
                        <option value="{{ $seconds }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('recordTtl')
                    <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                @else
                    <p class="mt-1.5 text-2xs text-muted">Aynı ana makine ve türdeki kayıtlar aynı TTL'yi kullanır.</p>
                @enderror
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('editingRecord', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
            <button type="submit" wire:loading.attr="disabled" wire:target="saveRecord" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ $recordKey === null ? 'Kaydı ekle' : 'Kaydet' }}</button>
        </div>
    </form>
</x-modal>
