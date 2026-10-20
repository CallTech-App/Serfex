<?php

namespace App\Models;

use App\Models\Concerns\OwnedThroughAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An SFTP account of a site, locked in one folder of it. The password
 * lives only on the server. A trashed account waits out its undo window
 * unable to sign in.
 */
#[Fillable(['site_id', 'system_user_id', 'name', 'directory', 'note', 'password_changed_at'])]
class FtpAccount extends Model
{
    use OwnedThroughAccount, SoftDeletes;

    protected function casts(): array
    {
        return [
            'password_changed_at' => 'datetime',
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
     * @return BelongsTo<SystemUser, $this>
     */
    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /**
     * The folder as the panel shows it: "public_html/" or the site folder.
     */
    public function folderLabel(): string
    {
        return $this->directory === '' ? __('Tüm site klasörü') : $this->directory.'/';
    }

    /**
     * The folder's absolute path on the server.
     */
    public function path(): string
    {
        $base = '/home/'.$this->systemUser->name.'/web/'.$this->site->domain;

        return $this->directory === '' ? $base : $base.'/'.$this->directory;
    }

    /**
     * What the account sees after signing in: its folder's name.
     */
    public function remoteFolder(): string
    {
        return '/'.($this->directory === '' ? $this->site->domain : basename($this->directory));
    }
}
