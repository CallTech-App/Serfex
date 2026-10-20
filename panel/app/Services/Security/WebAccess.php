<?php

namespace App\Services\Security;

use App\Models\ProtectedDirectory;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Str;

/**
 * Who may fetch what from a site: folders that ask for a user name and
 * password, and paths Nginx refuses outright (a fix of the security scan
 * for backups and secrets left under the web root). Both work in front
 * of Apache too.
 */
class WebAccess
{
    public const MAX_PROTECTED = 20;

    public const MAX_LOGINS = 50;

    public const MAX_DENIED = 200;

    /** A login name the agent accepts. */
    public const LOGIN_PATTERN = '/^[A-Za-z0-9._@-]{1,64}$/';

    public const REALM_PATTERN = '/^[\p{L}\p{N} ._,:()-]{0,60}$/u';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * "yonetim", "/yonetim" or "yonetim/" as the agent wants it: "/yonetim/".
     */
    public static function folder(string $path): string
    {
        $path = trim(trim($path), '/');

        return $path === '' ? '/' : '/'.$path.'/';
    }

    public static function validPath(string $path): bool
    {
        return (bool) preg_match(Waf::PATH_PATTERN, $path) && ! str_contains($path, '//') && ! str_contains($path, '/../') && ! str_ends_with($path, '/..');
    }

    /**
     * A SHA-512 crypt hash, which Nginx checks.
     */
    public static function hash(string $password): string
    {
        return crypt($password, '$6$rounds=5000$'.Str::random(16).'$');
    }

    // --- Password-protected folders -----------------------------------

    /**
     * Checks a folder and its logins; returns an error per field.
     *
     * @param  list<array{name: string, password: string}>  $logins  an empty password keeps the old one
     * @return array<string, string>
     */
    public function problems(Site $site, string $path, string $realm, array $logins, ?ProtectedDirectory $dir = null): array
    {
        $out = [];
        if ($path === '/' || ! self::validPath($path)) {
            $out['path'] = __('Klasörü /yonetim/ gibi yazın; harf, rakam ve ._~%@+- içerebilir.');
        } elseif (ProtectedDirectory::withTrashed()->where('site_id', $site->id)->where('path', $path)->whereKeyNot($dir?->id ?? 0)->exists()) {
            $out['path'] = __('Bu klasör zaten korunuyor.');
        } elseif ($dir === null && ProtectedDirectory::where('site_id', $site->id)->count() >= self::MAX_PROTECTED) {
            $out['path'] = __('Bir sitede en fazla :max_protected korumalı klasör olabilir.', ['max_protected' => self::MAX_PROTECTED]);
        }
        if (! preg_match(self::REALM_PATTERN, $realm)) {
            $out['realm'] = __('Başlık en fazla 60 karakter; harf, rakam, boşluk ve ._,:()- içerebilir.');
        }
        $names = array_map(fn ($l) => $l['name'], $logins);
        $old = $dir ? $dir->userNames() : [];
        if ($logins === [] || count($logins) > self::MAX_LOGINS) {
            $out['logins'] = __('En az bir, en fazla :max_logins kullanıcı ekleyin.', ['max_logins' => self::MAX_LOGINS]);
        } elseif (count(array_unique($names)) !== count($names)) {
            $out['logins'] = __('Aynı kullanıcı adı iki kez yazılmış.');
        } else {
            foreach ($logins as $l) {
                if (! preg_match(self::LOGIN_PATTERN, $l['name'])) {
                    $out['logins'] = __('":name" geçerli bir kullanıcı adı değil (harf, rakam ve ._@-).', ['name' => $l['name']]);
                } elseif ($l['password'] === '' && ! in_array($l['name'], $old, true)) {
                    $out['logins'] = __(':name için bir parola yazın.', ['name' => $l['name']]);
                } elseif ($l['password'] !== '' && (strlen($l['password']) < 8 || strlen($l['password']) > 128)) {
                    $out['logins'] = __('Parolalar 8-128 karakter olmalı.');
                }
            }
        }

        return $out;
    }

    /**
     * Protects a folder, or changes a protected folder.
     *
     * @param  list<array{name: string, password: string}>  $logins
     *
     * @throws AgentException
     */
    public function protect(Site $site, string $path, string $realm, array $logins, ?ProtectedDirectory $dir = null): ProtectedDirectory
    {
        $hashes = collect($dir?->users ?? [])->keyBy('name');
        $users = array_map(fn ($l) => ['name' => $l['name'], 'hash' => $l['password'] !== '' ? self::hash($l['password']) : $hashes[$l['name']]['hash']], $logins);
        $before = $dir?->only('path', 'realm', 'users');
        if ($dir === null) {
            $dir = ProtectedDirectory::create(['site_id' => $site->id, 'path' => $path, 'realm' => $realm ?: null, 'users' => $users]);
        } else {
            $dir->update(['path' => $path, 'realm' => $realm ?: null, 'users' => $users]);
        }
        try {
            $this->applyProtected($site);
        } catch (AgentException $e) {
            $before === null ? $dir->forceDelete() : $dir->update($before);
            throw $e;
        }

        return $dir;
    }

    /**
     * Opens the folder again; Undo protects it with the same logins.
     *
     * @throws AgentException
     */
    public function unprotect(ProtectedDirectory $dir): void
    {
        $dir->delete();
        try {
            $this->applyProtected($dir->site);
        } catch (AgentException $e) {
            $dir->restore();
            throw $e;
        }
    }

    /**
     * @throws AgentException
     */
    public function restore(ProtectedDirectory $dir): void
    {
        $dir->restore();
        try {
            $this->applyProtected($dir->site);
        } catch (AgentException $e) {
            $dir->delete();
            throw $e;
        }
    }

    /**
     * @throws AgentException
     */
    public function applyProtected(Site $site): void
    {
        $this->agent->call('web.protect.apply', [
            'domain' => $site->domain,
            'dirs' => ProtectedDirectory::where('site_id', $site->id)->orderBy('path')->get()
                ->map(fn (ProtectedDirectory $d) => ['path' => $d->path, 'realm' => (string) $d->realm, 'users' => array_values($d->users)])->values()->all(),
        ], config('servik.agent.job_timeout'));
    }

    // --- Blocked paths ------------------------------------------------

    /**
     * Adds paths visitors may not fetch ("/yedek.sql", "/eski/").
     *
     * @param  list<string>  $paths
     *
     * @throws AgentException
     */
    public function deny(Site $site, array $paths): void
    {
        $this->setDenied($site, array_values(array_unique([...($site->denied_paths ?? []), ...$paths])));
    }

    /**
     * @param  list<string>  $paths
     *
     * @throws AgentException
     */
    public function allow(Site $site, array $paths): void
    {
        $this->setDenied($site, array_values(array_diff($site->denied_paths ?? [], $paths)));
    }

    /**
     * @param  list<string>  $paths
     *
     * @throws AgentException
     */
    public function setDenied(Site $site, array $paths): void
    {
        sort($paths);
        $before = $site->denied_paths;
        $site->update(['denied_paths' => $paths ?: null]);
        try {
            $this->agent->call('web.deny.apply', ['domain' => $site->domain, 'paths' => $paths], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->update(['denied_paths' => $before]);
            throw $e;
        }
    }

    public static function message(AgentException $e): string
    {
        return Waf::message($e);
    }
}
