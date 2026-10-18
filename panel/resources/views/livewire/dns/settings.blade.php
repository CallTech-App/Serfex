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
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('dns.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Alan Adları ve DNS</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">Sunucu DNS ayarları</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">Sunucu DNS ayarları</h1>
        <p class="mt-1 text-[13px] text-muted">Yeni alan adlarının hangi ad sunucularını, adresleri ve kayıtları alacağını belirleyin. Mevcut bölgeler kendi kayıtlarını korur.</p>
    </div>

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <h2 class="text-[14.5px] font-semibold">Ad sunucuları ve adresler</h2>
            <div class="mt-2 divide-y divide-line">
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="nameservers" class="sm:pt-2">Ad sunucuları</label>
                    <div>
                        <input id="nameservers" wire:model="nameservers" placeholder="ns1.ornek.net.tr, ns2.ornek.net.tr" spellcheck="false" autocomplete="off"
                               class="{{ $field }} font-mono text-[13px] sm:w-96 {{ $errors->has('nameservers') ? 'border-crit' : 'border-line' }}">
                        @error('nameservers')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">
                                Boş bırakılırsa her alan adı kendi ad sunucularını kullanır, örneğin
                                <span class="font-mono">{{ collect($example)->map(fn ($n) => rtrim($n, '.'))->join(', ') }}</span>;
                                Servik bu adlar için bu sunucuyu gösteren A kayıtlarını ekler. Alan adı kayıt firmanızda aynı adları ad sunucusu olarak girin.
                            </p>
                        @enderror
                        <label class="mt-2 flex items-center gap-2 text-[13px]">
                            <input type="checkbox" wire:model="applyToZones" class="size-4 accent-accent">
                            Değişikliği eski ad sunucularını kullanan mevcut bölgelere de uygula
                        </label>
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="ipv4" class="sm:pt-2">Sunucu IPv4 adresi</label>
                    <div>
                        <input id="ipv4" wire:model="ipv4" list="ipv4-found" spellcheck="false" autocomplete="off" class="{{ $field }} font-mono text-[13px] sm:w-64 {{ $errors->has('ipv4') ? 'border-crit' : 'border-line' }}">
                        <datalist id="ipv4-found">@foreach ($detected['ipv4'] as $ip)<option value="{{ $ip }}">@endforeach</datalist>
                        @error('ipv4') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @else <p class="mt-1.5 text-2xs text-muted">Yeni bölgelerdeki A kayıtları (<span class="font-mono">&lt;ip4&gt;</span>) bu adresi gösterir.</p> @enderror
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="ipv6" class="sm:pt-2">Sunucu IPv6 adresi</label>
                    <div>
                        <input id="ipv6" wire:model="ipv6" list="ipv6-found" spellcheck="false" autocomplete="off" placeholder="Yok" class="{{ $field }} font-mono text-[13px] sm:w-80 {{ $errors->has('ipv6') ? 'border-crit' : 'border-line' }}">
                        <datalist id="ipv6-found">@foreach ($detected['ipv6'] as $ip)<option value="{{ $ip }}">@endforeach</datalist>
                        @error('ipv6') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @else <p class="mt-1.5 text-2xs text-muted">Boşsa yeni bölgelere AAAA kaydı eklenmez.</p> @enderror
                    </div>
                </div>
                <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="defaultTtl">Varsayılan TTL</label>
                    <select id="defaultTtl" wire:model.number="defaultTtl" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-64">
                        @foreach ($ttlChoices as $seconds => $label)
                            <option value="{{ $seconds }}">{{ $label }} ({{ $seconds }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid items-center gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>DNS sunucusu</span>
                    <p>
                        @if (($server['state'] ?? null) === 'ready')
                            PowerDNS {{ $server['version'] ?? '' }} · <span class="text-ok">çalışıyor</span>
                        @else
                            Durumu ve kurulumu <a href="{{ route('dns.index') }}" wire:navigate class="text-accent hover:underline">Alan Adları ve DNS</a> sayfasında.
                        @endif
                    </p>
                </div>
            </div>
            <details class="mt-2" @if ($errors->hasAny(['hostmaster', 'refresh', 'retry', 'expire', 'minimum'])) open @endif>
                <summary class="cursor-pointer py-2 text-[13px] text-accent">Gelişmiş (SOA varsayılanları)</summary>
                <div class="divide-y divide-line">
                    <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="hostmaster">Yönetici e-postası</label>
                        <div>
                            <input id="hostmaster" wire:model="hostmaster" placeholder="hostmaster@<alan adı>" spellcheck="false" class="{{ $field }} font-mono text-[13px] sm:w-80 {{ $errors->has('hostmaster') ? 'border-crit' : 'border-line' }}">
                            @error('hostmaster') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @foreach ([['refresh', 'retry', 'Yenileme / Yeniden deneme'], ['expire', 'minimum', 'Sona erme / Negatif TTL']] as [$a, $b, $label])
                        <div class="grid items-center gap-2 py-3 sm:grid-cols-[220px_1fr] sm:gap-6">
                            <label for="{{ $a }}">{{ $label }}</label>
                            <div>
                                <div class="flex items-center gap-2">
                                    <input id="{{ $a }}" wire:model.number="{{ $a }}" inputmode="numeric" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has($a) ? 'border-crit' : 'border-line' }}">
                                    <span class="text-muted">/</span>
                                    <input aria-label="{{ explode(' / ', $label)[1] }}" wire:model.number="{{ $b }}" inputmode="numeric" class="num h-9 w-28 rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has($b) ? 'border-crit' : 'border-line' }}">
                                    <span class="text-[13px] text-muted">sn</span>
                                </div>
                                @error($a) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                                @error($b) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
            <div class="mt-5">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">Kaydet</button>
            </div>
        </form>
    </section>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <h2 class="mr-auto text-[14.5px] font-semibold">Yeni alan adları için kayıt şablonu</h2>
            <button type="button" wire:click="restoreDefaults" class="h-9 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2">Varsayılana dön</button>
            <button type="button" wire:click="openAdd" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="plus" />Kayıt ekle
            </button>
        </div>
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[640px] table-fixed text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="w-[30%] py-2.5 pr-3 pl-5 font-medium">Ana makine</th>
                        <th class="w-20 px-3 py-2.5 font-medium">Tür</th>
                        <th class="px-3 py-2.5 font-medium">Değer</th>
                        <th class="w-24 px-3 py-2.5 font-medium">TTL</th>
                        <th class="w-32 px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr class="text-muted">
                        <td class="py-3 pr-3 pl-5 font-mono text-[13px]">&lt;domain&gt;</td>
                        <td class="px-3 py-3"><span class="rounded border border-line bg-surface-2 px-2 py-0.5 font-mono text-2xs font-medium">NS</span></td>
                        <td class="truncate px-3 py-3 text-[13px]">Ad sunucusu ayarından</td>
                        <td class="px-3 py-3">—</td>
                        <td></td>
                    </tr>
                    @forelse ($rows as $i => $row)
                        <tr wire:key="tpl-{{ $i }}-{{ md5(json_encode($row)) }}" class="hover:bg-surface-2/60">
                            <td class="truncate py-3 pr-3 pl-5 font-mono text-[13px]">{{ $row['name'] === '@' ? '<domain>' : $row['name'].'.<domain>' }}</td>
                            <td class="px-3 py-3"><span class="rounded border border-line bg-surface-2 px-2 py-0.5 font-mono text-2xs font-medium">{{ $row['type'] }}</span></td>
                            <td class="px-3 py-3"><span class="block truncate font-mono text-[13px]" title="{{ $row['content'] }}">@if (($row['priority'] ?? null) !== null)<span class="text-muted">{{ $row['priority'] }}</span> @endif{{ $row['content'] }}</span></td>
                            <td class="num px-3 py-3 text-muted">{{ isset($row['ttl']) ? DnsRecords::ttlLabel($row['ttl']) : 'Varsayılan' }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="openEdit({{ $i }})" class="text-[13px] text-muted hover:text-fg">Düzenle</button>
                                <button type="button" wire:click="deleteRow({{ $i }})" class="ml-3 text-[13px] text-muted hover:text-crit">Sil</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-6 text-center text-muted">Şablonda NS dışında kayıt yok; yeni bölgeler yalnızca ad sunucularıyla açılır.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">
            <span class="font-mono">&lt;domain&gt;</span> alan adıyla, <span class="font-mono">&lt;ip4&gt;</span> ve <span class="font-mono">&lt;ip6&gt;</span> yukarıdaki sunucu adresleriyle değiştirilir; adresi boş olan satır atlanır. E-posta kayıtları (MX, SPF, DKIM, DMARC) e-posta özelliğiyle birlikte gelecek.
        </p>
    </section>

    @include('livewire.dns.record-modal', ['suffix' => '.<domain>'])
</div>
