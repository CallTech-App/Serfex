<?php

namespace App\Livewire\Backups;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Backup;
use App\Models\BackupItem;
use App\Models\BackupRestore;
use App\Models\BackupTarget;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\BackupImports;
use App\Services\Backups\Backups;
use App\Services\Backups\BackupSettings;
use App\Services\Backups\Restores;
use App\Services\Customers\Quotas;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Yedekler: backups of the whole server and of single sites, their
 * targets (S3-compatible storage, SFTP) and the schedule. Administrators
 * see everything; customers see, take, restore and download their own
 * sites' backups within their package.
 */
#[Layout('components.layouts.app')]
#[Title('Yedekler · Servik')]
#[ForCustomers(feature: 'backups', except: [
    'saveSchedule', 'restoreSchedule', 'togglePassphrase',
    'openTarget', 'saveTarget', 'testTarget', 'deleteTarget', 'restoreTarget',
    'openImport', 'newUpload', 'importUpload', 'importFile', 'scanTarget', 'adopt',
])]
class BackupIndex extends Component
{
    use WithPagination;

    public const TABS = ['yedekler' => 'Yedekler', 'hedefler' => 'Hedefler', 'zamanlama' => 'Zamanlama'];

    #[Url(as: 'sekme', except: 'yedekler')]
    public string $tab = 'yedekler';

    /** '' (all), server or site. */
    #[Url(as: 'kapsam', except: '')]
    public string $scope = '';

    /** Only one site's backups (from the site's page). */
    #[Url(as: 'site', except: 0)]
    public int $siteId = 0;

    /** Why the last action could not be done. */
    public ?string $refused = null;

    // "Şimdi yedekle"
    public bool $backingUp = false;

    public string $backupScope = 'server';

    public ?int $backupSite = null;

    // "Geri yükle"
    public bool $restoring = false;

    public ?int $restoreBackupId = null;

    public ?int $restoreItemId = null;

    /** @var list<string> */
    public array $restoreParts = [];

    public bool $safety = true;

    // A backup's archives
    public ?int $detailId = null;

    // Progress of a backup or restore: ['backup'|'restore', id]
    public ?array $running = null;

    // Delete
    public ?int $deleteId = null;

    // Schedule
    public bool $sEnabled = true;

    public string $sFrequency = 'daily';

    public string $sTime = '03:00';

    public int $sWeekday = 0;

    public bool $sIncremental = true;

    public int $sKeepDaily = 7;

    public int $sKeepWeekly = 4;

    public int $sKeepMonthly = 3;

    public string $sTarget = '';

    public bool $sKeepLocal = false;

    public bool $sEncrypt = true;

    public string $sExclude = '';

    /** @var list<string> */
    public array $sSkip = [];

    public bool $showPassphrase = false;

    // Target form
    public bool $editingTarget = false;

    public ?int $targetId = null;

    public string $tName = '';

    public string $tType = 's3';

    public string $tProvider = 'aws';

    public string $tEndpoint = '';

    public string $tRegion = '';

    public string $tBucket = '';

    public string $tAccessKey = '';

    public string $tSecretKey = '';

    public bool $tPathStyle = false;

    public string $tFolder = 'servik';

    public string $tHost = '';

    public int $tPort = 22;

    public string $tUser = '';

    // İçe aktar
    public bool $importing = false;

    public string $importFrom = 'upload';

    public string $importPassphrase = '';

    public ?int $scanTargetId = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $scanResults = null;

    public function mount(BackupSettings $settings): void
    {
        if (! array_key_exists($this->tab, self::TABS) || ! auth()->user()->is_admin) {
            $this->tab = 'yedekler';
        }
        if ($this->siteId !== 0 && Site::visibleTo(auth()->user())->whereKey($this->siteId)->doesntExist()) {
            $this->siteId = 0;
        }
        $this->fillSchedule($settings);
        // Back on the page while something runs: show its progress.
        $restore = BackupRestore::whereIn('status', ['queued', 'running'])->when(! auth()->user()->is_admin, fn ($q) => $q->where('user_id', auth()->id()))->latest('id')->first();
        if ($restore !== null) {
            $this->running = ['restore', $restore->id];
        }
    }

    public function updatedScope(): void
    {
        $this->resetPage();
    }

    // ---- Taking a backup -------------------------------------------------

    public function openBackup(): void
    {
        $this->refused = null;
        $this->resetValidation();
        $sites = $this->sites();
        $this->backupScope = auth()->user()->is_admin && $this->siteId === 0 ? 'server' : 'site';
        $this->backupSite = $this->siteId !== 0 ? $this->siteId : $sites->first()?->id;
        $this->backingUp = true;
    }

    public function startBackup(Backups $backups): void
    {
        $user = auth()->user();
        if ($this->backupScope === 'server' && $user->is_admin) {
            $result = $backups->startServer($user);
        } else {
            $site = Site::visibleTo($user)->with('systemUser.customer.package')->find($this->backupSite);
            if ($site === null) {
                $this->addError('backupSite', 'Bir site seçin.');

                return;
            }
            $result = $backups->startSite($site, $user);
        }
        if (is_string($result)) {
            $this->addError('backupSite', $result);

            return;
        }
        $this->backingUp = false;
        $this->running = ['backup', $result->id];
        $this->dispatch('toast', message: $result->scope === 'server' ? 'Tüm sunucunun yedeği alınıyor.' : $result->items()->value('name').' yedekleniyor.');
    }

    // ---- Restoring -------------------------------------------------------

    public function openRestore(int $backupId, ?int $itemId = null): void
    {
        $this->refused = null;
        $backup = $this->visibleBackups()->usable()->find($backupId);
        if ($backup === null) {
            return;
        }
        $items = $this->restorableItems($backup);
        $item = $itemId !== null ? $items->firstWhere('id', $itemId) : ($this->siteId !== 0 ? $items->firstWhere('site_id', $this->siteId) : null) ?? $items->first();
        if ($item === null) {
            $this->refused = 'Bu yedekte geri yüklenebilecek bir site yok.';

            return;
        }
        $this->restoreBackupId = $backup->id;
        $this->restoreItemId = $item->id;
        $this->restoreParts = array_values(array_intersect(['files', 'databases'], $item->restorable())) ?: $item->restorable();
        $this->safety = true;
        $this->resetValidation();
        $this->restoring = true;
    }

    public function updatedRestoreItemId(): void
    {
        $item = $this->restoreItem();
        $this->restoreParts = $item ? array_values(array_intersect($this->restoreParts, $item->restorable())) : [];
    }

    public function restore(Restores $restores): void
    {
        $item = $this->restoreItem();
        if ($item === null) {
            $this->restoring = false;

            return;
        }
        $this->validate(['restoreParts' => ['required', 'array', 'min:1'], 'restoreParts.*' => [Rule::in($item->restorable())]],
            ['restoreParts.required' => 'En az bir şey seçin.', 'restoreParts.min' => 'En az bir şey seçin.']);
        $result = $restores->start($item, $this->restoreParts, $this->safety, auth()->user());
        if (is_string($result)) {
            $this->addError('restoreParts', $result);

            return;
        }
        $this->restoring = false;
        $this->running = ['restore', $result->id];
        $this->dispatch('toast', message: "{$item->name} geri yükleniyor.");
    }

    /**
     * Puts back the state from before a restore.
     */
    public function undoRestore(int $restoreId, Restores $restores): void
    {
        $restore = $this->visibleRestores()->find($restoreId);
        if ($restore === null) {
            return;
        }
        $result = $restores->undo($restore, auth()->user());
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $this->running = ['restore', $result->id];
        $this->dispatch('toast', message: 'Geri yüklemeden önceki hâline dönülüyor.');
    }

    // ---- Progress --------------------------------------------------------

    public function showRun(string $kind, int $id): void
    {
        $exists = $kind === 'restore' ? $this->visibleRestores()->whereKey($id)->exists() : $this->visibleBackups()->whereKey($id)->exists();
        if ($exists) {
            $this->running = [$kind, $id];
        }
    }

    public function closeRun(): void
    {
        $this->running = null;
    }

    /**
     * The log of the backup or restore shown, from $offset on.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, status: string, error?: ?string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $op = $this->visibleBackups()->where('run', $run)->first() ?? $this->visibleRestores()->where('run', $run)->first();
        if ($op === null) {
            return null;
        }
        try {
            $chunk = app(AgentClient::class)->call('backup.run.read', ['run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => ! $op->isRunning(), 'status' => $op->status, 'error' => 'Sunucu ajanına ulaşılamıyor.'];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => ! $op->isRunning(),
            'status' => $op->status,
            'error' => $op->status === 'failed' ? $op->error : null,
        ];
    }

    // ---- A backup's archives, download, delete ----------------------------

    public function openDetail(int $backupId): void
    {
        if ($this->visibleBackups()->whereKey($backupId)->exists()) {
            $this->detailId = $backupId;
        }
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    /**
     * Brings a backup that is only on its target to this server, for a
     * download.
     */
    public function fetch(int $backupId, Backups $backups): void
    {
        $backup = $this->visibleBackups()->usable()->find($backupId);
        if ($backup === null) {
            return;
        }
        if (($why = $backups->fetch($backup)) !== null) {
            $this->refused = $why;

            return;
        }
        $this->dispatch('toast', message: 'Yedek bu sunucuya alındı; '.Backups::CACHE_HOURS.' saat indirilebilir.');
    }

    public function askDelete(int $backupId): void
    {
        $backup = $this->deletable()->find($backupId);
        if ($backup !== null) {
            $this->detailId = null;
            $this->deleteId = $backup->id;
        }
    }

    public function delete(Backups $backups): void
    {
        $backup = $this->deleteId ? $this->deletable()->find($this->deleteId) : null;
        $this->deleteId = null;
        if ($backup === null) {
            return;
        }
        $ids = $backups->delete($backup);
        $this->dispatch('toast', message: count($ids) > 1 ? count($ids).' yedek silindi.' : 'Yedek silindi.', source: $this->getId(), undo: ['method' => 'undoDelete', 'args' => [$ids]]);
    }

    /**
     * @param  list<int>  $ids
     */
    public function undoDelete(array $ids, Backups $backups): void
    {
        $mine = Backup::whereIn('id', array_map('intval', $ids))->where('status', 'deleted')
            ->when(! auth()->user()->is_admin, fn ($q) => $q->where('customer_id', auth()->user()->customer_id))->pluck('id')->all();
        $backups->undoDelete($mine);
    }

    // ---- Schedule (administrators) -----------------------------------------

    public function saveSchedule(BackupSettings $settings): void
    {
        $this->validate([
            'sFrequency' => ['required', Rule::in(array_keys(BackupSettings::FREQUENCIES))],
            'sTime' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'sWeekday' => ['required', 'integer', 'between:0,6'],
            'sKeepDaily' => ['required', 'integer', 'between:0,90'],
            'sKeepWeekly' => ['required', 'integer', 'between:0,52'],
            'sKeepMonthly' => ['required', 'integer', 'between:0,60'],
            'sTarget' => ['nullable', Rule::in(BackupTarget::pluck('id')->map(fn ($id) => (string) $id)->all())],
            'sSkip.*' => ['string'],
        ], ['sTime.regex' => 'Saati SS:DD biçiminde yazın, örneğin 03:00.'], ['sKeepDaily' => 'günlük', 'sKeepWeekly' => 'haftalık', 'sKeepMonthly' => 'aylık']);
        $exclude = collect(preg_split('/[\r\n,]+/', $this->sExclude))->map(fn ($p) => trim($p))->filter()->values();
        foreach ($exclude as $pattern) {
            if (! preg_match('/^[^\x00-\x1f\'"\\\\]{1,200}$/', $pattern) || str_starts_with($pattern, '-')) {
                $this->addError('sExclude', "\"{$pattern}\" kullanılamaz.");

                return;
            }
        }
        $before = $settings->schedule();
        $settings->save([
            'enabled' => $this->sEnabled,
            'frequency' => $this->sFrequency,
            'time' => $this->sTime,
            'weekday' => $this->sWeekday,
            'incremental' => $this->sIncremental,
            'keep_daily' => $this->sKeepDaily,
            'keep_weekly' => $this->sKeepWeekly,
            'keep_monthly' => $this->sKeepMonthly,
            'target_id' => $this->sTarget === '' ? null : (int) $this->sTarget,
            'keep_local' => $this->sKeepLocal,
            'encrypt' => $this->sEncrypt,
            'exclude' => $exclude->all(),
            'skip_sites' => array_values(array_intersect($this->sSkip, Site::pluck('domain')->all())),
        ]);
        $this->dispatch('toast', message: 'Yedekleme zamanlaması kaydedildi.', source: $this->getId(), undo: ['method' => 'restoreSchedule', 'args' => [$before]]);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    public function restoreSchedule(array $before, BackupSettings $settings): void
    {
        $settings->save($before);
        $this->fillSchedule($settings);
    }

    public function togglePassphrase(): void
    {
        $this->showPassphrase = ! $this->showPassphrase;
    }

    // ---- Targets (administrators) -----------------------------------------

    public function openTarget(?int $id = null): void
    {
        $this->resetValidation();
        $this->refused = null;
        $t = $id ? BackupTarget::find($id) : null;
        $c = $t?->config ?? [];
        $this->targetId = $t?->id;
        $this->tName = $t?->name ?? '';
        $this->tType = $t?->type ?? 's3';
        $this->tProvider = $c['provider'] ?? 'aws';
        $this->tEndpoint = $c['endpoint'] ?? '';
        $this->tRegion = $c['region'] ?? BackupTarget::PROVIDERS['aws'][2];
        $this->tBucket = $c['bucket'] ?? '';
        $this->tAccessKey = $c['access_key'] ?? '';
        // A saved secret is not sent back to the browser.
        $this->tSecretKey = '';
        $this->tPathStyle = (bool) ($c['path_style'] ?? false);
        $this->tFolder = $t ? ($c['folder'] ?? '') : 'servik';
        $this->tHost = $c['host'] ?? '';
        $this->tPort = (int) ($c['port'] ?? 22);
        $this->tUser = $c['user'] ?? '';
        $this->editingTarget = true;
    }

    public function updatedTProvider(): void
    {
        [, $endpoint, $region, $path] = BackupTarget::PROVIDERS[$this->tProvider] ?? BackupTarget::PROVIDERS['minio'];
        $this->tRegion = $region;
        $this->tEndpoint = str_replace('{region}', $region, $endpoint);
        $this->tPathStyle = $path;
    }

    public function updatedTRegion(): void
    {
        $endpoint = BackupTarget::PROVIDERS[$this->tProvider][1] ?? '';
        if (str_contains($endpoint, '{region}')) {
            $this->tEndpoint = str_replace('{region}', $this->tRegion, $endpoint);
        }
    }

    /**
     * Tests the target with the form's values and saves it only when the
     * test passes.
     */
    public function saveTarget(Backups $backups): void
    {
        $existing = $this->targetId ? BackupTarget::find($this->targetId) : null;
        $this->tName = trim($this->tName);
        $this->tFolder = trim($this->tFolder, ' /');
        $rules = [
            'tName' => ['required', 'string', 'max:64'],
            'tType' => ['required', Rule::in(array_keys(BackupTarget::TYPES))],
            'tFolder' => ['nullable', 'max:120', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*$/'],
        ];
        if ($this->tType === 's3') {
            $rules += [
                'tBucket' => ['required', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'],
                'tRegion' => ['nullable', 'regex:/^[a-z0-9-]{1,32}$/'],
                'tEndpoint' => ['nullable', 'max:255'],
                'tAccessKey' => ['required', 'max:256'],
                'tSecretKey' => [$existing ? 'nullable' : 'required', 'max:256'],
            ];
        } else {
            $rules += [
                'tHost' => ['required', 'max:253'],
                'tPort' => ['required', 'integer', 'between:1,65535'],
                'tUser' => ['required', 'regex:/^[A-Za-z0-9._][A-Za-z0-9._-]{0,63}$/'],
            ];
        }
        $this->validate($rules, [
            'tFolder.regex' => 'Klasör yalnızca harf, rakam, . _ - ve / içerebilir.',
            'tBucket.regex' => 'Geçerli bir bucket adı girin (küçük harf, rakam, - ve .).',
        ], ['tName' => 'ad', 'tBucket' => 'bucket', 'tAccessKey' => 'erişim anahtarı', 'tSecretKey' => 'gizli anahtar', 'tHost' => 'sunucu', 'tUser' => 'kullanıcı', 'tRegion' => 'bölge', 'tFolder' => 'klasör']);

        $old = $existing?->config ?? [];
        $config = $this->tType === 's3' ? [
            'provider' => $this->tProvider,
            'endpoint' => trim($this->tEndpoint),
            'region' => trim($this->tRegion),
            'bucket' => $this->tBucket,
            'access_key' => trim($this->tAccessKey),
            'secret_key' => $this->tSecretKey !== '' ? trim($this->tSecretKey) : ($old['secret_key'] ?? ''),
            'path_style' => $this->tPathStyle,
            'folder' => $this->tFolder,
        ] : [
            'host' => trim($this->tHost),
            'port' => $this->tPort,
            'user' => $this->tUser,
            'folder' => $this->tFolder,
            // The host key learned before stays while the server does.
            'host_key' => ($old['host'] ?? null) === trim($this->tHost) && ($old['port'] ?? 22) == $this->tPort ? ($old['host_key'] ?? '') : '',
            'fingerprint' => ($old['host'] ?? null) === trim($this->tHost) ? ($old['fingerprint'] ?? null) : null,
        ];
        $probe = $existing ?? new BackupTarget;
        $previous = $existing?->only(['name', 'type', 'config', 'status', 'last_error', 'tested_at']);
        $probe->fill(['name' => $this->tName, 'type' => $this->tType, 'config' => $config]);
        // Tested before it is saved; a failing target is not kept.
        $probe->save();
        if (($why = $backups->test($probe)) !== null) {
            if ($previous === null) {
                $probe->forceDelete();
            } else {
                $probe->fill($previous)->save();
            }
            $this->addError('tName', 'Bağlantı testi başarısız, kaydedilmedi: '.$why);

            return;
        }
        $this->editingTarget = false;
        $this->dispatch('toast', message: "{$probe->name}: bağlantı ve yazma testi başarılı, kaydedildi.");
    }

    public function testTarget(int $id, Backups $backups): void
    {
        $target = BackupTarget::find($id);
        if ($target === null) {
            return;
        }
        $why = $backups->test($target);
        $this->dispatch('toast', message: $why === null ? "{$target->name}: bağlantı ve yazma testi başarılı." : "{$target->name}: {$why}");
    }

    public function deleteTarget(int $id, BackupSettings $settings): void
    {
        $target = BackupTarget::find($id);
        if ($target === null) {
            return;
        }
        if ((int) $settings->schedule()['target_id'] === $target->id) {
            $this->refused = "{$target->name} zamanlanmış yedeklerin hedefi; önce Zamanlama'dan başka bir hedef seçin.";

            return;
        }
        $target->delete();
        $this->dispatch('toast', message: "{$target->name} kaldırıldı. Üzerindeki yedekler silinmedi.", source: $this->getId(), undo: ['method' => 'restoreTarget', 'args' => [$target->id]]);
    }

    public function restoreTarget(int $id): void
    {
        BackupTarget::onlyTrashed()->find($id)?->restore();
    }

    // ---- İçe aktar (administrators) ---------------------------------------

    public function openImport(): void
    {
        $this->resetValidation();
        $this->importFrom = 'upload';
        $this->importPassphrase = '';
        $this->scanResults = null;
        $this->scanTargetId = BackupTarget::orderBy('name')->value('id');
        $this->importing = true;
    }

    /**
     * A new upload id for the browser to send an archive under.
     */
    #[Renderless]
    public function newUpload(): string
    {
        return 'up-'.Str::lower(Str::random(20));
    }

    public function importUpload(string $upload, BackupImports $imports): void
    {
        if (! preg_match('/^up-[a-z0-9]{20}$/', $upload)) {
            return;
        }
        $this->finishImport($imports->import(null, $upload, $this->importPassphrase ?: null, auth()->user()));
    }

    public function importFile(string $file, BackupImports $imports): void
    {
        $this->finishImport($imports->import($file, null, $this->importPassphrase ?: null, auth()->user()));
    }

    public function scanTarget(BackupImports $imports): void
    {
        $target = BackupTarget::find($this->scanTargetId);
        if ($target === null) {
            $this->addError('scanTargetId', 'Bir hedef seçin.');

            return;
        }
        $found = $imports->scan($target);
        if (is_string($found)) {
            $this->addError('scanTargetId', $found);

            return;
        }
        $this->scanResults = $found;
    }

    public function adopt(string $key, BackupImports $imports): void
    {
        $target = BackupTarget::find($this->scanTargetId);
        if ($target === null || $this->scanResults === null) {
            return;
        }
        $this->finishImport($imports->adopt($target, $this->scanResults, $key, $this->importPassphrase ?: null, auth()->user()));
    }

    private function finishImport(Backup|string $result): void
    {
        $this->resetErrorBag('importPassphrase');
        if (is_string($result)) {
            $this->addError('importPassphrase', $result);

            return;
        }
        $this->importing = false;
        $this->scope = '';
        $this->dispatch('toast', message: 'Yedek listeye eklendi; geri yüklemek için "Geri yükle"yi kullanın.');
    }

    // ---- Page ---------------------------------------------------------------

    public function render(Backups $backups, BackupSettings $settings, BackupImports $imports, Quotas $quotas): View
    {
        $user = auth()->user();
        if (! $user->is_admin) {
            $this->tab = 'yedekler';
        }
        $itemRows = ! $user->is_admin || $this->siteId !== 0;
        $data = [
            'itemRows' => $itemRows,
            'filterSite' => $this->siteId !== 0 ? Site::find($this->siteId) : null,
            'next' => $settings->next(now()),
            'summary' => $settings->summary(),
            'busy' => $backups->busy(),
            'sites' => $this->sites(),
            'publicKey' => null,
            'editing' => null,
            'importTargets' => collect(),
        ];
        if ($this->tab === 'yedekler') {
            if ($itemRows) {
                $data['rows'] = BackupItem::visibleTo($user)
                    ->with('backup.target', 'site')
                    ->where('name', '!=', 'panel')
                    ->whereHas('backup', fn ($q) => $q->where('status', '!=', 'deleted')->when($this->scope !== '', fn ($q) => $q->where('scope', $this->scope)))
                    ->when($this->siteId !== 0, fn ($q) => $q->where(fn ($q) => $q->where('site_id', $this->siteId)->orWhere('name', Site::find($this->siteId)?->domain)))
                    ->orderByDesc('backup_id')
                    ->paginate(25);
                $data['total'] = (int) BackupItem::visibleTo($user)->where('status', 'succeeded')->whereHas('backup', fn ($q) => $q->usable())
                    ->when($this->siteId !== 0, fn ($q) => $q->where('site_id', $this->siteId))->sum('size');
            } else {
                $data['rows'] = Backup::with('target', 'items')
                    ->where('status', '!=', 'deleted')
                    ->when($this->scope !== '', fn ($q) => $q->where('scope', $this->scope))
                    ->orderByDesc('id')
                    ->paginate(25);
                $data['total'] = (int) Backup::usable()->sum('size');
            }
            $data['lastBackup'] = Backup::usable()->where('scope', 'server')->latest('finished_at')->first();
            $data['problems'] = $user->is_admin ? $backups->problems() : [];
            if ($user->isCustomer()) {
                $data['quota'] = $quotas->report($user->customer)['backups'];
            }
        }
        if ($user->is_admin && $this->tab === 'hedefler') {
            $data['targets'] = BackupTarget::orderBy('name')->get();
            $data['usage'] = Backup::usable()->where('remote', true)->groupBy('target_id')->selectRaw('target_id, sum(size) as total')->pluck('total', 'target_id');
            $data['status'] = $backups->status();
        }
        if ($user->is_admin && $this->tab === 'zamanlama') {
            $data['targets'] = BackupTarget::orderBy('name')->get();
            $data['passphrase'] = $this->showPassphrase ? $settings->passphrase() : null;
            $data['allSites'] = Site::whereDoesntHave('preview')->orderBy('domain')->pluck('domain');
        }
        if ($this->editingTarget) {
            $data['publicKey'] = $this->tType === 'sftp' ? ($backups->status()['public_key'] ?? null) : null;
            $data['editing'] = $this->targetId ? BackupTarget::find($this->targetId) : null;
        }
        if ($this->importing && $user->is_admin) {
            $data['importTargets'] = BackupTarget::orderBy('name')->get();
            if ($this->importFrom === 'folder') {
                try {
                    $data['importFiles'] = $imports->files();
                } catch (AgentException $e) {
                    $data['importFiles'] = [];
                    $data['importError'] = $e->reason ?: 'Sunucu ajanına ulaşılamıyor.';
                }
            }
        }
        if ($this->restoring && ($item = $this->restoreItem()) !== null) {
            $backup = $item->backup;
            $data['restoreBackup'] = $backup;
            $data['restoreItems'] = $this->restorableItems($backup);
            $data['restoreItem'] = $item;
            $data['restoreSite'] = app(Restores::class)->siteFor($item);
        }
        if ($this->detailId !== null) {
            $data['detail'] = $this->visibleBackups()->with('target', 'base')->find($this->detailId);
            $data['detailItems'] = $data['detail'] ? BackupItem::visibleTo($user)->where('backup_id', $this->detailId)->orderByRaw("name = 'panel' desc")->orderBy('name')->get() : collect();
            $data['detailDependents'] = $data['detail'] && $user->is_admin ? $backups->dependents($data['detail'])->count() : 0;
        }
        if ($this->deleteId !== null) {
            $data['deleting'] = Backup::find($this->deleteId);
            $data['deletingDependents'] = $data['deleting'] ? $backups->dependents($data['deleting'])->count() : 0;
        }
        if ($this->running !== null) {
            [$kind, $id] = $this->running;
            $data['run'] = $kind === 'restore' ? $this->visibleRestores()->with('item.backup', 'safetyBackup')->find($id) : $this->visibleBackups()->with('items')->find($id);
            $data['runKind'] = $kind;
        }

        return view('livewire.backups.index', $data);
    }

    private function fillSchedule(BackupSettings $settings): void
    {
        $s = $settings->schedule();
        $this->sEnabled = (bool) $s['enabled'];
        $this->sFrequency = (string) $s['frequency'];
        $this->sTime = (string) $s['time'];
        $this->sWeekday = (int) $s['weekday'];
        $this->sIncremental = (bool) $s['incremental'];
        $this->sKeepDaily = (int) $s['keep_daily'];
        $this->sKeepWeekly = (int) $s['keep_weekly'];
        $this->sKeepMonthly = (int) $s['keep_monthly'];
        $this->sTarget = $s['target_id'] === null ? '' : (string) $s['target_id'];
        $this->sKeepLocal = (bool) $s['keep_local'];
        $this->sEncrypt = (bool) $s['encrypt'];
        $this->sExclude = implode("\n", $s['exclude']);
        $this->sSkip = array_values($s['skip_sites']);
    }

    /**
     * Sites the account may back up.
     *
     * @return Collection<int, Site>
     */
    private function sites(): Collection
    {
        return Site::visibleTo(auth()->user())->whereDoesntHave('preview')->whereNotNull('provisioned_at')->orderBy('domain')->get(['id', 'domain']);
    }

    /**
     * Backups the user may see: all for an administrator; for a customer,
     * those holding one of their sites.
     *
     * @return Builder<Backup>
     */
    private function visibleBackups(): Builder
    {
        $user = auth()->user();

        return Backup::query()->when(! $user->is_admin, fn ($q) => $q->whereHas('items', fn ($i) => $i->visibleTo($user)));
    }

    /**
     * @return Builder<BackupRestore>
     */
    private function visibleRestores(): Builder
    {
        $user = auth()->user();

        return BackupRestore::query()->when(! $user->is_admin, fn ($q) => $q->whereIn('site_id', Site::visibleTo($user)->select('sites.id')));
    }

    /**
     * Backups the user may delete: any for an administrator, their own
     * manual ones for a customer.
     *
     * @return Builder<Backup>
     */
    private function deletable(): Builder
    {
        $user = auth()->user();

        return Backup::whereIn('status', ['succeeded', 'partial', 'failed'])
            ->when(! $user->is_admin, fn ($q) => $q->where('customer_id', $user->customer_id)->where('trigger', 'manual'));
    }

    /**
     * The archives of a backup the user may restore.
     *
     * @return Collection<int, BackupItem>
     */
    private function restorableItems(Backup $backup): Collection
    {
        return BackupItem::visibleTo(auth()->user())->with('backup')->where('backup_id', $backup->id)
            ->where('name', '!=', 'panel')->where('status', 'succeeded')->orderBy('name')->get();
    }

    private function restoreItem(): ?BackupItem
    {
        return $this->restoreItemId === null ? null : BackupItem::visibleTo(auth()->user())->with('backup')
            ->where('backup_id', $this->restoreBackupId)->where('name', '!=', 'panel')->find($this->restoreItemId);
    }
}
