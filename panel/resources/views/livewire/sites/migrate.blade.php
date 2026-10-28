@php
    use App\Services\Migrations\SiteMigrations;
    use App\Support\Bytes;
    use App\Support\When;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $field = 'h-9 rounded-md border border-line bg-surface px-3 text-[13.5px] outline-none focus:border-accent';
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
    $plan = $migration?->plan ?? [];
    $plesk = $migration?->source === 'plesk';
    $copy = fn (string $value) => '<button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText('.e(json_encode($value)).'); done = true; setTimeout(() => done = false, 1500)" class="shrink-0 text-[12.5px] text-accent hover:underline"><span x-show="!done">'.e(__('Kopyala')).'</span><span x-show="done" x-cloak>'.e(__('Kopyalandı')).'</span></button>';
    $statusBadge = [
        'succeeded' => ['bg-ok-soft text-ok', __('Taşındı')],
        'partial' => ['bg-warn-soft text-warn', __('Kısmen taşındı')],
        'failed' => ['bg-crit-soft text-crit', __('Başarısız')],
        'pulling' => ['bg-accent-soft text-accent', __('Alınıyor')],
        'queued' => ['bg-surface-2 text-muted', __('Sırada')],
        'converting' => ['bg-accent-soft text-accent', __('Dönüştürülüyor')],
        'restoring' => ['bg-accent-soft text-accent', __('Siteler kuruluyor')],
    ];
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Başka sunucudan taşı') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Başka sunucudan taşı') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Bir cPanel hesabının tam yedeğini yükleyin ya da bir Plesk sunucusuna SSH ile bağlanıp aboneliği alın: siteleri, dosyaları, veritabanları, posta kutuları, DNS kayıtları ve SSL sertifikalarıyla bu sunucuya taşınır. Eski sunucu olduğu gibi kalır.') }}</p>
    </div>

    @if ($error)
        <p class="flex gap-2 rounded-lg bg-crit-soft px-4 py-3 text-[13px] text-crit" role="alert"><x-icon name="circle-x" class="mt-0.5 shrink-0" />{{ $error }}</p>
    @endif

    @if ($migration === null)
        {{-- 1. Arşivi seçin --}}
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="border-b border-line px-5 pt-4">
                <h2 class="text-[15px] font-semibold">{{ __('Taşınacak hesabı seçin') }}</h2>
                <div class="scroll-thin mt-3 flex gap-4 overflow-x-auto text-[13.5px]" role="tablist">
                    @foreach (['upload' => __('cPanel yedeği yükle'), 'folder' => __('İçe aktarma klasörü'), 'plesk' => __('Plesk sunucusu')] as $k => $l)
                        <button type="button" role="tab" wire:click="$set('from', '{{ $k }}')" @class(['-mb-px h-9 border-b-2 whitespace-nowrap', 'border-accent font-medium' => $from === $k, 'border-transparent text-muted hover:text-fg' => $from !== $k])>{{ $l }}</button>
                    @endforeach
                </div>
            </div>
            <div class="space-y-4 p-5 text-[13.5px]">
                @if ($from === 'upload')
                    <div x-data="{
                            file: null, sent: 0, busy: false, reading: false, error: '',
                            async send() {
                                if (! this.file) return;
                                this.busy = true; this.error = ''; this.sent = 0;
                                const id = await $wire.newUpload();
                                const size = {{ \App\Services\Backups\BackupImports::UPLOAD_CHUNK }};
                                while (this.sent < this.file.size || this.sent === 0) {
                                    const part = this.file.slice(this.sent, this.sent + size);
                                    const r = await fetch(@js(url('/backups/upload')) + '/' + id + '?offset=' + this.sent, { method: 'POST', body: part, headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Content-Type': 'application/octet-stream' } });
                                    const j = await r.json().catch(() => ({}));
                                    if (! r.ok) { this.error = j.error || @js(__('Yükleme durdu.')); this.busy = false; return; }
                                    this.sent = j.size;
                                    if (this.file.size === 0) break;
                                }
                                this.reading = true;
                                await $wire.inspectUpload(id);
                                this.busy = false; this.reading = false;
                            },
                         }">
                        <input type="file" accept=".gz,.tgz,.tar" @change="file = $event.target.files[0]" class="block w-full text-[13px] file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-surface file:px-3 file:text-[13px] hover:file:bg-surface-2">
                        <div x-show="busy" x-cloak class="mt-3">
                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full bg-accent" :style="`width: ${file ? Math.round(sent / Math.max(file.size, 1) * 100) : 0}%`"></div></div>
                            <p class="num mt-1 text-2xs text-muted" x-show="! reading" x-text="`${Math.round(sent / 1048576)} / ${Math.round((file?.size ?? 0) / 1048576)} MB`"></p>
                            <p class="mt-1 text-2xs text-muted" x-show="reading">{{ __('Arşiv okunuyor…') }}</p>
                        </div>
                        <p x-show="error" x-text="error" x-cloak class="mt-2 text-[13px] text-crit"></p>
                        <div class="mt-3 flex justify-end">
                            <button type="button" @click="send()" :disabled="! file || busy" class="{{ $primary }}"><x-icon name="upload" />{{ __('Yükle ve oku') }}</button>
                        </div>
                    </div>
                @elseif ($from === 'plesk')
                    @include('livewire.sites.migrate-plesk')
                @else
                    <p class="text-[13px] text-muted"><x-t text="Büyük arşivleri eski sunucudan :part klasörüne kopyalayın (örneğin :part2 ile), sonra burada seçin."><x-slot:part><span class="font-mono">/var/lib/serfex/backups/import</span></x-slot:part><x-slot:part2><span class="font-mono">scp</span></x-slot:part2></x-t></p>
                    @isset($filesError)
                        <p class="text-[13px] text-crit">{{ $filesError }}</p>
                    @endisset
                    <div class="divide-y divide-line rounded-lg border border-line">
                        @forelse ($files ?? [] as $f)
                            <div class="flex items-center gap-3 px-3 py-2 text-[13px]">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-mono">{{ $f['file'] }}</span>
                                    <span class="num block text-2xs text-muted">{{ Bytes::format($f['size']) }}@if ($f['modified']) · {{ When::dateTime(\Illuminate\Support\Carbon::parse($f['modified'])) }}@endif</span>
                                </span>
                                <button type="button" wire:click="inspectFile(@js($f['file']))" wire:loading.attr="disabled" class="text-[13px] text-accent hover:underline disabled:opacity-40">
                                    <span wire:loading.remove wire:target="inspectFile(@js($f['file']))">{{ __('Oku') }}</span>
                                    <span wire:loading wire:target="inspectFile(@js($f['file']))">{{ __('Okunuyor…') }}</span>
                                </button>
                            </div>
                        @empty
                            <p class="px-3 py-4 text-center text-[13px] text-muted">{{ __('Klasörde .tar.gz arşivi yok.') }}</p>
                        @endforelse
                    </div>
                @endif
                @if ($from !== 'plesk')
                <div class="rounded-lg bg-surface-2 p-4 text-[13px]">
                    <p class="font-medium">{{ __('Yedeği nereden alırım?') }}</p>
                    <ul class="mt-1.5 list-disc space-y-1 pl-5 text-muted">
                        <li><x-t text="cPanel'de :path → :button. Arşiv adı :name gibidir."><x-slot:path><span class="text-fg">{{ __('Dosyalar → Yedekleme') }}</span></x-slot:path><x-slot:button><span class="text-fg">{{ __('Tam hesap yedeğini indir') }}</span></x-slot:button><x-slot:name><span class="font-mono">backup-…_{{ __('kullanici') }}.tar.gz</span></x-slot:name></x-t></li>
                        <li><x-t text="WHM'de ya da sunucuda root olarak: :cmd. Arşiv :file olarak oluşur."><x-slot:cmd><span class="font-mono text-fg">/scripts/pkgacct {{ __('kullanici') }}</span></x-slot:cmd><x-slot:file><span class="font-mono">/home/cpmove-{{ __('kullanici') }}.tar.gz</span></x-slot:file></x-t></li>
                        <li>{{ __('Plesk\'ten taşımak için yedek gerekmez: Plesk sunucusu sekmesinden eski sunucuya bağlanın.') }}</li>
                    </ul>
                </div>
                @endif
            </div>
        </section>

        @if ($history->isNotEmpty())
            <section class="min-w-0 rounded-xl border border-line bg-surface">
                <h2 class="border-b border-line px-5 py-3 text-[15px] font-semibold">{{ __('Önceki taşımalar') }}</h2>
                <div class="divide-y divide-line">
                    @foreach ($history as $h)
                        <a href="{{ route('sites.migrate', ['migration' => $h->id]) }}" wire:navigate wire:key="h-{{ $h->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 text-[13.5px] hover:bg-surface-2/60">
                            <span class="font-mono">{{ $h->plan['user'] ?? '' }}</span>
                            <span class="rounded-full bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ $h->source === 'plesk' ? 'Plesk' : 'cPanel' }}</span>
                            <span class="text-muted">{{ implode(', ', $h->options['sites'] ?? []) }}</span>
                            <span class="num ml-auto text-2xs text-muted">{{ When::dateTime($h->finished_at ?? $h->created_at) }}</span>
                            <span class="rounded-full px-2 py-0.5 text-2xs font-medium {{ $statusBadge[$h->status][0] ?? '' }}">{{ $statusBadge[$h->status][1] ?? $h->status }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @elseif ($migration->status === 'ready')
        @php
            $siteRows = $plan['sites'] ?? [];
            $mailboxes = collect($plan['mail'] ?? [])->filter(fn ($m) => $m['site'] !== '')->sum(fn ($m) => count($m['mailboxes']));
            $certs = collect($siteRows)->whereNotNull('certificate')->count();
            $partInfo = [
                'files' => [__('Dosyalar'), Bytes::format($plan['files_size'] ?? 0), true, null],
                'databases' => [__('Veritabanları'), trans_choice(':count veritabanı', count($plan['databases'] ?? []), ['count' => count($plan['databases'] ?? [])]).' · '.Bytes::format($plan['databases_size'] ?? 0), $review['mysql'], __('MySQL kurulu değil. Önce Yazılım Yönetimi\'nden kurun.')],
                'mail' => [__('E-posta'), trans_choice(':count posta kutusu', $mailboxes, ['count' => $mailboxes]).' · '.Bytes::format($plan['mail_size'] ?? 0), $review['mail'], __('Posta sunucusu kurulu değil. Önce E-posta ayarlarından kurun.')],
                'dns' => [__('DNS'), trans_choice(':count bölge', count($plan['zones'] ?? []), ['count' => count($plan['zones'] ?? [])]), true, null],
                'certificates' => [__('SSL sertifikaları'), trans_choice(':count geçerli sertifika', $certs, ['count' => $certs]), true, null],
            ];
            $warnings = array_map(fn ($n) => SiteMigrations::noteText($n), $plan['notes'] ?? []);
            foreach ($plan['database_users'] ?? [] as $u) {
                if (! $u['keeps_password']) {
                    $warnings[] = __(':user veritabanı kullanıcısının eski parolası bu sunucuda kullanılamıyor; yeni parola verilir ve sitenin ayar dosyasına (wp-config.php, .env…) yazılır.', ['user' => $u['name']]);
                }
                if (! empty($u['remote_hosts'])) {
                    $warnings[] = __(':user için uzaktan erişim izinleri (:hosts) taşınmaz; gerekirse Veritabanları\'ndan açın.', ['user' => $u['name'], 'hosts' => implode(', ', $u['remote_hosts'])]);
                }
            }
            foreach ($review['existing_databases'] as $d) {
                $warnings[] = SiteMigrations::noteText(['code' => 'database-exists', 'subject' => $d]);
            }
            foreach ($plan['mail'] ?? [] as $md) {
                foreach ($md['mailboxes'] as $b) {
                    if ($md['site'] !== '' && ! $b['keeps_password']) {
                        $warnings[] = __(':box posta kutusunun parolası bu sunucuda kullanılamayan bir biçimde; yeni parola verilir.', ['box' => $b['name'].'@'.$md['domain']]);
                    }
                    if ($md['site'] !== '' && ($b['mdbox'] ?? false)) {
                        $warnings[] = __(':box posta kutusu mdbox biçiminde; kutu açılır ama içindeki iletiler taşınmaz.', ['box' => $b['name'].'@'.$md['domain']]);
                    }
                }
            }
            if (count($plan['cron'] ?? []) > 0) {
                $warnings[] = trans_choice(':count zamanlanmış görev taşınmaz; taşıma bitince raporda listelenir.', count($plan['cron']), ['count' => count($plan['cron'])]);
            }
            if (count($plan['ftp'] ?? []) > 0) {
                $warnings[] = trans_choice(':count FTP hesabı taşınmaz; taşıma bitince raporda listelenir.', count($plan['ftp']), ['count' => count($plan['ftp'])]);
            }
            if (! $review['space_ok']) {
                $warnings[] = __('Diskte yer az: arşivi dönüştürmek için :need, açılan dosyalar için :files boş yer gerekir.', ['need' => Bytes::format($review['space_needed']), 'files' => Bytes::format($plan['unpacked'] ?? 0)]);
            }
        @endphp
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line px-5 py-3">
                <h2 class="text-[15px] font-semibold">{{ $plesk ? __('Abonelikte ne var') : __('Arşivde ne var') }}</h2>
                <span class="text-[13px] text-muted"><span class="font-mono">{{ $migration->name }}</span> · {{ Bytes::format($migration->size) }}</span>
            </div>
            <div class="divide-y divide-line px-5 text-[13.5px]">
                {{-- Hesap --}}
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Hesap') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ $plesk ? __('Plesk\'teki sistem kullanıcısının adıyla açılır; veritabanı adları değişmez.') : __('cPanel\'deki adıyla açılır; veritabanı adları ve dosya yolları değişmez.') }}</p>
                    </div>
                    <dl class="grid gap-x-6 gap-y-1.5 sm:grid-cols-[140px_1fr]">
                        <dt class="text-muted">{{ __('Sistem kullanıcısı') }}</dt>
                        <dd class="font-mono">{{ $plan['user'] ?? '' }}@if ($review['account'])<span class="ml-2 font-sans text-2xs text-muted">{{ __('bu sunucuda var; siteler ona eklenir') }}</span>@endif</dd>
                        <dt class="text-muted">{{ __('Ana alan adı') }}</dt>
                        <dd class="font-mono">{{ $plan['main_domain'] ?? '' }}</dd>
                        @if ($plan['contact'] ?? '')
                            <dt class="text-muted">{{ __('İletişim') }}</dt>
                            <dd>{{ $plan['contact'] }}</dd>
                        @endif
                    </dl>
                </div>

                {{-- Müşteri --}}
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Müşteri') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('Siteler kimin hesabında görünsün') }}</p>
                    </div>
                    <div class="space-y-3">
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach (['new' => __('Yeni müşteri'), 'existing' => __('Var olan müşteri'), 'none' => __('Müşterisiz (yalnızca yöneticiler)')] as $k => $l)
                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="customerMode" value="{{ $k }}" class="accent-[rgb(var(--accent))]">{{ $l }}</label>
                            @endforeach
                        </div>
                        @if ($customerMode === 'new')
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label for="m-name" class="mb-1 block text-2xs text-muted">{{ __('Yetkili kişi') }}</label>
                                    <input id="m-name" wire:model="customerName" class="{{ $field }} w-full">
                                    @error('customerName') <p class="mt-1 text-2xs text-crit">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="m-email" class="mb-1 block text-2xs text-muted">{{ __('E-posta') }}</label>
                                    <input id="m-email" type="email" wire:model="customerEmail" class="{{ $field }} w-full">
                                    @error('customerEmail') <p class="mt-1 text-2xs text-crit">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="m-package" class="mb-1 block text-2xs text-muted">{{ __('Paket') }}</label>
                                    <select id="m-package" wire:model="packageId" class="{{ $field }} w-full px-2.5">
                                        @foreach ($packages as $p)
                                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <p class="text-2xs text-muted">{{ __('Müşteri, panele giriş için bir davet bağlantısı alır; bağlantı taşıma raporunda da görünür.') }}</p>
                        @elseif ($customerMode === 'existing')
                            <select wire:model="customerId" aria-label="{{ __('Müşteri') }}" class="{{ $field }} w-full max-w-sm px-2.5">
                                <option value="">{{ __('Seçin') }}</option>
                                @foreach ($customers as $c)
                                    <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                                @endforeach
                            </select>
                            @error('customerId') <p class="text-2xs text-crit">{{ $message }}</p> @enderror
                        @endif
                        @error('customerMode') <p class="text-2xs text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Siteler --}}
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Siteler') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ $plesk ? __('Ana alan adı ve ek alan adları ayrı site olur; takma adlar (alias) ek ad olarak gelir.') : __('Ana alan adı ve ek alan adları ayrı site olur; park edilmiş alan adları ek ad olarak gelir.') }}</p>
                    </div>
                    <div class="space-y-2">
                        @foreach ($siteRows as $s)
                            @php $taken = $review['taken'][$s['domain']] ?? null; @endphp
                            <label wire:key="s-{{ $s['domain'] }}" @class(['flex gap-3 rounded-lg border border-line p-3', 'opacity-60' => $taken])>
                                <input type="checkbox" wire:model="sites" value="{{ $s['domain'] }}" @disabled($taken) class="mt-1 accent-[rgb(var(--accent))]">
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono">{{ $s['domain'] }}</span>
                                        <span class="rounded-full bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ $s['kind'] === 'main' ? __('Ana alan adı') : __('Ek alan adı') }}</span>
                                        @if ($s['php'] ?? '')<span class="rounded-full bg-surface-2 px-1.5 py-0.5 font-mono text-2xs text-muted">PHP {{ $s['php'] }}</span>@endif
                                        @if ($s['certificate'] ?? null)<span class="flex items-center gap-1 rounded-full bg-ok-soft px-1.5 py-0.5 text-2xs text-ok"><x-icon name="lock" class="size-3" />{{ $s['certificate']['issuer'] }}</span>@endif
                                    </span>
                                    <span class="mt-1 block text-2xs text-muted">
                                        <span class="font-mono">~/{{ $s['docroot'] }}</span>
                                        @if ($s['aliases'] ?? [])
                                            · {{ __('Ek adlar: :names', ['names' => implode(', ', $s['aliases'])]) }}
                                        @endif
                                        @if ($s['subdomains'] ?? [])
                                            · {{ __('Alt alan adları: :names', ['names' => implode(', ', array_column($s['subdomains'], 'name'))]) }}
                                        @endif
                                    </span>
                                    @if ($taken)
                                        <span class="mt-1 block text-2xs text-crit">{{ $taken }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                        @error('sites') <p class="text-2xs text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Taşınacaklar --}}
                <div class="{{ $row }}">
                    <div>
                        <p class="font-medium">{{ __('Taşınacaklar') }}</p>
                        <p class="mt-0.5 text-2xs text-muted">{{ __('DNS kayıtlarında eski sunucunun adresi bu sunucununkiyle değiştirilir.') }}</p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($partInfo as $k => [$label, $detail, $available, $why])
                            <label wire:key="p-{{ $k }}" @class(['flex gap-3 rounded-lg border border-line p-3', 'opacity-60' => ! $available])>
                                <input type="checkbox" wire:model="parts" value="{{ $k }}" @disabled(! $available) class="mt-1 accent-[rgb(var(--accent))]">
                                <span>
                                    <span class="block">{{ $label }}</span>
                                    <span class="num block text-2xs text-muted">{{ $available ? $detail : $why }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                @if ($warnings)
                    <div class="{{ $row }}">
                        <div>
                            <p class="font-medium">{{ __('Dikkat') }}</p>
                            <p class="mt-0.5 text-2xs text-muted">{{ __('Taşımayı durdurmaz') }}</p>
                        </div>
                        <ul class="space-y-1.5 text-[13px]">
                            @foreach ($warnings as $w)
                                <li class="flex gap-2"><x-icon name="triangle-alert" class="mt-0.5 size-3.5 shrink-0 text-warn" /><span>{{ $w }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="discard" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="confirmStart" class="{{ $primary }}"><x-icon name="folder-input" />{{ __('Taşımayı başlat') }}</button>
            </div>
        </section>

        <x-modal :title="__('Taşımayı başlat')" show="confirming" size="max-w-xl">
            <div class="p-5 text-[13.5px]">
                <p class="font-semibold">{{ trans_choice(':count site taşınsın mı?', count($sites), ['count' => count($sites)]) }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ __('Siteler bu sunucuda oluşturulur ve seçtikleriniz arşivden yüklenir. Eski sunucuya dokunulmaz; DNS\'i siz yönlendirene kadar ziyaretçiler eski sunucuya gider.') }}</p>
                <ul class="mt-3 space-y-1 font-mono text-[13px]">
                    @foreach ($sites as $d)
                        <li>{{ $d }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-[13px]">{{ implode(' · ', array_map(fn ($p) => __(SiteMigrations::PARTS[$p]), $parts)) ?: __('Yalnızca siteler') }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('confirming', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="start" wire:loading.attr="disabled" class="{{ $primary }}">{{ __('Başlat') }}</button>
                </div>
            </div>
        </x-modal>
    @else
        @php
            $results = collect($migration->result['sites'] ?? [])->keyBy('domain');
            $secrets = $migration->secrets ?? [];
        @endphp
        @if ($migration->isRunning())
            <div wire:poll.2s hidden></div>
        @endif
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-5 py-3">
                <h2 class="text-[15px] font-semibold">{{ $migration->status === 'pulling' ? __('Plesk\'ten alınıyor') : ($migration->isRunning() ? __('Taşınıyor') : __('Taşıma raporu')) }}</h2>
                <span class="font-mono text-[13px] text-muted">{{ $plan['user'] ?? '' }}</span>
                <span class="rounded-full px-2 py-0.5 text-2xs font-medium {{ $statusBadge[$migration->status][0] ?? '' }}">{{ $statusBadge[$migration->status][1] ?? $migration->status }}</span>
                @if ($migration->isFinished())
                    <button type="button" wire:click="fresh" class="ml-auto text-[13px] text-accent hover:underline">{{ __('Yeni taşıma') }}</button>
                @endif
            </div>
            <ol class="divide-y divide-line px-5 text-[13.5px]">
                @php
                    $converted = ! in_array($migration->status, ['queued', 'converting'], true) && ! ($migration->status === 'failed' && $results->isEmpty());
                    // A Plesk copy that stopped before anything was chosen.
                    $pulled = isset($migration->options['sites']);
                @endphp
                @if ($plesk)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3">
                        @if ($migration->status === 'pulling')
                            <x-icon name="loader-circle" class="animate-spin text-accent" />
                        @elseif ($pulled)
                            <x-icon name="circle-check" class="text-ok" />
                        @else
                            <x-icon name="circle-x" class="text-crit" />
                        @endif
                        <span>{{ __('Abonelik Plesk sunucusundan alınıyor') }}</span>
                        <span class="font-mono text-2xs text-muted">{{ $plan['main_domain'] ?? '' }} · {{ $migration->options['plesk']['host'] ?? '' }}</span>
                    </li>
                    @if ($migration->status === 'pulling')
                        <li class="py-3 text-[13px] text-muted">{{ __('Sayfadan ayrılabilirsiniz; aktarım arka planda sürer. Bitince neyin taşınacağını burada seçersiniz.') }}</li>
                    @endif
                @endif
                @if (! $plesk || $pulled)
                <li class="flex items-center gap-3 py-3">
                    @if ($converted)
                        <x-icon name="circle-check" class="text-ok" />
                    @elseif ($migration->status === 'failed')
                        <x-icon name="circle-x" class="text-crit" />
                    @else
                        <x-icon name="loader-circle" class="animate-spin text-accent" />
                    @endif
                    <span>{{ __('Arşiv Serfex yedeklerine dönüştürülüyor') }}</span>
                </li>
                @endif
                @foreach ($migration->options['sites'] ?? [] as $d)
                    @php $r = $results[$d] ?? null; @endphp
                    <li wire:key="r-{{ $d }}" class="flex flex-wrap items-start gap-3 py-3">
                        @if ($r === null)
                            @if ($migration->isRunning())
                                <x-icon name="circle-dashed" class="mt-0.5 text-muted" />
                            @else
                                <x-icon name="circle-x" class="mt-0.5 text-crit" />
                            @endif
                        @elseif ($r['status'] === 'succeeded')
                            <x-icon name="circle-check" class="mt-0.5 text-ok" />
                        @else
                            <x-icon name="circle-x" class="mt-0.5 text-crit" />
                        @endif
                        <span class="min-w-0 flex-1">
                            @if ($r['site_id'] ?? null)
                                <a href="{{ route('sites.show', $r['site_id']) }}" wire:navigate class="font-mono hover:text-accent">{{ $d }}</a>
                            @else
                                <span class="font-mono">{{ $d }}</span>
                            @endif
                            @if ($r)
                                <span class="mt-0.5 block text-2xs text-muted">
                                    @if ($r['restored'])
                                        {{ implode(' · ', array_map(fn ($p) => __(\App\Models\Backup::RESTORE_PARTS[$p] ?? ['home' => 'Hesabın diğer dosyaları', 'laravel' => 'Laravel'][$p] ?? $p), array_values(array_unique(array_map(fn ($p) => $p === 'home' ? 'files' : $p, $r['restored']))))) }}
                                    @endif
                                </span>
                                @foreach ($r['skipped'] ?? [] as $what => $why)
                                    <span class="mt-0.5 block text-2xs text-warn">{{ $what }}: {{ __($why) }}</span>
                                @endforeach
                                @foreach ($r['notes'] ?? [] as $n)
                                    <span class="mt-0.5 block text-2xs text-warn">{{ SiteMigrations::noteText($n) }}</span>
                                @endforeach
                                @if ($r['error'] ?? null)
                                    <span class="mt-0.5 block text-2xs text-crit">{{ $r['error'] }}</span>
                                @endif
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
            @if ($migration->error)
                <p class="mx-5 mb-4 flex gap-2 rounded-lg bg-crit-soft px-3 py-2 text-[13px] text-crit"><x-icon name="circle-x" class="mt-0.5 shrink-0" />{{ $migration->error }}</p>
            @endif
            @if ($migration->isRunning() || ! $migration->isFinished() || $migration->status === 'failed')
                <div class="space-y-3 border-t border-line p-5">
                    @foreach ($runs as $run)
                        <x-run-log :run="$run" class="h-56" />
                    @endforeach
                </div>
            @endif
        </section>

        @if ($migration->isFinished() && $migration->status !== 'failed')
            {{-- Yeni parolalar ve davet --}}
            @if (! empty($secrets['databases']) || ! empty($secrets['mailboxes']) || ! empty($secrets['invite']))
                <section class="min-w-0 rounded-xl border border-line bg-surface">
                    <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                        <h2 class="text-[15px] font-semibold">{{ __('Yeni parolalar') }}</h2>
                        <button type="button" wire:click="forgetSecrets" class="ml-auto text-[13px] text-accent hover:underline">{{ __('Parolaları unut') }}</button>
                    </div>
                    <div class="space-y-4 p-5 text-[13.5px]">
                        <p class="flex gap-2 rounded-lg bg-warn-soft p-3 text-[13px]"><x-icon name="key-round" class="mt-0.5 shrink-0 text-warn" /><span>{{ __('Bunlar yalnızca burada, şifreli olarak saklanır. Kaydettikten sonra "Parolaları unut" ile silin.') }}</span></p>
                        @if (! empty($secrets['invite']))
                            <div>
                                <p class="mb-1 font-medium">{{ __('Müşterinin davet bağlantısı') }}</p>
                                <div class="flex items-center gap-3 rounded-lg border border-line px-3 py-2"><span class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $secrets['invite'] }}</span>{!! $copy($secrets['invite']) !!}</div>
                            </div>
                        @endif
                        @if (! empty($secrets['databases']))
                            <div>
                                <p class="mb-1 font-medium">{{ __('Veritabanı kullanıcıları') }}</p>
                                <div class="divide-y divide-line rounded-lg border border-line">
                                    @foreach ($secrets['databases'] as $p)
                                        <div class="px-3 py-2">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5"><span class="w-full truncate font-mono text-[13px] sm:w-40 sm:shrink-0">{{ $p['name'] }}</span><span class="min-w-0 flex-1 font-mono text-[13px] break-all">{{ $p['password'] }}</span>{!! $copy($p['password']) !!}</div>
                                            <p class="mt-0.5 text-2xs text-muted">
                                                @if ($p['files'])
                                                    {{ __('Yazıldığı dosya: :files', ['files' => implode(', ', $p['files'])]) }}
                                                @else
                                                    {{ __('Ayar dosyası bulunamadı; uygulamanın ayarına elle yazın.') }}
                                                @endif
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if (! empty($secrets['mailboxes']))
                            <div>
                                <p class="mb-1 font-medium">{{ __('Posta kutuları') }}</p>
                                <div class="divide-y divide-line rounded-lg border border-line">
                                    @foreach ($secrets['mailboxes'] as $p)
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-3 py-2"><span class="w-full truncate font-mono text-[13px] sm:w-56 sm:shrink-0">{{ $p['name'] }}</span><span class="min-w-0 flex-1 font-mono text-[13px] break-all">{{ $p['password'] }}</span>{!! $copy($p['password']) !!}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Sıradakiler --}}
            <section class="min-w-0 rounded-xl border border-line bg-surface">
                <h2 class="border-b border-line px-5 py-3 text-[15px] font-semibold">{{ __('Sırada ne var') }}</h2>
                <ol class="list-decimal space-y-3 py-4 pr-5 pl-10 text-[13.5px]">
                    <li>
                        {{ __('Siteleri bu sunucuda deneyin: bilgisayarınızın hosts dosyasına alan adını bu sunucunun adresiyle yazıp açın.') }}
                    </li>
                    <li>
                        <x-t text="Hazır olunca alan adlarının ad sunucularını kayıt firmasında :ns olarak değiştirin. DNS başka yerdeyse yalnızca A kayıtlarını bu sunucuya çevirin."><x-slot:ns><span class="font-mono">{{ implode(', ', array_map(fn ($n) => rtrim($n, '.'), $nameservers)) }}</span></x-slot:ns></x-t>
                    </li>
                    <li>{{ __('Taşınan sertifikalar süreleri dolana kadar kullanılır. DNS bu sunucuyu gösterince SSL sayfasından Let\'s Encrypt\'e geçebilirsiniz.') }}</li>
                    @if (count($plan['cron'] ?? []) > 0)
                        <li>
                            {{ __('Zamanlanmış görevler taşınmadı; sitenin Zamanlanmış görevler sayfasından yeniden ekleyin:') }}
                            <pre class="scroll-thin mt-1.5 overflow-x-auto rounded-md bg-surface-2 p-3 font-mono text-[12.5px]">{{ implode("\n", $plan['cron']) }}</pre>
                        </li>
                    @endif
                    @if (count($plan['ftp'] ?? []) > 0)
                        <li>{{ __('FTP hesapları taşınmadı; sitenin FTP sayfasından yeniden açın: :accounts', ['accounts' => implode(', ', $plan['ftp'])]) }}</li>
                    @endif
                    @if ($plesk)
                        <li>{{ __('Eski sunucudaki aboneliğe dokunulmadı. Siteler burada çalışınca ve DNS bu sunucuyu gösterince Plesk\'teki aboneliği askıya alabilirsiniz.') }}</li>
                    @endif
                    @if ($migration->file)
                        <li><x-t text="Arşiv hâlâ içe aktarma klasöründe (:file); işiniz bitince silin."><x-slot:file><span class="font-mono">{{ $migration->file }}</span></x-slot:file></x-t></li>
                    @endif
                </ol>
            </section>
        @endif
    @endif
</div>
