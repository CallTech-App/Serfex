@php
    use App\Services\Updates\Updates;
    use App\Support\Badge;
    use Illuminate\Support\Carbon;
    $when = fn ($d) => $d ? \App\Support\When::dateTime(Carbon::parse($d)) : '—';
    $installed = (bool) ($status['installed'] ?? false);
    $version = $status['version'] ?? config('servik.version');
    $previous = ($status['previous'] ?? '') ?: null;
    $result = fn ($u) => match ($u->status) {
        'succeeded' => new Badge($u->kind === 'rollback' ? __('Dönüldü') : __('Kuruldu'), 'ok'),
        'rolled_back' => new Badge(__('Geri alındı'), 'warn'),
        'failed' => new Badge(__('Başarısız'), 'crit'),
        default => new Badge(__('Sürüyor'), 'info', true),
    };
    $notes = fn (?string $md) => \App\Services\Ai\Transcript::markdown(trim((string) $md) ?: __('Bu sürüm için not yazılmamış.'));
    $link = 'text-[13px] text-accent hover:underline disabled:opacity-40 disabled:no-underline';
@endphp
<x-settings-page current="settings.updates" :title="__('Güncellemeler')">
    <x-slot:description><x-t text="Servik panelini ve sunucu ajanını yeni sürümlere güncelleyin. Sunucudaki yazılımlar :link'nden güncellenir."><x-slot:link><a href="{{ route('components.index') }}" wire:navigate class="text-accent hover:underline">{{ __('Yazılım Yönetimi') }}</a></x-slot:link></x-t></x-slot:description>

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
    @if ($recent)
        @if ($recent->status === 'succeeded')
            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-ok-soft px-5 py-3 text-[13.5px]" role="status">
                <x-icon name="circle-check" class="shrink-0 text-ok" />
                <p class="flex-1">
                    {{ $recent->kind === 'rollback' ? __('Servik :to_version sürümüne dönüldü', ['to_version' => $recent->to_version]) : __('Servik :to_version kuruldu', ['to_version' => $recent->to_version]) }}{{ $recent->duration() ? ' ('.$recent->duration().')' : '' }}.
                </p>
                @if ($recent->kind === 'upgrade' && $previous === $recent->from_version)
                    <button type="button" wire:click="ask('rollback')" class="font-medium text-accent hover:underline">{{ __('Geri al') }}</button>
                @endif
            </div>
        @else
            <div class="rounded-xl border border-line bg-warn-soft px-5 py-3 text-[13.5px]" role="alert">
                <p class="flex items-center gap-3 font-medium">
                    <x-icon name="triangle-alert" class="shrink-0 text-warn" />
                    @if ($recent->status === 'rolled_back')
                        {{ __('Servik :to_version denetimden geçmedi; :from_version sürümüne otomatik dönüldü.', ['to_version' => $recent->to_version, 'from_version' => $recent->from_version]) }}
                    @else
                        {{ $recent->kind === 'rollback' ? __('Önceki sürüme dönülemedi.') : __('Servik :to_version kurulamadı.', ['to_version' => $recent->to_version]) }}
                    @endif
                </p>
                @if ($recent->error)
                    <p class="mt-1 ml-[30px] font-mono text-[12.5px] break-words text-muted">{{ \Illuminate\Support\Str::limit($recent->error, 400) }}</p>
                @endif
            </div>
        @endif
    @endif

    {{-- Servik sürümü --}}
    <section class="rounded-xl border border-line bg-surface">
        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 text-[13.5px]">
                <p class="text-[15px] font-semibold">
                    <x-t text="Servik :version kurulu"><x-slot:version><span class="font-mono">{{ $version }}</span></x-slot:version></x-t>
                    @if ($release)
                        <x-t text="· :version hazır"><x-slot:version><span class="font-mono text-accent">{{ $release['version'] }}</span></x-slot:version></x-t>
                    @elseif ($installed)
                        · <span class="font-normal text-muted">{{ __('güncel') }}</span>
                    @endif
                </p>
                @if (! $installed && $status !== null)
                    <p class="mt-1 max-w-2xl text-[13px] text-muted"><x-t text="Bu sunucu kurulum betiğiyle kurulmamış (geliştirme kurulumu), bu yüzden Servik kendini burada güncelleyemez. Sunuculara Servik :part ile kurulur; güncellemeler o kurulumda bu sayfadan yapılır."><x-slot:part><span class="font-mono">install.sh</span></x-slot:part></x-t></p>
                @elseif ($release)
                    <p class="mt-1 text-[13px] text-muted">
                        {{ collect([$release['published_at'] ? __(':date yayımlandı', ['date' => \App\Support\When::date(Carbon::parse($release['published_at']))]) : null, ($release['prerelease'] ?? false) ? __('beta sürüm') : null, __('panel yaklaşık bir dakika bakımda kalır, siteler, e-posta ve DNS etkilenmez.')])->filter()->implode(' · ') }}
                    </p>
                    @foreach ($releases as $r)
                        <div class="mt-3 max-w-2xl">
                            @if (count($releases) > 1)
                                <p class="text-2xs font-medium tracking-[.06em] text-muted uppercase">Servik {{ $r['version'] }}</p>
                            @endif
                            <div class="release-notes mt-1 text-[13px] leading-relaxed [&_a]:text-accent [&_a]:underline [&_code]:font-mono [&_code]:text-[12.5px] [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_li]:mt-0.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mt-1 [&_ul]:list-disc [&_ul]:pl-5">{!! $notes($r['notes'] ?? '') !!}</div>
                            @if ($r['url'] ?? null)
                                <a href="{{ $r['url'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-2xs text-muted hover:text-accent">{{ __('Sürüm sayfası') }}</a>
                            @endif
                        </div>
                    @endforeach
                @elseif ($installed)
                    <p class="mt-1 text-[13px] text-muted">{{ ($status['checked_at'] ?? null) ? __('Son denetimde yeni sürüm yoktu.') : __('Henüz denetlenmedi.') }}</p>
                @endif
            </div>
            @if ($installed)
                <div class="flex shrink-0 items-center gap-2">
                    @if ($release)
                        <button type="button" wire:click="ask('upgrade')" @disabled($blocked !== null) title="{{ $blocked }}" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                            <x-icon name="download" />{{ __('Güncelle') }}
                        </button>
                    @else
                        <span class="flex h-9 items-center gap-2 rounded-md border border-line px-3.5 text-[13.5px] text-muted"><x-icon name="check" />{{ __('Güncel') }}</span>
                    @endif
                </div>
            @endif
        </div>

        @if ($running)
            @php
                $title = $running->kind === 'rollback' ? __('Servik :to_version sürümüne dönülüyor', ['to_version' => $running->to_version]) : __('Servik :to_version kuruluyor', ['to_version' => $running->to_version]);
            @endphp
            <div wire:key="update-{{ $running->id }}" wire:ignore class="border-t border-line px-5 py-4 text-[13.5px]"
                 x-data="{
                    url: @js(route('settings.updates.progress', $running->run)),
                    offset: 0, status: @js($running->status), error: @js($running->error), agent: true, timer: null, final: false, log: '', rollback: false,
                    steps: [{ text: @js(__('Panel yedeği alınıyor')), state: 'run' }],
                    parse(text) {
                        for (const line of text.split('\n')) {
                            if (line === '') continue;
                            this.log += line + '\n';
                            if (line.startsWith('▸ ')) {
                                this.steps.forEach(s => { if (s.state === 'run') s.state = 'ok'; });
                                this.steps.push({ text: line.slice(2), state: 'run' });
                            } else if (line.startsWith('↺ ')) {
                                if (! this.rollback) this.steps.forEach(s => { if (s.state === 'run') s.state = 'fail'; });
                                this.rollback = true;
                            }
                        }
                    },
                    async tick() {
                        let r;
                        try {
                            const res = await fetch(this.url + '?offset=' + this.offset, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                            if (! res.ok) throw new Error(res.status);
                            r = await res.json();
                        } catch (e) {
                            // The panel restarts during the switch.
                            this.agent = false;
                            this.timer = setTimeout(() => this.tick(), 2000);
                            return;
                        }
                        this.agent = r.agent;
                        this.status = r.status; this.error = r.error;
                        if (r.status !== 'queued' && r.status !== 'backing_up' && this.steps[0].state === 'run') this.steps[0].state = r.status === 'failed' && ! r.data && this.offset === 0 ? 'fail' : 'ok';
                        if (r.data) { this.parse(r.data); this.$nextTick(() => { const el = this.$refs.log; if (el) el.scrollTop = el.scrollHeight; }); }
                        this.offset = r.offset;
                        if (r.more) { this.timer = setTimeout(() => this.tick(), 0); return; }
                        if (r.done) {
                            this.steps.forEach(s => { if (s.state === 'run') s.state = r.status === 'succeeded' ? 'ok' : 'fail'; });
                            setTimeout(() => window.location.reload(), 1500);
                            return;
                        }
                        this.timer = setTimeout(() => this.tick(), r.data ? 500 : 1500);
                    },
                    destroy() { clearTimeout(this.timer); },
                 }" x-init="tick()">
                <p class="font-medium">{{ $title }}</p>
                <ol class="mt-3 space-y-2 text-[13px]">
                    <template x-for="(s, i) in steps" :key="i">
                        <li class="flex items-start gap-2.5">
                            <span class="mt-px shrink-0">
                                <x-icon name="circle-check" class="size-4 text-ok" x-show="s.state === 'ok'" />
                                <x-icon name="loader-circle" class="size-4 animate-spin text-accent" x-show="s.state === 'run'" />
                                <x-icon name="circle-x" class="size-4 text-crit" x-show="s.state === 'fail'" />
                            </span>
                            <span x-text="s.text" class="break-words"></span>
                        </li>
                    </template>
                    <li x-show="rollback && status === 'running'" x-cloak class="flex items-start gap-2.5 text-warn">
                        <x-icon name="undo-2" class="mt-px size-4 shrink-0" /><span>{{ __('Bir adım başarısız oldu; :from_version sürümüne geri dönülüyor', ['from_version' => $running->from_version]) }}</span>
                    </li>
                </ol>
                <p x-show="! agent" x-cloak class="mt-3 flex items-center gap-2 text-[13px] text-muted"><x-icon name="loader-circle" class="size-4 animate-spin" />{{ __('Panel yeniden başlatılıyor, bağlantı bekleniyor…') }}</p>
                <p x-show="status === 'failed' && error" x-cloak x-text="error" class="mt-3 rounded-lg bg-crit-soft p-3 font-mono text-[12.5px] break-words text-crit"></p>
                <details class="mt-3 text-[13px]">
                    <summary class="cursor-pointer text-muted hover:text-fg">{{ __('Ayrıntılı günlük') }}</summary>
                    <pre x-ref="log" x-text="log" class="scroll-thin mt-2 h-56 overflow-auto rounded-md bg-[#0f1a1c] p-3 font-mono text-[12px] whitespace-pre-wrap text-[#d7e4e4]"></pre>
                </details>
                <p class="mt-3 text-2xs text-muted">{{ __('Bu sayfayı kapatabilirsiniz; güncelleme sunucuda sürer. Panel geçiş sırasında kısa bir süre "Servik güncelleniyor" sayfasını gösterir.') }}</p>
            </div>
        @endif

        @if ($installed)
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-line px-5 py-3 text-[13px] text-muted">
                @if ($release)
                    <span class="flex items-center gap-1.5"><x-icon name="undo-2" class="size-4" /><x-t text="Önce panelin yedeği alınır; sorun çıkarsa otomatik olarak :version sürümüne döner."><x-slot:version><span class="font-mono text-fg">{{ $version }}</span></x-slot:version></x-t></span>
                @endif
                <span class="num">
                    <x-t text="Kanal: :channel"><x-slot:channel><span class="text-fg">{{ Updates::CHANNELS[$channel] ?? __('Kararlı') }}</span></x-slot:channel></x-t>
                    · {{ ($status['checked_at'] ?? null) ? __('son denetim :checked_at', ['checked_at' => Carbon::parse($status['checked_at'])->diffForHumans()]) : __('henüz denetlenmedi') }}
                </span>
                @if ($status['check_error'] ?? null)
                    <span class="text-warn" title="{{ $status['check_error'] }}">{{ __('Son denetim başarısız oldu') }}</span>
                @endif
                <span class="ml-auto flex items-center gap-4">
                    @if ($previous)
                        <button type="button" wire:click="ask('rollback')" @disabled($blocked !== null) class="{{ $link }}">{{ __('Önceki sürüme dön (:previous)', ['previous' => $previous]) }}</button>
                    @endif
                    <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" @disabled($running !== null) class="flex items-center gap-1.5 {{ $link }}">
                        <x-icon name="refresh-cw" class="size-4" wire:loading.class="animate-spin" wire:target="check" />{{ __('Şimdi denetle') }}
                    </button>
                </span>
            </div>
        @endif
    </section>

    {{-- Geçmiş ve ilke --}}
    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
            @foreach (['gecmis' => __('Güncelleme geçmişi'), 'ilke' => __('Güncelleme ilkesi')] as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                        @class(['-mb-px h-12 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'gecmis')
            <div class="overflow-x-auto">
                <table class="w-full text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Sürüm') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Tarih') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Sonuç') }}</th>
                            <th class="hidden py-2.5 pr-5 pl-3 font-medium md:table-cell">{{ __('Not') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($history as $u)
                            <tr wire:key="h-{{ $u->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 font-mono text-[13px] whitespace-nowrap">
                                    {{ $u->from_version }} <span class="text-muted">→</span> {{ $u->to_version }}
                                    <span class="mt-0.5 block font-sans text-2xs text-muted sm:hidden">{{ $when($u->started_at ?? $u->created_at) }}</span>
                                </td>
                                <td class="num hidden px-3 py-3 whitespace-nowrap sm:table-cell">{{ $when($u->started_at ?? $u->created_at) }}</td>
                                <td class="px-3 py-3"><x-status-badge :status="$result($u)" /></td>
                                <td class="hidden max-w-md py-3 pr-5 pl-3 text-[13px] text-muted md:table-cell">
                                    {{ collect([$u->kind === 'rollback' ? __('Önceki sürüme dönüş') : null, $u->user?->name, $u->duration()])->filter()->implode(' · ') }}
                                    @if ($u->error && $u->status !== 'succeeded')
                                        <span class="mt-0.5 block text-2xs break-words {{ $u->status === 'rolled_back' ? 'text-warn' : 'text-crit' }}">{{ \Illuminate\Support\Str::limit($u->error, 160) }}</span>
                                    @endif
                                    @if ($u->backup_id)
                                        <a href="{{ route('backups.index') }}" wire:navigate class="mt-0.5 block text-2xs text-accent hover:underline">{{ __('Önceki panel yedeği') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center">
                                    <p class="font-medium">{{ __('Henüz güncelleme yapılmadı') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('Yeni bir sürüm çıktığında burada "Güncelle" ile kurulur.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="saveChannel" class="max-w-3xl px-5 py-2 text-[13.5px]">
                <div class="divide-y divide-line">
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <label for="channel" class="sm:pt-2">{{ __('Kanal') }}</label>
                        <div>
                            <select id="channel" wire:model="channel" class="h-9 w-full rounded-md border border-line bg-surface px-2.5 sm:w-60">
                                @foreach (Updates::CHANNELS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Kararlı yalnızca yayımlanmış sürümleri, Beta deneme sürümlerini de getirir. Beta sürümler canlı sunucular için önerilmez.') }}</p>
                        </div>
                    </div>
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>{{ __('Denetim') }}</span>
                        <p class="text-[13px] text-muted">{{ __('Her gün 04:30\'da yeni sürüm aranır; bulunursa Genel Bakış\'ta ve menüde gösterilir. Güncellemeyi siz başlatırsınız, Servik kendiliğinden güncellenmez.') }}</p>
                    </div>
                    <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                        <span>{{ __('Saklanan sürüm') }}</span>
                        <p class="text-[13px] text-muted">{{ __('Bir önceki sürüm sunucuda kalır; "Önceki sürüme dön" onu birkaç saniyede geri getirir. Güncellemeden önce alınan panel yedekleri Yedekler\'de :update_days gün tutulur.', ['update_days' => \App\Services\Backups\Backups::UPDATE_DAYS]) }}</p>
                    </div>
                </div>
                <div class="flex gap-2 border-t border-line py-4">
                    <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
                </div>
            </form>
        @endif
    </section>

    {{-- Onay --}}
    <x-modal :title="__('Onay')" show="confirming" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            @if ($confirming === 'rollback')
                <p class="font-semibold">{{ __('Servik :previous sürümüne dönülsün mü?', ['previous' => $previous]) }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ __('Önce panelin yedeği alınır. :version sürümünün veritabanı değişiklikleri geri alınır, ardından :previous çalışır. Panel yaklaşık bir dakika bakımda kalır; siteler etkilenmez. Yeniden :version sürümüne geçmek için Güncelle\'ye basmanız yeter.', ['version' => $version, 'previous' => $previous]) }}</p>
            @else
                <p class="font-semibold">{{ __('Servik :version sürümüne güncellensin mi?', ['version' => $release['version'] ?? '']) }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ __('Önce panelin yedeği alınır. Panel yaklaşık bir dakika bakımda kalır; siteler, e-posta ve DNS çalışmaya devam eder. Yeni sürüm denetimden geçmezse :version sürümüne otomatik dönülür.', ['version' => $version]) }}</p>
            @endif
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirming', null)" class="h-9 rounded-md border border-line px-3.5 hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" @class(['h-9 rounded-md px-3.5 font-medium disabled:opacity-40', 'bg-crit text-on-crit' => $confirming === 'rollback', 'bg-accent text-accent-fg hover:brightness-110' => $confirming !== 'rollback'])>
                    {{ $confirming === 'rollback' ? __('Önceki sürüme dön') : __('Güncelle') }}
                </button>
            </div>
        </div>
    </x-modal>
</x-settings-page>
