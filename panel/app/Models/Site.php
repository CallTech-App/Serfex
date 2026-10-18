<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['system_user_id', 'domain', 'aliases', 'php_version', 'document_root', 'status', 'last_error', 'provisioned_at'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $attributes = [
        'aliases' => '[]',
        'status' => 'provisioning',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'status' => SiteStatus::class,
            'provisioned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SystemUser, $this>
     */
    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /**
     * Parameters for the agent's site.apply method.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        return [
            'domain' => $this->domain,
            'user' => $this->systemUser->name,
            'php_version' => $this->php_version,
            'aliases' => array_values($this->aliases ?? []),
        ];
    }
}
