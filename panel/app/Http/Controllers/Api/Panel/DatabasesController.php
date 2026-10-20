<?php

namespace App\Http\Controllers\Api\Panel;

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Veritabanları: the sites' databases. Names start with the site's
 * account prefix (shop_) as in the panel; a user made with a database
 * gets a password made here, returned once.
 */
class DatabasesController extends ApiController
{
    /**
     * GET /api/v1/databases[?site=<domain>]
     */
    public function index(Request $request): JsonResponse
    {
        $databases = Database::visibleTo($this->user($request))->with('site', 'users')
            ->when($request->filled('site'), fn ($q) => $q->whereHas('site', fn ($s) => $s->where('domain', mb_strtolower((string) $request->query('site')))))
            ->orderBy('name')->get();

        return response()->json(['data' => $databases->map(fn (Database $d) => $this->database($d))->values()]);
    }

    public function show(Request $request, string $name): JsonResponse
    {
        return response()->json(['data' => $this->database($this->find($request, $name))]);
    }

    /**
     * POST /api/v1/databases {site, name, user?, password?, access?, server?}:
     * name and user without the prefix (shop) or with it (acme_shop); user
     * "" makes no user.
     */
    public function store(Request $request, Databases $databases, DatabaseServers $servers): JsonResponse
    {
        $data = $request->validate([
            'site' => ['required', 'string', 'max:253'],
            'name' => ['required', 'string', 'max:64'],
            'user' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'min:12', 'max:128', 'regex:/^\S+$/'],
            'access' => ['nullable', Rule::in(array_keys(DatabaseUser::ACCESS))],
            'server' => ['nullable', 'string'],
        ], [
            'password.min' => __('Parola en az 12 karakter olmalı.'),
            'password.regex' => __('Parola boşluk içeremez.'),
        ], ['site' => 'site', 'name' => __('veritabanı adı'), 'user' => __('kullanıcı adı'), 'password' => __('parola')]);
        $site = $this->findSite($request, $data['site']);
        $server = $data['server'] ?? $servers->defaultServer();
        if ($server === null || ! in_array($server, $servers->installed(), true)) {
            return $this->refuse($server === null ? __('Bu sunucuda veritabanı sunucusu kurulu değil.') : __(':server bu sunucuda kurulu değil; kurulu olanlar: :installed.', ['server' => $server, 'installed' => implode(', ', $servers->installed()) ?: '—']));
        }
        $prefix = Databases::prefix($site->systemUser);
        $name = self::withPrefix($prefix, mb_strtolower(trim($data['name'])));
        $withUser = ! array_key_exists('user', $data) || $data['user'] !== '';
        $user = $withUser ? self::withPrefix($prefix, mb_strtolower(trim((string) ($data['user'] ?? '')) ?: substr($name, strlen($prefix)))) : null;
        if (! preg_match(Databases::NAME_PATTERN, $name) || strlen($name) > Databases::MAX_DATABASE) {
            return $this->refuse(__('Veritabanı adı küçük harf, rakam ve _ içerebilir, ön ekiyle en fazla :max karakter.', ['max' => Databases::MAX_DATABASE]));
        }
        if (Database::withTrashed()->where('server', $server)->where('name', $name)->exists()) {
            return $this->refuse(__('Bu adda bir veritabanı zaten var.'), 409);
        }
        if ($user !== null) {
            $user = substr($user, 0, Databases::MAX_USER);
            if (! preg_match(Databases::NAME_PATTERN, $user)) {
                return $this->refuse(__('Kullanıcı adı küçük harf, rakam ve _ içerebilir.'));
            }
            if (DatabaseUser::withTrashed()->where('server', $server)->where('name', $user)->exists()) {
                return $this->refuse(__('Bu adda bir kullanıcı zaten var.'), 409);
            }
        }
        if ($refusal = $databases->refusal($site->systemUser)) {
            return $this->refuse($refusal);
        }
        $password = $user !== null ? ($data['password'] ?? Databases::password()) : null;
        try {
            $database = $databases->create($site, $server, $name, $user, $password, $data['access'] ?? 'all');
        } catch (AgentException $e) {
            return $this->refuse(__('Veritabanı oluşturulamadı: :reason', ['reason' => $e->reason]), 502);
        }
        Quotas::forget();
        $this->audit($request, __('Veritabanları'), $user !== null
            ? __(':name veritabanı ve :prefix:user_suffix kullanıcısı oluşturuldu.', ['name' => $database->name, 'prefix' => '', 'user_suffix' => $user])
            : __(':name veritabanı oluşturuldu.', ['name' => $database->name]), route('databases.show', $database, false));

        return response()->json(['data' => $this->database($database->fresh(['site', 'users'])) + [
            'connection' => $user !== null ? $database->connection($user, $password) : null,
        ]], 201);
    }

    /**
     * DELETE /api/v1/databases/{name}[?backup=0]: by default a copy is kept
     * in the backups first, as in the panel.
     */
    public function destroy(Request $request, Databases $databases, string $name): JsonResponse
    {
        $database = $this->find($request, $name);
        $databases->delete($database, $request->boolean('backup', true));
        Quotas::forget();
        $this->audit($request, __('Veritabanları'), __(':name silindi.', ['name' => $database->name]));

        return response()->json(null, 204);
    }

    private static function withPrefix(string $prefix, string $name): string
    {
        return str_starts_with($name, $prefix) ? $name : $prefix.$name;
    }

    private function find(Request $request, string $name): Database
    {
        return Database::visibleTo($this->user($request))->with('site', 'users')->where('name', $name)
            ->when($request->filled('server'), fn ($q) => $q->where('server', (string) $request->query('server')))
            ->first()
            ?? $this->missing(__(':name adında bir veritabanı yok.', ['name' => $name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function database(Database $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'server' => $d->server,
            'site' => $d->site?->domain,
            'size_bytes' => $d->size_bytes,
            'tables' => $d->tables,
            'users' => $d->users->map(fn (DatabaseUser $u) => ['name' => $u->name, 'access' => $u->pivot->access ?? null, 'hosts' => $u->hosts ?? []])->values(),
        ];
    }
}
