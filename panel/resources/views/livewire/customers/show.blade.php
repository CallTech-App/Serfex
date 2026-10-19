@php
    use App\Services\Customers\Quotas;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $worst = Quotas::worst($report);
    $siteCount = $accounts->sum(fn ($a) => $a->sites->count());
@endphp
<div class="space-y-6">
    {{-- Title --}}
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('customers.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Müşteriler ve Paketler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="truncate text-fg">{{ $customer->displayName() }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate text-[22px] font-semibold tracking-tight">{{ $customer->displayName() }}</h1>
                <x-customer-status :customer="$customer" />
                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="Diğer işlemler"><x-icon name="ellipsis-vertical" /></button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full left-0 z-20 mt-1 w-52 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                        @if ($customer->suspended_at)
                            <button type="button" wire:click="unsuspend" @click="open = false" class="w-full px-3 py-2 text-left hover:bg-surface-2">Etkinleştir</button>
                        @else
                            <button type="button" wire:click="openSuspend" @click="open = false" class="w-full px-3 py-2 text-left hover:bg-surface-2">Askıya al</button>
                        @endif
                        <button type="button" wire:click="openDelete" @click="open = false" class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2">Müşteriyi sil</button>
                    </div>
                </div>
            </div>
            @if ($customer->user)
                <form method="POST" action="{{ route('customers.impersonate', $customer) }}">
                    @csrf
                    <button type="submit" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                        <x-icon name="log-in" />Müşteri olarak giriş yap
                    </button>
                </form>
            @endif
        </div>
        <p class="mt-1 text-[13px] text-muted">
            {{ $customer->company ? $customer->name.' · ' : '' }}{{ $customer->email }} · <span class="font-mono">{{ $customer->username }}</span> · {{ $customer->package->name }} paketi
        </p>
    </div>

    @if ($customer->suspended_at)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-warn"></span>
            <x-icon name="circle-pause" class="text-warn" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Bu müşteri {{ $customer->suspended_at->timezone(config('servik.timezone'))->format('d.m.Y H:i') }} tarihinde askıya alındı</p>
                <p class="mt-0.5 text-[13px] text-muted">
                    {{ $customer->suspend_reason ? 'Neden: '.$customer->suspend_reason.'. ' : '' }}Siteleri askı sayfası gösteriyor, postası bekletiliyor ve panele giremiyor. Verileri silinmedi.
                </p>
            </div>
            <button type="button" wire:click="unsuspend" class="flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
                <x-icon name="circle-play" class="size-3.5" />Etkinleştir
            </button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (['kullanim' => 'Kullanım', 'siteler' => 'Siteler ('.$siteCount.')', 'bilgiler' => 'Bilgiler ve paket'] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="-mb-px flex h-12 items-center gap-1.5 border-b-2 whitespace-nowrap {{ $tab === $key ? 'border-accent font-medium text-fg' : 'border-transparent text-muted hover:text-fg' }}">
                    {{ $label }}
                    @if ($key === 'kullanim' && $worst !== 'ok')<i @class(['size-1.5 rounded-full', 'bg-warn' => $worst === 'warn', 'bg-crit' => $worst === 'crit'])></i>@endif
                </button>
            @endforeach
        </div>

        @if ($tab === 'kullanim')
            @if ($agentError)
                <p class="border-b border-line px-5 py-3 text-[13px] text-crit">{{ $agentError }}</p>
            @endif
            @include('livewire.customers.usage', ['report' => $report])
            <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-3 text-[13px] text-muted">
                <span>
                    Disk; site dosyaları, posta ve Laravel yedeklerinin toplamıdır.
                    {{ $measuredAt ? 'Son ölçüm '.\Illuminate\Support\Carbon::parse($measuredAt)->timezone(config('servik.timezone'))->format('d.m.Y H:i').'.' : 'Henüz ölçülmedi.' }}
                </span>
                <button type="button" wire:click="measure" wire:loading.attr="disabled" wire:target="measure" class="ml-auto flex h-8 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] text-fg hover:bg-surface-2 disabled:opacity-60">
                    <x-icon name="refresh-cw" class="size-3.5" wire:loading.class="animate-spin" wire:target="measure" />Şimdi ölç
                </button>
            </div>
        @elseif ($tab === 'siteler')
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <a href="{{ route('sites.index', ['musteri' => $customer->id]) }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                    <x-icon name="plus" />Site ekle
                </a>
                <button type="button" wire:click="openAttach" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2">
                    <x-icon name="link" />Mevcut siteyi bağla
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">Alan adı</th>
                            <th class="px-3 py-2.5 font-medium">PHP</th>
                            <th class="px-3 py-2.5 font-medium">SSL</th>
                            <th class="py-2.5 pr-5 pl-3 font-medium">Durum</th>
                        </tr>
                    </thead>
                    @forelse ($accounts as $account)
                        <tbody wire:key="account-{{ $account->id }}" class="divide-y divide-line border-b border-line">
                            <tr class="bg-surface-2/50">
                                <td colspan="4" class="py-2 pr-5 pl-5">
                                    <div class="flex items-center gap-2 text-[13px]">
                                        <x-icon name="user-round" class="size-3.5 text-muted" />
                                        <span class="font-mono">{{ $account->name }}</span>
                                        <span class="text-muted">· {{ $account->sites->count() }} site{{ $account->disk_bytes !== null ? ' · '.\App\Support\Bytes::format($account->disk_bytes) : '' }}</span>
                                        @if ($account->name !== $customer->username || $account->sites->isNotEmpty())
                                            <button type="button" wire:click="detach({{ $account->id }})" wire:confirm="{{ $account->name }} hesabı ve siteleri bu müşteriden ayrılsın mı? Siteler sunucuda kalır, yalnızca yöneticiler görür."
                                                    class="ml-auto flex h-7 items-center gap-1.5 rounded-md px-2 text-[12.5px] text-muted hover:bg-surface hover:text-fg">
                                                <x-icon name="unlink" class="size-3.5" />Müşteriden ayır
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @forelse ($account->sites as $site)
                                @php $ssl = $site->sslState(); @endphp
                                <tr class="hover:bg-surface-2/60">
                                    <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium"><a href="{{ route('sites.show', $site) }}" wire:navigate class="hover:text-accent">{{ $site->domain }}</a>@if ($site->laravelApp)<span class="ml-2 rounded-full bg-surface-2 px-2 py-0.5 font-sans text-2xs font-medium text-muted">Laravel</span>@endif</td>
                                    <td class="num px-3 py-3 text-muted">{{ $site->php_version }}</td>
                                    <td class="px-3 py-3 text-muted">{{ $ssl->label() }}</td>
                                    <td class="py-3 pr-5 pl-3">
                                        @if ($customer->isSuspended() && $site->status === \App\Enums\SiteStatus::Active)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-warn-soft px-2 py-0.5 text-2xs font-medium text-warn"><i class="size-1.5 rounded-full bg-warn"></i>Askıda</span>
                                        @else
                                            <x-status-badge :status="$site->status" />
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-3 pr-5 pl-5 text-[13px] text-muted">Bu hesapta henüz site yok. Müşteri için açılan siteler buraya gelir.</td></tr>
                            @endforelse
                        </tbody>
                    @empty
                        <tbody><tr><td colspan="4" class="px-5 py-10 text-center text-muted">Müşterinin henüz sitesi yok.</td></tr></tbody>
                    @endforelse
                </table>
            </div>
        @else
            <form wire:submit="saveInfo" class="divide-y divide-line text-[13.5px]">
                @foreach ([
                    ['company', 'Firma adı', 'text', 'organization'],
                    ['name', 'Yetkili kişi', 'text', 'name'],
                    ['email', 'E-posta', 'email', 'email'],
                    ['phone', 'Telefon', 'tel', 'tel'],
                ] as [$prop, $label, $type, $auto])
                    <div class="grid gap-1.5 px-5 py-3.5 sm:grid-cols-[12rem_minmax(0,28rem)] sm:items-center">
                        <label for="info-{{ $prop }}">{{ $label }}</label>
                        <div>
                            <input id="info-{{ $prop }}" type="{{ $type }}" wire:model="{{ $prop }}" autocomplete="{{ $auto }}" class="{{ $field }} {{ $errors->has($prop) ? 'border-crit' : 'border-line' }}">
                            @error($prop) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                            @if ($prop === 'email')<p class="mt-1.5 text-2xs text-muted">Müşteri panele bu adresle girer.</p>@endif
                        </div>
                    </div>
                @endforeach
                <div class="grid gap-1.5 px-5 py-3.5 sm:grid-cols-[12rem_minmax(0,28rem)] sm:items-center">
                    <label for="info-package">Paket</label>
                    <div>
                        <select id="info-package" wire:model="packageId" class="{{ $field }} border-line px-2.5">
                            @foreach ($packages as $package)
                                <option value="{{ $package->id }}">{{ $package->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-2xs text-muted">Yeni paketin sınırları ve özellikleri hemen geçerli olur; sınırı aşan mevcut şeyler silinmez.</p>
                    </div>
                </div>
                <div class="grid gap-1.5 px-5 py-3.5 sm:grid-cols-[12rem_minmax(0,28rem)]">
                    <label for="info-notes" class="sm:pt-2">Notlar <span class="text-muted">(yalnızca yöneticiler görür)</span></label>
                    <textarea id="info-notes" wire:model="notes" rows="3" class="w-full rounded-md border bg-surface px-3 py-2 text-[13.5px] outline-none focus:border-accent {{ $errors->has('notes') ? 'border-crit' : 'border-line' }}"></textarea>
                </div>
                <div class="grid gap-1.5 px-5 py-3.5 sm:grid-cols-[12rem_minmax(0,28rem)] sm:items-center">
                    <span>Panel girişi</span>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-muted">{{ $customer->user?->isInvited() ? 'Müşteri henüz şifresini belirlemedi.' : 'Müşteri kendi şifresiyle giriyor.' }}</span>
                        <button type="button" wire:click="newInvite" class="flex h-8 items-center gap-1.5 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">
                            <x-icon name="key-round" class="size-3.5" />{{ $customer->user?->isInvited() ? 'Yeni giriş bağlantısı' : 'Şifre sıfırlama bağlantısı' }}
                        </button>
                    </div>
                </div>
                <div class="flex justify-end px-5 py-3">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveInfo" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Kaydet</button>
                </div>
            </form>
        @endif
    </section>

    @if ($inviteLink)
        @include('livewire.customers.invite', ['link' => $inviteLink, 'close' => 'closeInvite'])
    @endif

    {{-- Askıya al --}}
    <x-modal title="Müşteriyi askıya al" show="suspending" size="max-w-md">
        <form wire:submit="suspend" class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ $customer->displayName() }} askıya alınsın mı?</p>
            <p class="mt-1 text-[13px] text-muted">Siteleri "hizmet dışı" sayfası gösterir, gelen posta bekletilir ve posta kutularına girilemez, Laravel kuyrukları ve süreçleri durur, müşteri panele giremez. Dosyalar, posta ve veritabanları silinmez; etkinleştirince her şey kaldığı yerden sürer.</p>
            <label for="reason" class="mt-4 mb-1.5 block">Neden <span class="text-muted">(isteğe bağlı, müşteri görmez)</span></label>
            <input id="reason" wire:model="reason" placeholder="Örneğin: ödeme gecikti" class="{{ $field }} {{ $errors->has('reason') ? 'border-crit' : 'border-line' }}">
            @error('reason') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('suspending', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-warn px-3.5 text-[13.5px] font-medium text-nav">Askıya al</button>
            </div>
        </form>
    </x-modal>

    {{-- Sil --}}
    <x-modal title="Müşteriyi sil" show="deleting" size="max-w-md">
        <form wire:submit="delete" class="p-5 text-[13.5px]">
            <p class="font-semibold">{{ $customer->displayName() }} silinsin mi?</p>
            <p class="mt-1 text-[13px] text-muted">Müşteri ve panel girişi silinir. {{ \App\Services\Customers\Customers::UNDO_SECONDS / 60 }} dakika içinde müşteri listesinden geri alabilirsiniz; bu sürede siteleri askı sayfası gösterir.</p>
            <label class="mt-4 flex items-start gap-2.5">
                <input type="checkbox" wire:model="deleteSites" class="mt-0.5 size-4 accent-accent">
                <span>Sitelerini de sil ({{ $siteCount }} site, posta, DNS ve Laravel uygulamalarıyla)<span class="block text-[12.5px] text-muted">İşaretlemezseniz siteler sunucuda kalır ve müşterisiz olarak yayına devam eder.</span></span>
            </label>
            <label for="confirmName" class="mt-4 mb-1.5 block">Onaylamak için <span class="font-mono font-medium">{{ $customer->username }}</span> yazın</label>
            <input id="confirmName" wire:model="confirmName" autocomplete="off" spellcheck="false" class="{{ $field }} font-mono text-[13px] {{ $errors->has('confirmName') ? 'border-crit' : 'border-line' }}">
            @error('confirmName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deleting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">Sil</button>
            </div>
        </form>
    </x-modal>

    {{-- Mevcut siteyi bağla --}}
    <x-modal title="Mevcut siteyi bağla" show="attaching">
        <form wire:submit="attach" class="p-5 text-[13.5px]">
            <p class="font-semibold">Mevcut siteyi bağla</p>
            <p class="mt-1 text-[13px] text-muted">Siteler sistem kullanıcısıyla birlikte taşınır: hesap bağlanınca içindeki bütün siteler bu müşterinin olur ve paketine sayılır.</p>
            @if ($freeAccounts->isEmpty())
                <p class="mt-4 rounded-md bg-surface-2 px-3 py-2.5 text-[13px] text-muted">Müşterisi olmayan site yok.</p>
            @else
                <label for="accountId" class="mt-4 mb-1.5 block">Sistem kullanıcısı</label>
                <select id="accountId" wire:model.live="accountId" class="{{ $field }} border-line px-2.5 font-mono text-[13px]">
                    @foreach ($freeAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }} · {{ $account->sites->first()?->domain }}{{ $account->sites->count() > 1 ? ' +'.($account->sites->count() - 1) : '' }}</option>
                    @endforeach
                </select>
                @error('accountId') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                @php $chosen = $freeAccounts->firstWhere('id', $accountId); @endphp
                @if ($chosen)
                    <p class="mt-2 text-[12.5px] text-muted">Taşınacak siteler: <span class="font-mono">{{ $chosen->sites->pluck('domain')->join(', ') }}</span></p>
                @endif
            @endif
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('attaching', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" @disabled($freeAccounts->isEmpty()) class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Bağla</button>
            </div>
        </form>
    </x-modal>
</div>
