<?php

namespace App\Http\Controllers\Api\Panel;

use App\Models\Backup;
use App\Models\BackupItem;
use App\Models\BackupRestore;
use App\Services\Backups\Backups;
use App\Services\Backups\Restores;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Yedekler: backups with the archives in them, a backup now (of the whole
 * server, administrators only, or of one site), and restoring a site from
 * an archive. A restore backs the site up first, so the panel can undo it.
 */
class BackupsController extends ApiController
{
    /**
     * GET /api/v1/backups[?site=<domain>][&limit=<n>]: newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $site = $request->filled('site') ? mb_strtolower((string) $request->query('site')) : null;
        $items = fn ($q) => $q->visibleTo($user)->when($site !== null, fn ($q) => $q->where('name', $site));
        $backups = Backup::with(['items' => $items])
            ->whereHas('items', $items)
            ->latest('id')
            ->limit(min(200, max(1, (int) $request->query('limit', 50))))
            ->get();

        return response()->json(['data' => $backups->map(fn (Backup $b) => $this->backup($b))->values()]);
    }

    public function show(Request $request, int $backup): JsonResponse
    {
        return response()->json(['data' => $this->backup($this->find($request, $backup))]);
    }

    /**
     * POST /api/v1/backups {site?}: a backup of the site now, or of the
     * whole server without one.
     */
    public function store(Request $request, Backups $backups): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['site' => ['nullable', 'string', 'max:253']]);
        if (($data['site'] ?? null) === null) {
            if (! $user->is_admin) {
                return $this->refuse(__('Sunucunun tamamını yalnızca yöneticiler yedekleyebilir; bir site belirtin.'), 403);
            }
            $result = $backups->startServer($user);
        } else {
            $result = $backups->startSite($this->findSite($request, $data['site']), $user);
        }
        if (is_string($result)) {
            return $this->refuse($result, 409);
        }
        $this->audit($request, __('Yedekler'), ($data['site'] ?? null) ? __(':domain yedekleniyor.', ['domain' => $data['site']]) : __('Sunucu yedekleniyor.'), route('backups.index', [], false));

        return response()->json(['data' => $this->backup($result->load(['items' => fn ($q) => $q->visibleTo($user)]))], 202);
    }

    /**
     * POST /api/v1/backups/{backup}/restore {site, parts?, safety?}: puts the
     * site back from its archive in that backup: every part it has unless
     * parts (files, databases, mail, dns, certificates) says which.
     */
    public function restore(Request $request, Restores $restores, int $backup): JsonResponse
    {
        $backup = $this->find($request, $backup);
        $data = $request->validate([
            'site' => ['required', 'string', 'max:253'],
            'parts' => ['nullable', 'array'],
            'parts.*' => [Rule::in(array_keys(Backup::RESTORE_PARTS))],
            'safety' => ['nullable', 'boolean'],
        ]);
        $item = $backup->items->firstWhere('name', mb_strtolower($data['site']))
            ?? $this->missing(__('Bu yedekte :site yok.', ['site' => $data['site']]));
        $parts = $data['parts'] ?? $item->restorable();
        $result = $restores->start($item, $parts, (bool) ($data['safety'] ?? true), $this->user($request));
        if (is_string($result)) {
            return $this->refuse($result, 409);
        }
        $this->audit($request, __('Yedekler'), __(':domain geri yükleniyor.', ['domain' => $item->name]), route('backups.index', [], false));

        return response()->json(['data' => $this->restoreData($result)], 202);
    }

    public function showRestore(Request $request, int $restore): JsonResponse
    {
        $restore = BackupRestore::with('item')->whereHas('item', fn ($q) => $q->visibleTo($this->user($request)))->find($restore)
            ?? $this->missing(__('Böyle bir geri yükleme yok.'));

        return response()->json(['data' => $this->restoreData($restore)]);
    }

    private function find(Request $request, int $id): Backup
    {
        $items = fn ($q) => $q->visibleTo($this->user($request));

        return Backup::with(['items' => $items])->whereHas('items', $items)->find($id)
            ?? $this->missing(__('Böyle bir yedek yok.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function backup(Backup $b): array
    {
        return [
            'id' => $b->id,
            'scope' => $b->scope,
            'type' => $b->type,
            'trigger' => $b->trigger,
            'status' => $b->status,
            'destination' => $b->destinationLabel(),
            'encrypted' => $b->encrypted,
            'size' => $b->size,
            'error' => $b->error,
            'created_at' => $b->created_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'done' => ! $b->isRunning(),
            'items' => $b->items->map(fn (BackupItem $i) => [
                'name' => $i->name,
                'status' => $i->status,
                'size' => $i->size,
                'parts' => $i->partsLabel(),
                'restorable' => $i->isPanel() ? [] : $i->restorable(),
                'error' => $i->error,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreData(BackupRestore $r): array
    {
        return [
            'id' => $r->id,
            'site' => $r->domain,
            'parts' => $r->parts,
            'status' => $r->status,
            'error' => $r->error,
            'done' => ! $r->isRunning(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
