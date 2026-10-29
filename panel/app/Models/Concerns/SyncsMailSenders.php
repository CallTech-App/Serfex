<?php

namespace App\Models\Concerns;

use App\Jobs\SyncMailSenders as SyncJob;
use App\Services\Mail\MailSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Models whose changes alter the domains a site may send mail as queue
 * SyncMailSenders; only changes to SENDER_FIELDS count, and only once the
 * mail server is set up (setting it up sends them).
 */
trait SyncsMailSenders
{
    protected static function bootSyncsMailSenders(): void
    {
        $sync = function () {
            if (app(MailSettings::class)->isSetUp()) {
                SyncJob::dispatch()->afterCommit();
            }
        };
        static::created($sync);
        static::updated(function (Model $model) use ($sync) {
            if ($model->wasChanged(static::SENDER_FIELDS)) {
                $sync();
            }
        });
        static::deleted($sync);
    }
}
