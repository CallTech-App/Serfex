<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person who can work on one Laravel application, with a role.
 */
#[Fillable(['laravel_app_id', 'user_id', 'role'])]
class LaravelAppMember extends Model
{
    /** role => [label, what the role can do] */
    public const ROLES = [
        'owner' => ['Sahip', 'Her şey; kişi ekleme, API anahtarları, yedekten geri yükleme ve kaldırma dahil'],
        'developer' => ['Geliştirici', 'Dağıtım, önizlemeler, komutlar ve Tinker, .env, kuyruklar, süreçler, yedek alma, bakım modu'],
        'viewer' => ['Salt okunur', 'Durumu, dağıtımları ve günlükleri görür; .env ve yedekleri göremez, değişiklik yapamaz'],
    ];

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
