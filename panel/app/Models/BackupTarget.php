<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Remote storage backups are copied to: S3-compatible storage (AWS, B2,
 * Wasabi, MinIO, Hetzner...) or an SFTP server. The address and keys are
 * encrypted with the panel's application key; the agent is given them
 * with every call and keeps none.
 */
#[Fillable(['name', 'type', 'config', 'status', 'last_error', 'tested_at'])]
#[Hidden(['config'])]
class BackupTarget extends Model
{
    use SoftDeletes;

    public const TYPES = ['s3' => 'S3 uyumlu depolama', 'sftp' => 'SFTP'];

    /** S3 providers offered in the form: key => [label, endpoint (with {region}), default region, path style]. */
    public const PROVIDERS = [
        'aws' => ['Amazon S3', '', 'eu-central-1', false],
        'b2' => ['Backblaze B2', 's3.{region}.backblazeb2.com', 'eu-central-003', false],
        'wasabi' => ['Wasabi', 's3.{region}.wasabisys.com', 'eu-central-2', false],
        'hetzner' => ['Hetzner Object Storage', '{region}.your-objectstorage.com', 'fsn1', false],
        'r2' => ['Cloudflare R2', '', 'auto', true],
        'minio' => ['MinIO ya da başka bir S3 uyumlu', '', 'us-east-1', true],
    ];

    protected $attributes = [
        'status' => 'untested',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'tested_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class, 'target_id');
    }

    /**
     * The target as the agent's backup methods take it, with backups under
     * $prefix (the folder plus the server's name).
     *
     * @return array<string, mixed>
     */
    public function agentSpec(?string $prefix = null): array
    {
        $c = $this->config ?? [];
        $spec = ['type' => $this->type, 'prefix' => $prefix ?? $this->prefix()];
        if ($this->type === 's3') {
            return $spec + [
                'endpoint' => (string) ($c['endpoint'] ?? ''),
                'region' => (string) ($c['region'] ?? ''),
                'bucket' => (string) ($c['bucket'] ?? ''),
                'access_key' => (string) ($c['access_key'] ?? ''),
                'secret_key' => (string) ($c['secret_key'] ?? ''),
                'path_style' => (bool) ($c['path_style'] ?? false),
            ];
        }

        return $spec + [
            'host' => (string) ($c['host'] ?? ''),
            'port' => (int) ($c['port'] ?? 22),
            'user' => (string) ($c['user'] ?? ''),
            'host_key' => (string) ($c['host_key'] ?? ''),
        ];
    }

    /**
     * Where this server's backups go on the target: the folder set in the
     * form, then the server's host name, so several servers can share one
     * bucket.
     */
    public function prefix(): string
    {
        $folder = trim((string) ($this->config['folder'] ?? ''), '/');
        $server = self::serverName();

        return $folder === '' ? $server : $folder.'/'.$server;
    }

    /**
     * This server's name for target folders: its host name, made safe.
     */
    public static function serverName(): string
    {
        $name = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) (gethostname() ?: 'servik')));

        return trim($name, '.-') ?: 'servik';
    }

    /**
     * "servik-yedek · s3.eu-central-1.wasabisys.com" or "yedek@host:/folder".
     */
    public function address(): string
    {
        $c = $this->config ?? [];
        if ($this->type === 'sftp') {
            return ($c['user'] ?? '').'@'.($c['host'] ?? '').(($c['port'] ?? 22) != 22 ? ':'.$c['port'] : '').':'.$this->prefix();
        }
        $endpoint = ($c['endpoint'] ?? '') !== '' ? preg_replace('#^https?://#', '', $c['endpoint']) : 's3.'.($c['region'] ?? 'us-east-1').'.amazonaws.com';

        return $endpoint.' · '.($c['bucket'] ?? '').'/'.$this->prefix();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
