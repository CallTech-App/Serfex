<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Core Rule Set rule a site turns off, everywhere or under one path,
 * when it blocks the site's own legitimate requests. A trashed exclusion
 * is off on the server and waits for Undo.
 */
#[Fillable(['site_id', 'rule_id', 'path', 'note', 'user_id'])]
class WafExclusion extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'path' => '',
    ];

    protected function casts(): array
    {
        return [
            'rule_id' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pathLabel(): string
    {
        return $this->path === '' ? __('Tüm site') : $this->path;
    }
}
