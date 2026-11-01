<?php

namespace App\Models\Concerns;

use App\Jobs\SyncMailSenders as SyncJob;
use App\Services\Mail\MailSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Models whose changes alter the domains a site may send mail as queue
 * SyncMailSenders; only changes to SENDER_FIELDS count, and only once the
 * mail server is set up (setting it up sends them). The job is unique, so
 * it is dispatched once the change is committed: its lock is not taken for
 * a change that is rolled back.
 */
trait SyncsMailSenders
{
    protected static function bootSyncsMailSenders(): void
    {
        $sync = function () {
            if (app(MailSettings::class)->isSetUp()) {
                DB::afterCommit(fn () => SyncJob::dispatch());
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
