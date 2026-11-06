<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time the site did not answer: from the first failed check to the
 * first good one after it (null while it lasts).
 */
#[Fillable(['site_id', 'started_at', 'ended_at', 'error', 'cause'])]
class SiteOutage extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
     * How long it lasted (until now while it lasts), in whole minutes, at
     * least one.
     */
    public function minutes(): int
    {
        return max(1, (int) round($this->started_at->diffInSeconds($this->ended_at ?? now()) / 60));
    }
}
