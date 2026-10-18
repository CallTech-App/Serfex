<?php

namespace App\Models;

use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['mail_domain_id', 'name', 'password_hash', 'quota_mb', 'forward_to', 'keep_copy', 'autoreply', 'usage_bytes', 'usage_at'])]
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'forward_to' => '[]',
        'keep_copy' => true,
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'quota_mb' => 'integer',
            'forward_to' => 'array',
            'keep_copy' => 'boolean',
            'autoreply' => 'array',
            'usage_bytes' => 'integer',
            'usage_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MailDomain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class, 'mail_domain_id');
    }

    public function address(): string
    {
        return $this->name.'@'.$this->domain->name;
    }

    /**
     * True while an autoreply is on and its end date has not passed.
     */
    public function autoreplyActive(): bool
    {
        $a = $this->autoreply;
        if (! ($a['enabled'] ?? false)) {
            return false;
        }

        return empty($a['until']) || $a['until'] >= now()->toDateString();
    }

    /**
     * Share of the quota in use, 0-100, or null without a quota or usage.
     */
    public function usagePercent(): ?int
    {
        if ($this->quota_mb === 0 || $this->usage_bytes === null) {
            return null;
        }

        return (int) min(100, round($this->usage_bytes / ($this->quota_mb * 1048576) * 100));
    }

    /**
     * One entry of mail.domain.apply's mailboxes.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        $a = $this->autoreply;

        return [
            'name' => $this->name,
            'password_hash' => $this->password_hash,
            'quota_mb' => $this->quota_mb,
            'forward_to' => array_values($this->forward_to ?? []),
            'keep_copy' => $this->keep_copy,
            'autoreply' => ($a['enabled'] ?? false) ? [
                'subject' => (string) ($a['subject'] ?? ''),
                'body' => (string) ($a['body'] ?? ''),
                'until' => (string) ($a['until'] ?? ''),
            ] : null,
        ];
    }
}
