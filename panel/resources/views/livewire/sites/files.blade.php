@php
    use App\Services\Files\FileManager;
    use App\Support\Bytes;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40';
    $none = $selected === [];
    $crumbs = $path === '' ? [] : explode('/', $path);
    $dirs = collect($entries)->filter(fn ($e) => $e['type'] === 'dir')->count();
    $icon = function (array $e): string {
        if ($e['type'] === 'dir' || ($e['type'] === 'link' && ($e['target_dir'] ?? null) !== null)) {
            return 'folder';
        }
        if ($e['type'] === 'link') {
            return 'link';
        }
        return match (true) {
            FileManager::isArchive($e['name']) => 'file-archive',
            (bool) preg_match('/\.(png|jpe?g|gif|webp|svg|ico|avif)$/i', $e['name']) => 'file-image',
            (bool) preg_match('/\.(php|js|ts|css|scss|html?|json|xml|vue|sh|py)$/i', $e['name']) => 'file-code',
            FileManager::editable($e['name']) => 'file-text',
            default => 'file',
        };
    };
    $download = fn (string $p) => route('sites.files.download', ['site' => $site->id, 'path' => $p]);
@endphp
<div class="space-y-6"
     x-data="{
        base: @js(url('/sites/'.$site->id.'/files')),
        csrf: @js(csrf_token()),
        chunk: {{ FileManager::CHUNK }},
        maxUpload: {{ FileManager::MAX_UPLOAD }},
        dragging: false,
        uploads: [],
        asking: null,
        editor: { open: false, path: '', content: '', mtime: 0, busy: false, error: '', dirty: false },
        toast(message) { this.$dispatch('toast', { message }); },
        ask(name) { return new Promise(resolve => { this.asking = { name, resolve }; }); },
        answer(yes) { const a = this.asking; this.asking = null; a?.resolve(yes); },
        id() { return 'up-' + Array.from(crypto.getRandomValues(new Uint8Array(10)), b => (b % 36).toString(36)).join(''); },
        async upload(list) {
            const files = Array.from(list || []);
            if (! files.length) return;
            const folder = $wire.path, paths = [], replaced = [];
            for (const file of files) {
                this.uploads.push({ name: file.name, size: file.size, sent: 0, state: 'uploading', error: '' });
                // The reactive copy, so the progress bar follows.
                const item = this.uploads[this.uploads.length - 1];
                if (file.size > this.maxUpload) { item.state = 'failed'; item.error = @js(__('2 GB üstü dosyaları SFTP ile yükleyin.')); continue; }
                const id = this.id();
                let offset = 0, overwrite = false;
                while (true) {
                    const part = file.slice(offset, offset + this.chunk);
                    const last = offset + part.size >= file.size;
                    const q = new URLSearchParams({ path: folder, name: file.name, upload: id, offset, final: last ? 1 : 0, overwrite: overwrite ? 1 : 0 });
                    let r, j = {};
                    try {
                        r = await fetch(this.base + '/upload?' + q, { method: 'POST', body: part, headers: { 'X-CSRF-TOKEN': this.csrf, 'Content-Type': 'application/octet-stream', 'Accept': 'application/json' } });
                        j = await r.json().catch(() => ({}));
                    } catch (e) { r = { ok: false }; }
                    if (! r.ok) {
                        if (j.code === 'exists' && offset === 0 && ! overwrite) {
                            if (await this.ask(file.name)) { overwrite = true; continue; }
                            item.state = 'skipped';
                            break;
                        }
                        item.state = 'failed';
                        item.error = j.error || @js(__('Yükleme durdu; bağlantıyı kontrol edin.'));
                        break;
                    }
                    offset = j.size; item.sent = offset;
                    if (last) {
                        item.state = 'done';
                        paths.push(j.path);
                        if (j.trash) replaced.push(j.trash);
                        break;
                    }
                }
            }
            if (paths.length) await $wire.uploaded(paths, replaced);
            if (this.uploads.every(u => u.state === 'done')) setTimeout(() => { if (this.uploads.every(u => u.state === 'done')) this.uploads = []; }, 2500);
        },
        async edit(path) {
            const r = await fetch(this.base + '/text?' + new URLSearchParams({ path }), { headers: { 'Accept': 'application/json' } });
            const j = await r.json().catch(() => ({}));
            if (! r.ok) { this.toast(j.error || @js(__('Dosya açılamadı.'))); return; }
            this.editor = { open: true, path, content: j.content, mtime: j.mtime, busy: false, error: '', dirty: false };
            this.$nextTick(() => this.$refs.text?.focus());
        },
        async save() {
            if (this.editor.busy) return;
            this.editor.busy = true; this.editor.error = '';
            const r = await fetch(this.base + '/save', { method: 'POST', body: JSON.stringify({ path: this.editor.path, content: this.editor.content, mtime: this.editor.mtime }), headers: { 'X-CSRF-TOKEN': this.csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' } });
            const j = await r.json().catch(() => ({}));
            this.editor.busy = false;
            if (! r.ok) { this.editor.error = j.error || @js(__('Kaydedilemedi.')); return; }
            this.editor.mtime = j.mtime; this.editor.dirty = false;
            $wire.saved(this.editor.path, j.trash || '');
        },
        close() {
            if (this.editor.dirty && ! window.confirm(@js(__('Kaydedilmemiş değişiklikler kaybolacak. Kapatılsın mı?')))) return;
            this.editor.open = false;
        },
        tab(e) {
            const t = e.target, s = t.selectionStart;
            t.setRangeText('    ', s, t.selectionEnd, 'end');
            this.editor.content = t.value; this.editor.dirty = true;
        },
     }">
    {{-- Title --}}
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Dosya Yöneticisi') }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Dosya Yöneticisi') }}</h1>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('sites.ftp', $site) }}" wire:navigate class="{{ $btn }}"><x-icon name="hard-drive-upload" />{{ __('FTP hesapları:ftp_count', ['ftp_count' => $ftpCount ? ' ('.$ftpCount.')' : '']) }}</a>
                <a href="{{ route('sites.show', $site) }}" wire:navigate class="{{ $btn }} font-medium"><x-icon name="arrow-left" />{{ __('Siteye dön') }}</a>
            </div>
        </div>
        <p class="mt-1 text-[13px] text-muted">
            <x-t text=":domain dosyalarını yükleyin, düzenleyin ve izinlerini değiştirin. Silinenler :trash_days gün çöp kutusunda kalır." :with="['trash_days' => FileManager::TRASH_DAYS]"><x-slot:domain><span class="font-mono">{{ $site->domain }}</span></x-slot:domain></x-t>
            @if ($disk)
                <x-t text="Disk: :text"><x-slot:text><span class="num">{{ $disk['text'] }}</span></x-slot:text></x-t>
            @endif
        </p>
    </div>

    @if ($flagged)
        <div class="flex items-start gap-3 rounded-xl border border-line bg-crit-soft px-5 py-3 text-[13.5px]" role="alert">
            <x-icon name="shield-alert" class="mt-0.5 shrink-0 text-crit" />
            <p class="flex-1">
                <x-t text="Yüklenen :flagged güvenlik taramasında zararlı ya da şüpheli bulundu: :part." :with="['flagged' => count($flagged) === 1 ? 'dosya' : __(':flagged dosya', ['flagged' => count($flagged)])]"><x-slot:part><span class="font-mono">{{ implode(', ', array_slice($flagged, 0, 3)) }}{{ count($flagged) > 3 ? '…' : '' }}</span></x-slot:part></x-t>
                @if (auth()->user()->allows('security'))
                    <a href="{{ route('sites.scan', $site) }}" wire:navigate class="font-medium text-accent hover:underline">{{ __('Bulgulara bak ve karantinaya al') }}</a>
                @endif
            </p>
            <button type="button" wire:click="$set('flagged', null)" class="text-muted hover:text-fg" aria-label="{{ __('Kapat') }}"><x-icon name="x" /></button>
        </div>
    @endif

    <section class="relative min-w-0 rounded-xl border border-line bg-surface"
             @dragover.prevent="dragging = true" @dragleave.self="dragging = false" @drop.prevent="dragging = false; upload($event.dataTransfer.files)">
        <div x-show="dragging" x-cloak class="pointer-events-none absolute inset-0 z-10 grid place-items-center rounded-xl border-2 border-dashed border-accent bg-accent/5 text-[14px] font-medium text-accent">
            <x-t text="Bırakın, :path klasörüne yüklensin"><x-slot:path><span class="mx-1 font-mono">/{{ $path }}</span></x-slot:path></x-t>
        </div>

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            <button type="button" @click="$refs.picker.click()" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="upload" />{{ __('Yükle') }}</button>
            <input x-ref="picker" type="file" multiple hidden @change="upload($event.target.files); $event.target.value = ''">
            <button type="button" wire:click="openNew('file')" class="{{ $btn }}"><x-icon name="file-plus" /><span class="hidden sm:inline">{{ __('Yeni dosya') }}</span><span class="sm:hidden">{{ __('Dosya') }}</span></button>
            <button type="button" wire:click="openNew('folder')" class="{{ $btn }}"><x-icon name="folder-plus" /><span class="hidden sm:inline">{{ __('Yeni klasör') }}</span><span class="sm:hidden">{{ __('Klasör') }}</span></button>
            <button type="button" wire:click="confirmDelete" @disabled($none) class="{{ $btn }}">{{ __('Sil') }}</button>
            <button type="button" wire:click="openPermissions" @disabled($none) class="{{ $btn }}">{{ __('İzinler') }}</button>
            <div class="relative" x-data="{ open: false }">
                <button type="button" @click="open = !open" @disabled($none) class="{{ $btn }}">{{ __('Diğer') }}<x-icon name="chevron-down" class="size-3.5" /></button>
                <div x-show="open" x-cloak @click.outside="open = false" @click="open = false" class="absolute top-full left-0 z-20 mt-1 w-44 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                    <button type="button" wire:click="openTransfer('move')" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Taşı') }}</button>
                    <button type="button" wire:click="openTransfer('copy')" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Kopyala') }}</button>
                    <button type="button" wire:click="openCompress" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Sıkıştır (zip)') }}</button>
                </div>
            </div>
            <div class="ml-auto flex w-full items-center gap-2 sm:w-auto">
                <label class="relative min-w-0 flex-1 sm:w-56">
                    <span class="sr-only">{{ __('Ara') }}</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" wire:model.live.debounce.250ms="search" wire:keydown.enter="searchAll" placeholder="{{ __('Ara (Enter: alt klasörler)') }}"
                           class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
                <button type="button" wire:click="$set('trashOpen', true)" class="grid size-9 shrink-0 place-items-center rounded-md border border-line hover:bg-surface-2" aria-label="{{ __('Çöp kutusu') }}" title="{{ __('Çöp kutusu') }}"><x-icon name="trash-2" /></button>
            </div>
        </div>

        {{-- Folder path --}}
        <nav class="flex min-w-0 flex-wrap items-center gap-x-1 border-b border-line px-5 py-2.5 font-mono text-[12.5px] text-muted" aria-label="{{ __('Klasör yolu') }}">
            <span class="hidden sm:inline">{{ dirname($root) }}/</span>
            <button type="button" wire:click="open('')" @class(['hover:text-accent', 'text-fg' => $crumbs === []])>{{ $site->domain }}</button>
            @foreach ($crumbs as $i => $part)
                <span>/</span>
                @if ($i === count($crumbs) - 1)
                    <span class="text-fg">{{ $part }}</span>
                @else
                    <button type="button" wire:click="open(@js(implode('/', array_slice($crumbs, 0, $i + 1))))" class="hover:text-accent">{{ $part }}</button>
                @endif
            @endforeach
            @if ($searching)
                <span class="ml-2 font-sans text-[12.5px]">{{ __('· ":search" için alt klasörlerde arama sonuçları', ['search' => $search]) }}</span>
                <button type="button" wire:click="$set('search', '')" class="ml-1 font-sans text-[12.5px] text-accent hover:underline">{{ __('Temizle') }}</button>
            @endif
        </nav>

        {{-- Uploads --}}
        <div x-show="uploads.length" x-cloak class="space-y-2 border-b border-line px-5 py-3" wire:ignore>
            <template x-for="(u, i) in uploads" :key="i">
                <div class="flex items-center gap-3 text-[13px]">
                    <span class="w-48 min-w-0 truncate font-mono sm:w-72" x-text="u.name"></span>
                    <div class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full" :class="u.state === 'failed' ? 'bg-crit' : 'bg-accent'" :style="`width: ${u.size ? Math.round(u.sent / u.size * 100) : (u.state === 'done' ? 100 : 0)}%`"></div>
                    </div>
                    <span class="num w-40 shrink-0 truncate text-right text-2xs" :class="u.state === 'failed' ? 'text-crit' : 'text-muted'"
                          :title="u.error"
                          x-text="u.state === 'failed' ? u.error : (u.state === 'skipped' ? @js(__('Atlandı')) : (u.state === 'done' ? @js(__('Yüklendi')) : `${(u.sent / 1048576).toFixed(1)} / ${(u.size / 1048576).toFixed(1)} MB`))"></span>
                </div>
            </template>
            <button type="button" x-show="uploads.every(u => u.state !== 'uploading')" @click="uploads = []" class="text-[12.5px] text-accent hover:underline">{{ __('Listeyi temizle') }}</button>
        </div>

        @if ($error)
            <div class="px-5 py-12 text-center">
                <p class="font-medium">{{ __('Klasör açılamadı') }}</p>
                <p class="mt-1 text-[13px] text-muted">{{ $error }}</p>
                <button type="button" wire:click="open('')" class="mt-3 text-[13px] text-accent hover:underline">{{ __('Site klasörüne dön') }}</button>
            </div>
        @else
            <div class="relative overflow-x-auto">
                <table class="w-full text-[13.5px] sm:min-w-[680px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="w-10 py-2.5 pl-5"><input type="checkbox" aria-label="{{ __('Tümünü seç') }}" class="size-4 accent-accent"
                                   @checked($entries !== [] && count($selected) === count($entries))
                                   wire:click="$set('selected', {{ $entries !== [] && count($selected) === count($entries) ? '[]' : \Illuminate\Support\Js::from(array_column($entries, 'path')) }})"></th>
                            <th class="w-full px-3 py-2.5 font-medium">{{ __('Ad') }}</th>
                            <th class="hidden px-3 py-2.5 text-right font-medium whitespace-nowrap sm:table-cell">{{ __('Boyut') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium whitespace-nowrap md:table-cell">{{ __('Değiştirilme') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('İzinler') }}</th>
                            <th class="w-10 py-2.5 pr-5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @if ($path !== '' && ! $searching)
                            <tr class="hover:bg-surface-2/60">
                                <td class="py-2.5 pl-5"></td>
                                <td class="px-3 py-2.5" colspan="5">
                                    <button type="button" wire:click="open(@js(dirname($path) === '.' ? '' : dirname($path)))" class="flex items-center gap-2.5 text-muted hover:text-accent"><x-icon name="corner-left-up" />{{ __('Üst klasör') }}</button>
                                </td>
                            </tr>
                        @endif
                        @forelse ($entries as $e)
                            @php
                                $folderLike = $e['type'] === 'dir' || ($e['type'] === 'link' && isset($e['target_dir']));
                                $target = $e['type'] === 'link' && isset($e['target_dir']) ? $e['target_dir'] : $e['path'];
                                $editable = ! $folderLike && $e['size'] <= FileManager::MAX_EDIT && FileManager::editable($e['name']);
                                $archive = ! $folderLike && FileManager::isArchive($e['name']);
                            @endphp
                            <tr wire:key="f-{{ md5($e['path']) }}" @class(['hover:bg-surface-2/60', 'bg-accent/5' => in_array($e['path'], $selected, true)])>
                                <td class="py-2.5 pl-5"><input type="checkbox" wire:model.live="selected" value="{{ $e['path'] }}" aria-label="{{ __(':name seç', ['name' => $e['name']]) }}" class="size-4 accent-accent"></td>
                                <td class="max-w-0 px-3 py-2.5">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <x-icon :name="$icon($e)" @class(['shrink-0', 'text-accent' => $folderLike, 'text-muted' => ! $folderLike]) />
                                        @if ($folderLike)
                                            <button type="button" wire:click="open(@js($target))" class="truncate font-mono text-[13px] hover:text-accent">{{ $e['name'] }}</button>
                                        @elseif ($editable)
                                            <button type="button" @click="edit(@js($e['path']))" class="truncate font-mono text-[13px] hover:text-accent" title="{{ __('Düzenle') }}">{{ $e['name'] }}</button>
                                        @else
                                            <a href="{{ $download($e['path']) }}" class="truncate font-mono text-[13px] hover:text-accent" title="{{ __('İndir') }}">{{ $e['name'] }}</a>
                                        @endif
                                        @if ($e['type'] === 'link')
                                            <span class="hidden truncate font-mono text-[12px] text-muted sm:inline">→ {{ $e['target'] ?? '' }}</span>
                                        @endif
                                    </div>
                                    @if ($searching)
                                        <span class="mt-0.5 block truncate pl-7 font-mono text-[12px] text-muted">/{{ dirname($e['path']) === '.' ? '' : dirname($e['path']) }}</span>
                                    @endif
                                </td>
                                <td class="num hidden px-3 py-2.5 text-right whitespace-nowrap text-muted sm:table-cell">{{ $e['type'] === 'file' ? Bytes::format($e['size']) : '—' }}</td>
                                <td class="num hidden px-3 py-2.5 whitespace-nowrap text-muted md:table-cell">{{ $date($e['mtime']) }}</td>
                                <td class="px-3 py-2.5 font-mono text-[12.5px] whitespace-nowrap">
                                    @if ($e['type'] === 'link')
                                        <span class="text-muted">{{ __('bağlantı') }}</span>
                                    @else
                                        <button type="button" wire:click="openPermissions(@js($e['path']), @js($e['mode']))" class="hover:text-accent" title="{{ __('İzinleri değiştir') }}">{{ $e['mode'] }}</button>
                                    @endif
                                    @unless ($e['owned'])
                                        <x-icon name="lock" class="ml-1 inline size-3.5 text-warn" :title="__('Sahibi site kullanıcısı değil; değiştirilemeyebilir')" />
                                    @endunless
                                </td>
                                <td class="py-2.5 pr-5 text-right">
                                    <div class="relative inline-block text-left" x-data="{ open: false }">
                                        <button type="button" @click="open = !open" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __(':name işlemleri', ['name' => $e['name']]) }}"><x-icon name="ellipsis-vertical" /></button>
                                        <div x-show="open" x-cloak @click.outside="open = false" @click="open = false" class="absolute top-full right-0 z-20 mt-1 w-48 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                                            @if ($folderLike)
                                                <button type="button" wire:click="open(@js($target))" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Aç') }}</button>
                                            @else
                                                <a href="{{ $download($e['path']) }}" class="block px-3 py-2 hover:bg-surface-2">{{ __('İndir') }}</a>
                                                @if ($editable)
                                                    <button type="button" @click="edit(@js($e['path']))" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Düzenle') }}</button>
                                                @endif
                                            @endif
                                            @if ($archive)
                                                <button type="button" wire:click="openExtract(@js($e['path']))" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Arşivi aç') }}</button>
                                            @endif
                                            <button type="button" wire:click="openRename(@js($e['path']))" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Yeniden adlandır') }}</button>
                                            <button type="button" wire:click="confirmDelete(@js($e['path']))" class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2">{{ __('Sil') }}</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center">
                                    @if ($search !== '')
                                        <p class="text-muted">{{ __('":search" ile eşleşen dosya yok.', ['search' => $search]) }}@unless ($searching) <button type="button" wire:click="searchAll" class="text-accent hover:underline">{{ __('Alt klasörlerde ara') }}</button>@endunless</p>
                                    @else
                                        <p class="font-medium">{{ __('Bu klasör boş') }}</p>
                                        <p class="mt-1 text-[13px] text-muted">{{ __('Dosyaları buraya sürükleyip bırakın ya da "Yükle"ye tıklayın.') }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="num flex flex-wrap gap-x-3 border-t border-line px-5 py-3 text-2xs text-muted">
                <span>{{ __(':dirs klasör, :dirs2 dosya:selected', ['dirs' => $dirs, 'dirs2' => count($entries) - $dirs, 'selected' => $selected ? __(' · :selected seçili', ['selected' => count($selected)]) : '']) }}</span>
                @if ($truncated)
                    <span class="text-warn">{{ $searching ? __('İlk 500 sonuç gösteriliyor; aramayı daraltın.') : __('İlk 5.000 öğe gösteriliyor; SFTP ile bağlanarak tümünü görebilirsiniz.') }}</span>
                @endif
                <span class="sm:ml-auto">{{ __('Yükleme: dosya başına en fazla 2 GB') }}</span>
            </p>
        @endif
    </section>

    {{-- Yeni dosya / klasör --}}
    <div x-show="$wire.newKind !== ''" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.newKind = ''">
        <form wire:submit="create" class="w-full max-w-md rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Yeni öğe') }}">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $newKind === 'folder' ? __('Yeni klasör') : __('Yeni dosya') }}</h2>
            <div class="p-5 text-[13.5px]">
                <label for="new-name" class="mb-1.5 block">{{ __('Ad') }}</label>
                <input id="new-name" wire:model="newName" required autocomplete="off" spellcheck="false" placeholder="{{ $newKind === 'folder' ? __('resimler') : 'index.html' }}" class="{{ $field }} font-mono {{ $errors->has('newName') ? 'border-crit' : 'border-line' }}">
                @error('newName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                <p class="mt-2 text-2xs text-muted"><x-t text="Konum: :path"><x-slot:path><span class="font-mono">/{{ $path }}</span></x-slot:path></x-t></p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('newKind', '')" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Oluştur') }}</button>
            </div>
        </form>
    </div>

    {{-- Yeniden adlandır --}}
    <div x-show="$wire.renamePath !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.renamePath = null">
        <form wire:submit="rename" class="w-full max-w-md rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Yeniden adlandır') }}">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Yeniden adlandır') }}</h2>
            <div class="p-5 text-[13.5px]">
                <label for="rename" class="mb-1.5 block">{{ __('Yeni ad') }}</label>
                <input id="rename" wire:model="renameName" required autocomplete="off" spellcheck="false" class="{{ $field }} font-mono {{ $errors->has('renameName') ? 'border-crit' : 'border-line' }}">
                @error('renameName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('renamePath', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </div>

    {{-- İzinler --}}
    <x-modal :title="__('İzinleri değiştir')" show="permOpen" size="max-w-md">
        <form wire:submit="chmod">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('İzinleri değiştir') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <p class="truncate font-mono text-[13px] text-muted">{{ count($targets) === 1 ? basename($targets[0] ?? '') : __(':targets öğe', ['targets' => count($targets)]) }}</p>
                <div class="grid items-center gap-x-4 gap-y-3 sm:grid-cols-[8rem_minmax(0,1fr)]">
                    <label for="perm">{{ __('İzin') }}</label>
                    <div>
                        <input id="perm" wire:model="permMode" maxlength="3" inputmode="numeric" class="{{ $field }} w-28 font-mono {{ $errors->has('permMode') ? 'border-crit' : 'border-line' }}">
                        @error('permMode') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                    <span></span>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="recursive" class="size-4 accent-accent">
                        <span>{{ __('Klasörlerin içindekilere de uygula') }}</span>
                    </label>
                    @if ($recursive)
                        <label for="perm-dir">{{ __('Alt klasörler') }}</label>
                        <input id="perm-dir" wire:model="dirMode" maxlength="3" class="{{ $field }} w-28 font-mono {{ $errors->has('dirMode') ? 'border-crit' : 'border-line' }}">
                        <label for="perm-file">{{ __('Dosyalar') }}</label>
                        <input id="perm-file" wire:model="fileMode" maxlength="3" class="{{ $field }} w-28 font-mono {{ $errors->has('fileMode') ? 'border-crit' : 'border-line' }}">
                    @endif
                </div>
                <p class="text-2xs text-muted">{{ __('Dosyalar için genelde 644, klasörler için 755. :recursive', ['recursive' => $recursive ? __('İçindekilere uygulanan değişiklik geri alınamaz.') : '']) }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('permOpen', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Uygula') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Taşı / kopyala --}}
    <div x-show="$wire.transfer !== ''" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.transfer = ''">
        <form wire:submit="transferSelected" class="w-full max-w-md rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Taşı ya da kopyala') }}">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __(':transfer: :selected öğe', ['transfer' => $transfer === 'copy' ? __('Kopyala') : __('Taşı'), 'selected' => count($selected)]) }}</h2>
            <div class="p-5 text-[13.5px]">
                <label for="transfer-to" class="mb-1.5 block">{{ __('Hedef klasör') }}</label>
                <div class="flex overflow-hidden rounded-md border border-line focus-within:border-accent">
                    <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[12.5px] text-muted">{{ $site->domain }}/</span>
                    <input id="transfer-to" wire:model="transferTo" autocomplete="off" spellcheck="false" placeholder="{{ __('public_html/eski') }}" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                </div>
                <p class="mt-2 text-2xs text-muted">{{ __('Klasör var olmalı. Aynı adda bir öğe varsa işlem durur; hiçbir şeyin üzerine yazılmaz.') }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('transfer', '')" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">{{ $transfer === 'copy' ? __('Kopyala') : __('Taşı') }}</button>
            </div>
        </form>
    </div>

    {{-- Sıkıştır --}}
    <x-modal :title="__('Sıkıştır')" show="compressOpen" size="max-w-md">
        <form wire:submit="compress">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Sıkıştır: :selected öğe', ['selected' => count($selected)]) }}</h2>
            <div class="grid items-center gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[6rem_minmax(0,1fr)]">
                <label for="arch-name">{{ __('Arşiv adı') }}</label>
                <div>
                    <input id="arch-name" wire:model="archiveName" required autocomplete="off" spellcheck="false" class="{{ $field }} font-mono {{ $errors->has('archiveName') ? 'border-crit' : 'border-line' }}">
                    @error('archiveName') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                </div>
                <label for="arch-format">{{ __('Biçim') }}</label>
                <select id="arch-format" wire:model="archiveFormat" class="{{ $field }} w-40 border-line">
                    <option value="zip">.zip</option>
                    <option value="tar.gz">.tar.gz</option>
                </select>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('compressOpen', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">
                    <span wire:loading.remove wire:target="compress">{{ __('Sıkıştır') }}</span><span wire:loading wire:target="compress">{{ __('Sıkıştırılıyor…') }}</span>
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Arşivi aç --}}
    <div x-show="$wire.extractPath !== null" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.extractPath = null">
        <form wire:submit="extract" class="w-full max-w-md rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Arşivi aç') }}">
            <h2 class="flex h-12 items-center truncate border-b border-line px-5 text-[14.5px] font-semibold"><x-t text="Arşivi aç: :extract_path"><x-slot:extract_path><span class="ml-1 truncate font-mono">{{ $extractPath ? basename($extractPath) : '' }}</span></x-slot:extract_path></x-t></h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                <div>
                    <label for="extract-to" class="mb-1.5 block">{{ __('Hedef klasör') }}</label>
                    <div class="flex overflow-hidden rounded-md border border-line focus-within:border-accent">
                        <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[12.5px] text-muted">{{ $site->domain }}/</span>
                        <input id="extract-to" wire:model="extractTo" autocomplete="off" spellcheck="false" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                    </div>
                </div>
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="extractOverwrite" class="mt-0.5 size-4 accent-accent">
                    <span>{{ __('Var olan dosyaların üzerine yaz') }} <span class="block text-2xs text-muted">{{ __('Eski dosyalar :days gün çöp kutusunda kalır. Seçilmezse çakışma olduğunda hiçbir şey çıkarılmaz.', ['days' => FileManager::TRASH_DAYS]) }}</span></span>
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('extractPath', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50">
                    <span wire:loading.remove wire:target="extract">{{ __('Çıkar') }}</span><span wire:loading wire:target="extract">{{ __('Çıkarılıyor…') }}</span>
                </button>
            </div>
        </form>
    </div>

    {{-- Silme onayı --}}
    <x-modal :title="__('Sil')" show="deleteOpen" size="max-w-md">
        <div class="p-5 text-[13.5px]">
            <p class="font-semibold">
                @if (count($targets) === 1)
                    <x-t text=":targets silinsin mi?"><x-slot:targets><span class="font-mono">{{ basename($targets[0]) }}</span></x-slot:targets></x-t>
                @else
                    {{ __(':targets öğe silinsin mi?', ['targets' => count($targets)]) }}
                @endif
            </p>
            @if (count($targets) > 1)
                <ul class="mt-2 space-y-0.5 font-mono text-[12.5px]">
                    @foreach (array_slice($targets, 0, 5) as $t)
                        <li class="truncate">{{ basename($t) }}</li>
                    @endforeach
                    @if (count($targets) > 5)
                        <li class="font-sans text-muted">{{ __('ve :targets öğe daha', ['targets' => count($targets) - 5]) }}</li>
                    @endif
                </ul>
            @endif
            <p class="mt-1 text-[13px] text-muted">{{ __('Silinenler :trash_days gün çöp kutusunda kalır; oradan ya da hemen "Geri al" ile geri getirebilirsiniz.', ['trash_days' => FileManager::TRASH_DAYS]) }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('deleteOpen', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="delete" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Sil') }}</button>
            </div>
        </div>
    </x-modal>

    {{-- Çöp kutusu --}}
    <x-modal :title="__('Çöp kutusu')" show="trashOpen" size="max-w-2xl">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Çöp kutusu') }}</h2>
        <div class="max-h-[60vh] overflow-y-auto">
            <table class="w-full text-[13.5px]">
                <tbody class="divide-y divide-line">
                    @forelse ($trash as $item)
                        <tr wire:key="t-{{ $item['id'] }}">
                            <td class="w-full max-w-0 py-2.5 pr-3 pl-5">
                                <span class="block truncate font-mono text-[13px]" title="{{ $item['path'] }}">{{ $item['path'] }}</span>
                                <span class="num block text-2xs text-muted">
                                    {{ ['deleted' => __('Silindi'), 'version' => __('Düzenlemeden önceki sürüm'), 'replaced' => __('Üzerine yazılan dosya'), 'quarantined' => __('Karantina (güvenlik taraması)')][$item['kind']] ?? $item['kind'] }}
                                    · {{ $date($item['deleted_at']) }} · {{ Bytes::format($item['size']) }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-5 pl-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="restoreTrash(['{{ $item['id'] }}'])" class="text-[13px] text-accent hover:underline">{{ __('Geri yükle') }}</button>
                                <button type="button" wire:click="deleteFromTrash('{{ $item['id'] }}')" wire:confirm="{{ __('Kalıcı olarak silinsin mi? Geri alınamaz.') }}" class="ml-3 text-[13px] text-crit hover:underline">{{ __('Kalıcı sil') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-10 text-center text-muted">{{ __('Çöp kutusu boş.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex items-center gap-2 border-t border-line px-5 py-3">
            <p class="text-2xs text-muted">{{ __('Öğeler :trash_days gün sonra kendiliğinden silinir ve o zamana kadar disk kotasına sayılır.', ['trash_days' => FileManager::TRASH_DAYS]) }}</p>
            @if ($trash !== [])
                <button type="button" wire:click="emptyTrash" wire:confirm="{{ __('Çöp kutusundaki her şey kalıcı olarak silinsin mi? Geri alınamaz.') }}" class="ml-auto h-9 shrink-0 rounded-md border border-line px-3.5 text-[13.5px] text-crit hover:bg-surface-2">{{ __('Boşalt') }}</button>
            @endif
            <button type="button" wire:click="$set('trashOpen', false)" class="{{ $trash === [] ? 'ml-auto' : '' }} h-9 shrink-0 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
        </div>
    </x-modal>

    {{-- Üzerine yazılsın mı (yükleme) --}}
    <div x-show="asking" x-cloak class="fixed inset-0 z-[55] grid place-items-center bg-black/40 p-4" wire:ignore>
        <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Üzerine yaz') }}">
            <p class="font-semibold"><x-t text=":part zaten var. Üzerine yazılsın mı?"><x-slot:part><span class="font-mono" x-text="asking?.name"></span></x-slot:part></x-t></p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Eski dosya çöp kutusuna taşınır; "Geri al" ile geri gelir.') }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="answer(false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Atla') }}</button>
                <button type="button" @click="answer(true)" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Üzerine yaz') }}</button>
            </div>
        </div>
    </div>

    {{-- Düzenleyici --}}
    <div x-show="editor.open" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" wire:ignore @keydown.escape.window="editor.open && close()">
        <form @submit.prevent="save()" @keydown.ctrl.s.prevent="save()" @keydown.meta.s.prevent="save()" class="flex max-h-full w-full max-w-4xl flex-col rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Dosya düzenleyici') }}">
            <div class="flex h-12 shrink-0 items-center gap-3 border-b border-line px-5">
                <h2 class="truncate font-mono text-[14px] font-semibold" x-text="editor.path"></h2>
                <span x-show="editor.dirty" class="shrink-0 text-2xs text-warn">{{ __('Kaydedilmedi') }}</span>
                <span class="ml-auto shrink-0 text-2xs text-muted">{{ __('UTF-8 · Ctrl+S kaydeder') }}</span>
            </div>
            <label for="editor-text" class="sr-only">{{ __('Dosya içeriği') }}</label>
            <textarea id="editor-text" x-ref="text" x-model="editor.content" @input="editor.dirty = true" @keydown.tab.prevent="tab($event)" spellcheck="false" autocomplete="off" autocapitalize="off"
                      class="block h-[65vh] w-full resize-none bg-surface-2/50 p-4 font-mono text-[12.5px] leading-relaxed outline-none" style="tab-size: 4"></textarea>
            <div class="flex shrink-0 items-center gap-2 border-t border-line px-5 py-3">
                <p x-show="editor.error" x-text="editor.error" class="min-w-0 truncate text-[13px] text-crit"></p>
                <button type="button" @click="close()" class="ml-auto h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
                <button type="submit" :disabled="editor.busy" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50" x-text="editor.busy ? 'Kaydediliyor…' : @js(__('Kaydet'))"></button>
            </div>
        </form>
    </div>
</div>
