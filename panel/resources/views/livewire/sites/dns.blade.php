<div class="space-y-6">
    <x-site-header :site="$site" active="domains" :tool="__('DNS kayıtları')" />
    @include('livewire.sites.partials.domains-nav', ['current' => 'dns'])

    @if ($zone)
        @php $nameservers = $zone->nameservers(); @endphp
        <p class="text-[13px] text-muted">
            @if ($zone->name !== $site->domain)
                <x-t text="Bu sitenin kayıtları üst alan adının bölgesinde duruyor: :zone."><x-slot:zone><span class="font-mono text-fg">{{ $zone->name }}</span></x-slot:zone></x-t>
            @else
                <x-t text=":zone bölgesinin kayıtları. Değişiklikler hemen yayınlanır."><x-slot:zone><span class="font-mono text-fg">{{ $zone->name }}</span></x-slot:zone></x-t>
            @endif
            @if ($nameservers)
                {{ __('Ad sunucuları:') }}
                @foreach ($nameservers as $ns)
                    <span class="font-mono text-fg">{{ rtrim($ns, '.') }}</span>{{ $loop->last ? '' : ',' }}
                @endforeach
            @endif
        </p>
        @livewire('dns.zone-show', ['zone' => $zone->id, 'siteId' => $site->id], key('zone-'.$zone->id))
    @else
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="mx-auto flex max-w-xl flex-col items-center px-6 py-10 text-center">
                <span class="grid size-12 place-items-center rounded-xl bg-surface-2 text-muted"><x-icon name="network" class="size-6" /></span>
                <h1 class="mt-4 text-[16px] font-semibold">{{ __('Bu alan adının DNS\'i bu sunucuda tutulmuyor') }}</h1>
                <p class="mt-1.5 text-[13.5px] text-muted"><x-t text=":domain için kayıtlar başka bir yerde (alan adını aldığınız firma ya da Cloudflare gibi bir hizmet) yönetiliyor. Orada aşağıdaki kayıtların olması yeter; isterseniz DNS'i Serfex'e de taşıyabilirsiniz."><x-slot:domain><span class="font-mono text-fg">{{ $site->domain }}</span></x-slot:domain></x-t></p>
                <button type="button" wire:click="createZone" wire:loading.attr="disabled" class="mt-5 flex h-9 items-center gap-2 rounded-md bg-accent px-4 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">
                    <x-icon name="plus" />{{ __('DNS bölgesi oluştur') }}
                </button>
                <p class="mt-3 text-[12.5px] text-muted">{{ __('Bölge site, e-posta ve SSL kayıtlarıyla oluşturulur. Çalışması için alan adının ad sunucularını bu sunucuya çevirmeniz gerekir.') }}</p>
            </div>
            @if ($needed)
                <div class="border-t border-line">
                    <h2 class="px-5 pt-4 text-[13.5px] font-semibold">{{ __('Sitenin açılması için gereken kayıtlar') }}</h2>
                    <div class="relative overflow-x-auto">
                        <table class="mt-2 w-full text-[13.5px] sm:min-w-[520px]">
                            <thead>
                                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                    <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Ad') }}</th>
                                    <th class="px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                                    <th class="px-3 py-2.5 pr-5 font-medium">{{ __('Değer') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($needed as [$name, $type, $value])
                                    <tr>
                                        <td class="py-2.5 pr-3 pl-5 font-mono text-[13px] break-all">{{ $name }}</td>
                                        <td class="px-3 py-2.5"><span class="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[12px]">{{ $type }}</span></td>
                                        <td class="px-3 py-2.5 pr-5 font-mono text-[13px] break-all">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
