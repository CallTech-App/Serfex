<?php

namespace App\Services\Databases;

use App\Jobs\PurgeDatabase;
use App\Jobs\PurgeDatabaseUser;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer databases and their users. The panel decides who owns what and
 * which user may do what; the agent makes the server match.
 *
 * Names carry the owning account's prefix (cPanel style): account
 * "kahve" makes kahve_shop and kahve_app. Passwords are never stored:
 * the panel shows a new one once and passes it on to the server.
 *
 * Deleting a database or a user takes its access away at once and waits
 * UNDO_SECONDS before the server drops it (a database after a backup when
 * asked), so Undo can bring it back as it was.
 */
class Databases
{
    public const UNDO_SECONDS = 600;

    /** Longest prefix taken from the account name. */
    public const PREFIX_LENGTH = 16;

    /** Name limits of the agent (MySQL users: 32). */
    public const MAX_DATABASE = 54;

    public const MAX_USER = 32;

    /** A name after its prefix: lowercase letters, digits and _. */
    public const NAME_PATTERN = '/^[a-z0-9_]+$/';

    public function __construct(private readonly AgentClient $agent, private readonly DatabaseServers $servers) {}

    /**
     * "kahve_" for account kahve; hyphens become underscores.
     */
    public static function prefix(SystemUser $account): string
    {
        return Str::limit(str_replace('-', '_', $account->name), self::PREFIX_LENGTH, '').'_';
    }

    /**
     * A strong password that survives copying: 24 letters and digits.
     */
    public static function password(): string
    {
        return Str::password(24, symbols: false);
    }

    /**
     * Why the customer owning $account may not create another database,
     * or null when they may.
     */
    public function refusal(SystemUser $account): ?string
    {
        return app(Quotas::class)->refusal($account->customer, 'databases');
    }

    /**
     * Creates a database for a site, with a new user that has $access on
     * it when $userName is given, or access for existing users. Throws
     * AgentException when the server refuses; nothing is kept then.
     *
     * @param  array<int, string>  $existing  database_user id => access
     */
    public function create(Site $site, string $server, string $name, ?string $userName = null, ?string $password = null, string $access = 'all', array $existing = []): Database
    {
        $account = $site->systemUser;
        $this->agent->call('db.database.create', ['server' => $server, 'name' => $name, 'site' => $site->domain], config('serfex.agent.job_timeout'));
        $database = Database::create(['server' => $server, 'name' => $name, 'system_user_id' => $account->id, 'site_id' => $site->id]);
        Quotas::forget();

        try {
            if ($userName !== null) {
                $this->createUser($account, $server, $userName, (string) $password, [], [$database->id => $access]);
            }
            foreach (DatabaseUser::whereIn('id', array_keys($existing))->where('server', $server)->get() as $user) {
                $this->grant($database, $user, $existing[$user->id]);
            }
        } catch (AgentException $e) {
            // The database without its user is not what was asked for.
            $this->agent->call('db.database.delete', ['server' => $server, 'name' => $name, 'backup' => false], config('serfex.agent.job_timeout'));
            $database->forceDelete();
            throw $e;
        }

        return $database;
    }

    /**
     * Creates a user on the server with access to $grants (database id =>
     * access).
     *
     * @param  list<string>  $hosts
     * @param  array<int, string>  $grants
     */
    public function createUser(SystemUser $account, string $server, string $name, string $password, array $hosts = [], array $grants = []): DatabaseUser
    {
        return DB::transaction(function () use ($account, $server, $name, $password, $hosts, $grants) {
            $user = DatabaseUser::create(['server' => $server, 'name' => $name, 'system_user_id' => $account->id, 'hosts' => $hosts]);
            $user->databases()->sync(collect($grants)->map(fn ($access) => ['access' => $access])->all());
            $this->apply($user, $password);

            return $user;
        });
    }

    /**
     * Makes the server's user match the panel: hosts and the grants on
     * databases that are not being deleted. $password changes it. A user
     * being deleted, or of a suspended customer, keeps its password but
     * may use nothing.
     */
    public function apply(DatabaseUser $user, ?string $password = null): void
    {
        $closed = $user->trashed() || (bool) $user->systemUser?->customer?->isSuspended();
        $grants = $closed ? [] : $user->databases()->get()->map(fn (Database $d) => ['database' => $d->name, 'access' => $d->pivot->access])->values()->all();
        $this->agent->call('db.user.apply', [
            'server' => $user->server,
            'name' => $user->name,
            'password' => (string) $password,
            'hosts' => $closed ? [] : array_values($user->hosts ?? []),
            'grants' => $grants,
        ], config('serfex.agent.job_timeout'));
    }

    /**
     * Applies every user of an account again, after its customer was
     * suspended, brought back or deleted.
     */
    public function applyAccount(SystemUser $account): void
    {
        foreach (DatabaseUser::where('system_user_id', $account->id)->get() as $user) {
            $this->applyQuietly($user);
        }
    }

    /**
     * Gives (or changes) a user's access to a database.
     */
    public function grant(Database $database, DatabaseUser $user, string $access): void
    {
        $before = $user->databases()->whereKey($database->id)->first()?->pivot->access;
        $user->databases()->syncWithoutDetaching([$database->id => ['access' => $access]]);
        $this->applyOrUndo($user, fn () => $before === null
            ? $user->databases()->detach($database->id)
            : $user->databases()->updateExistingPivot($database->id, ['access' => $before]));
    }

    /**
     * Takes a user's access to a database away; the user stays.
     */
    public function revoke(Database $database, DatabaseUser $user): void
    {
        $before = $user->databases()->whereKey($database->id)->first()?->pivot->access;
        if ($before === null) {
            return;
        }
        $user->databases()->detach($database->id);
        $this->applyOrUndo($user, fn () => $user->databases()->attach($database->id, ['access' => $before]));
    }

    /**
     * Changes where a user may connect from.
     *
     * @param  list<string>  $hosts
     */
    public function setHosts(DatabaseUser $user, array $hosts): void
    {
        $before = $user->hosts;
        $user->update(['hosts' => array_values(array_unique($hosts))]);
        $this->applyOrUndo($user, fn () => $user->update(['hosts' => $before]));
    }

    public function changePassword(DatabaseUser $user, string $password): void
    {
        $this->apply($user, $password);
    }

    /**
     * Starts deleting a database: its users lose access now, the server
     * drops it (after a backup when asked) once Undo has expired.
     */
    public function delete(Database $database, bool $backup = true): void
    {
        $users = $database->users()->get();
        $database->update(['backup_on_delete' => $backup]);
        $database->delete();
        Quotas::forget();
        // A Laravel application keeps working until the data is gone; its
        // link to the database goes with it.
        foreach ($users as $user) {
            $this->applyQuietly($user);
        }
        PurgeDatabase::dispatch($database->id)->delay(now()->addSeconds(self::UNDO_SECONDS));
    }

    public function restore(Database $database): void
    {
        $database->restore();
        $database->update(['backup_on_delete' => null]);
        Quotas::forget();
        foreach ($database->users()->get() as $user) {
            $this->applyQuietly($user);
        }
    }

    /**
     * Drops a deleted database from the server for good. Returns the
     * backup's path, if one was taken.
     */
    public function purge(Database $database): ?string
    {
        $result = $this->agent->call('db.database.delete', [
            'server' => $database->server,
            'name' => $database->name,
            'backup' => (bool) $database->backup_on_delete,
        ], config('serfex.agent.backup_timeout'));
        $database->forceDelete();
        Quotas::forget();

        return $result['backup'] ?? null;
    }

    /**
     * Starts deleting a user: it loses every access and remote host now and
     * is removed once Undo has expired.
     */
    public function deleteUser(DatabaseUser $user): void
    {
        $user->delete();
        $this->applyQuietly($user);
        PurgeDatabaseUser::dispatch($user->id)->delay(now()->addSeconds(self::UNDO_SECONDS));
    }

    public function restoreUser(DatabaseUser $user): void
    {
        $user->restore();
        $this->apply($user);
    }

    public function purgeUser(DatabaseUser $user): void
    {
        $this->agent->call('db.user.delete', ['server' => $user->server, 'name' => $user->name], config('serfex.agent.job_timeout'));
        $user->forceDelete();
    }

    /**
     * Deletes every database of a site right away (with a backup), for a
     * site being removed. Users that only served these databases go too.
     */
    public function deleteForSite(Site $site): void
    {
        foreach (Database::withTrashed()->where('site_id', $site->id)->get() as $database) {
            $users = $database->users()->get();
            $database->backup_on_delete = true;
            $this->purge($database);
            foreach ($users as $user) {
                if ($user->databases()->doesntExist()) {
                    $this->purgeUser($user);
                } else {
                    $this->applyQuietly($user);
                }
            }
        }
    }

    /**
     * A one-time address that opens the database in phpMyAdmin or Adminer.
     * $host is the address the panel was reached at.
     */
    public function managerUrl(Database $database, string $host): string
    {
        $login = $this->agent->call('db.manager.login', ['server' => $database->server, 'database' => $database->name], config('serfex.agent.job_timeout'));
        $host = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;

        return 'https://'.$host.':'.(int) $login['port'].$login['path'];
    }

    /**
     * Stores the sizes the servers report. Returns how many databases were
     * measured.
     */
    public function measure(): int
    {
        $count = 0;
        foreach ($this->servers->installed() as $server) {
            $listing = $this->agent->call('db.list', ['server' => $server], config('serfex.agent.job_timeout'));
            $found = collect($listing['databases'] ?? [])->keyBy('name');
            Database::withTrashed()->where('server', $server)->each(function (Database $database) use ($found, &$count) {
                if ($info = $found->get($database->name)) {
                    $database->update([
                        'size_bytes' => (int) $info['size'],
                        'tables' => $info['tables'] >= 0 ? (int) $info['tables'] : null,
                        'measured_at' => now(),
                    ]);
                    $count++;
                }
            });
        }
        Quotas::forget();

        return $count;
    }

    /**
     * Databases a user's account may grant: the account's own, on the
     * user's server.
     *
     * @return Collection<int, Database>
     */
    public function grantable(DatabaseUser $user): Collection
    {
        return Database::where('server', $user->server)->where('system_user_id', $user->system_user_id)->orderBy('name')->get();
    }

    /**
     * Applies the user and puts the panel back with $undo if the server
     * refuses.
     */
    private function applyOrUndo(DatabaseUser $user, callable $undo): void
    {
        try {
            $this->apply($user);
        } catch (AgentException $e) {
            $undo();
            throw $e;
        }
    }

    /**
     * Applies the user; a failure is reported but does not stop a delete or
     * an undo (the next change applies the user again).
     */
    private function applyQuietly(DatabaseUser $user): void
    {
        try {
            $this->apply($user);
        } catch (AgentException $e) {
            report($e);
        }
    }
}
