<?php

namespace App\Services\Files;

use App\Models\Site;
use App\Models\SshKey;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Str;

/**
 * SSH access of a site's own Linux account, the one that owns its files.
 * Off by default. SFTP locks the account in its home folder (it sees
 * /home/<user> and nothing else); a shell, which only an administrator
 * may give, signs in to the real server as that account.
 *
 * Sign-in is with the public keys kept here, or with a password the panel
 * shows once and never stores. A suspended customer keeps everything but
 * cannot sign in. Access belongs to the account, so it reaches every site
 * the account owns.
 */
class SshAccess
{
    public const OFF = 'off';

    public const SFTP = 'sftp';

    public const SHELL = 'shell';

    /** Labels by access. */
    public const LABELS = [self::OFF => 'Kapalı', self::SFTP => 'Yalnızca SFTP', self::SHELL => 'SFTP ve kabuk'];

    public const MAX_KEYS = 20;

    /** Deleted keys can be brought back this long. */
    public const UNDO_SECONDS = 600;

    /** Key types OpenSSH and the agent accept. */
    private const TYPES = ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com'];

    public function __construct(private readonly AgentClient $agent) {}

    public static function password(): string
    {
        return FtpAccounts::password();
    }

    /**
     * Why $user may not change SSH access of $account, or null.
     */
    public function refusal(User $user, SystemUser $account): ?string
    {
        if ($user->is_admin) {
            return null;
        }
        $package = $account->customer?->package;

        return $package !== null && ! $package->allows('ssh') ? __('Paketinizde yok') : null;
    }

    /**
     * Reads one public key line. Returns [the key as stored, its SHA256
     * fingerprint, its comment], or an error message.
     *
     * @return array{0: string, 1: string, 2: string}|string
     */
    public static function parseKey(string $line): array|string
    {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        $parts = explode(' ', $line, 3);
        if (count($parts) < 2 || ! in_array($parts[0], self::TYPES, true)) {
            return __('Bu bir SSH açık anahtarı değil. ssh-ed25519 ya da ssh-rsa ile başlayan .pub dosyasının içeriğini yapıştırın; özel anahtarı (BEGIN OPENSSH PRIVATE KEY) asla paylaşmayın.');
        }
        $blob = base64_decode($parts[1], true);
        $inner = $blob === false ? null : self::sshString($blob, 0);
        if ($inner === null || $inner[0] !== $parts[0]) {
            return __('Anahtar bozuk görünüyor; dosyanın tamamını tek satır olarak yapıştırın.');
        }
        if ($parts[0] === 'ssh-rsa') {
            $e = self::sshString($blob, $inner[1]);
            $n = $e ? self::sshString($blob, $e[1]) : null;
            $bits = $n ? (strlen(ltrim($n[0], "\0")) * 8) : 0;
            if ($bits < 2048) {
                return __('RSA anahtarı en az 2048 bit olmalı; yeni bir ED25519 anahtarı önerilir.');
            }
        }

        return [$parts[0].' '.$parts[1], 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='), $parts[2] ?? ''];
    }

    /**
     * A length-prefixed string of an SSH key blob at $offset, and the
     * offset after it.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function sshString(string $blob, int $offset): ?array
    {
        if (strlen($blob) < $offset + 4) {
            return null;
        }
        $len = unpack('N', substr($blob, $offset, 4))[1];
        if ($len > strlen($blob) - $offset - 4) {
            return null;
        }

        return [substr($blob, $offset + 4, $len), $offset + 4 + $len];
    }

    /**
     * Adds a key. Returns an error message instead when it cannot be used.
     */
    public function addKey(SystemUser $account, string $name, string $line): SshKey|string
    {
        $parsed = self::parseKey($line);
        if (is_string($parsed)) {
            return $parsed;
        }
        [$key, $fingerprint, $comment] = $parsed;
        if ($account->sshKeys()->where('fingerprint', $fingerprint)->exists()) {
            return __('Bu anahtar zaten ekli.');
        }
        if ($account->sshKeys()->count() >= self::MAX_KEYS) {
            return __('En fazla :max anahtar eklenebilir.', ['max' => self::MAX_KEYS]);
        }
        $name = trim($name) !== '' ? trim($name) : (trim($comment) !== '' ? Str::limit(trim($comment), 100, '') : __('Anahtar'));
        $ssh = $account->sshKeys()->create(['name' => Str::limit($name, 100, ''), 'public_key' => $key, 'fingerprint' => $fingerprint]);
        try {
            $this->apply($account);
        } catch (AgentException $e) {
            $ssh->forceDelete();
            throw $e;
        }

        return $ssh;
    }

    public function deleteKey(SshKey $key): void
    {
        $key->delete();
        try {
            $this->apply($key->systemUser);
        } catch (AgentException $e) {
            $key->restore();
            throw $e;
        }
    }

    public function restoreKey(SshKey $key): void
    {
        if (SshKey::where('system_user_id', $key->system_user_id)->where('fingerprint', $key->fingerprint)->exists()) {
            return;
        }
        $key->restore();
        $this->apply($key->systemUser);
    }

    /**
     * Changes the access. Turning it off keeps keys and password for the
     * next time.
     */
    public function setAccess(SystemUser $account, string $access): void
    {
        $before = $account->ssh_access;
        $account->update(['ssh_access' => $access]);
        try {
            $this->apply($account);
        } catch (AgentException $e) {
            $account->update(['ssh_access' => $before]);
            throw $e;
        }
    }

    /**
     * Sets a new password and turns password logins on; returns the
     * password to show once.
     */
    public function setPassword(SystemUser $account): string
    {
        $password = self::password();
        $before = $account->ssh_password_at;
        $account->update(['ssh_password_at' => now()]);
        try {
            $this->apply($account, $password);
        } catch (AgentException $e) {
            $account->update(['ssh_password_at' => $before]);
            throw $e;
        }

        return $password;
    }

    /**
     * Turns password logins off ($at null) or back on with the password
     * set at $at (Undo).
     */
    public function setPasswordLogin(SystemUser $account, ?string $at): void
    {
        $before = $account->ssh_password_at;
        $account->update(['ssh_password_at' => $at]);
        try {
            $this->apply($account);
        } catch (AgentException $e) {
            $account->update(['ssh_password_at' => $before]);
            throw $e;
        }
    }

    /**
     * Sends the account's access to the server as the panel has it: locked
     * while its customer is suspended.
     */
    public function apply(SystemUser $account, ?string $password = null): void
    {
        $account->loadMissing('customer');
        // Keys whose undo window passed go for good.
        $account->sshKeys()->onlyTrashed()->where('deleted_at', '<', now()->subSeconds(self::UNDO_SECONDS))->forceDelete();
        $params = [
            'user' => $account->name,
            'access' => $account->ssh_access,
            'keys' => $account->sshKeys()->orderBy('id')->pluck('public_key')->all(),
            'password_login' => $account->ssh_password_at !== null,
            'locked' => (bool) $account->customer?->isSuspended(),
        ];
        if ($password !== null) {
            $params['password'] = $password;
        }
        $this->agent->call('ssh.user.apply', $params, config('serfex.agent.job_timeout'));
    }

    /**
     * Takes access away on the server before the account itself goes.
     */
    public function removeForAccount(SystemUser $account): void
    {
        if ($account->ssh_access === self::OFF) {
            return;
        }
        $this->agent->call('ssh.user.apply', ['user' => $account->name, 'access' => self::OFF, 'keys' => [], 'password_login' => false, 'locked' => true],
            config('serfex.agent.job_timeout'));
        $account->update(['ssh_access' => self::OFF]);
    }

    /**
     * What to type to connect, for the connection card.
     *
     * @return array{host: string, port: int, user: string, sftp: string, ssh: string|null}
     */
    public function connection(Site $site, SystemUser $account): array
    {
        $port = app(FtpAccounts::class)->port();
        $target = $account->name.'@'.$site->domain;

        return [
            'host' => $site->domain,
            'port' => $port,
            'user' => $account->name,
            'sftp' => 'sftp '.($port !== 22 ? '-P '.$port.' ' : '').$target,
            'ssh' => $account->ssh_access === self::SHELL ? 'ssh '.($port !== 22 ? '-p '.$port.' ' : '').$target : null,
        ];
    }
}
