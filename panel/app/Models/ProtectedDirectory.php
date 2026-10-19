<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A folder of a site that asks visitors for a user name and password
 * (HTTP basic authentication). Only SHA-512 crypt hashes are kept.
 */
#[Fillable(['site_id', 'path', 'realm', 'users'])]
#[Hidden(['users'])]
class ProtectedDirectory extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'users' => 'array',
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
     * @return list<string>
     */
    public function userNames(): array
    {
        return array_values(array_map(fn ($u) => (string) $u['name'], $this->users ?? []));
    }
}
