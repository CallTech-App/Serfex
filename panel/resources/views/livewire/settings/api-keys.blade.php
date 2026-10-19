@php
    use App\Livewire\Settings\ApiKeyIndex;
    use App\Models\ApiKey;
    use App\Support\When;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
@endphp
<x-settings-page current="settings.api" title="API anahtarları" description="Faturalama sisteminiz, betikleriniz ya da izleme aracınız paneli bu anahtarlarla kullanır. Bir anahtar, onu oluşturan tam yöneticinin adına çalışır.">
    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openCreate" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="plus" />Anahtar oluştur
            </button>
            <span class="ml-auto font-mono text-[12.5px] text-muted">{{ $baseUrl }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[680px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">Ad</th>
                        <th class="px-3 py-2.5 font-medium">Yetki</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">Son kullanım</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Geçerlilik</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($keys as $key)
                        <tr wire:key="key-{{ $key->id }}" @class(['hover:bg-surface-2/60', 'text-muted' => ! $key->isUsable()])>
                            <td class="py-3 pr-3 pl-5">
                                <span class="font-medium">{{ $key->name }}</span>
                                <span class="block font-mono text-[12.5px] text-muted">{{ $key->prefix }}…</span>
                                <span class="block text-2xs text-muted">{{ $key->user?->name ?? 'Kaldırılmış yönetici' }}@if ($key->allowed_ips) · yalnızca <span class="font-mono">{{ implode(', ', $key->allowed_ips) }}</span>@endif</span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">{{ $key->scopeName() }}</td>
                            <td class="num hidden px-3 py-3 whitespace-nowrap text-muted md:table-cell">
                                @if ($key->last_used_at)
                                    {{ When::withTime($key->last_used_at) }}
                                    <span class="block font-mono text-[12.5px]">{{ $key->last_used_ip }}</span>
                                @else
                                    Hiç kullanılmadı
                                @endif
                            </td>
                            <td class="hidden px-3 py-3 whitespace-nowrap sm:table-cell">
                                @if ($key->revoked_at)
                                    <x-status-badge :status="new \App\Support\Badge('İptal edildi', 'muted')" />
                                @elseif ($key->expires_at?->isPast())
                                    <x-status-badge :status="new \App\Support\Badge('Süresi doldu', 'warn')" />
                                @elseif ($key->expires_at)
                                    <span class="num text-muted">{{ When::short($key->expires_at) }} tarihine kadar</span>
                                @else
                                    <span class="text-muted">Süresiz</span>
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                @if (! $key->revoked_at)
                                    <button type="button" wire:click="confirmRevoke({{ $key->id }})" class="text-[13px] text-crit hover:underline">İptal et</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center">
                                <p class="font-medium">Henüz API anahtarı yok</p>
                                <p class="mt-1 text-[13px] text-muted">Siteleri, müşterileri ve DNS kayıtlarını başka bir sistemden yönetmek için bir anahtar oluşturun.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-1 border-t border-line px-5 py-3 text-2xs text-muted">
            <p>İstekler <span class="font-mono">Authorization: Bearer &lt;anahtar&gt;</span> başlığıyla gönderilir. Anahtar başına dakikada {{ \App\Services\Settings\ApiKeys::PER_MINUTE }} istek. Yazma istekleri İşlem günlüğüne "API" olarak düşer.</p>
            <p>Uç noktalar: <span class="font-mono">GET /server · GET|POST /sites · GET|DELETE /sites/{alan-adı} · GET|POST /customers · POST /customers/{id}/suspend · GET /packages · GET /dns/zones · POST /dns/zones/{alan-adı}/records</span></p>
        </div>
    </section>

    {{-- Anahtar oluştur --}}
    <x-modal title="Anahtar oluştur" show="creating">
        <form wire:submit="create">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Anahtar oluştur</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div class="grid items-start gap-x-4 gap-y-1.5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                    <label for="key-name" class="sm:pt-2">Ad</label>
                    <div>
                        <input id="key-name" wire:model="name" maxlength="80" required placeholder="Faturalama sistemi" class="{{ $field }} {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                        @error('name') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>

                    <span class="mt-2.5 sm:mt-0 sm:pt-1">Yetki</span>
                    <div class="space-y-2">
                        @foreach (ApiKey::SCOPES as $key => [$label, $help])
                            <label class="flex items-start gap-2.5">
                                <input type="radio" wire:model="scope" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                                <span>{{ $label }}<span class="block text-2xs text-muted">{{ $help }}</span></span>
                            </label>
                        @endforeach
                    </div>

                    <label for="key-expires" class="mt-2.5 sm:mt-0 sm:pt-2">Geçerlilik</label>
                    <select id="key-expires" wire:model="expires" class="{{ $field }} border-line sm:w-40">
                        @foreach (ApiKeyIndex::EXPIRY as $days => $label)
                            <option value="{{ $days }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <label for="key-ips" class="mt-2.5 sm:mt-0 sm:pt-2">İzinli IP'ler</label>
                    <div>
                        <input id="key-ips" wire:model="ips" spellcheck="false" autocomplete="off" placeholder="boş: her yerden" class="{{ $field }} font-mono {{ $errors->has('ips') ? 'border-crit' : 'border-line' }}">
                        @error('ips')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">Virgülle ayırın; aralık da olur, örneğin 203.0.113.0/24.</p>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Oluştur</button>
            </div>
        </form>
    </x-modal>

    {{-- İptal et --}}
    @php $revoking = $revokingId ? $keys->firstWhere('id', $revokingId) : null; @endphp
    <div x-data x-show="$wire.revokingId !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.revokingId = null">
        <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="Anahtarı iptal et">
            <p class="font-semibold">{{ $revoking?->name }} iptal edilsin mi?</p>
            <p class="mt-1 text-[13px] text-muted">Bu anahtarı kullanan sistemler hemen erişimini kaybeder. {{ \App\Services\Settings\ApiKeys::UNDO_MINUTES }} dakika içinde geri alabilirsiniz.</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('revokingId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="button" wire:click="revoke" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">İptal et</button>
            </div>
        </div>
    </div>

    {{-- Yeni anahtar bir kez gösterilir --}}
    @if ($created)
        <div x-data class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.set('created', null)">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Yeni API anahtarı">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $created['name'] }}</h2>
                <div class="space-y-3 p-5 text-[13.5px]">
                    <p>Anahtarı şimdi kopyalayıp güvenli bir yere koyun:</p>
                    <div class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                        <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]">{{ $created['key'] }}</span>
                        <button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($created['key'])); done = true; setTimeout(() => done = false, 1500)" class="shrink-0 text-[12.5px] text-accent hover:underline">
                            <span x-show="!done">Kopyala</span><span x-show="done" x-cloak>Kopyalandı</span>
                        </button>
                    </div>
                    <p class="text-2xs text-muted">Bir daha gösterilmez; kaybederseniz iptal edip yenisini oluşturun.</p>
                    <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3 font-mono text-[12px]">curl -H "Authorization: Bearer {{ \Illuminate\Support\Str::limit($created['key'], 14, '…') }}" {{ $baseUrl }}/sites</pre>
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('created', null)" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Tamam</button>
                </div>
            </div>
        </div>
    @endif
</x-settings-page>
