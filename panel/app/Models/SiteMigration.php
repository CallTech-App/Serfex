<?php

namespace App\Models;

use App\Support\Hostnames;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account moved from another panel: a cPanel archive, or a Plesk
 * subscription copied from the old server over SSH (status "pulling"
 * until the copy is here). It keeps the archive read, what it holds
 * (plan), what the administrator chose (options), and how each site came
 * through (result). Passwords made on the way (database users whose
 * stored password this server cannot use, mailboxes with an old hash, the
 * new customer's invitation) are kept encrypted in secrets for the report.
 */
#[Fillable(['user_id', 'source', 'file', 'upload', 'name', 'size', 'status', 'plan', 'options', 'result', 'secrets', 'run', 'backup_key', 'customer_id', 'error', 'started_at', 'finished_at'])]
class SiteMigration extends Model
{
    /** Read and waiting for the administrator's choices. */
    public const READY = 'ready';

    public const RUNNING = ['pulling', 'queued', 'converting', 'restoring'];

    protected $attributes = [
        'source' => 'cpanel',
        'status' => self::READY,
    ];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'options' => 'array',
            'result' => 'array',
            'secrets' => 'encrypted:array',
            'size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The system user the sites go under here: the administrator's choice,
     * else a cPanel account's own name, or for a Plesk subscription a short
     * name from its main domain (Plesk's generated names are long:
     * "shop.example_c2mbyoz2syf").
     */
    public function account(): string
    {
        if (($chosen = $this->options['account'] ?? null) !== null) {
            return (string) $chosen;
        }
        if (isset($this->options['sites'])) {
            // Started before the name could be chosen: the archive's.
            return (string) ($this->plan['user'] ?? '');
        }

        return $this->source === 'plesk'
            ? Hostnames::suggestUser((string) ($this->plan['main_domain'] ?? ''))
            : (string) ($this->plan['user'] ?? '');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'partial', 'failed'], true);
    }

    /**
     * The archive as the agent knows it.
     *
     * @return array{file?: string, source?: string}
     */
    public function agentSource(): array
    {
        return $this->upload !== null ? ['source' => $this->upload] : ['file' => (string) $this->file];
    }
}
