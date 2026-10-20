<?php

namespace App\Services\Files;

use App\Jobs\PurgeFtpAccount;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SFTP accounts of sites. The server's OpenSSH serves them, SFTP only,
 * each locked in one folder of its site and writing as the site's own
 * user. Names carry the account's prefix like database users do
 * ("kahve_deploy"). Passwords are never stored: the panel shows a new one
 * once and passes it on to the server.
 *
 * A suspended customer's accounts stay but cannot sign in. Deleting an
 * account locks it at once and removes it from the server after
 * UNDO_SECONDS, so Undo brings it back with its password.
 */
class FtpAccounts
{
    public const UNDO_SECONDS = 600;

    public const MAX_NAME = 32;

    /** A name after its prefix. */
    public const NAME_PATTERN = '/^[a-z0-9_]+$/';

    /** A folder inside the site: "public_html", "public_html/uploads". */
    public const DIR_PATTERN = '#^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#';

    public function __construct(private readonly AgentClient $agent) {}

    public static function prefix(SystemUser $account): string
    {
        return Databases::prefix($account);
    }

    public static function password(): string
    {
        return Str::password(20, symbols: false);
    }

    /**
     * Why the customer owning $account may not add another account, or
     * null when they may.
     */
    public function refusal(SystemUser $account): ?string
    {
        return app(Quotas::class)->refusal($account->customer, 'ftp_accounts');
    }

    /**
     * Checks a full name and a folder; returns an error per field.
     *
     * @return array<string, string>
     */
    public function problems(Site $site, string $name, string $directory, ?int $ignore = null): array
    {
        $out = [];
        $prefix = self::prefix($site->systemUser);
        $suffix = Str::after($name, $prefix);
        if (! str_starts_with($name, $prefix) || $suffix === '' || ! preg_match(self::NAME_PATTERN, $suffix)) {
            $out['name'] = __('Ad yalnızca küçük harf, rakam ve alt çizgi içerebilir.');
        } elseif (strlen($name) > self::MAX_NAME) {
            $out['name'] = __('Ad en fazla :max_name karakter olabilir (önek dahil).', ['max_name' => self::MAX_NAME]);
        } elseif (FtpAccount::withTrashed()->where('name', $name)->whereKeyNot($ignore ?? 0)->exists() || SystemUser::where('name', $name)->exists()) {
            $out['name'] = __('Bu ad kullanılıyor.');
        }
        if ($directory !== '' && (! preg_match(self::DIR_PATTERN, $directory) || strlen($directory) > 200 || in_array('..', explode('/', $directory), true))) {
            $out['directory'] = __('Klasör, site klasörünün içinde bir yol olmalı (örneğin public_html).');
        }

        return $out;
    }

    /**
     * Creates an account. Throws AgentException when the server refuses;
     * nothing is kept then.
     */
    public function create(Site $site, string $name, string $directory, string $password, ?string $note = null): FtpAccount
    {
        $this->ensureFolder($site, $directory);
        $account = FtpAccount::create([
            'site_id' => $site->id,
            'system_user_id' => $site->system_user_id,
            'name' => $name,
            'directory' => $directory,
            'note' => $note,
            'password_changed_at' => now(),
        ]);
        try {
            $this->apply($account, $password);
        } catch (AgentException $e) {
            $account->forceDelete();
            throw $e;
        }
        Quotas::forget();

        return $account;
    }

    /**
     * Changes the folder, the note or (with $password) the password.
     */
    public function update(FtpAccount $account, string $directory, ?string $note, ?string $password = null): void
    {
        if ($directory !== $account->directory) {
            $this->ensureFolder($account->site, $directory);
        }
        $before = $account->only('directory', 'note', 'password_changed_at');
        $account->update(['directory' => $directory, 'note' => $note] + ($password !== null ? ['password_changed_at' => now()] : []));
        try {
            $this->apply($account, $password);
        } catch (AgentException $e) {
            $account->update($before);
            throw $e;
        }
    }

    /**
     * Creates the account's folder as the site's user when it is missing,
     * so a new "public_html/ajans" works at once.
     */
    private function ensureFolder(Site $site, string $directory): void
    {
        $parent = '';
        foreach ($directory === '' ? [] : explode('/', $directory) as $part) {
            try {
                app(FileManager::class)->call($site, 'mkdir', ['path' => $parent, 'name' => $part]);
            } catch (AgentException $e) {
                if (! str_starts_with($e->reason, 'exists:')) {
                    throw $e;
                }
            }
            $parent = ltrim($parent.'/'.$part, '/');
        }
    }

    /**
     * Sends the account to the server as it is in the panel: locked while
     * its customer is suspended or it waits for deletion.
     */
    public function apply(FtpAccount $account, ?string $password = null): void
    {
        $account->loadMissing('site', 'systemUser.customer');
        $params = [
            'name' => $account->name,
            'user' => $account->systemUser->name,
            'domain' => $account->site->domain,
            'dir' => $account->directory,
            'locked' => $account->trashed() || (bool) $account->systemUser->customer?->isSuspended(),
        ];
        if ($password !== null) {
            $params['password'] = $password;
        }
        $this->agent->call('ftp.account.apply', $params, config('servik.agent.job_timeout'));
    }

    /**
     * Locks the account now and removes it after the undo window.
     */
    public function delete(FtpAccount $account): void
    {
        $account->delete();
        try {
            $this->apply($account);
        } catch (AgentException $e) {
            $account->restore();
            throw $e;
        }
        Quotas::forget();
        PurgeFtpAccount::dispatch($account->id)->delay(now()->addSeconds(self::UNDO_SECONDS));
    }

    public function restore(FtpAccount $account): bool
    {
        if (! $account->trashed()) {
            return false;
        }
        $account->restore();
        $this->apply($account);
        Quotas::forget();

        return true;
    }

    /**
     * Removes a deleted account from the server for good.
     */
    public function purge(FtpAccount $account): void
    {
        $this->agent->call('ftp.account.delete', ['name' => $account->name], config('servik.agent.job_timeout'));
        $account->forceDelete();
    }

    /**
     * Applies every account of a Linux account again (suspension).
     */
    public function applyAccount(SystemUser $account): void
    {
        foreach (FtpAccount::where('system_user_id', $account->id)->get() as $ftp) {
            $this->apply($ftp);
        }
    }

    /**
     * Removes a site's accounts from the server and the panel (the site
     * is being deleted).
     */
    public function deleteForSite(Site $site): void
    {
        foreach (FtpAccount::withTrashed()->where('site_id', $site->id)->get() as $account) {
            $this->purge($account);
        }
        Quotas::forget();
    }

    /**
     * OpenSSH's port, as the agent last reported it.
     */
    public function port(): int
    {
        return (int) Cache::remember('servik.ftp.port', 600, function () {
            try {
                return (int) ($this->agent->call('ftp.status')['port'] ?? 22);
            } catch (AgentException) {
                return 22;
            }
        });
    }
}
