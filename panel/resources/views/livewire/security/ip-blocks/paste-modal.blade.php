@php
    use App\Services\Monitor\IpBlocks;
    use App\Support\Load;
    use App\Support\Num;
    $new = $check ? count($check['new']) : 0;
    $updates = $check ? $check['update']->unique('address')->values() : collect();
    $total = $new + $updates->count();
    $ranges = $check ? count(array_filter($check['new'], fn ($a) => str_contains($a, '/'))) : 0;
    $mineSkipped = $check ? array_values(array_filter($check['skipped'], fn ($s) => $s['why'] === 'mine')) : [];
    $others = $check ? array_values(array_filter($check['skipped'], fn ($s) => $s['why'] !== 'mine')) : [];
    $over = (bool) ($check['over'] ?? false);
    $duration = IpBlocks::DURATIONS[$minutes] ?? '';
@endphp
{{-- IP engelle: one address or a pasted list, checked before anything is blocked. --}}
<x-modal :title="__('IP engelle')" show="pasting" size="max-w-3xl">
    <form wire:submit="saveBlocks" class="flex max-h-[calc(100vh-2rem)] flex-col">
        <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('IP engelle') }}</h2>
        <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
            <div class="grid gap-2 sm:grid-cols-[110px_1fr] sm:items-start">
                <label for="paste" class="sm:pt-2">{{ __('Adresler') }}</label>
                <div>
                    <textarea id="paste" wire:model.live.debounce.400ms="paste" rows="7" spellcheck="false" autocomplete="off"
                              @class(['block w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] leading-[1.55] outline-none focus:border-accent', 'border-crit' => $errors->has('paste'), 'border-line' => ! $errors->has('paste')])></textarea>
                    @error('paste') <p class="mt-1.5 text-[13px] break-words text-crit" role="alert">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-2xs text-muted"><x-t text="Her satıra bir adres ya da aralık (CIDR). Virgülle ya da boşlukla ayrılmış listeler de olur; :hash ile başlayan notlar atlanır. Bir kerede en fazla :n adres." :with="['n' => Num::format(IpBlocks::MAX_BATCH)]"><x-slot:hash><span class="font-mono">#</span></x-slot:hash></x-t></p>
                </div>
            </div>

            @if ($check)
                <div class="grid gap-2 sm:grid-cols-[110px_1fr] sm:items-start">
                    <span class="sm:pt-0.5">{{ __('Denetim') }}</span>
                    <ul class="space-y-2.5 rounded-lg border border-line px-4 py-3" wire:loading.class="opacity-60" wire:target="paste">
                        @if ($over)
                            <li class="flex items-start gap-2.5"><x-icon name="circle-x" class="mt-0.5 text-crit" /><span>{{ $tooMany }}</span></li>
                        @endif
                        @if ($new > 0)
                            <li class="flex items-start gap-2.5">
                                <x-icon name="circle-check" class="mt-0.5 text-ok" />
                                <span>
                                    <span class="font-medium">{{ trans_choice(':count yeni adres engellenecek', $new, ['count' => Num::format($new)]) }}</span>
                                    @if ($ranges > 0)
                                        <span class="block text-2xs text-muted">{{ __(':single tek adres, :ranges aralık', ['single' => Num::format($new - $ranges), 'ranges' => Num::format($ranges)]) }}</span>
                                    @endif
                                </span>
                            </li>
                        @endif
                        @foreach ($updates->take(3) as $block)
                            <li class="flex items-start gap-2.5" wire:key="update-{{ $block->id }}">
                                <x-icon name="info" class="mt-0.5 text-info" />
                                <span>
                                    <x-t text=":address zaten engelli; yeni süresi: :duration" :with="['duration' => $duration]"><x-slot:address><span class="font-mono text-[13px]">{{ $block->address }}</span></x-slot:address></x-t>
                                    <span class="block text-2xs text-muted">{{ __(':by engellemişti, :left', ['by' => $block->byLabel(), 'left' => mb_strtolower(Load::remaining($block->expires_at))]) }}</span>
                                </span>
                            </li>
                        @endforeach
                        @if ($updates->count() > 3)
                            <li class="flex items-start gap-2.5"><x-icon name="info" class="mt-0.5 text-info" /><span>{{ trans_choice('Zaten engelli :count adresin daha yeni süresi: :duration', $updates->count() - 3, ['count' => Num::format($updates->count() - 3), 'duration' => $duration]) }}</span></li>
                        @endif
                        @foreach ($mineSkipped as $skip)
                            <li class="flex items-start gap-2.5">
                                <x-icon name="triangle-alert" class="mt-0.5 text-warn" />
                                <span>
                                    <x-t text=":address atlanacak: şu anki bağlantınızın adresi"><x-slot:address><span class="font-mono text-[13px]">{{ $skip['text'] }}</span></x-slot:address></x-t>
                                    <span class="block text-2xs text-muted">{{ __('Engellerseniz panele ulaşamazsınız.') }}</span>
                                </span>
                            </li>
                        @endforeach
                        @if ($others !== [])
                            <li class="flex items-start gap-2.5">
                                <x-icon name="circle-x" class="mt-0.5 text-crit" />
                                <span class="min-w-0">
                                    {{ trans_choice(':count satır atlanacak', count($others), ['count' => Num::format(count($others))]) }}
                                    <span class="block text-2xs break-words text-muted">
                                        @foreach (array_slice($others, 0, 8) as $skip)
                                            <span class="font-mono">{{ $skip['text'] }}</span> {{ $skip['message'] }}@if (! $loop->last) · @endif
                                        @endforeach
                                        @if (count($others) > 8)
                                            · {{ __('ve :count satır daha', ['count' => Num::format(count($others) - 8)]) }}
                                        @endif
                                    </span>
                                </span>
                            </li>
                        @endif
                    </ul>
                </div>
            @endif

            <div class="grid gap-2 sm:grid-cols-[110px_1fr] sm:items-center">
                <span id="paste-duration">{{ __('Süre') }}</span>
                <div>
                    <div class="inline-flex flex-wrap divide-x divide-line overflow-hidden rounded-md border border-line text-[13.5px]" role="radiogroup" aria-labelledby="paste-duration">
                        @foreach (IpBlocks::DURATIONS as $m => $label)
                            <button type="button" role="radio" aria-checked="{{ $minutes === $m ? 'true' : 'false' }}" wire:click="$set('minutes', {{ $m }})"
                                    @class(['flex h-9 items-center px-3.5', 'bg-accent-soft font-medium text-accent' => $minutes === $m, 'hover:bg-surface-2' => $minutes !== $m])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="grid gap-2 sm:grid-cols-[110px_1fr] sm:items-start">
                <label for="paste-reason" class="sm:pt-2">{{ __('Neden') }}</label>
                <div>
                    <input id="paste-reason" wire:model="reason" maxlength="200" class="{{ $field }} {{ $errors->has('reason') ? 'border-crit' : 'border-line' }}">
                    @error('reason') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-2xs text-muted">{{ __('Listede ve geçmişte bu adreslerin yanında görünür.') }}</p>
                </div>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-line px-5 py-3">
            <span class="mr-auto min-w-0 basis-full text-2xs text-muted sm:basis-0 sm:flex-1">{{ __('Adreslerden gelen bütün bağlantılar (web, posta, SSH) reddedilir.') }}</span>
            <button type="button" wire:click="$set('pasting', false)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
            <button type="submit" wire:loading.attr="disabled" wire:target="saveBlocks, paste" @disabled($total === 0 || $over) class="{{ $danger }}">
                <x-icon name="ban" />{{ $total > 0 ? trans_choice(':count adresi engelle', $total, ['count' => Num::format($total)]) : __('Engelle') }}
            </button>
        </div>
    </form>
</x-modal>
