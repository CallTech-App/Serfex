<?php

namespace App\Models;

use App\Models\Concerns\OwnedThroughAccount;
use App\Services\Databases\DatabaseServers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer database on MySQL/MariaDB (server mysql) or PostgreSQL. A
 * trashed database waits out its undo window: its users already lost
 * access, the data is still there.
 */
#[Fillable(['server', 'name', 'system_user_id', 'site_id', 'size_bytes', 'tables', 'measured_at', 'backup_on_delete', 'copy_of_id'])]
class Database extends Model
{
    use HasFactory, OwnedThroughAccount, SoftDeletes;

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'backup_on_delete' => 'boolean',
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
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Users with access, with their access level as pivot->access.
     *
     * @return BelongsToMany<DatabaseUser, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseUser::class, 'database_grants')->withPivot('access')->withTimestamps()->orderBy('name');
    }

    /**
     * The customer who owns the database, through its account.
     */
    public function customer(): ?Customer
    {
        return $this->systemUser?->customer;
    }

    /**
     * "MySQL", "MariaDB" or "PostgreSQL".
     */
    public function serverLabel(): string
    {
        return app(DatabaseServers::class)->label($this->server);
    }

    /**
     * Laravel's connection settings for the database with a user: driver,
     * host, port, database, username, password.
     *
     * @return array{driver: string, host: string, port: string, database: string, username: string, password: string}
     */
    public function connection(string $username, string $password): array
    {
        return [
            // MariaDB too: the mysql driver works with every Laravel version.
            'driver' => $this->server === DatabaseServers::POSTGRESQL ? 'pgsql' : 'mysql',
            'host' => '127.0.0.1',
            'port' => $this->server === DatabaseServers::POSTGRESQL ? '5432' : '3306',
            'database' => $this->name,
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * The tool that opens the database in the browser.
     */
    public function managerLabel(): string
    {
        return $this->server === DatabaseServers::POSTGRESQL ? 'Adminer' : 'phpMyAdmin';
    }
}
