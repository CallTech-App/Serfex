<?php

namespace App\Models;

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
