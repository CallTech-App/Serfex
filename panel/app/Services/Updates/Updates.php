<?php

namespace App\Services\Updates;

use App\Jobs\RunPanelUpdate;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\ComponentOperation;
use App\Models\PanelUpdate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Backups\Backups;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Güncellemeler: Servik's own releases. The agent checks GitHub for
 * releases newer than the one running (on the stable or beta channel),
 * installs one with the panel in maintenance for the switch and returns
 * to the previous release by itself when the new one fails its health
 * check. The panel takes a panel backup first, starts the agent's run and
 * records how it ended; it never installs anything itself.
 *
 * Nothing updates on its own: the daily check (04:30) only finds
 * releases, the administrator starts the update.
 */
class Updates
{
    public const CHANNELS = ['stable' => 'Kararlı', 'beta' => 'Beta'];

    public const STATUS_KEY = 'servik.updates.status';

    /** Seconds the agent's status is reused by the page. */
    public const STATUS_TTL = 300;

    /**
     * The newest release the last check found, kept until the next one so
     * the menu and dashboard never call the agent.
     */
    public const KNOWN_KEY = 'servik.updates.known';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Backups $backups,
    ) {}

    /**
     * The agent's update status (update.status), or null with $error set
     * when it cannot be read. $fresh skips the short cache the menu uses.
     *
     * @return array<string, mixed>|null
     */
    public function status(bool $fresh = false, ?string &$error = null): ?array
    {
        if (! $fresh && is_array($cached = Cache::get(self::STATUS_KEY))) {
            return $cached;
        }
        try {
            $status = (array) $this->agent->call('update.status');
        } catch (AgentException $e) {
            $error = $e instanceof AgentUnavailableException ? 'Sunucu ajanına ulaşılamıyor.' : $e->reason;

            return null;
        }
        $this->remember($status);

        return $status;
    }

    public function forget(): void
    {
        Cache::forget(self::STATUS_KEY);
        Cache::forget(self::KNOWN_KEY);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function remember(array $status): void
    {
        Cache::put(self::STATUS_KEY, $status, self::STATUS_TTL);
        Cache::forever(self::KNOWN_KEY, $this->available($status));
    }

    public function channel(): string
    {
        $channel = (string) Setting::get('updates.channel', 'stable');

        return array_key_exists($channel, self::CHANNELS) ? $channel : 'stable';
    }

    public function setChannel(string $channel): void
    {
        if (array_key_exists($channel, self::CHANNELS)) {
            Setting::put('updates.channel', $channel);
        }
    }

    /**
     * Asks the agent for new releases on the chosen channel. Returns the
     * status, or why the check failed as text.
     *
     * @return array<string, mixed>|string
     */
    public function check(): array|string
    {
        try {
            $status = (array) $this->agent->call('update.check', ['channel' => $this->channel()], 60);
        } catch (AgentException $e) {
            $this->forget();

            return $e instanceof AgentUnavailableException ? 'Sunucu ajanına ulaşılamıyor.' : 'Sürümler denetlenemedi: '.$e->reason;
        }
        $this->remember($status);

        return $status;
    }

    /**
     * The newest release the last check found, or null.
     *
     * @param  array<string, mixed>|null  $status
     * @return array<string, mixed>|null
     */
    public function available(?array $status): ?array
    {
        if (! ($status['installed'] ?? false)) {
            return null;
        }

        return ((array) ($status['releases'] ?? []))[0] ?? null;
    }

    /**
     * Why an update or rollback cannot start now, or null.
     *
     * @param  array<string, mixed>|null  $status
     */
    public function refusal(?array $status, ?string $error = null): ?string
    {
        if ($status === null) {
            return $error ?: 'Sunucu ajanına ulaşılamıyor.';
        }
        if (! ($status['installed'] ?? false)) {
            return 'Bu sunucu kurulum betiğiyle kurulmamış (geliştirme kurulumu); Servik kendini yalnızca install.sh ile kurulan sunucularda günceller.';
        }
        if (PanelUpdate::running()->exists() || ($status['running'] ?? '') !== '') {
            return 'Bir güncelleme sürüyor. Bitince yeniden deneyin.';
        }
        if (Backup::whereIn('status', ['queued', 'running'])->exists() || BackupRestore::whereIn('status', ['queued', 'running'])->exists()) {
            return 'Bir yedekleme ya da geri yükleme sürüyor; güncelleme panelin iş kuyruklarını durdurur. Bitince yeniden deneyin.';
        }
        if (ComponentOperation::running()->exists()) {
            return 'Yazılım Yönetimi\'nde bir işlem sürüyor. Bitince yeniden deneyin.';
        }

        return null;
    }

    /**
     * Starts the update to the newest release found: a panel backup, then
     * the agent's run. Returns why not, as text, when it cannot start.
     */
    public function start(?User $user, ?string $version = null): PanelUpdate|string
    {
        $status = $this->status(true, $error);
        if (($refusal = $this->refusal($status, $error)) !== null) {
            return $refusal;
        }
        $release = $this->available($status);
        if ($version !== null) {
            $release = collect((array) $status['releases'])->firstWhere('version', $version);
        }
        if ($release === null) {
            return 'Kurulacak yeni bir sürüm yok. Önce "Şimdi denetle" ile yeni sürümleri arayın.';
        }

        return $this->queue('upgrade', (string) $status['version'], (string) $release['version'], $user);
    }

    /**
     * Starts the return to the previous release.
     */
    public function rollback(?User $user): PanelUpdate|string
    {
        $status = $this->status(true, $error);
        if (($refusal = $this->refusal($status, $error)) !== null) {
            return $refusal;
        }
        if (($status['previous'] ?? '') === '') {
            return 'Dönülecek önceki sürüm bu sunucuda yok.';
        }

        return $this->queue('rollback', (string) $status['version'], (string) $status['previous'], $user);
    }

    private function queue(string $kind, string $from, string $to, ?User $user): PanelUpdate
    {
        $update = PanelUpdate::create([
            'kind' => $kind,
            'from_version' => $from,
            'to_version' => $to,
            'run' => 'upd-'.Str::lower(Str::random(20)),
            'user_id' => $user?->id,
        ]);
        RunPanelUpdate::dispatch($update);

        return $update;
    }

    /**
     * Takes the panel backup and starts the agent's run (RunPanelUpdate
     * calls it). The run goes on without the panel, which it restarts;
     * reconcile() records how it ended.
     */
    public function begin(PanelUpdate $update): void
    {
        $update->update(['status' => 'backing_up', 'started_at' => now()]);
        $backup = $this->backups->takePanel($update->user);
        $update->update(['backup_id' => $backup->id]);
        $item = $backup->items()->first();
        if ($backup->status !== 'succeeded' || ($item?->warnings ?? []) !== []) {
            $why = $backup->error ?: $item?->error ?: ($item?->warnings[0] ?? null) ?: 'bilinmeyen hata';
            $this->fail($update, 'Panel yedeği alınamadı, hiçbir şey değiştirilmedi: '.$why);

            return;
        }
        $update->update(['status' => 'running']);
        try {
            $this->agent->call(
                $update->kind === 'rollback' ? 'update.rollback' : 'update.start',
                $update->kind === 'rollback' ? ['run' => $update->run] : ['version' => $update->to_version, 'run' => $update->run],
                30,
            );
        } catch (AgentException $e) {
            $this->fail($update, $e instanceof AgentUnavailableException ? 'Sunucu ajanına ulaşılamadı.' : $e->reason);
        }
        $this->forget();
    }

    /**
     * Records how the runs the agent finished ended, from update.status.
     */
    public function reconcile(?array $status = null): void
    {
        $running = PanelUpdate::where('status', 'running')->get();
        if ($running->isEmpty()) {
            return;
        }
        $status ??= $this->status(true);
        if ($status === null) {
            return;
        }
        $last = (array) ($status['last'] ?? []);
        foreach ($running as $update) {
            if (($status['running'] ?? '') === $update->run) {
                continue;
            }
            if (($last['run'] ?? '') === $update->run) {
                $update->update([
                    'status' => in_array($last['status'] ?? '', ['succeeded', 'failed', 'rolled_back'], true) ? $last['status'] : 'failed',
                    'error' => ($last['error'] ?? '') ?: null,
                    'finished_at' => now(),
                ]);
                $this->forget();
            } elseif ($update->started_at?->lt(now()->subHours(2))) {
                $this->fail($update, 'Güncelleme yarıda kaldı; sunucu ajanında kaydı yok.');
            }
        }
    }

    /**
     * What the menu and dashboard point at: a failed update first, then a
     * release ready to install. Reads only the database and the release
     * the last check remembered; the agent is asked only while an update
     * is running.
     *
     * @return list<array{text: string, level: string}>
     */
    public function notices(): array
    {
        if (PanelUpdate::where('status', 'running')->exists()) {
            $this->reconcile();
        }
        $out = [];
        $last = PanelUpdate::whereNotIn('status', PanelUpdate::RUNNING)->latest('id')->first();
        if ($last !== null && in_array($last->status, ['failed', 'rolled_back'], true) && $last->created_at->gt(now()->subDays(7))) {
            $out[] = ['level' => 'warn', 'text' => $last->status === 'rolled_back'
                ? 'Servik '.$last->to_version.' kurulamadı, '.$last->from_version.' sürümüne dönüldü'
                : ($last->kind === 'rollback' ? 'Önceki sürüme dönülemedi' : 'Servik '.$last->to_version.' güncellemesi başarısız oldu')];
        }
        $release = Cache::get(self::KNOWN_KEY);
        if (is_array($release) && ($release['version'] ?? null) !== config('servik.version') && ($out === [] || $release['version'] !== $last?->to_version)) {
            $out[] = ['level' => 'info', 'text' => 'Servik '.$release['version'].' hazır'];
        }

        return $out;
    }

    private function fail(PanelUpdate $update, string $why): void
    {
        $update->update(['status' => 'failed', 'error' => $why, 'finished_at' => now()]);
    }
}
