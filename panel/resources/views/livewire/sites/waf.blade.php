@php
    use App\Services\Security\Waf;
    use App\Support\Badge;
    $tz = config('servik.timezone');
    $field = 'h-9 rounded-md border bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $link = 'text-[13px] text-accent hover:underline whitespace-nowrap';
    $when = fn ($t) => $t->timezone($tz)->isToday() ? 'Bugün '.$t->timezone($tz)->format('H:i') : $t->timezone($tz)->format('d.m.Y H:i');
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">Siteler</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">WAF</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">Web uygulaması güvenlik duvarı</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">ModSecurity ve OWASP Core Rule Set, siteye gelen SQL enjeksiyonu, XSS, dosya yolu saldırısı gibi istekleri yakalar. Site {{ $site->stackLabel() }} ile çalışıyor; WAF her iki yığında da Nginx önünde çalışır.</p>
    </div>

    @if (! $installed)
        <section class="rounded-xl border border-line bg-surface px-5 py-12 text-center text-[13.5px]">
            <p class="font-medium">ModSecurity kurulu değil</p>
            @if ($admin)
                <p class="mt-1 text-muted">WAF'ı kullanmak için önce sunucuya kurun.</p>
                <a href="{{ route('components.index', ['sekme' => 'diger']) }}" wire:navigate class="{{ $primary }} mx-auto mt-4 w-fit"><x-icon name="package-plus" />Yazılım Yönetimi'nden kur</a>
            @else
                <p class="mt-1 text-muted">Sunucu yöneticiniz WAF'ı kurduğunda bu sayfadan yönetebilirsiniz.</p>
            @endif
        </section>
    @else
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <form wire:submit="saveMode" class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-[13.5px]">
                <label for="waf-mode">Kip</label>
                <select id="waf-mode" wire:model="mode" class="{{ $field }} {{ $errors->has('mode') ? 'border-crit' : 'border-line' }}">
                    <option value="">Sunucu ayarı ({{ Waf::MODES[$server['mode']] }})</option>
                    @foreach (Waf::MODES as $k => $l)
                        <option value="{{ $k }}">{{ $l }}</option>
                    @endforeach
                </select>
                <button type="submit" class="{{ $btn }}">Kaydet</button>
                <x-status-badge class="ml-1" :status="match ($effective) { 'block' => new Badge('Saldırılar engelleniyor', 'ok'), 'detect' => new Badge('Saldırılar yalnızca kaydediliyor', 'info'), default => new Badge('Kapalı', 'warn') }" />
                <button type="button" wire:click="openExclude" class="{{ $btn }} ml-auto"><x-icon name="plus" />İstisna ekle</button>
                @error('mode') <p class="w-full text-[13px] text-crit">{{ $message }}</p> @enderror
            </form>
            <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
                @foreach ($this::TABS as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                            @class(['-mb-px h-11 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                        {{ $label }}{{ $key === 'istisnalar' && $exclusions->isNotEmpty() ? ' ('.$exclusions->count().')' : '' }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'istisnalar')
                <div class="relative overflow-x-auto">
                    <table class="w-full text-[13.5px] sm:min-w-[600px]">
                        <thead>
                            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">Kural</th>
                                <th class="px-3 py-2.5 font-medium">Nerede</th>
                                <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Not</th>
                                <th class="py-2.5 pr-5 pl-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($exclusions as $x)
                                <tr wire:key="x-{{ $x->id }}" class="hover:bg-surface-2/60">
                                    <td class="py-3 pr-3 pl-5 font-mono text-[13px] font-medium">{{ $x->rule_id }}</td>
                                    <td class="px-3 py-3 {{ $x->path === '' ? '' : 'font-mono text-[13px]' }}">{{ $x->pathLabel() }}</td>
                                    <td class="hidden px-3 py-3 text-muted sm:table-cell">{{ $x->note ?? '—' }}<span class="block text-2xs">{{ $x->user?->name }} · {{ $x->created_at->timezone($tz)->format('d.m.Y') }}</span></td>
                                    <td class="py-3 pr-5 pl-3 text-right"><button type="button" wire:click="removeExclusion({{ $x->id }})" class="text-[13px] text-crit hover:underline">Kaldır</button></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-12 text-center">
                                        <p class="font-medium">İstisna yok</p>
                                        <p class="mx-auto mt-1 max-w-md text-[13px] text-muted">Bir kural sitenin kendi isteklerini engelliyorsa (yanlış alarm), o kuralı yalnızca bu sitede ya da bir yolun altında kapatın.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @elseif ($wafError)
                <p class="px-5 py-10 text-center text-[13.5px] text-muted">{{ $wafError }}</p>
            @else
                <div class="relative overflow-x-auto">
                    <table class="w-full text-[13.5px] sm:min-w-[760px]">
                        <thead>
                            <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                <th class="py-2.5 pr-3 pl-5 font-medium">Zaman</th>
                                <th class="px-3 py-2.5 font-medium">Adres</th>
                                <th class="px-3 py-2.5 font-medium">İstek</th>
                                <th class="hidden px-3 py-2.5 font-medium lg:table-cell">Kural</th>
                                <th class="px-3 py-2.5 font-medium">Sonuç</th>
                                <th class="py-2.5 pr-5 pl-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($events as $e)
                                @php
                                    $rule = collect($e['rules'])->first();
                                    $path = parse_url((string) $e['uri'], PHP_URL_PATH) ?: '/';
                                @endphp
                                <tr wire:key="ev-{{ $e['id'] }}" class="align-top hover:bg-surface-2/60">
                                    <td class="num py-3 pr-3 pl-5 whitespace-nowrap text-muted">{{ $when(\Illuminate\Support\Carbon::parse($e['time'])) }}</td>
                                    <td class="px-3 py-3 font-mono text-[13px]">{{ $e['ip'] }}</td>
                                    <td class="max-w-[22rem] px-3 py-3"><span class="block truncate font-mono text-[13px]" title="{{ $e['method'] }} {{ $e['uri'] }}">{{ $e['method'] }} {{ $e['uri'] }}</span></td>
                                    <td class="hidden max-w-[18rem] px-3 py-3 lg:table-cell">
                                        @if ($rule)
                                            <span class="font-mono text-[13px]">{{ $rule['id'] }}</span>
                                            <span class="block truncate text-2xs text-muted" title="{{ $rule['message'] }}">{{ $rule['message'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 whitespace-nowrap"><x-status-badge :status="$e['blocked'] ? new Badge('Engellendi', 'crit') : new Badge('Kaydedildi', 'warn')" /></td>
                                    <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                        <div class="flex flex-col items-end gap-1">
                                        @if ($admin)
                                            @if (in_array($e['ip'], $blocked, true))
                                                <span class="text-2xs text-muted">Engelli</span>
                                            @else
                                                <button type="button" wire:click="openBlock(@js($e['ip']), {{ $site->id }}, @js('WAF: '.($rule ? $rule['id'].' '.$rule['message'] : $e['uri'])))" class="{{ $link }}">IP'yi engelle</button>
                                            @endif
                                        @endif
                                        @if ($rule)
                                            <button type="button" wire:click="openExclude({{ $rule['id'] }}, @js($path))" class="{{ $link }}" title="Bu istek sitenin kendi isteğiyse">Yanlış alarm</button>
                                        @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center">
                                        <p class="font-medium">Yakalanan saldırı yok</p>
                                        <p class="mt-1 text-[13px] text-muted">WAF'ın bu sitede kaydettiği ya da engellediği istekler burada görünür.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">Sunucu ayarı: {{ Waf::MODES[$server['mode']] }}, paranoya seviyesi {{ $server['paranoia'] }}. "Yalnızca izle" ile önce yanlış alarmları görün, sonra "Engelle"ye geçin.</p>
        </section>
    @endif

    {{-- İstisna ekle --}}
    <x-modal title="Kural istisnası" show="excluding">
        <form wire:submit="saveExclusion">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Kural istisnası</h2>
            <div class="grid items-start gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[6rem_minmax(0,1fr)]">
                <label for="x-rule" class="sm:pt-2">Kural</label>
                <div>
                    <input id="x-rule" wire:model="ruleId" inputmode="numeric" placeholder="942100" class="{{ $field }} w-40 font-mono text-[13px] {{ $errors->has('ruleId') ? 'border-crit' : 'border-line' }}">
                    @error('ruleId') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <label for="x-path" class="sm:pt-2">Yol</label>
                <div>
                    <input id="x-path" wire:model="path" placeholder="boş: tüm site, örneğin /wp-admin/" spellcheck="false" class="{{ $field }} w-full font-mono text-[13px] {{ $errors->has('path') ? 'border-crit' : 'border-line' }}">
                    @error('path')
                        <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-2xs text-muted">Kural yalnızca bu yolla başlayan isteklerde kapanır.</p>
                    @enderror
                </div>
                <label for="x-note" class="sm:pt-2">Not</label>
                <div>
                    <input id="x-note" wire:model="note" maxlength="200" placeholder="isteğe bağlı, örneğin Sayfa düzenleyicisi" class="{{ $field }} w-full {{ $errors->has('note') ? 'border-crit' : 'border-line' }}">
                    @error('note') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('excluding', false)" class="{{ $btn }}">Vazgeç</button>
                <button type="submit" class="{{ $primary }}">Kuralı kapat</button>
            </div>
        </form>
    </x-modal>

    @if ($admin)
        @include('livewire.security.block-modal')
    @endif
</div>
