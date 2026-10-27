{{-- In an install confirmation: the version that will be installed and where it comes from; for a database server, the versions Serfex offers to choose from. --}}
@php($candidate = $confirm['candidate'] ?? null)
@if ($candidate !== null && ($candidate['choices'] ?? []) !== [])
    <fieldset class="mt-3">
        <legend class="mb-2 text-[13px] font-medium">{{ __('Kurulacak sürüm') }}</legend>
        <div class="grid gap-2">
            @foreach ($candidate['choices'] as $c)
                <label class="flex cursor-pointer gap-3 rounded-lg border p-3 {{ $installVersion === $c['major'] ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2' }}">
                    <input type="radio" wire:model.live="installVersion" value="{{ $c['major'] }}" class="mt-0.5 size-4 accent-accent">
                    <span class="min-w-0">
                        <span class="block font-medium">{{ $c['name'] }}@if ($c['default'])<span class="ml-1.5 rounded bg-surface-2 px-1.5 py-0.5 text-2xs font-normal text-muted">{{ __('varsayılan') }}</span>@endif</span>
                        <span class="mt-0.5 block text-[12.5px] text-muted">
                            @if ($c['version'])
                                <span class="font-mono break-words">{{ $c['version'] }}</span>
                            @else
                                {{ __('Paket sürümü henüz bilinmiyor; "Güncellemeleri denetle" ile öğrenilir.') }}
                            @endif
                            @if ($c['source'])
                                · {{ $c['source'] }}
                            @endif
                        </span>
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>
@elseif ($candidate !== null)
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border border-line bg-surface-2/60 px-3 py-2.5 text-[13px]">
        <dt class="text-muted">{{ __('Kurulacak sürüm') }}</dt>
        @if ($candidate['version'])
            <dd class="min-w-0 font-mono break-words">{{ $candidate['version'] }}</dd>
        @else
            <dd class="min-w-0 text-muted">{{ __('Henüz bilinmiyor; "Güncellemeleri denetle" ile öğrenilir.') }}</dd>
        @endif
        @if ($candidate['source'])
            <dt class="text-muted">{{ __('Kaynak') }}</dt>
            <dd class="min-w-0 break-words">{{ $candidate['source'] }}</dd>
        @endif
    </dl>
@endif
