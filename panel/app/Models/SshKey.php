<?php

namespace App\Models;

use App\Models\Concerns\OwnedThroughAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An SSH public key that may sign in as a site's Linux account. A trashed
 * key no longer signs in and waits out its undo window.
 */
#[Fillable(['system_user_id', 'name', 'public_key', 'fingerprint'])]
class SshKey extends Model
{
    use OwnedThroughAccount, SoftDeletes;

    /**
     * @return BelongsTo<SystemUser, $this>
     */
    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /**
     * The key's algorithm as people know it: "ED25519", "RSA".
     */
    public function typeLabel(): string
    {
        $type = strtok($this->public_key, ' ');

        return match (true) {
            str_contains($type, 'ed25519') => str_starts_with($type, 'sk-') ? 'ED25519-SK' : 'ED25519',
            str_contains($type, 'ecdsa') => str_starts_with($type, 'sk-') ? 'ECDSA-SK' : 'ECDSA',
            default => 'RSA',
        };
    }
}
