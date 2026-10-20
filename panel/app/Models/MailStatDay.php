<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of a mail domain's messages
 */
#[Fillable(['domain', 'day', 'sent', 'received', 'rejected', 'bounced'])]
class MailStatDay extends Model
{
    protected $table = 'mail_stats_daily';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
