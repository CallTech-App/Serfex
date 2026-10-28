@php
    use App\Support\DnsRecords;
    $ttlChoices = DnsRecords::TTLS;
    if (! isset($ttlChoices[$defaultTtl])) {
        $ttlChoices = [$defaultTtl => DnsRecords::ttlLabel($defaultTtl)] + $ttlChoices;
    }
    $field = 'h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('dns.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />{{ __('Alan Adları ve DNS') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Sunucu DNS ayarları') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Sunucu DNS ayarları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Yeni alan adlarının hangi ad sunucularını, adresleri ve kayıtları alacağını belirleyin. Mevcut bölgeler kendi kayıtlarını korur.') }}</p>
    </div>

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <h2 class="text-[14.5px] font-semibold">{{ __('Ad sunucuları ve adresler') }}</h2>
            <div class="mt-2 divide-y divide-line">
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="nameservers" class="sm:pt-2">{{ __('Ad sunucuları') }}</label>
                    <div>
                        <input id="nameservers" wire:model.blur="nameservers" placeholder="{{ __('ns1.ornek.net.tr, ns2.ornek.net.tr') }}" spellcheck="false" autocomplete="off"
                               class="{{ $field }} font-mono text-[13px] sm:w-96 {{ $errors->has('nameservers') ? 'border-crit' : 'border-line' }}">
                        @error('nameservers')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">
                                <x-t text="Boş bırakılırsa her alan adı kendi ad sunucularını kullanır, örneğin :n; Serfex bu adlar için bu sunucuyu gösteren A kayıtlarını ekler. Alan adı kayıt firmanızda aynı adları ad sunucusu olarak girin."><x-slot:n><span class="font-mono">{{ collect($example)->map(fn ($n) => rtrim($n, '.'))->join(', ') }}</span></x-slot:n></x-t>
                            </p>
                        @enderror
                        <label class="mt-2 flex items-center gap-2 text-[13px]">
                            <input type="checkbox" wire:model="applyToZones" class="size-4 accent-accent">
                            {{ __('Değişikliği eski ad sunucularını kullanan mevcut bölgelere de uygula') }}
                        </label>
                    </div>
                </div>
                @if ($nameserverIps !== [])
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span class="sm:pt-2">{{ __('Ad sunucularının IP adresleri') }}</span>
                        <div class="space-y-2">
                            @foreach ($nameserverIps as $i => $row)
                                <div wire:key="ns-ip-{{ $row['host'] }}">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label for="ns-ip-{{ $i }}" class="w-full truncate font-mono text-[13px] sm:w-56">{{ $row['host'] }}</label>
                                        <input id="ns-ip-{{ $i }}" wire:model="nameserverIps.{{ $i }}.ip" placeholder="{{ $ipv4 }}" spellcheck="false" autocomplete="off"
                                               class="{{ $field }} font-mono text-[13px] sm:w-44 {{ $errors->has('nameserverIps.'.$i.'.ip') ? 'border-crit' : 'border-line' }}">
                                    </div>
                                    @error('nameserverIps.'.$i.'.ip')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                            <p class="text-2xs text-muted">{{ __('Boş bırakılan ad sunucusu bu sunucunun IPv4 adresini gösterir. Başka bir sunucudaki ya da sunucunun ikinci IP adresindeki ad sunucusu için o adresi yazın; bu sunucudaki bölgesinde A kaydı da o adrese taşınır.') }}</p>
                        </div>
                    </div>
                @endif
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="ipv4" class="sm:pt-2">{{ __('Sunucu IPv4 adresi') }}</label>
                    <div>
                        <input id="ipv4" wire:model="ipv4" list="ipv4-found" spellcheck="false" autocomplete="off" class="{{ $field }} font-mono text-[13px] sm:w-64 {{ $errors->has('ipv4') ? 'border-crit' : 'border-line' }}">
                        <datalist id="ipv4-found">@foreach ($detected['ipv4'] as $ip)<option value="{{ $ip }}">@endforeach</datalist>
                        @error('ipv4') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @else <p class="mt-1.5 text-2xs text-muted"><x-t text="Yeni bölgelerdeki A kayıtları (:part) bu adresi gösterir."><x-slot:part><span class="font-mono">&lt;ip4&gt;</span></x-slot:part></x-t></p> @enderror
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="ipv6" class="sm:pt-2">{{ __('Sunucu IPv6 adresi') }}</label>
                    <div>
                        <input id="ipv6" wire:model="ipv6" list="ipv6-found" spellcheck="false" autocomplete="off" placeholder="{{ __('Yok') }}" class="{{ $field }} font-mono text-[13px] sm:w-80 {{ $errors->has('ipv6') ? 'border-crit' : 'border-line' }}">
                        <datalist id="ipv6-found">@foreach ($detected['ipv6'] as $ip)<option value="{{ $ip }}">@endforeach</datalist>
                        @error('ipv6') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @else <p class="mt-1.5 text-2xs text-muted">{{ __('Boşsa yeni bölgelere AAAA kaydı eklenmez.') }}</p> @enderror
                    </div>
                </div>
                <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="defaultTtl">{{ __('Varsayılan TTL') }}</label>
                    <select id="defaultTtl" wire:model.number="defaultTtl" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-64">
                        @foreach ($ttlChoices as $seconds => $label)
                            <option value="{{ $seconds }}">{{ $label }} ({{ $seconds }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('DNS sunucusu') }}</span>
                    <p>
                        @if (($server['state'] ?? null) === 'ready')
                            PowerDNS {{ $server['version'] ?? '' }} · <span class="text-ok">{{ __('çalışıyor') }}</span>
                        @else
                            <x-t text="Durumu ve kurulumu :link sayfasında."><x-slot:link><a href="{{ route('dns.index') }}" wire:navigate class="text-accent hover:underline">{{ __('Alan Adları ve DNS') }}</a></x-slot:link></x-t>
                        @endif
                    </p>
                </div>
            </div>
            <details class="mt-2" @if ($errors->hasAny(['hostmaster', 'refresh', 'retry', 'expire', 'minimum'])) open @endif>
                <summary class="cursor-pointer py-2 text-[13px] text-accent">{{ __('Gelişmiş (SOA varsayılanları)') }}</summary>
                <div class="divide-y divide-line">
                    <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="hostmaster">{{ __('Yönetici e-postası') }}</label>
                        <div>
                            <input id="hostmaster" wire:model="hostmaster" placeholder="{{ __('hostmaster@<alan adı>') }}" spellcheck="false" class="{{ $field }} font-mono text-[13px] sm:w-80 {{ $errors->has('hostmaster') ? 'border-crit' : 'border-line' }}">
                            @error('hostmaster') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                        </div>
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
            <div class="mt-5">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </section>

    @php
        $sec = $secondaryState;
        $catalog = $sec['catalog'] ?? '';
        $primary = $ipv4 !== '' ? $ipv4 : ($detected['ipv4'][0] ?? '203.0.113.10');
    @endphp
    <section id="secondaries" class="rounded-xl border border-line bg-surface">
        <form wire:submit="saveSecondaries" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <h2 class="text-[14.5px] font-semibold">{{ __('İkincil ad sunucuları') }}</h2>
            <p class="mt-1 text-[13px] text-muted">{{ __('Bölgeleriniz başka bir DNS sunucusuna da kopyalanır; bu sunucu yanıt veremezse alan adları ikinci sunucudan çözülmeye devam eder.') }}</p>
            @if ($sec === null)
                <p class="mt-4 rounded-lg bg-surface-2 px-3 py-2 text-[13px]">
                    <x-t text="DNS sunucusu çalışmıyor ya da kurulmadı. Durumu ve kurulumu :link sayfasında."><x-slot:link><a href="{{ route('dns.index') }}" wire:navigate class="text-accent hover:underline">{{ __('Alan Adları ve DNS') }}</a></x-slot:link></x-t>
                </p>
            @else
                <div class="mt-2 divide-y divide-line">
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="secondaries-field" class="sm:pt-2">{{ __('İkincil sunucuların adresleri') }}</label>
                        <div>
                            <textarea id="secondaries-field" wire:model="secondaries" rows="3" spellcheck="false" placeholder="192.0.2.53&#10;2001:db8::53"
                                      class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent sm:w-80 {{ $errors->has('secondaries') ? 'border-crit' : 'border-line' }}"></textarea>
                            @error('secondaries')
                                <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                            @else
                                <p class="mt-1.5 text-2xs text-muted">{{ __('Her satıra bir IP adresi ya da ağ. Yalnızca bunlar bölgeleri aktarabilir (AXFR); tek adreslere her değişiklik anında bildirilir (NOTIFY), ağlar SOA yenileme süresinde denetler. Boşsa bölgeler kimseye aktarılmaz. Kaydedince DNS sunucusu bir iki saniyeliğine yeniden başlar.') }}</p>
                            @enderror
                        </div>
                    </div>
                    @if ($sec['addresses'] !== [])
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>{{ __('Durum') }}</span>
                            <p>
                                <span class="text-ok">{{ trans_choice(':count bölge aktarılabilir', $sec['zones']) }}</span>
                                @if ($sec['notify'] !== [])
                                    <span class="text-muted">· {{ __('bildirim:') }}</span> <span class="font-mono text-[13px]">{{ implode(', ', $sec['notify']) }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <span>{{ __('Katalog bölgesi') }}</span>
                            <div>
                                <span class="font-mono text-[13px] break-all">{{ $catalog }}</span>
                                <p class="mt-1 text-2xs text-muted">{{ __('Tüm alan adlarınızın listesi (RFC 9432). İkincil sunucu bu bölgeyi okursa yeni alan adlarını bir dakika içinde kendiliğinden ekler, silinenleri kaldırır.') }}</p>
                            </div>
                        </div>
                    @endif
                    <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>{{ __('Birincil sunucu adresi') }}</span>
                        <p><span class="font-mono text-[13px]">{{ $primary }}</span> <span class="text-2xs text-muted">{{ __('İkincil sunucuya bölgeleri buradan alacağını yazın.') }}</span></p>
                    </div>
                </div>
                <details class="mt-2">
                    <summary class="cursor-pointer py-2 text-[13px] text-accent">{{ __('İkincil sunucu nasıl ayarlanır?') }}</summary>
                    <ol class="mt-1 list-decimal space-y-3 pl-5 text-[13px]">
                        <li>{{ __('İkincil sunucunun adresini yukarıya yazıp kaydedin.') }}</li>
                        @if ($catalog === '')
                            <li>{{ __('Kaydettikten sonra burada bu sunucuya göre hazırlanmış BIND ve PowerDNS ayarları görünür.') }}</li>
                        @else
                        <li>
                            {{ __('BIND 9.18 ya da daha yenisi: aşağıdakileri named.conf.options içindeki options bölümüne ve named.conf.local dosyasına ekleyip BIND\'i yeniden yükleyin. Bölgeler ve sonradan eklenenler kendiliğinden gelir.') }}
                            <pre class="scroll-thin mt-1.5 overflow-x-auto rounded-md bg-surface-2 p-3 font-mono text-[12px] leading-5">// {{ __('named.conf.options, options bölümünün içine') }}
catalog-zones {
    zone "{{ $catalog }}" default-primaries { {{ $primary }}; };
};

// named.conf.local
zone "{{ $catalog }}" {
    type secondary;
    file "/var/cache/bind/{{ $catalog }}.db";
    primaries { {{ $primary }}; };
};</pre>
                        </li>
                        <li>
                            {{ __('PowerDNS 4.7 ya da daha yenisi: ayarlarda secondary=yes olsun, sonra şu iki komutu çalıştırın. Başka bir Serfex sunucusu şimdilik ikincil sunucu olamaz.') }}
                            <pre class="scroll-thin mt-1.5 overflow-x-auto rounded-md bg-surface-2 p-3 font-mono text-[12px] leading-5">pdnsutil create-secondary-zone {{ $catalog }} {{ $primary }}
pdnsutil set-kind {{ $catalog }} consumer</pre>
                        </li>
                        @endif
                        <li>{{ __('Katalog bölgesini desteklemeyen bir ikincil DNS hizmetinde her alan adını ikincil bölge olarak ekleyin ve birincil sunucu olarak yukarıdaki adresi girin; yeni alan adlarını orada da eklemeniz gerekir.') }}</li>
                        <li>{{ __('İkincil sunucunun adını alan adlarının NS kayıtlarına ve kayıt firmasındaki ad sunucularına ekleyin; yalnızca listelenen ad sunucuları sorgu alır.') }}</li>
                    </ol>
                </details>
                <div class="mt-5">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveSecondaries" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">
                        <x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="saveSecondaries" />{{ __('Kaydet ve uygula') }}
                    </button>
                </div>
            @endif
        </form>
    </section>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <h2 class="mr-auto text-[14.5px] font-semibold">{{ __('Yeni alan adları için kayıt şablonu') }}</h2>
            <button type="button" wire:click="restoreDefaults" class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Varsayılana dön') }}</button>
            <button type="button" wire:click="openAdd" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="plus" />{{ __('Kayıt ekle') }}
            </button>
        </div>
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[640px] table-fixed text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="w-[30%] py-2.5 pr-3 pl-5 font-medium">{{ __('Ana makine') }}</th>
                        <th class="w-20 px-3 py-2.5 font-medium">{{ __('Tür') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Değer') }}</th>
                        <th class="w-24 px-3 py-2.5 font-medium">{{ __('TTL') }}</th>
                        <th class="w-32 px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr class="text-muted">
                        <td class="py-3 pr-3 pl-5 font-mono text-[13px]">&lt;domain&gt;</td>
                        <td class="px-3 py-3"><span class="rounded border border-line bg-surface-2 px-2 py-0.5 font-mono text-2xs font-medium">NS</span></td>
                        <td class="truncate px-3 py-3 text-[13px]">{{ __('Ad sunucusu ayarından') }}</td>
                        <td class="px-3 py-3">—</td>
                        <td></td>
                    </tr>
                    @forelse ($rows as $i => $row)
                        <tr wire:key="tpl-{{ $i }}-{{ md5(json_encode($row)) }}" class="hover:bg-surface-2/60">
                            <td class="truncate py-3 pr-3 pl-5 font-mono text-[13px]">{{ $row['name'] === '@' ? '<domain>' : $row['name'].'.<domain>' }}</td>
                            <td class="px-3 py-3"><span class="rounded border border-line bg-surface-2 px-2 py-0.5 font-mono text-2xs font-medium">{{ $row['type'] }}</span></td>
                            <td class="px-3 py-3"><span class="block truncate font-mono text-[13px]" title="{{ $row['content'] }}">@if (($row['priority'] ?? null) !== null)<span class="text-muted">{{ $row['priority'] }}</span> @endif{{ $row['content'] }}</span></td>
                            <td class="num px-3 py-3 text-muted">{{ isset($row['ttl']) ? DnsRecords::ttlLabel($row['ttl']) : __('Varsayılan') }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="openEdit({{ $i }})" class="text-[13px] text-muted hover:text-fg">{{ __('Düzenle') }}</button>
                                <button type="button" wire:click="deleteRow({{ $i }})" class="ml-3 text-[13px] text-muted hover:text-crit">{{ __('Sil') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-6 text-center text-muted">{{ __('Şablonda NS dışında kayıt yok; yeni bölgeler yalnızca ad sunucularıyla açılır.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">
            <x-t text=":part alan adıyla, :part2 ve :part3 yukarıdaki sunucu adresleriyle değiştirilir; adresi boş olan satır atlanır. E-posta kayıtları (MX, SPF, DKIM, DMARC) e-posta özelliğiyle birlikte gelecek."><x-slot:part><span class="font-mono">&lt;domain&gt;</span></x-slot:part><x-slot:part2><span class="font-mono">&lt;ip4&gt;</span></x-slot:part2><x-slot:part3><span class="font-mono">&lt;ip6&gt;</span></x-slot:part3></x-t>
        </p>
    </section>

    @include('livewire.dns.record-modal', ['suffix' => '.<domain>'])
</div>
