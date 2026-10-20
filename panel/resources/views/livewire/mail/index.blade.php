@php
    use App\Support\Bytes;
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent works on a domain. --}}
    @if ($busy)
        <div wire:poll.2s hidden></div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-semibold tracking-tight">{{ __('E-posta') }}</h1>
            <p class="mt-1 text-[13px] text-muted">{{ __(':is_admin Posta kutularını görmek için bir alan adına tıklayın.', ['is_admin' => auth()->user()->is_admin ? __('E-postası bu sunucuda olan alan adları.') : __('E-postası açık alan adlarınız.')]) }}</p>
        </div>
        @if (auth()->user()->is_admin)
            <a href="{{ route('mail.settings') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2">
                <x-icon name="settings" />{{ __('E-posta ayarları') }}
            </a>
        @endif
    </div>

    @if (auth()->user()->is_admin)
        @include('livewire.mail.server-state')
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" wire:click="openEnable" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="plus" />{{ __('E-postayı aç') }}
            </button>
            <div class="ml-auto flex w-full gap-2 sm:w-auto">
                <select wire:model.live="filter" aria-label="{{ __('Durum') }}" class="h-9 rounded-md border border-line bg-surface px-2.5 text-[13.5px]">
                    <option value="">{{ __('Tümü') }}</option>
                    <option value="problem">{{ __('Sorunlu olanlar') }}</option>
                </select>
                <label class="relative flex-1 sm:w-60 sm:flex-none">
                    <span class="sr-only">{{ __('Alan adı ara') }}</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Alan adı ara') }}"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            </div>
        </div>

        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[760px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Alan adı') }}</th>
                        <th class="px-3 py-2.5 text-right font-medium">{{ __('Posta kutusu') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Kullanılan alan') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Spam filtresi') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('DNS kayıtları') }}</th>
                        <th class="py-2.5 pr-5 pl-3 font-medium">{{ __('Durum') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($domains as $domain)
                        <tr wire:key="mail-{{ $domain->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">
                                <a href="{{ route('mail.show', $domain) }}" wire:navigate class="hover:text-accent">{{ $domain->name }}</a>
                            </td>
                            <td class="num px-3 py-3 text-right">{{ $domain->mailboxes_count }}</td>
                            <td class="num px-3 py-3 whitespace-nowrap text-muted">
                                @if ($domain->mailboxes_count === 0)
                                    —
                                @else
                                    {{ Bytes::format((int) $domain->mailboxes_sum_usage_bytes) }} / {{ $domain->unlimited_count > 0 ? __('sınırsız') : Bytes::format((int) $domain->mailboxes_sum_quota_mb * 1048576) }}
                                @endif
                            </td>
                            <td class="px-3 py-3 text-muted">{{ $domain->spam_filter ? __('Açık') : __('Kapalı') }}</td>
                            <td class="px-3 py-3"><x-status-badge :status="$dnsStates[$domain->id]" /></td>
                            <td class="py-3 pr-5 pl-3">
                                <x-status-badge :status="$domain->status" :title="$domain->last_error" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                @if ($search !== '' || $filter !== '')
                                    <p class="text-muted">{{ __('Aramanızla eşleşen alan adı yok.') }}</p>
                                @else
                                    <p class="font-medium">{{ __('Henüz e-postası açık alan adı yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('Posta sunucusu kurulduktan sonra yeni sitelerde e-posta kendiliğinden açılır. Var olan bir alan adı için "E-postayı aç"a basın.') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($domains->total() > 0)
            <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                <span>{{ __(':domains–:domains2 / :domains3 alan adı', ['domains' => $domains->firstItem(), 'domains2' => $domains->lastItem(), 'domains3' => $domains->total()]) }}</span>
                @if ($domains->hasPages())
                    <span class="ml-auto flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($domains->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
                        <span class="px-2">{{ __('Sayfa :domains / :domains2', ['domains' => $domains->currentPage(), 'domains2' => $domains->lastPage()]) }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $domains->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
                    </span>
                @endif
            </div>
        @endif
    </section>

    {{-- E-postayı aç --}}
    <x-modal :title="__('E-postayı aç')" show="enabling">
        <form wire:submit="enable">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('E-postayı aç') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="domain" class="mb-1.5 block">{{ __('Alan adı') }}</label>
                    <input id="domain" wire:model="domain" list="mail-candidates" required placeholder="{{ __('ornek.com.tr') }}" autocomplete="off" spellcheck="false"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('domain') ? 'border-crit' : 'border-line' }}">
                    <datalist id="mail-candidates">
                        @foreach ($candidates as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                    @error('domain')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-2xs text-muted">{{ __('Alan adının DNS\'i bu sunucudaysa MX, SPF, DKIM ve DMARC kayıtları otomatik yazılır; başka yerdeyse eklemeniz gereken kayıtlar alan adının sayfasında listelenir.') }}</p>
                    @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('enabling', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="enable" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('E-postayı aç') }}</button>
            </div>
        </form>
    </x-modal>
</div>
