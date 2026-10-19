<?php

namespace App\Models;

use App\Models\Concerns\OwnedThroughAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A database user (login) on MySQL/MariaDB or PostgreSQL. The password
 * lives only on the server. A trashed user waits out its undo window
 * without access to any database.
 */
#[Fillable(['server', 'name', 'system_user_id', 'hosts'])]
class DatabaseUser extends Model
{
    use HasFactory, OwnedThroughAccount, SoftDeletes;

    /** Access levels: key => [label, explanation]. */
    public const ACCESS = [
        'all' => ['Tam yetki', 'Tabloları oluşturur, değiştirir ve siler; veriyi okur ve yazar.'],
        'readwrite' => ['Okuma-yazma', 'Veriyi okur, ekler, günceller ve siler; tablo yapısına dokunamaz.'],
        'readonly' => ['Salt okunur', 'Yalnızca okur.'],
    ];

    protected $attributes = [
        'hosts' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'hosts' => 'array',
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
     * Databases the user may use, with the access level as pivot->access.
     *
     * @return BelongsToMany<Database, $this>
     */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class, 'database_grants')->withPivot('access')->withTimestamps()->orderBy('name');
    }

    /**
     * "Yalnızca bu sunucu" or the remote hosts.
     */
    public function hostsLabel(): string
    {
        $hosts = $this->hosts ?? [];
        if ($hosts === []) {
            return 'Yalnızca bu sunucu';
        }

        return in_array('%', $hosts, true) ? 'Her yerden' : implode(', ', $hosts);
    }

    public static function accessLabel(string $access): string
    {
        return self::ACCESS[$access][0] ?? $access;
    }
}
