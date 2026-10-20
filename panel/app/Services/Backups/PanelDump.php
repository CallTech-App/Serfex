<?php

namespace App\Services\Backups;

use App\Services\Agent\AgentClient;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The panel's own part of a whole-server backup: its PostgreSQL database
 * (dumped as the panel, which never runs as root) and its .env, sent to
 * the agent as uploads for the panel archive.
 */
class PanelDump
{
    /** Bytes per upload call. */
    public const CHUNK = 4 << 20;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Uploads what there is and returns member => upload id, plus a
     * warning for what could not be taken.
     *
     * @return array{uploads: array<string, string>, warnings: list<string>}
     */
    public function upload(): array
    {
        $uploads = [];
        $warnings = [];
        $dir = storage_path('app/private/backup-work');
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $dump = $dir.'/panel-'.Str::random(12).'.sql.gz';
        try {
            $this->dump($dump);
            $uploads['panel.sql.gz'] = $this->send($dump);
        } catch (RuntimeException $e) {
            $warnings[] = __('Panel veritabanı yedeklenemedi: :message', ['message' => $e->getMessage()]);
        } finally {
            @unlink($dump);
        }
        $env = base_path('.env');
        if (is_file($env) && is_readable($env)) {
            try {
                $uploads['panel.env'] = $this->send($env);
            } catch (RuntimeException $e) {
                $warnings[] = __('Panel ayarları (.env) yedeklenemedi: :message', ['message' => $e->getMessage()]);
            }
        }

        return ['uploads' => $uploads, 'warnings' => $warnings];
    }

    /**
     * pg_dump of the panel's database into a gzip file.
     */
    public function dump(string $file): void
    {
        $c = config('database.connections.'.config('database.default'));
        if (($c['driver'] ?? '') !== 'pgsql') {
            throw new RuntimeException(__('panel PostgreSQL kullanmıyor'));
        }
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new RuntimeException(__('geçici dosya açılamadı'));
        }
        $command = ['pg_dump', '--no-owner', '--no-privileges', '--clean', '--if-exists',
            '-h', (string) ($c['host'] ?? '127.0.0.1'), '-p', (string) ($c['port'] ?? 5432), '-U', (string) $c['username'], (string) $c['database']];
        $result = Process::env(['PGPASSWORD' => (string) ($c['password'] ?? '')])->timeout(3600)
            ->run($command, function (string $type, string $output) use ($gz) {
                if ($type === 'out') {
                    gzwrite($gz, $output);
                }
            });
        gzclose($gz);
        if (! $result->successful()) {
            throw new RuntimeException(Str::limit(trim($result->errorOutput()) ?: __('pg_dump çalışmadı'), 300));
        }
    }

    /**
     * Sends a file to the agent in chunks; returns the upload id.
     */
    public function send(string $file): string
    {
        $id = 'up-'.Str::lower(Str::random(20));
        $in = fopen($file, 'rb');
        if ($in === false) {
            throw new RuntimeException(__('dosya okunamadı'));
        }
        try {
            $offset = 0;
            do {
                $data = (string) fread($in, self::CHUNK);
                $this->agent->call('backup.upload.write', ['upload' => $id, 'offset' => $offset, 'data' => base64_encode($data)], 120);
                $offset += strlen($data);
            } while (! feof($in) && $data !== '');
        } finally {
            fclose($in);
        }

        return $id;
    }
}
