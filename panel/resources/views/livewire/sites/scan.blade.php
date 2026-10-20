@php
    use App\Support\Badge;
    $tz = config('servik.timezone');
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
@endphp
<div class="space-y-6">
    @if ($running)
        <div wire:poll.3s hidden></div>
    @endif
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">Güvenlik taraması</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">Güvenlik taraması</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">Sitenin bütün dosyaları zararlı yazılım ve arka kapı (web shell), herkesin yazabildiği dosyalar, yanlış sahipler, internetten okunabilen .env, .git ve yedek dosyaları ile eski WordPress, Laravel ve PHP sürümleri için taranır. Yüklenen dosyalar da hemen denetlenir.</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-[13.5px]">
            @if ($running)
                <x-status-badge :status="new Badge('Taranıyor', 'info', true)" />
                <span class="text-muted">{{ $running->scope === 'server' ? 'Bütün sunucu taranıyor.' : 'Site taranıyor.' }}</span>
            @elseif ($last)
                <span class="text-muted">Son tarama {{ $last->finished_at?->timezone($tz)->format('d.m.Y H:i') }} · {{ $last->triggerLabel() }}</span>
            @else
                <span class="text-muted">Bu site henüz taranmadı. Sunucu her pazar 03:20'de taranır.</span>
            @endif
            <span class="ml-auto"></span>
            @if ($tab === 'bulgular')
                <select wire:model.live="findingStatus" class="{{ $field }} border-line" aria-label="Durum">
                    <option value="open">Açık ({{ $statusCounts['open'] ?? 0 }})</option>
                    <option value="fixed">Düzeltilen ({{ $statusCounts['fixed'] ?? 0 }})</option>
                    <option value="ignored">Yok sayılan ({{ $statusCounts['ignored'] ?? 0 }})</option>
                </select>
                <button type="button" wire:click="scan" @disabled($running) class="{{ $primary }}"><x-icon name="scan-search" />Şimdi tara</button>
            @else
                <button type="button" wire:click="openDeny" class="{{ $btn }}"><x-icon name="ban" />Yol engelle</button>
            @endif
        </div>
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach ($this::TABS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px h-11 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}{{ $key === 'engelli' && $denied ? ' ('.count($denied).')' : '' }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'bulgular')
            @include('livewire.security.findings', ['findings' => $findings, 'showSite' => false])
        @else
            <ul class="divide-y divide-line text-[13.5px]">
                @forelse ($denied as $path)
                    <li wire:key="deny-{{ md5($path) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-surface-2/60">
                        <span class="font-mono text-[13px]">{{ $path }}</span>
                        <span class="text-2xs text-muted">{{ str_ends_with($path, '/') ? 'klasör' : 'dosya' }} · ziyaretçiler 403 alır</span>
                        <button type="button" wire:click="allow(@js($path))" class="ml-auto text-[13px] text-muted hover:text-fg">Engeli kaldır</button>
                    </li>
                @empty
                    <li class="px-5 py-12 text-center">
                        <p class="font-medium">Engellenen yol yok</p>
                        <p class="mt-1 text-[13px] text-muted">Taramada internetten indirilebilen bir yedek ya da gizli dosya bulunursa "Erişimi engelle" ile buraya eklenir.</p>
                    </li>
                @endforelse
            </ul>
        @endif
    </section>

    <x-modal title="Yol engelle" show="denying">
        <form wire:submit="saveDeny">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Yol engelle</h2>
            <div class="p-5 text-[13.5px]">
                <label for="deny-path" class="mb-1.5 block">Yol</label>
                <input id="deny-path" wire:model="denyPath" placeholder="/yedek.sql ya da /eski/" spellcheck="false" class="{{ $field }} w-full font-mono text-[13px] {{ $errors->has('denyPath') ? 'border-crit' : 'border-line' }}">
                @error('denyPath')
                    <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                @else
                    <p class="mt-1.5 text-2xs text-muted">Sonu / ile biten yol bütün klasörü kapatır. Dosyalar silinmez, yalnızca internetten açılamaz.</p>
                @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('denying', false)" class="{{ $btn }}">Vazgeç</button>
                <button type="submit" class="{{ $primary }}">Engelle</button>
            </div>
        </form>
    </x-modal>
</div>
