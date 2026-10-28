@php
    use App\Models\Site;
    use App\Services\Components\Components;
    use App\Support\Badge;
    $busyLabels = ['install' => __('Kuruluyor'), 'update' => __('Güncelleniyor'), 'remove' => __('Kaldırılıyor')];
    $badge = fn (array $r) => match (true) {
        $r['busy'] !== null => new Badge($busyLabels[$r['busy']] ?? __('Çalışıyor'), 'info', true),
        ! $r['installed'] => new Badge(__('Kurulu değil'), 'muted'),
        ! $r['running'] && $r['units'] !== [] => new Badge(__('Durdu'), 'crit'),
        $r['latest'] !== null => new Badge(__('Güncelleme var'), 'warn'),
        default => new Badge(__('Kurulu'), 'ok'),
    };
    $link = 'text-[13px] text-accent hover:underline disabled:opacity-40 disabled:no-underline';
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Yazılım Yönetimi') }}</h1>
            <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Sunucuya web sunucusu, veritabanı sunucuları ve diğer yazılımları kurun veya kaldırın. Bir adım başarısız olursa değişiklik otomatik geri alınır. Panelin kendi Nginx, PHP ve PostgreSQL\'i ayrı çalışır ve burada listelenmez.') }}</p>
        </div>
        <div class="flex items-center gap-3">
            @if ($checkedAt)
                <span class="text-2xs text-muted" title="{{ \App\Support\When::dateTime(\Illuminate\Support\Carbon::parse($checkedAt)) }}">{{ __('Son denetim :checked_at', ['checked_at' => \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans()]) }}</span>
            @endif
            <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" @disabled($busy || $agentError) class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40">
                <x-icon name="refresh-cw" wire:loading.class="animate-spin" wire:target="check" />{{ __('Güncellemeleri denetle') }}
            </button>
        </div>
    </div>

    @if ($busy && ! $operation)
        <div wire:poll.3s hidden></div>
    @endif

    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-4 text-[13.5px]">
            <p class="font-medium text-crit">{{ $agentError }}</p>
        </div>
    @endif
    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="flex-1">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif
    @include('livewire.components.blocker')

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (Components::TABS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="relative overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[720px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ $tab === 'other' ? __('Bileşen') : __('Yazılım') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Sürüm') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Kullanım') }}</th>
                        <th class="hidden py-2.5 pr-5 pl-3 sm:table-cell"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($groups as $group => $rows)
                        @if ($group !== '' && count($groups) > 1)
                            <tr class="bg-surface-2/60"><td colspan="5" class="px-5 py-2 text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ $group }}</td></tr>
                        @endif
                        @foreach ($rows as $r)
                            <tr wire:key="c-{{ $r['id'] }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5">
                                    <span class="font-medium">{{ $r['name'] }}</span>
                                    @if ($r['default'])
                                        <span class="ml-1.5 rounded bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ __('varsayılan') }}</span>
                                    @endif
                                    <span class="mt-0.5 block text-2xs text-muted">{{ $r['description'] }}</span>
                                    <span class="mt-0.5 block font-mono text-2xs text-muted sm:hidden">{{ $r['installed'] ? ($r['version'] ?? '') : ($r['candidate'] ? __('Kurulacak: :version', ['version' => $r['candidate']]) : '') }}</span>
                                    <div class="mt-2 sm:hidden">@include('livewire.components.actions')</div>
                                </td>
                                <td @class(['hidden px-3 py-3 font-mono text-[13px] whitespace-nowrap sm:table-cell', 'text-muted' => ! $r['installed']])>
                                    @include('livewire.components.version')
                                </td>
                                <td class="px-3 py-3"><x-status-badge :status="$badge($r)" /></td>
                                <td class="hidden px-3 py-3 text-[13px] text-muted md:table-cell">{{ $r['usage'] }}</td>
                                <td class="hidden max-w-[220px] py-3 pr-5 pl-3 text-right sm:table-cell">
                                    @include('livewire.components.actions')
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                    @if ($tab === 'databases' && $manager !== null)
                        <tr class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5"><span class="font-medium">{{ __('phpMyAdmin ve Adminer') }}</span><span class="mt-0.5 block text-2xs text-muted">{{ __('Tarayıcıdan yönetim, veritabanı sunucusuyla birlikte kurulur') }}</span></td>
                            <td class="hidden px-3 py-3 font-mono text-[13px] text-muted sm:table-cell">{{ __('port :manager_port', ['manager_port' => $managerPort]) }}</td>
                            <td class="px-3 py-3"><x-status-badge :status="($manager['configured'] ?? false) ? new Badge(__('Kurulu'), 'ok') : new Badge(__('Kurulu değil'), 'muted')" /></td>
                            <td class="hidden px-3 py-3 text-[13px] text-muted md:table-cell">{{ __('Veritabanı sayfalarında') }}</td>
                            <td class="hidden py-3 pr-5 pl-3 text-right sm:table-cell"><a href="{{ route('databases.settings') }}" wire:navigate class="{{ $link }}">{{ __('Ayarlar') }}</a></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        @if ($tab === 'web')
            <form wire:submit="saveDefault" class="grid grid-cols-1 gap-x-6 gap-y-2 border-t border-line px-5 py-4 text-[13.5px] md:grid-cols-[220px_1fr]">
                <label for="def-stack" class="font-medium md:pt-2">{{ __('Yeni siteler için varsayılan') }}</label>
                <div class="flex flex-wrap items-center gap-3">
                    <select id="def-stack" wire:model="defaultStack" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-80">
                        @foreach (Site::STACKS as $key => $label)
                            <option value="{{ $key }}" @disabled($key === Site::APACHE && ! $apacheInstalled)>{{ $label }}{{ $key === Site::APACHE && ! $apacheInstalled ? __(' (Apache kurulu değil)') : '' }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kaydet') }}</button>
                    @error('defaultStack') <p class="w-full text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="w-full text-[13px] text-muted"><x-t text="Her site kendi PHP-FPM havuzunda çalışır. Var olan bir sitenin web sunucusu, site sayfasındaki :part aracından değiştirilir."><x-slot:part><span class="text-fg">{{ __('PHP ve Web Sunucusu') }}</span></x-slot:part></x-t></p>
                </div>
            </form>
            <a href="{{ route('services.index', ['tab' => 'php']) }}" wire:navigate class="flex items-center gap-3 border-t border-line px-5 py-3 text-[13.5px] hover:bg-surface-2/60">
                <x-icon name="code" class="text-muted" />
                <span><span class="font-medium">{{ __('PHP sürümleri') }}</span> <span class="text-muted">{{ __('artık Hizmetler sayfasında: kurma, kaldırma, eklentiler ve php.ini varsayılanları.') }}</span></span>
                <x-icon name="chevron-right" class="ml-auto text-muted" />
            </a>
        @elseif ($tab === 'databases')
            <p class="border-t border-line px-5 py-3 text-2xs text-muted"><x-t text="MySQL ve MariaDB aynı anda kurulamaz; PostgreSQL ikisinin yanında çalışır. Panelin kendi PostgreSQL'i ayrı çalışır, buradaki işlemler onu etkilemez. Veritabanları ve kullanıcılar :link sayfasından yönetilir."><x-slot:link><a href="{{ route('databases.index') }}" wire:navigate class="text-accent hover:underline">{{ __('Veritabanları') }}</a></x-slot:link></x-t></p>
        @else
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Redis her Laravel uygulamasına kendi parolalı örneğini verir; uygulamanın Ortam bölümünden bağlanır. Node.js ve Composer, Laravel derlemelerinde kullanılır.') }}</p>
        @endif
    </section>

    {{-- Onay --}}
    <x-modal title="{{ $confirm['title'] ?? __('Onay') }}" show="confirming" size="max-w-xl">
        @if ($confirm)
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold">{{ $confirm['title'] }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ $confirm['text'] }}</p>
                @include('livewire.components.candidate')
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" @class(['h-9 rounded-md px-3.5 font-medium disabled:opacity-40', 'bg-crit text-on-crit' => $confirm['danger'], 'bg-accent text-accent-fg hover:brightness-110' => ! $confirm['danger']])>{{ $confirm['button'] }}</button>
                </div>
            </div>
        @endif
    </x-modal>

    {{-- İlerleme --}}
    @include('livewire.components.progress')

    {{-- ModSecurity ayarları --}}
    <x-modal :title="__('ModSecurity ayarları')" show="configuring">
        <form wire:submit="saveSettings">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('ModSecurity ayarları') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <fieldset>
                    <legend class="mb-2 font-medium">{{ __('Kip') }}</legend>
                    <div class="grid gap-2">
                        @foreach (['detect' => [__('Yalnızca izle'), __('Saldırılar /var/log/serfex/modsecurity altına yazılır, istekler engellenmez. Yanlış alarmları görmek için önerilen başlangıç.')], 'block' => [__('Engelle'), __('Kurallara takılan istekler 403 ile reddedilir.')]] as $key => [$label, $hint])
                            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 {{ $modsecMode === $key ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2' }}">
                                <input type="radio" wire:model.live="modsecMode" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                                <span><span class="block font-medium">{{ $label }}</span><span class="mt-0.5 block text-[12.5px] text-muted">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div>
                    <label for="ms-paranoia" class="mb-1.5 block font-medium">{{ __('Hassasiyet (OWASP CRS paranoya seviyesi)') }}</label>
                    <select id="ms-paranoia" wire:model="modsecParanoia" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                        <option value="1">{{ __('1 · Az yanlış alarm (önerilen)') }}</option>
                        <option value="2">{{ __('2 · Daha sıkı') }}</option>
                        <option value="3">{{ __('3 · Çok sıkı, ince ayar gerekir') }}</option>
                        <option value="4">{{ __('4 · En sıkı') }}</option>
                    </select>
                </div>
                @error('modsecMode') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('configuring', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </x-modal>
</div>
