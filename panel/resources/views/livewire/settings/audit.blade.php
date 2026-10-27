@php
    use App\Services\Audit\AuditLog;
    use App\Support\When;
    $user = auth()->user();
    $field = 'h-9 rounded-md border border-line bg-surface px-3 text-[13px] outline-none focus:border-accent';
@endphp
<x-settings-page current="settings.audit" :title="__('İşlem günlüğü')">
    <x-slot:description><x-t text="Panelden, API'den ve Serfex AI'dan yapılan her işlem: kim, ne zaman, ne yaptı. Son :undo_minutes dakikadaki işlemler buradan da geri alınabilir. Serfex AI'ın okuduğu ve önerdiği her şey :link." :with="['undo_minutes' => \App\Models\AuditEntry::UNDO_MINUTES]"><x-slot:link><a href="{{ route('ai.audit') }}" wire:navigate class="text-accent hover:underline">{{ __('AI kaydında') }}</a></x-slot:link></x-t></x-slot:description>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <div class="relative min-w-0 flex-1 sm:max-w-xs">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('İşlem, bölüm ya da kişi ara') }}" aria-label="{{ __('Ara') }}" class="{{ $field }} w-full pl-9">
            </div>
            <select wire:model.live="who" aria-label="{{ __('Kim') }}" class="{{ $field }}">
                <option value="">{{ __('Herkes') }}</option>
                @foreach ($admins as $admin)
                    <option value="{{ $admin->id }}">{{ $admin->name }}</option>
                @endforeach
                <option value="customers">{{ __('Müşteriler') }}</option>
                <option value="api">{{ __('API anahtarları') }}</option>
                <option value="ai">Serfex AI</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[720px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Zaman') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Kim') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('İşlem') }}</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($entries as $entry)
                        <tr wire:key="audit-{{ $entry->id }}" class="align-top hover:bg-surface-2/60">
                            <td class="num py-3 pr-3 pl-5 whitespace-nowrap text-muted" title="{{ \App\Support\When::dateTime($entry->created_at, true) }}">{{ When::withTime($entry->created_at) }}</td>
                            <td class="px-3 py-3">
                                <span class="whitespace-nowrap">{{ $entry->actor }}</span>
                                <span class="block text-2xs text-muted">{{ $entry->viaLabel() }}{{ $entry->ip ? ' · '.$entry->ip : '' }}</span>
                            </td>
                            <td class="px-3 py-3">
                                @if ($entry->section)
                                    <span class="text-2xs text-muted">{{ $entry->section }}</span>
                                @endif
                                <span class="block break-words">{{ \App\Support\Locales::reword($entry->message) }}</span>
                                @if ($entry->undone_at)
                                    <span class="mt-0.5 inline-block rounded bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ __('Geri alındı') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                @if ($entry->undoable() && ($entry->user_id === $user->id || $user->isFullAdmin()))
                                    @php $sep = str_contains($entry->url, '?') ? '&' : '?'; @endphp
                                    <a href="{{ $entry->url.$sep.AuditLog::UNDO_PARAM.'='.$entry->id }}" class="text-[13px] text-accent hover:underline">{{ __('Geri al') }}</a>
                                @elseif ($entry->url && ! $entry->undone_at)
                                    <a href="{{ $entry->url }}" wire:navigate class="text-[13px] text-muted hover:text-accent hover:underline">{{ __('Sayfaya git') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center">
                                <p class="font-medium">{{ $search !== '' || $who !== '' ? __('Eşleşen işlem yok') : __('Henüz kayıtlı işlem yok') }}</p>
                                <p class="mt-1 text-[13px] text-muted">{{ __('Panelde yapılan her değişiklik burada görünür.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($entries->total() > 0)
            <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                <span>{{ __(':entries–:entries2 / :entries3 işlem', ['entries' => $entries->firstItem(), 'entries2' => $entries->lastItem(), 'entries3' => $entries->total()]) }}</span>
                @if ($entries->hasPages())
                    <span class="ml-auto flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($entries->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
                        <span class="px-2">{{ __('Sayfa :entries / :entries2', ['entries' => $entries->currentPage(), 'entries2' => $entries->lastPage()]) }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $entries->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
                    </span>
                @endif
            </div>
        @endif
    </section>
</x-settings-page>
