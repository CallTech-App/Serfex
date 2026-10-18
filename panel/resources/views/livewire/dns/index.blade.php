<div class="space-y-6">
    {{-- Refresh while the agent works on a zone or installs PowerDNS. --}}
    @if ($busy)
        <div wire:poll.2s hidden></div>
    @endif
    @if ($server['state'] === 'installing')
        <div wire:poll.3s="refreshServer" hidden></div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-semibold tracking-tight">Alan Adları ve DNS</h1>
            <p class="mt-1 text-[13px] text-muted">Sunucuda barındırılan alan adları. Kayıtlarını görmek için bir alan adına tıklayın.</p>
        </div>
        <a href="{{ route('dns.settings') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
            <x-icon name="settings" />Sunucu DNS ayarları
        </a>
    </div>

    {{-- PowerDNS state, shown only when it needs attention. --}}
    @if ($server['state'] !== 'ready')
        @php
            [$tone, $title, $text, $action] = match ($server['state']) {
                'installing' => ['info', 'DNS sunucusu kuruluyor', 'PowerDNS kuruluyor ve başlatılıyor. Bitince bekleyen bölgeler otomatik yayınlanır.', null],
                'missing' => ['warn', 'DNS sunucusu kurulu değil', 'Bölgeleriniz panelde duruyor ama bu sunucu henüz DNS sorgularını yanıtlamıyor. Servik, PowerDNS\'i kurup yapılandırabilir.', 'DNS sunucusunu kur'],
                'stopped' => ['crit', 'DNS sunucusu yanıt vermiyor', 'PowerDNS yapılandırılmış ama çalışmıyor. Servik yapılandırmayı denetleyip yeniden başlatabilir.', 'Onar ve başlat'],
                'failed' => ['crit', 'DNS sunucusu kurulamadı', 'Önceki ayarlar geri yüklendi.', 'Tekrar dene'],
                default => ['crit', 'Sunucu ajanına ulaşılamıyor', 'Sunucuda "systemctl status servik-agent" ile kontrol edin.', null],
            };
        @endphp
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span @class(["absolute inset-y-0 left-0 w-1", "bg-info" => $tone === "info", "bg-warn" => $tone === "warn", "bg-crit" => $tone === "crit"])></span>
            @if ($server['state'] === 'installing')
                <x-icon name="loader-circle" class="animate-spin text-info" />
            @else
                <x-icon name="triangle-alert" @class(["text-warn" => $tone === "warn", "text-crit" => $tone === "crit"]) />
            @endif
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ $title }}</p>
                <p class="mt-0.5 text-[13px] text-muted">{{ $text }}</p>
                @if (! empty($server['error']))
                    <p class="mt-1 font-mono text-[12.5px] break-words text-muted">{{ $server['error'] }}</p>
                @endif
            </div>
            @if ($action)
                <button type="button" wire:click="setupServer" class="flex h-8 items-center gap-2 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110">
                    {{ $action }}
                </button>
            @endif
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openCreate" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="plus" />Alan adı ekle
            </button>
            <button type="button" wire:click="confirmDelete" @disabled($selected === [])
                    class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40">
                Sil{{ $selected ? ' ('.count($selected).')' : '' }}
            </button>
            <div class="ml-auto flex w-full gap-2 sm:w-auto">
                <select wire:model.live="filter" aria-label="Durum" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                    <option value="">Tümü</option>
                    <option value="problem">Sorunlu olanlar</option>
                </select>
                <label class="relative flex-1 sm:w-60 sm:flex-none">
                    <span class="sr-only">Alan adı ara</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Alan adı ara"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>
        </div>

        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[720px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="w-10 py-2.5 pl-5"><span class="sr-only">Seç</span></th>
                        <th class="px-3 py-2.5 font-medium">Alan adı</th>
                        <th class="px-3 py-2.5 font-medium">Site</th>
                        <th class="px-3 py-2.5 text-right font-medium">Kayıt</th>
                        <th class="px-3 py-2.5 font-medium">DNS durumu</th>
                        <th class="py-2.5 pr-5 pl-3 font-medium">Son değişiklik</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($zones as $zone)
                        <tr wire:key="zone-{{ $zone->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pl-5">
                                <input type="checkbox" wire:model.live="selected" value="{{ $zone->id }}" aria-label="{{ $zone->name }} seç" class="size-4 accent-accent">
                            </td>
                            <td class="px-3 py-3 font-mono text-[13px] font-medium">
                                <a href="{{ route('dns.show', $zone) }}" wire:navigate class="hover:text-accent">{{ $zone->name }}</a>
                            </td>
                            <td class="px-3 py-3 text-muted">
                                @if ($zone->site)
                                    <a href="{{ route('sites.show', $zone->site) }}" wire:navigate class="font-mono text-[13px] hover:text-accent">{{ $zone->site->domain }}</a>
                                @else
                                    Yalnızca DNS
                                @endif
                            </td>
                            <td class="num px-3 py-3 text-right">{{ $zone->records_count }}</td>
                            <td class="px-3 py-3">
                                <x-status-badge :status="$zone->status" :title="$zone->last_error" />
                            </td>
                            <td class="num py-3 pr-5 pl-3 whitespace-nowrap text-muted">{{ $zone->updated_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                @if ($search !== '' || $filter !== '')
                                    <p class="text-muted">Aramanızla eşleşen alan adı yok.</p>
                                @else
                                    <p class="font-medium">Henüz alan adı yok</p>
                                    <p class="mt-1 text-[13px] text-muted">Site eklediğinizde DNS bölgesi otomatik açılır. Yalnızca DNS barındırmak için alan adı da ekleyebilirsiniz.</p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($zones->total() > 0)
            <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                <span>{{ $zones->firstItem() }}–{{ $zones->lastItem() }} / {{ $zones->total() }} alan adı</span>
                @if ($zones->hasPages())
                    <span class="ml-auto flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($zones->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Önceki</button>
                        <span class="px-2">Sayfa {{ $zones->currentPage() }} / {{ $zones->lastPage() }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $zones->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">Sonraki</button>
                    </span>
                @endif
            </div>
        @endif
    </section>

    {{-- Alan adı ekle --}}
    <x-modal title="Alan adı ekle" show="creating">
        <form wire:submit="create">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Alan adı ekle</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="domain" class="mb-1.5 block">Alan adı</label>
                    <input id="domain" wire:model="domain" required placeholder="ornek.com.tr" autocomplete="off" spellcheck="false"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('domain') ? 'border-crit' : 'border-line' }}">
                    @error('domain') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="start" class="mb-1.5 block">Başlangıç kayıtları</label>
                    <select id="start" wire:model="start" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                        <option value="template">Sunucu şablonu (A, AAAA, www ve ad sunucuları)</option>
                        <option value="empty">Boş bölge (yalnızca NS ve SOA)</option>
                    </select>
                    <p class="mt-1.5 text-2xs text-muted">Siteler bu alan adını kullandığında bölge otomatik bağlanır.</p>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="create" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Ekle</button>
            </div>
        </form>
    </x-modal>

    {{-- Silme onayı --}}
    <x-modal title="DNS bölgelerini sil" show="confirmingDelete" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">Seçili DNS bölgeleri silinsin mi?</p>
            <p class="mt-1 text-[13px] text-muted">
                <span class="font-mono">{{ $selectedZones->pluck('name')->join(', ') }}</span>
                için DNS bölgesi ve tüm kayıtları silinir; bu alan adlarındaki siteler ve posta, DNS'i başka yerde barındırılmıyorsa erişilemez olur.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmingDelete', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="button" wire:click="deleteSelected" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">Sil</button>
            </div>
        </div>
    </x-modal>
</div>
