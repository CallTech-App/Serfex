@php
    use App\Livewire\Sites\SiteErrorPages;
    use App\Support\Badge;
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Hata sayfaları') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Hata sayfaları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">
            {{ __('Web sunucusu bir hatayı kendisi yanıtladığında ziyaretçiler Nginx\'in düz sayfası yerine bu sayfaları görür. Sitenizin kendi kodunun ürettiği hata sayfaları (Laravel, WordPress) değişmez. Sayfalar alt alan adlarında da kullanılır.') }}
        </p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="relative overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[640px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Hata') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Ne zaman görünür') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Sayfa') }}</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach (SiteErrorPages::PAGES as $key => [$codes, $name, $when])
                        <tr wire:key="page-{{ $key }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5">
                                <span class="num font-mono text-[13px]">{{ $codes }}</span>
                                <span class="block text-[12.5px] text-muted">{{ $name }}</span>
                            </td>
                            <td class="hidden max-w-0 px-3 py-3 text-[13px] text-muted md:table-cell">{{ $when }}</td>
                            <td class="hidden px-3 py-3 sm:table-cell">
                                <x-status-badge :status="isset($pages[$key]) ? new Badge(__('Özel sayfa'), 'ok') : new Badge(__('Web sunucusunun'), 'muted')" />
                            </td>
                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="openEdit('{{ $key }}')" class="text-[13px] text-accent hover:underline">{{ isset($pages[$key]) ? __('Düzenle') : __('Özel sayfa yap') }}</button>
                                @if (isset($pages[$key]))
                                    <button type="button" wire:click="confirmReset('{{ $key }}')" class="ml-3 text-[13px] text-crit hover:underline">{{ __('Varsayılana dön') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('503 sayfası bakım ve askıya alma için Serfex\'e ayrılmıştır. Her sayfa en fazla 256 KB olabilir; görseller için tam adres kullanın.') }}</p>
    </section>

    {{-- Düzenle --}}
    <x-modal :title="__('Hata sayfası')" show="editing" close="$wire.editing = null" size="max-w-4xl">
        @if ($editing)
            <form wire:submit="save" x-data="{ preview: false }">
                <div class="flex h-12 items-center gap-3 border-b border-line px-5">
                    <h2 class="text-[14.5px] font-semibold">{{ SiteErrorPages::PAGES[$editing][1] }} <span class="num font-mono text-[13px] font-normal text-muted">{{ SiteErrorPages::PAGES[$editing][0] }}</span></h2>
                    <div class="ml-auto flex rounded-md border border-line p-0.5 text-[13px]">
                        <button type="button" @click="preview = false" :class="preview ? 'text-muted' : 'bg-surface-2 font-medium'" class="h-7 rounded px-2.5">{{ __('HTML') }}</button>
                        <button type="button" @click="preview = true" :class="preview ? 'bg-surface-2 font-medium' : 'text-muted'" class="h-7 rounded px-2.5">{{ __('Önizleme') }}</button>
                    </div>
                </div>
                <div class="p-5">
                    <textarea x-show="!preview" wire:model="html" aria-label="{{ __('HTML') }}" spellcheck="false" rows="18" class="w-full rounded-md border bg-surface p-3 font-mono text-[12.5px] leading-relaxed outline-none focus:border-accent {{ $errors->has('html') ? 'border-crit' : 'border-line' }}"></textarea>
                    <iframe x-show="preview" x-cloak :srcdoc="$wire.html" sandbox="" title="{{ __('Önizleme') }}" class="h-[26rem] w-full rounded-md border border-line bg-white"></iframe>
                    @error('html') <p class="mt-1.5 text-[13px] break-words text-crit">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="closeEdit" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="submit" wire:loading.attr="disabled" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Kaydet ve uygula') }}</button>
                </div>
            </form>
        @endif
    </x-modal>

    {{-- Varsayılana dön --}}
    <x-modal :title="__('Varsayılana dön')" show="resetting" close="$wire.resetting = null" size="max-w-md">
        @if ($resetting)
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold">{{ __(':pages için özel sayfa kaldırılsın mı?', ['pages' => SiteErrorPages::PAGES[$resetting][1]]) }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ __('Ziyaretçiler yeniden web sunucusunun kendi sayfasını görür. 10 dakika içinde geri alabilirsiniz.') }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('resetting', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="restoreDefault" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Varsayılana dön') }}</button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
