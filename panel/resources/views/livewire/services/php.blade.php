@php
    use App\Livewire\Services\PhpVersionShow;
    use App\Services\Php\PhpVersions;
    $input = 'h-9 rounded-md border border-line bg-surface px-2.5 font-mono text-[13px]';
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('services.index') }}" wire:navigate class="hover:text-accent">{{ __('Hizmetler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('services.index', ['tab' => 'php']) }}" wire:navigate class="hover:text-accent">{{ __('PHP sürümleri') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">PHP {{ $version }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-[22px] font-semibold tracking-tight">PHP {{ $version }}</h1>
            <a href="{{ route('services.show', 'php'.$version.'-fpm') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="server-cog" />{{ __('PHP-FPM hizmeti') }}</a>
        </div>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">
            @if ($sites === [])
                {{ __('Bu sürümü kullanan site yok.') }}
            @else
                @if (count($sites) > 3)
                    <x-t text=":count site bu sürümü kullanıyor: :sites ve :more diğeri." :count="count($sites)" :with="['more' => count($sites) - 3]"><x-slot:sites><span class="font-mono text-fg">{{ implode(', ', array_slice($sites, 0, 3)) }}</span></x-slot:sites></x-t>
                @else
                    <x-t text=":count site bu sürümü kullanıyor: :sites." :count="count($sites)"><x-slot:sites><span class="font-mono text-fg">{{ implode(', ', $sites) }}</span></x-slot:sites></x-t>
                @endif
            @endif
            {{ __('Buradaki değerler sitelerin başlangıç ayarlarıdır; her site kendi PHP ve Web Sunucusu sayfasından bazılarını değiştirebilir.') }}
            {{ __('Komut satırı PHP\'si de (artisan, kuyruk işçileri, zamanlanmış görevler, Composer) bu değerleri kullanır; "yalnızca web" işaretli olanlar orada geçerli değildir.') }}
        </p>
    </div>

    @if ($busy && ! $operation)
        <div wire:poll.3s hidden></div>
    @endif

    @if ($agentError)
        <div class="rounded-xl border border-line bg-crit-soft px-5 py-4 text-[13.5px]"><p class="font-medium text-crit">{{ $agentError }}</p></div>
    @endif
    @if ($refused)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
            <p class="flex-1">{{ $refused }}</p>
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (PhpVersionShow::TABS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'settings')
            <form wire:submit="save">
                @if (! $managed && ! $agentError)
                    <p class="mx-5 mt-4 rounded-lg bg-info-soft px-3 py-2 text-[13px]">{{ __('Bu sürüm Serfex\'ten önce kurulmuş; değerler sunucunun php.ini dosyasından okundu. Kaydedince Serfex\'in ayar dosyasına yazılır, php.ini\'ye dokunulmaz.') }}</p>
                @endif
                @if ($bodyLimit)
                    <p class="mx-5 mt-4 rounded-lg bg-warn-soft px-3 py-2 text-[13px]"><x-t text="Nginx en fazla :body_limit MB'lık isteğe izin veriyor; daha büyük yüklemeler PHP'ye ulaşmadan reddedilir. Sınırı :link yükseltin." :with="['body_limit' => $bodyLimit]"><x-slot:link><a href="{{ route('services.show', ['unit' => 'nginx', 'tab' => 'settings']) }}" wire:navigate class="text-accent hover:underline">{{ __('Nginx ayarlarından') }}</a></x-slot:link></x-t></p>
                @endif
                <div class="grid grid-cols-1 gap-x-6 gap-y-5 p-5 text-[13.5px] md:grid-cols-[220px_1fr]">
                    @foreach (PhpVersions::INI as $key => [$label, $type, $help])
                        @php($field = PhpVersionShow::field($key))
                        <label for="ini-{{ $key }}" class="font-medium md:pt-2">{{ __($label) }}</label>
                        <div class="max-w-xl">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($type === 'onoff')
                                    <select id="ini-{{ $key }}" wire:model="values.{{ $field }}" class="{{ $input }} w-40"><option value="On">{{ __('Açık') }}</option><option value="Off">{{ __('Kapalı') }}</option></select>
                                @elseif ($type === 'flag')
                                    <select id="ini-{{ $key }}" wire:model="values.{{ $field }}" class="{{ $input }} w-40"><option value="1">{{ __('Açık') }}</option><option value="0">{{ __('Kapalı') }}</option></select>
                                @else
                                    <input id="ini-{{ $key }}" type="text" wire:model="values.{{ $field }}" class="{{ $input }} {{ $type === 'text' ? 'w-60' : 'w-40' }}">
                                @endif
                                <span class="font-mono text-2xs text-muted">{{ $key }}</span>
                                @if (in_array($key, PhpVersions::WEB_ONLY, true))
                                    <span class="rounded bg-surface-2 px-1.5 py-0.5 text-2xs text-muted" title="{{ __('Komut satırında PHP\'nin kendi değeri geçerlidir: bellek ve süre sınırı yoktur, hatalar terminale yazılır.') }}">{{ __('yalnızca web') }}</span>
                                @endif
                            </div>
                            @error('values.'.$field) <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                            @if ($help)<p class="mt-1.5 text-[13px] text-muted">{{ __($help) }}</p>@endif
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-3">
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" @disabled($agentError) class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                        <x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="save" />{{ __('Kaydet ve uygula') }}
                    </button>
                    <button type="button" wire:click="resetToDefaults" @disabled($defaults === []) class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40">{{ __('Serfex varsayılanlarını doldur') }}</button>
                    <span class="text-[13px] text-muted">{{ __('PHP-FPM kesintisiz yeniden yüklenir; ayarı kabul etmezse önceki değerler kalır.') }}</span>
                </div>
            </form>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Eklenti') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Açık') }}</th>
                            <th class="py-2.5 pr-5 pl-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($extensions as $ext)
                            <tr wire:key="ext-{{ $ext['name'] }}" class="hover:bg-surface-2/60">
                                <td class="py-2.5 pr-3 pl-5 font-mono text-[13px]">{{ $ext['name'] }}@if (! $ext['installed'])<span class="ml-2 font-sans text-2xs text-muted">{{ __('kurulu değil') }}</span>@endif</td>
                                <td class="px-3 py-2.5">
                                    @if ($ext['installed'])
                                        <button type="button" role="switch" aria-checked="{{ $ext['enabled'] ? 'true' : 'false' }}" aria-label="{{ $ext['name'] }}" wire:click="toggle('{{ $ext['name'] }}')" wire:loading.attr="disabled" @disabled($busy)
                                                @class(['relative inline-flex h-6 w-10 shrink-0 items-center rounded-full transition disabled:opacity-50', 'bg-accent' => $ext['enabled'], 'bg-line' => ! $ext['enabled']])>
                                            <span @class(['inline-block size-5 rounded-full bg-surface shadow transition', 'translate-x-[18px]' => $ext['enabled'], 'translate-x-0.5' => ! $ext['enabled']])></span>
                                        </button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-5 pl-3 text-right">
                                    @if ($ext['optional'] ?? false)
                                        @if ($ext['installed'])
                                            <button type="button" wire:click="askExtension('{{ $ext['name'] }}', 'remove')" @disabled($busy) class="text-[13px] text-muted hover:text-crit disabled:opacity-40">{{ __('Kaldır') }}</button>
                                        @else
                                            <button type="button" wire:click="askExtension('{{ $ext['name'] }}', 'install')" @disabled($busy) class="text-[13px] text-accent hover:underline disabled:opacity-40">{{ __('Kur') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Kapatılan eklenti kaldırılmaz, yalnızca PHP-FPM onu yüklemez. Kurulabilir eklentiler Ubuntu ya da ondrej/php paketlerinden gelir. Sitelerin Laravel kuyrukları ve zamanlayıcısı komut satırı PHP\'sini kullanır; eklenti açıp kapatmak onu da etkiler.') }}</p>
        @endif
    </section>

    <x-modal title="{{ $confirm['title'] ?? __('Onay') }}" show="confirming" size="max-w-md">
        @if ($confirm)
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold">{{ $confirm['title'] }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ $confirm['text'] }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" @class(['h-9 rounded-md px-3.5 font-medium disabled:opacity-40', 'bg-crit text-on-crit' => $confirm['danger'], 'bg-accent text-accent-fg hover:brightness-110' => ! $confirm['danger']])>{{ $confirm['button'] }}</button>
                </div>
            </div>
        @endif
    </x-modal>

    @include('livewire.components.progress')
</div>
