<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Files\FileManager;
use App\Services\Security\Scans;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dosya Yöneticisi: a site's folder (/home/<account>/web/<domain>), one
 * folder at a time. Uploads, downloads and the editor's text go through
 * SiteFileController; everything else is here. Whatever is deleted or
 * overwritten goes to the site's trash, so every change has an Undo.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteFiles extends Component
{
    public int $siteId;

    /** The folder shown, relative to the site's folder. */
    #[Url(as: 'yol', except: '')]
    public string $path = '';

    /** Filters the folder by name; Enter searches below it too. */
    public string $search = '';

    /** @var list<array<string, mixed>>|null results of a search below the folder */
    public ?array $results = null;

    public bool $resultsTruncated = false;

    /** @var list<string> */
    public array $selected = [];

    /**
     * What the open delete or permissions dialog acts on: the selection,
     * or one row's entry without changing the selection.
     *
     * @var list<string>
     */
    public array $targets = [];

    // Yeni dosya / klasör
    public string $newKind = '';

    public string $newName = '';

    // Yeniden adlandır
    public ?string $renamePath = null;

    public string $renameName = '';

    // İzinler
    public bool $permOpen = false;

    public string $permMode = '644';

    public bool $recursive = false;

    public string $dirMode = '755';

    public string $fileMode = '644';

    // Taşı / kopyala
    public string $transfer = '';

    public string $transferTo = '';

    // Sıkıştır
    public bool $compressOpen = false;

    public string $archiveName = '';

    public string $archiveFormat = 'zip';

    // Arşivi aç
    public ?string $extractPath = null;

    public string $extractTo = '';

    public bool $extractOverwrite = false;

    public bool $deleteOpen = false;

    public bool $trashOpen = false;

    public bool $emptyTrashOpen = false;

    /** @var list<string>|null uploaded files the security scan flagged */
    public ?array $flagged = null;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
        $this->path = $this->cleanPath($this->path);
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package')->findOrFail($this->siteId);
    }

    private function cleanPath(string $path): string
    {
        $parts = array_filter(explode('/', $path), fn ($p) => $p !== '' && $p !== '.' && $p !== '..');

        return implode('/', $parts);
    }

    private function join(string $name): string
    {
        return ltrim($this->path.'/'.$name, '/');
    }

    /**
     * Runs an operation; on a refusal shows it and returns null.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function run(string $op, array $params = [], ?float $timeout = null): ?array
    {
        try {
            return app(FileManager::class)->call($this->site(), $op, $params, $timeout);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: FileManager::message($e));

            return null;
        }
    }

    private function undoToast(string $message, string $method, array $args): void
    {
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: ['method' => $method, 'args' => $args]);
    }

    public function open(string $path): void
    {
        $this->path = $this->cleanPath($path);
        $this->reset('selected', 'search', 'results');
    }

    public function updatedSearch(): void
    {
        $this->results = null;
    }

    public function searchAll(): void
    {
        $q = trim($this->search);
        if ($q === '') {
            $this->results = null;

            return;
        }
        $res = $this->run('search', ['path' => $this->path, 'query' => $q], 30);
        if ($res !== null) {
            $this->results = $res['entries'] ?? [];
            $this->resultsTruncated = (bool) ($res['truncated'] ?? false);
        }
    }

    // --- New file or folder ---

    public function openNew(string $kind): void
    {
        $this->resetValidation();
        $this->newKind = $kind === 'folder' ? 'folder' : 'file';
        $this->newName = '';
    }

    public function create(): void
    {
        $name = trim($this->newName);
        if ($name === '' || str_contains($name, '/')) {
            $this->addError('newName', 'Bir ad yazın; "/" kullanılamaz.');

            return;
        }
        $folder = $this->newKind === 'folder';
        $res = $this->run($folder ? 'mkdir' : 'create', ['path' => $this->path, 'name' => $name]);
        if ($res === null) {
            return;
        }
        $this->newKind = '';
        $this->undoToast(($folder ? 'Klasör' : 'Dosya').' oluşturuldu: '.$name, 'remove', [[$res['path']]]);
    }

    /**
     * Undo of a create, copy, compress or new upload: the new entries go
     * to the trash.
     *
     * @param  list<string>  $paths
     */
    public function remove(array $paths): void
    {
        if ($this->run('delete', ['paths' => array_values($paths)]) !== null) {
            $this->dispatch('toast', message: 'Geri alındı.');
        }
    }

    // --- Rename ---

    public function openRename(string $path): void
    {
        $this->resetValidation();
        $this->renamePath = $path;
        $this->renameName = basename($path);
    }

    public function rename(): void
    {
        $name = trim($this->renameName);
        if ($name === '' || str_contains($name, '/')) {
            $this->addError('renameName', 'Bir ad yazın; "/" kullanılamaz.');

            return;
        }
        $from = (string) $this->renamePath;
        $res = $this->run('rename', ['path' => $from, 'name' => $name]);
        if ($res === null) {
            return;
        }
        $this->renamePath = null;
        $this->selected = [];
        if (($res['path'] ?? $from) !== $from) {
            $this->undoToast('Adı değiştirildi: '.$name, 'undoRename', [$res['path'], basename($from)]);
        }
    }

    public function undoRename(string $path, string $name): void
    {
        if ($this->run('rename', ['path' => $path, 'name' => $name]) !== null) {
            $this->dispatch('toast', message: 'Geri alındı.');
        }
    }

    // --- Delete and the trash ---

    public function confirmDelete(?string $path = null): void
    {
        $this->targets = $path !== null ? [$path] : array_values($this->selected);
        $this->deleteOpen = $this->targets !== [];
    }

    public function delete(): void
    {
        $this->deleteOpen = false;
        $res = $this->run('delete', ['paths' => $this->targets]);
        if ($res === null) {
            return;
        }
        $ids = $res['ids'] ?? [];
        $count = count($ids);
        $this->unselectTargets();
        $this->undoToast(($count === 1 ? '1 öğe' : $count.' öğe').' çöp kutusuna taşındı.', 'restoreTrash', [$ids]);
    }

    /**
     * @param  list<string>  $ids
     */
    public function restoreTrash(array $ids): void
    {
        $res = $this->run('trash.restore', ['ids' => array_values($ids)]);
        if ($res !== null) {
            $restored = $res['restored'] ?? [];
            $this->dispatch('toast', message: count($restored) === 1 ? 'Geri yüklendi: '.$restored[0]['path'] : count($restored).' öğe geri yüklendi.');
        }
    }

    public function emptyTrash(): void
    {
        $this->emptyTrashOpen = false;
        $res = $this->run('trash.delete', ['ids' => []]);
        if ($res !== null) {
            $this->dispatch('toast', message: 'Çöp kutusu boşaltıldı.');
        }
    }

    public function deleteFromTrash(string $id): void
    {
        $this->run('trash.delete', ['ids' => [$id]]);
    }

    // --- Move and copy ---

    public function openTransfer(string $kind): void
    {
        if ($this->selected === []) {
            return;
        }
        $this->resetValidation();
        $this->transfer = $kind === 'copy' ? 'copy' : 'move';
        $this->transferTo = $this->path;
    }

    public function transferSelected(): void
    {
        $to = $this->cleanPath($this->transferTo);
        $kind = $this->transfer;
        $res = $this->run($kind, ['paths' => array_values($this->selected), 'to' => $to], 600);
        if ($res === null) {
            return;
        }
        $this->transfer = '';
        $this->selected = [];
        if ($kind === 'move') {
            $moved = $res['moved'] ?? [];
            $this->undoToast(count($moved).' öğe taşındı: /'.$to, 'undoMove', [$moved]);
        } else {
            $copied = $res['copied'] ?? [];
            $this->undoToast(count($copied).' öğe kopyalandı: /'.$to, 'remove', [$copied]);
        }
    }

    /**
     * @param  list<array{from: string, to: string}>  $moved
     */
    public function undoMove(array $moved): void
    {
        foreach ($moved as $m) {
            $dir = dirname($m['from']);
            if ($this->run('move', ['paths' => [$m['to']], 'to' => $dir === '.' ? '' : $dir]) === null) {
                return;
            }
        }
        $this->dispatch('toast', message: 'Geri alındı.');
    }

    // --- Permissions ---

    public function openPermissions(?string $path = null, ?string $mode = null): void
    {
        $this->targets = $path !== null ? [$path] : array_values($this->selected);
        if ($this->targets === []) {
            return;
        }
        $this->resetValidation();
        $this->permMode = $mode ?? '644';
        $this->recursive = false;
        $this->dirMode = '755';
        $this->fileMode = '644';
        $this->permOpen = true;
    }

    public function chmod(): void
    {
        foreach (['permMode', 'dirMode', 'fileMode'] as $field) {
            if (($field === 'permMode' || $this->recursive) && ! preg_match('/^[0-7]{3}$/', $this->{$field})) {
                $this->addError($field, 'Üç rakam yazın, örneğin 644.');

                return;
            }
        }
        $res = $this->run('chmod', ['paths' => $this->targets, 'mode' => $this->permMode, 'recursive' => $this->recursive, 'dir_mode' => $this->dirMode, 'file_mode' => $this->fileMode], 600);
        if ($res === null) {
            return;
        }
        $this->permOpen = false;
        $this->unselectTargets();
        if ($this->recursive) {
            $this->dispatch('toast', message: 'İzinler değiştirildi ('.($res['changed'] ?? 0).' öğe).');
        } else {
            $this->undoToast('İzinler '.$this->permMode.' yapıldı.', 'undoChmod', [$res['before'] ?? []]);
        }
    }

    /**
     * @param  array<string, string>  $before
     */
    private function unselectTargets(): void
    {
        $this->selected = array_values(array_diff($this->selected, $this->targets));
        $this->targets = [];
    }

    public function undoChmod(array $before): void
    {
        foreach ($before as $path => $mode) {
            if ($this->run('chmod', ['paths' => [$path], 'mode' => $mode]) === null) {
                return;
            }
        }
        $this->dispatch('toast', message: 'Geri alındı.');
    }

    // --- Archives ---

    public function openCompress(): void
    {
        if ($this->selected === []) {
            return;
        }
        $this->resetValidation();
        $first = basename($this->selected[0]);
        $this->archiveName = count($this->selected) === 1 ? $first : (basename($this->path) ?: 'arsiv');
        $this->archiveFormat = 'zip';
        $this->compressOpen = true;
    }

    public function compress(): void
    {
        $name = trim($this->archiveName);
        if ($name === '' || str_contains($name, '/')) {
            $this->addError('archiveName', 'Bir ad yazın; "/" kullanılamaz.');

            return;
        }
        $res = $this->run('compress', ['path' => $this->path, 'paths' => array_values($this->selected), 'name' => $name, 'format' => $this->archiveFormat === 'tar.gz' ? 'tar.gz' : 'zip'], 1800);
        if ($res === null) {
            return;
        }
        $this->compressOpen = false;
        $this->selected = [];
        $this->undoToast('Arşiv oluşturuldu: '.basename($res['path']), 'remove', [[$res['path']]]);
    }

    public function openExtract(string $path): void
    {
        $this->resetValidation();
        $this->extractPath = $path;
        $dir = dirname($path);
        $this->extractTo = $dir === '.' ? '' : $dir;
        $this->extractOverwrite = false;
    }

    public function extract(): void
    {
        $res = $this->run('extract', ['path' => (string) $this->extractPath, 'to' => $this->cleanPath($this->extractTo), 'overwrite' => $this->extractOverwrite], 1800);
        if ($res === null) {
            return;
        }
        $this->extractPath = null;
        $this->dispatch('toast', message: ($res['entries'] ?? 0).' öğe çıkarıldı'.($this->extractOverwrite ? '; üzerine yazılan dosyalar çöp kutusunda.' : '.'));
    }

    // --- Uploads and the editor (called by the page's script) ---

    /**
     * After an upload: an Undo removes the new files and brings back the
     * ones they replaced.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $replaced  trash ids
     */
    public function uploaded(array $paths, array $replaced): void
    {
        $count = count($paths);
        if ($count === 0) {
            return;
        }
        $this->undoToast(($count === 1 ? basename($paths[0]) : $count.' dosya').' yüklendi.', 'undoUpload', [$paths, $replaced]);
        // Uploads are checked for web shells at once; the full scan runs weekly.
        $findings = app(Scans::class)->scanUploads($this->site(), array_values(array_slice($paths, 0, 200)));
        $this->flagged = $findings === [] ? null : array_values(array_unique(array_map(fn ($f) => $f->path, $findings)));
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $replaced
     */
    public function undoUpload(array $paths, array $replaced): void
    {
        if ($this->run('delete', ['paths' => array_values($paths)]) === null) {
            return;
        }
        if ($replaced !== [] && $this->run('trash.restore', ['ids' => array_values($replaced)]) === null) {
            return;
        }
        $this->dispatch('toast', message: 'Geri alındı.');
    }

    public function saved(string $path, string $trash): void
    {
        if ($trash === '') {
            $this->dispatch('toast', message: basename($path).' kaydedildi.');

            return;
        }
        $this->undoToast(basename($path).' kaydedildi.', 'restoreTrash', [[$trash]]);
    }

    public function render(): View
    {
        $site = $this->site();
        $entries = [];
        $truncated = false;
        $error = null;
        try {
            $res = app(FileManager::class)->call($site, 'list', ['path' => $this->path]);
            $entries = $res['entries'] ?? [];
            $truncated = (bool) ($res['truncated'] ?? false);
        } catch (AgentException $e) {
            $error = FileManager::message($e);
        }
        $q = Str::lower(trim($this->search));
        if ($q !== '' && $this->results === null) {
            $entries = array_values(array_filter($entries, fn ($e) => str_contains(Str::lower($e['name']), $q)));
        }
        $trash = [];
        if ($this->trashOpen) {
            try {
                $trash = app(FileManager::class)->call($site, 'trash.list')['items'] ?? [];
            } catch (AgentException $e) {
                $trash = [];
            }
        }
        $customer = $site->customer();
        $disk = $customer ? app(Quotas::class)->report($customer)['disk'] : null;

        return view('livewire.sites.files', [
            'site' => $site,
            'root' => FileManager::root($site),
            'entries' => $this->results ?? $entries,
            'searching' => $this->results !== null,
            'truncated' => $this->results !== null ? $this->resultsTruncated : $truncated,
            'error' => $error,
            'trash' => $trash,
            'disk' => $disk,
            'ftpCount' => FtpAccount::where('site_id', $site->id)->count(),
            'date' => fn (int $t) => Date::createFromTimestamp($t)->timezone(config('servik.timezone'))->format('d.m.Y H:i'),
        ])->title('Dosya Yöneticisi · '.$site->domain.' · Servik');
    }
}
