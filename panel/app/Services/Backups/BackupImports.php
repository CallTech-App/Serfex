<?php

namespace App\Services\Backups;

use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Backups brought in from elsewhere, to move sites from another Serfex
 * server or to rebuild this one: an archive uploaded from the browser, an
 * archive copied to /var/lib/serfex/backups/import, or the backups found on
 * a remote target (the other server's folder in the same bucket).
 * Administrators only. cPanel and Plesk archives are not read.
 */
class BackupImports
{
    /** Bytes per browser upload request (under Nginx's 1 MB default). */
    public const UPLOAD_CHUNK = 960 * 1024;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Archives waiting in the import folder.
     *
     * @return list<array{file: string, name: ?string, server: ?string, created: ?string, encrypted: bool}>
     */
    public function files(): array
    {
        $found = (array) $this->agent->call('backup.scan', [], 60);

        return array_values(array_map(fn ($f) => [
            'file' => (string) ($f['file'] ?? ''),
            'name' => $f['inspection']['manifest']['name'] ?? null,
            'server' => $f['inspection']['manifest']['server'] ?? null,
            'created' => $f['inspection']['manifest']['created'] ?? null,
            'encrypted' => (bool) ($f['encrypted'] ?? false),
        ], $found));
    }

    /**
     * Imports an archive from the import folder ($file) or an upload
     * ($upload). Returns the backup, or why not as text.
     */
    public function import(?string $file, ?string $upload, ?string $passphrase, ?User $user): Backup|string
    {
        $key = now()->format('Ymd-His').'-'.Str::lower(Str::random(10));
        try {
            $in = (array) $this->agent->call('backup.import', array_filter([
                'file' => $file,
                'upload' => $upload,
                'key' => $key,
                'passphrase' => $passphrase,
            ]), config('serfex.agent.backup_timeout'));
        } catch (AgentException $e) {
            return $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : $this->reason($e);
        }
        $m = (array) ($in['manifest'] ?? []);
        if (($m['incremental'] ?? []) !== []) {
            $this->agent->call('backup.delete', ['key' => $key, 'local' => true], config('serfex.agent.job_timeout'));

            return __('Bu arşiv artımlı bir yedek; tek başına geri yüklenemez. Zincirin tam yedeğini içe aktarın ya da yedeğin bulunduğu hedefi tarayın.');
        }
        $name = (string) ($m['name'] ?? '');
        $meta = isset($in['meta']) ? (is_string($in['meta']) ? json_decode($in['meta'], true) : (array) $in['meta']) : null;
        $backup = Backup::create([
            'key' => $key,
            'scope' => $name === 'panel' ? 'server' : 'site',
            'type' => 'full',
            'trigger' => 'import',
            'local' => true,
            'status' => 'succeeded',
            'size' => (int) ($in['size'] ?? 0),
            'run' => 'import-'.Str::lower(Str::random(12)),
            'source_host' => ($m['server'] ?? '') ?: '?',
            'user_id' => $user?->id,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => isset($m['created']) ? Carbon::parse($m['created']) : now(),
        ]);
        $backup->items()->create([
            'name' => $name,
            'site_id' => $name === 'panel' ? null : Site::where('domain', $name)->value('id'),
            'size' => (int) ($in['size'] ?? 0),
            'parts' => (array) ($m['parts'] ?? []),
            'meta' => $meta,
            'status' => 'succeeded',
        ]);

        return $backup;
    }

    /**
     * The backups on a target, from every server that writes to its
     * folder, with whether each is listed here already.
     *
     * @return list<array<string, mixed>>|string
     */
    public function scan(BackupTarget $target): array|string
    {
        $spec = $target->agentSpec(trim((string) ($target->config['folder'] ?? ''), '/'));
        try {
            $found = (array) $this->agent->call('backup.scan', ['target' => $spec], 300);
        } catch (AgentException $e) {
            return $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : __('Hedef taranamadı: :reason', ['reason' => $this->reason($e)]);
        }
        $known = Backup::whereIn('key', array_map(fn ($f) => $f['descriptor']['key'] ?? '', $found))->pluck('key')->all();

        return array_values(array_map(function ($f) use ($known) {
            $d = (array) $f['descriptor'];

            return [
                'key' => $d['key'],
                'path' => (string) ($f['path'] ?? ''),
                'server' => (string) ($d['server'] ?? ''),
                'scope' => (string) ($d['scope'] ?? 'site'),
                'created' => (string) ($d['created'] ?? ''),
                'incremental' => (bool) ($d['incremental'] ?? false),
                'encrypted' => (bool) ($f['encrypted'] ?? false),
                'size' => array_sum(array_map(fn ($i) => (int) ($i['size'] ?? 0), (array) ($d['items'] ?? []))),
                'names' => array_values(array_filter(array_map(fn ($i) => ($i['error'] ?? '') === '' ? ($i['name'] ?? null) : null, (array) ($d['items'] ?? [])))),
                'items' => (array) ($d['items'] ?? []),
                'known' => in_array($d['key'], $known, true),
            ];
        }, $found));
    }

    /**
     * Lists a backup found on a target here, to restore from it; its
     * archives stay on the target until used. An incremental one comes
     * with the older backups of its chain from the same scan.
     *
     * @param  list<array<string, mixed>>  $scan  what scan() returned
     */
    public function adopt(BackupTarget $target, array $scan, string $key, ?string $passphrase, ?User $user): Backup|string
    {
        $found = collect($scan)->firstWhere('key', $key);
        if ($found === null) {
            return __('Yedek hedefte bulunamadı; yeniden tarayın.');
        }
        if ($found['encrypted'] && ($passphrase === null || strlen($passphrase) < 12)) {
            return __('Bu yedek şifreli; alındığı sunucunun şifreleme parolasını girin.');
        }
        $chain = [$found];
        if ($found['incremental']) {
            $older = collect($scan)->filter(fn ($f) => $f['path'] === $found['path'] && $f['scope'] === 'server' && $f['created'] < $found['created'])
                ->sortByDesc('created')->values();
            foreach ($older as $f) {
                array_unshift($chain, $f);
                if (! $f['incremental']) {
                    break;
                }
            }
            if ($chain[0]['incremental']) {
                return __('Bu artımlı yedeğin dayandığı tam yedek hedefte yok.');
            }
        }
        $backup = null;
        foreach ($chain as $f) {
            $backup = $f['known'] ? Backup::where('key', $f['key'])->first() : $this->adoptOne($target, $f, $passphrase, $user);
        }

        return $backup ?? __('Yedek eklenemedi.');
    }

    /**
     * @param  array<string, mixed>  $found
     */
    private function adoptOne(BackupTarget $target, array $found, ?string $passphrase, ?User $user): Backup
    {
        $folder = trim((string) ($target->config['folder'] ?? ''), '/');
        $prefix = trim($folder.'/'.$found['path'], '/');
        $backup = Backup::create([
            'key' => $found['key'],
            'scope' => $found['scope'] === 'server' ? 'server' : 'site',
            'type' => $found['incremental'] ? 'incremental' : 'full',
            'trigger' => 'import',
            'target_id' => $target->id,
            'remote_prefix' => $prefix === '' ? null : $prefix,
            'local' => false,
            'remote' => true,
            'encrypted' => (bool) $found['encrypted'],
            'passphrase' => $found['encrypted'] ? $passphrase : null,
            'status' => 'succeeded',
            'size' => (int) $found['size'],
            'run' => 'import-'.Str::lower(Str::random(12)),
            'source_host' => ($found['server'] ?? '') ?: '?',
            'user_id' => $user?->id,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => $found['created'] !== '' ? Carbon::parse($found['created']) : now(),
        ]);
        foreach ($found['items'] as $i) {
            if (($i['error'] ?? '') !== '') {
                continue;
            }
            $backup->items()->create([
                'name' => $i['name'],
                'site_id' => $i['name'] === 'panel' ? null : Site::where('domain', $i['name'])->value('id'),
                'size' => (int) ($i['size'] ?? 0),
                'parts' => (array) ($i['parts'] ?? []),
                'incremental' => array_values((array) ($i['incremental'] ?? [])),
                'skipped' => (array) ($i['skipped'] ?? []),
                'warnings' => array_values((array) ($i['warnings'] ?? [])),
                'status' => 'succeeded',
            ]);
        }

        return $backup;
    }

    private function reason(AgentException $e): string
    {
        $why = $e->reason ?: $e->getMessage();

        return match (true) {
            str_contains($why, 'passphrase is wrong') || str_contains($why, 'wrong passphrase') => __('Şifreleme parolası yanlış.'),
            str_contains($why, 'enter its passphrase') => __('Arşiv şifreli; şifreleme parolasını girin.'),
            str_contains($why, 'not a Serfex backup') => __('Bu dosya bir Serfex yedeği değil. cPanel ve Plesk yedekleri içe aktarılamaz.'),
            $e->getCode() === -32006 => __(Backups::BUSY),
            default => $why,
        };
    }
}
