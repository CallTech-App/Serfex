@php
    use App\Models\Site;
    use App\Services\Components\Components;
    use App\Support\Badge;
    $busyLabels = ['install' => 'Kuruluyor', 'update' => 'Güncelleniyor', 'remove' => 'Kaldırılıyor'];
    $badge = fn (array $r) => match (true) {
        $r['busy'] !== null => new Badge($busyLabels[$r['busy']] ?? 'Çalışıyor', 'info', true),
        ! $r['installed'] => new Badge('Kurulu değil', 'muted'),
        ! $r['running'] && $r['units'] !== [] => new Badge('Durdu', 'crit'),
        $r['latest'] !== null => new Badge('Güncelleme var', 'warn'),
        default => new Badge('Kurulu', 'ok'),
    };
    $link = 'text-[13px] text-accent hover:underline disabled:opacity-40 disabled:no-underline';
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[22px] font-semibold tracking-tight">Yazılım Yönetimi</h1>
            <p class="mt-1 max-w-3xl text-[13px] text-muted">Sunucuya web sunucusu, PHP sürümleri, veritabanı sunucuları ve diğer yazılımları kurun veya kaldırın. Bir adım başarısız olursa değişiklik otomatik geri alınır. Panelin kendi Nginx, PHP ve PostgreSQL'i ayrı çalışır ve burada listelenmez.</p>
        </div>
        <div class="flex items-center gap-3">
            @if ($checkedAt)
                <span class="text-2xs text-muted" title="{{ \Illuminate\Support\Carbon::parse($checkedAt)->timezone(config('app.timezone'))->format('d.m.Y H:i') }}">Son denetim {{ \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans() }}</span>
            @endif
            <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" @disabled($busy || $agentError) class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40">
                <x-icon name="refresh-cw" wire:loading.class="animate-spin" wire:target="check" />Güncellemeleri denetle
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
            <button type="button" wire:click="$set('refused', null)" class="text-muted hover:text-fg" aria-label="Kapat"><x-icon name="x" /></button>
        </div>
    @endif

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
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ $tab === 'diger' ? 'Bileşen' : 'Yazılım' }}</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">Sürüm</th>
                        <th class="px-3 py-2.5 font-medium">Durum</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">Kullanım</th>
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
                                        <span class="ml-1.5 rounded bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">varsayılan</span>
                                    @endif
                                    <span class="mt-0.5 block text-2xs text-muted">{{ $r['description'] }}</span>
                                    <span class="mt-0.5 block font-mono text-2xs text-muted sm:hidden">{{ $r['installed'] ? ($r['version'] ?? '') : '' }}</span>
                                    <div class="mt-2 sm:hidden">@include('livewire.components.actions')</div>
                                </td>
                                <td @class(['hidden px-3 py-3 font-mono text-[13px] whitespace-nowrap sm:table-cell', 'text-muted' => ! $r['installed']])>
                                    {{ $r['installed'] ? ($r['version'] ?: '—') : '—' }}
                                    @if ($r['latest'])
                                        <span class="block text-2xs text-warn">→ {{ $r['latest'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3"><x-status-badge :status="$badge($r)" /></td>
                                <td class="hidden px-3 py-3 text-[13px] text-muted md:table-cell">{{ $r['usage'] }}</td>
                                <td class="hidden max-w-[220px] py-3 pr-5 pl-3 text-right sm:table-cell">
                                    @include('livewire.components.actions')
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                    @if ($tab === 'veritabani' && $manager !== null)
                        <tr class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5"><span class="font-medium">phpMyAdmin ve Adminer</span><span class="mt-0.5 block text-2xs text-muted">Tarayıcıdan yönetim, veritabanı sunucusuyla birlikte kurulur</span></td>
                            <td class="hidden px-3 py-3 font-mono text-[13px] text-muted sm:table-cell">port {{ $managerPort }}</td>
                            <td class="px-3 py-3"><x-status-badge :status="($manager['configured'] ?? false) ? new Badge('Kurulu', 'ok') : new Badge('Kurulu değil', 'muted')" /></td>
                            <td class="hidden px-3 py-3 text-[13px] text-muted md:table-cell">Veritabanı sayfalarında</td>
                            <td class="hidden py-3 pr-5 pl-3 text-right sm:table-cell"><a href="{{ route('databases.settings') }}" wire:navigate class="{{ $link }}">Ayarlar</a></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        @if ($tab === 'web')
            <form wire:submit="saveDefault" class="grid grid-cols-1 gap-x-6 gap-y-2 border-t border-line px-5 py-4 text-[13.5px] md:grid-cols-[220px_1fr]">
                <label for="def-stack" class="font-medium md:pt-2">Yeni siteler için varsayılan</label>
                <div class="flex flex-wrap items-center gap-3">
                    <select id="def-stack" wire:model="defaultStack" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-80">
                        @foreach (Site::STACKS as $key => $label)
                            <option value="{{ $key }}" @disabled($key === Site::APACHE && ! $apacheInstalled)>{{ $label }}{{ $key === Site::APACHE && ! $apacheInstalled ? ' (Apache kurulu değil)' : '' }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kaydet</button>
                    @error('defaultStack') <p class="w-full text-[13px] text-crit">{{ $message }}</p> @enderror
                    <p class="w-full text-[13px] text-muted">Her site kendi PHP-FPM havuzunda çalışır. Var olan bir sitenin web sunucusu, site sayfasındaki <span class="text-fg">PHP ve Web Sunucusu</span> aracından değiştirilir.</p>
                </div>
            </form>
        @elseif ($tab === 'veritabani')
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">MySQL ve MariaDB aynı anda kurulamaz; PostgreSQL ikisinin yanında çalışır. Panelin kendi PostgreSQL'i ayrı çalışır, buradaki işlemler onu etkilemez. Veritabanları ve kullanıcılar <a href="{{ route('databases.index') }}" wire:navigate class="text-accent hover:underline">Veritabanları</a> sayfasından yönetilir.</p>
        @else
            <p class="border-t border-line px-5 py-3 text-2xs text-muted">Redis her Laravel uygulamasına kendi parolalı örneğini verir; uygulamanın Ortam bölümünden bağlanır. Node.js ve Composer, Laravel derlemelerinde kullanılır.</p>
        @endif
    </section>

    {{-- Onay --}}
    <x-modal title="{{ $confirm['title'] ?? 'Onay' }}" show="confirming" size="max-w-md">
        @if ($confirm)
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold">{{ $confirm['title'] }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ $confirm['text'] }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">Vazgeç</button>
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" @class(['h-9 rounded-md px-3.5 font-medium disabled:opacity-40', 'bg-crit text-on-crit' => $confirm['danger'], 'bg-accent text-accent-fg hover:brightness-110' => ! $confirm['danger']])>{{ $confirm['button'] }}</button>
                </div>
            </div>
        @endif
    </x-modal>

    {{-- İlerleme --}}
    @if ($operation)
        @php
            $verb = ['install' => 'kuruluyor', 'update' => 'güncelleniyor', 'remove' => 'kaldırılıyor'][$operation->action] ?? '';
            $doneText = ['install' => 'kuruldu', 'update' => 'güncellendi', 'remove' => 'kaldırıldı'][$operation->action] ?? '';
        @endphp
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" wire:key="op-{{ $operation->id }}">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ $operationName }} {{ $verb }}"
                 x-data="{
                    offset: 0, steps: [], log: '', rollback: false, status: @js($operation->status), error: @js($operation->error), timer: null, final: false,
                    parse(text) {
                        for (const line of text.split('\n')) {
                            if (line === '') continue;
                            this.log += line + '\n';
                            if (line.startsWith('▸ ')) {
                                this.steps.forEach(s => { if (s.state === 'run') s.state = 'ok'; });
                                this.steps.push({ text: line.slice(2), state: 'run' });
                            } else if (line.startsWith('↺ ')) {
                                this.rollback = true;
                            }
                        }
                    },
                    async tick() {
                        let r;
                        try { r = await $wire.readRun(@js($operation->run), this.offset); } catch (e) { this.timer = setTimeout(() => this.tick(), 3000); return; }
                        if (r === null) return;
                        if (r.data) { this.parse(r.data); this.$nextTick(() => { const el = this.$refs.log; if (el) el.scrollTop = el.scrollHeight; }); }
                        this.offset = r.offset;
                        if (r.more) { this.timer = setTimeout(() => this.tick(), 0); return; }
                        if (r.done) {
                            if (! this.final) { this.final = true; this.timer = setTimeout(() => this.tick(), 300); return; }
                            this.status = r.status; this.error = r.error;
                            this.steps.forEach(s => { if (s.state === 'run') s.state = r.status === 'succeeded' ? 'ok' : 'fail'; });
                            $wire.$refresh();
                            return;
                        }
                        this.timer = setTimeout(() => this.tick(), r.data ? 500 : 1200);
                    },
                    destroy() { clearTimeout(this.timer); },
                 }" x-init="tick()">
                <div class="flex h-12 items-center border-b border-line px-5">
                    <h2 class="text-[14.5px] font-semibold">
                        <span x-show="status === 'queued' || status === 'running'">{{ $operationName }} {{ $verb }}</span>
                        <span x-show="status === 'succeeded'" x-cloak>{{ $operationName }} {{ $doneText }}</span>
                        <span x-show="status === 'failed'" x-cloak>{{ $operationName }}: işlem tamamlanamadı</span>
                    </h2>
                </div>
                <div class="space-y-3 p-5 text-[13.5px]">
                    <p x-show="status === 'queued' && steps.length === 0" class="flex items-center gap-2.5 text-[13px] text-muted"><x-icon name="loader-circle" class="size-4 animate-spin text-accent" />Sırada bekliyor</p>
                    <ol class="space-y-2 text-[13px]">
                        <template x-for="(s, i) in steps" :key="i">
                            <li class="flex items-start gap-2.5">
                                <span class="mt-px shrink-0">
                                    <x-icon name="check" class="size-4 text-ok" x-show="s.state === 'ok'" />
                                    <x-icon name="loader-circle" class="size-4 animate-spin text-accent" x-show="s.state === 'run'" />
                                    <x-icon name="x" class="size-4 text-crit" x-show="s.state === 'fail'" />
                                </span>
                                <span x-text="s.text" class="break-words"></span>
                            </li>
                        </template>
                    </ol>
                    <div x-show="status === 'failed'" x-cloak class="rounded-lg border border-line bg-crit-soft p-3 text-[13px]">
                        <p class="font-medium text-crit" x-text="rollback ? 'Bir adım başarısız oldu; yapılan değişiklikler geri alındı.' : 'İşlem tamamlanamadı; sunucuda bir şey değişmedi.'"></p>
                        <p class="mt-1 font-mono text-[12.5px] break-words text-muted" x-text="error"></p>
                    </div>
                    <details class="text-[13px]" @if ($operation->status === 'failed') open @endif>
                        <summary class="cursor-pointer text-muted hover:text-fg">Ayrıntılı günlük</summary>
                        <pre x-ref="log" x-text="log" class="scroll-thin mt-2 h-56 overflow-auto rounded-md bg-[#0f1a1c] p-3 font-mono text-[12px] whitespace-pre-wrap text-[#d7e4e4]"></pre>
                    </details>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
                    <p class="text-2xs text-muted" x-show="status === 'queued' || status === 'running'">Bu pencereyi kapatabilirsiniz; işlem arka planda sürer.</p>
                    <span x-show="status !== 'queued' && status !== 'running'"></span>
                    <button type="button" wire:click="closeOperation" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ModSecurity ayarları --}}
    <x-modal title="ModSecurity ayarları" show="configuring">
        <form wire:submit="saveSettings">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">ModSecurity ayarları</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <fieldset>
                    <legend class="mb-2 font-medium">Kip</legend>
                    <div class="grid gap-2">
                        @foreach (['detect' => ['Yalnızca izle', 'Saldırılar /var/log/servik/modsecurity altına yazılır, istekler engellenmez. Yanlış alarmları görmek için önerilen başlangıç.'], 'block' => ['Engelle', 'Kurallara takılan istekler 403 ile reddedilir.']] as $key => [$label, $hint])
                            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 {{ $modsecMode === $key ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2' }}">
                                <input type="radio" wire:model.live="modsecMode" value="{{ $key }}" class="mt-0.5 size-4 accent-accent">
                                <span><span class="block font-medium">{{ $label }}</span><span class="mt-0.5 block text-[12.5px] text-muted">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div>
                    <label for="ms-paranoia" class="mb-1.5 block font-medium">Hassasiyet (OWASP CRS paranoya seviyesi)</label>
                    <select id="ms-paranoia" wire:model="modsecParanoia" class="h-9 w-full rounded-md border border-line bg-surface px-2.5">
                        <option value="1">1 · Az yanlış alarm (önerilen)</option>
                        <option value="2">2 · Daha sıkı</option>
                        <option value="3">3 · Çok sıkı, ince ayar gerekir</option>
                        <option value="4">4 · En sıkı</option>
                    </select>
                </div>
                @error('modsecMode') <p class="text-[13px] text-crit">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('configuring', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Kaydet</button>
            </div>
        </form>
    </x-modal>
</div>
