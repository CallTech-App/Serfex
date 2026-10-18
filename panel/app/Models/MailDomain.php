<?php

namespace App\Models;

use App\Enums\MailDomainStatus;
use Database\Factories\MailDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['site_id', 'name', 'status', 'spam_filter', 'manage_dns', 'dkim_name', 'dkim_record', 'last_error', 'synced_at', 'disabled_at'])]
class MailDomain extends Model
{
    /** @use HasFactory<MailDomainFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'syncing',
        'spam_filter' => true,
        'manage_dns' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => MailDomainStatus::class,
            'spam_filter' => 'boolean',
            'manage_dns' => 'boolean',
            'synced_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Mailboxes in use; removed ones waiting for their purge are trashed.
     *
     * @return HasMany<Mailbox, $this>
     */
    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class)->orderBy('name');
    }

    /**
     * Forwarders, the catch-all ("*") among them.
     *
     * @return HasMany<MailForwarder, $this>
     */
    public function forwarders(): HasMany
    {
        return $this->hasMany(MailForwarder::class)->orderBy('name');
    }

    public function address(string $name): string
    {
        return $name.'@'.$this->name;
    }

    /**
     * Parameters for the agent's mail.domain.apply method.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        $this->loadMissing('mailboxes', 'forwarders');
        $catchAll = $this->forwarders->firstWhere('name', MailForwarder::CATCH_ALL);

        return [
            'domain' => $this->name,
            'spam_filter' => $this->spam_filter,
            'mailboxes' => $this->mailboxes->map(fn (Mailbox $m) => $m->agentSpec())->values()->all(),
            'aliases' => $this->forwarders->where('name', '!=', MailForwarder::CATCH_ALL)
                ->map(fn (MailForwarder $f) => ['name' => $f->name, 'to' => array_values($f->destinations)])
                ->values()->all(),
            'catch_all' => array_values($catchAll->destinations ?? []),
        ];
    }
}
