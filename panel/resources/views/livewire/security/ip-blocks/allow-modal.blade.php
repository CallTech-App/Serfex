@php
    use App\Models\IpAllow;
    use App\Services\Monitor\IpBlocks;
    use App\Services\Security\IpAllows;
    // Offered only when it is an address that could be added.
    $offer = $allowing && $mine !== '' && IpBlocks::flaw($mine) === null && IpAllows::match($mine, IpAllow::all()) === null && ! in_array($mine, $server, true) ? $mine : null;
@endphp
{{-- İzinli adres ekle --}}
<x-modal :title="__('İzinli adres ekle')" show="allowing" size="max-w-xl">
    <form wire:submit="saveAllow">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('İzinli adres ekle') }}</h2>
        <div class="grid gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[110px_1fr]">
            <label for="allow-address" class="sm:pt-2">{{ __('Adres') }}</label>
            <div>
                <input id="allow-address" wire:model="allowAddress" spellcheck="false" autocomplete="off" placeholder="203.0.113.7"
                       class="{{ $field }} font-mono text-[13px] {{ $errors->has('allowAddress') ? 'border-crit' : 'border-line' }}">
                @error('allowAddress') <p class="mt-1.5 text-[13px] text-crit" role="alert">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-2xs text-muted">{{ __('Tek adres ya da aralık (CIDR); en fazla /16, IPv6\'da /48.') }}
                    @if ($offer)
                        <button type="button" x-on:click="$wire.allowAddress = @js($offer)" class="text-accent hover:underline"><x-t text="Şu anki adresinizi yazın (:address)"><x-slot:address><span class="font-mono">{{ $offer }}</span></x-slot:address></x-t></button>
                    @endif
                </p>
            </div>
            <label for="allow-note" class="sm:pt-2">{{ __('Not') }}</label>
            <div>
                <input id="allow-note" wire:model="allowNote" maxlength="200" class="{{ $field }} {{ $errors->has('allowNote') ? 'border-crit' : 'border-line' }}">
                @error('allowNote') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-2xs text-muted">{{ __('Bu adresin kime ait olduğunu yazın; örneğin "Ofis (sabit IP)".') }}</p>
            </div>
        </div>
        <p class="border-t border-line px-5 py-3 text-[13px] text-muted">{{ __('Adres engelliyse engeli kalkar. İzinli adres elle, Fail2ban ya da Serfex AI tarafından engellenemez, ülke engeli de onu durdurmaz; saldırı bu adresten gelirse de engellenmez.') }}</p>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('allowing', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
            <button type="submit" wire:loading.attr="disabled" wire:target="saveAllow" class="{{ $primary }}">{{ __('İzinlilere ekle') }}</button>
        </div>
    </form>
</x-modal>
