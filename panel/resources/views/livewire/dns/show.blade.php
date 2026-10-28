@php
    use App\Support\DnsRecords;
    $nameservers = $zone->nameservers();
    $ttlChoices = DnsRecords::TTLS;
    if (! isset($ttlChoices[$defaultTtl])) {
        $ttlChoices = [$defaultTtl => DnsRecords::ttlLabel($defaultTtl)] + $ttlChoices;
    }
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on the zone. --}}
    @if ($zone->status->isBusy())
        <div wire:poll.2s="refreshStatus" hidden></div>
    @endif

    {{-- Title --}}
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('dns.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Alan Adları ve DNS') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="font-mono text-fg">{{ $zone->name }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $zone->name }}</h1>
                <x-status-badge :status="$zone->status" />
            </div>
            @if ($zone->site)
                <a href="{{ route('sites.show', $zone->site) }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                    <x-icon name="globe" />{{ __('Siteye git') }}
                </a>
            @endif
        </div>
        <p class="mt-1 text-[13px] text-muted">
            {{ __('Bu alan adının DNS kayıtlarını ve bölge ayarlarını yönetin.') }}
            @if ($nameservers)
                {{ __('Ad sunucuları:') }}
                @foreach ($nameservers as $ns)
                    <span class="font-mono">{{ rtrim($ns, '.') }}</span>{{ $loop->last ? '' : ',' }}
                @endforeach
            @endif
        </p>
    </div>

    @if ($zone->status === \App\Enums\ZoneStatus::Failed)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Son değişiklik DNS sunucusuna uygulanamadı. Sunucu önceki kayıtları yayınlamaya devam ediyor.') }}</p>
                <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $zone->last_error }}</p>
            </div>
            <button type="button" wire:click="retry" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="refresh-cw" class="size-3.5" />{{ __('Tekrar dene') }}
            </button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (['records' => __('Kayıtlar'), 'settings' => __('Ayarlar')] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px h-12 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'records')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="openAdd" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />{{ __('Kayıt ekle') }}
                </button>
                <button type="button" wire:click="deleteSelected" @disabled($selected === [])
                        class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                    {{ __('Sil') }}{{ $selected ? ' ('.count($selected).')' : '' }}
                </button>
                <button type="button" wire:click="$set('confirmingReset', true)" class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Varsayılana dön') }}</button>
                <div class="ml-auto flex w-full gap-2 sm:w-auto">
                    <select wire:model.live="type" aria-label="{{ __('Tür') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                        <option value="">{{ __('Tüm türler') }}</option>
                        @foreach (DnsRecords::TYPES as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                    <label class="relative flex-1 sm:w-56 sm:flex-none">
                        <span class="sr-only">{{ __('Kayıt ara') }}</span>
                        <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Ara') }}"
                               class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                    </label>
                </div>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[720px] table-fixed text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="w-10 py-2.5 pl-5"><span class="sr-only">{{ __('Seç') }}</span></th>
                            <th class="w-[30%] px-3 py-2.5 font-medium">{{ __('Ana makine') }}</th>
                            <th class="w-20 px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Değer') }}</th>
                            <th class="w-24 px-3 py-2.5 font-medium">{{ __('TTL') }}</th>
                            <th class="w-24 px-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($records as $r)
                            @php $fqdn = DnsRecords::fqdn($r->name, $zone->name); @endphp
                            <tr wire:key="rec-{{ $r->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pl-5"><input type="checkbox" wire:model.live="selected" value="{{ $r->id }}" aria-label="{{ __(':fqdn :type seç', ['fqdn' => $fqdn, 'type' => $r->type]) }}" class="size-4 accent-accent"></td>
                                <td class="truncate px-3 py-3 font-mono text-[13px]" title="{{ $fqdn }}">
                                    @if ($r->name === '@')
                                        {{ $zone->name }}
                                    @else
                                        {{ $r->name }}<span class="text-muted">.{{ $zone->name }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3"><span class="rounded border border-line bg-surface-2 px-2 py-0.5 font-mono text-2xs font-medium">{{ $r->type }}</span></td>
                                <td class="px-3 py-3">
                                    <span class="block truncate font-mono text-[13px]" title="{{ $r->content }}">
                                        @if ($r->priority !== null)<span class="text-muted">{{ $r->priority }}</span> @endif{{ $r->content }}
                                    </span>
                                </td>
                                <td class="num px-3 py-3 text-muted">{{ DnsRecords::ttlLabel($r->ttl) }}</td>
                                <td class="px-3 py-3 text-right"><button type="button" wire:click="openEdit({{ $r->id }})" class="text-[13px] text-muted hover:text-fg">{{ __('Düzenle') }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-8 text-center text-muted">{{ __('Eşleşen kayıt yok.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="num border-t border-line px-5 py-3 text-2xs text-muted">
                {{ __(':records / :records2 kayıt · son değişiklik :updated_at', ['records' => $records->count(), 'records2' => $zone->records->count(), 'updated_at' => \App\Support\When::dateTime($zone->updated_at)]) }}
                @if ($zone->serial) {{ __('· seri :serial', ['serial' => $zone->serial]) }} @endif
            </p>
        @else
            <form wire:submit="saveSettings" class="max-w-3xl px-5 py-5 text-[13.5px]">
                <div class="divide-y divide-line">
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>{{ __('Ad sunucuları') }}</span>
                        <p class="font-mono text-[13px] leading-6">
                            @forelse ($nameservers as $ns) {{ rtrim($ns, '.') }}<br> @empty <span class="text-muted">{{ __('NS kaydı yok') }}</span> @endforelse
                        </p>
                    </div>
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="defaultTtl">{{ __('Varsayılan TTL') }}</label>
                        <div>
                            <select id="defaultTtl" wire:model.number="defaultTtl" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-64">
                                @foreach ($ttlChoices as $seconds => $label)
                                    <option value="{{ $seconds }}">{{ $label }} ({{ $seconds }})</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-2xs text-muted">{{ __('Yeni kayıtların ve SOA kaydının TTL\'si.') }}</p>
                        </div>
                    </div>
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span class="sm:pt-0.5">{{ __('DNSSEC') }}</span>
                        <div>
                            <label class="flex items-center gap-2.5 text-muted"><input type="checkbox" disabled class="size-4">{{ __('Bölgeyi imzala') }}</label>
                            <p class="mt-1 text-2xs text-muted">{{ __('Sonraki sürümde gelecek.') }}</p>
                        </div>
                    </div>
                </div>
                <details class="mt-2" @if ($errors->hasAny(['hostmaster', 'refresh', 'retry', 'expire', 'minimum'])) open @endif>
                    <summary class="cursor-pointer py-2 text-[13px] text-accent">{{ __('Gelişmiş (SOA)') }}</summary>
                    <div class="divide-y divide-line">
                        <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>{{ __('Birincil ad sunucusu') }}</span>
                            <span class="font-mono text-[13px]">{{ $nameservers[0] ?? '—' }}</span>
                        </div>
                        <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <label for="hostmaster">{{ __('Yönetici e-postası') }}</label>
                            <div>
                                <input id="hostmaster" wire:model="hostmaster" spellcheck="false" class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent sm:w-80 {{ $errors->has('hostmaster') ? 'border-crit' : 'border-line' }}">
                                @error('hostmaster') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>{{ __('Seri numarası') }}</span>
                            <span class="num font-mono text-[13px]">{{ $zone->serial ?? '—' }}</span>
                        </div>
                        @foreach ([['refresh', 'retry', __('Yenileme / Yeniden deneme')], ['expire', 'minimum', __('Sona erme / Negatif TTL')]] as [$a, $b, $label])
                            <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                                <label for="{{ $a }}">{{ $label }}</label>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <input id="{{ $a }}" wire:model.number="{{ $a }}" inputmode="numeric" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has($a) ? 'border-crit' : 'border-line' }}">
                                        <span class="text-muted">/</span>
                                        <input aria-label="{{ explode(' / ', $label)[1] }}" wire:model.number="{{ $b }}" inputmode="numeric" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has($b) ? 'border-crit' : 'border-line' }}">
                                        <span class="text-[13px] text-muted">{{ __('sn') }}</span>
                                    </div>
                                    @error($a) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                                    @error($b) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
                    <button type="button" wire:click="export" class="flex h-9 items-center gap-2 rounded-md border border-line px-3.5 hover:bg-surface-2 sm:ml-auto">
                        <x-icon name="download" />{{ __('Bölge dosyasını dışa aktar') }}
                    </button>
                </div>
            </form>
        @endif
    </section>

    @include('livewire.dns.record-modal', ['suffix' => '.'.$zone->name])

    {{-- Varsayılana dön --}}
    <x-modal :title="__('Varsayılana dön')" show="confirmingReset" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ __('DNS bölgesi varsayılana dönsün mü?') }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Elle eklenen ve değiştirilen tüm kayıtlar silinir; sunucu şablonundaki kayıtlar yeniden yazılır. Hemen ardından "Geri al" ile eski kayıtlara dönebilirsiniz.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmingReset', false)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="resetToTemplate" class="h-9 rounded-md bg-crit px-3.5 font-medium text-on-crit">{{ __('Varsayılana dön') }}</button>
            </div>
        </div>
    </x-modal>
</div>
