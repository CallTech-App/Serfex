<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use App\Models\LaravelApiToken;
use App\Models\Setting;
use App\Services\Settings\ApiKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me: who the bearer is. The command line asks it after
 * signing in and when it is given a key or a token in CI.
 */
class MeController extends Controller
{
    public function show(Request $request, ApiKeys $keys): JsonResponse
    {
        $plain = (string) $request->bearerToken();
        $server = [
            'hostname' => Setting::get('panel.hostname') ?: (Setting::get('server.hostname') ?: gethostname()),
            'version' => config('servik.version'),
        ];

        if (str_starts_with($plain, ApiKeys::PREFIX)) {
            $key = $keys->find($plain);
            if ($key === null || ! $key->isUsable()) {
                return AuthenticateApiKey::refuse(401, $key?->revoked_at ? __('Bu API anahtarı iptal edildi.') : ($key ? __('Bu API anahtarının süresi doldu.') : __('API anahtarı yok ya da geçersiz.')));
            }
            if (($refusal = AuthenticateApiKey::userRefusal($key, $user = $key->user)) !== null) {
                return AuthenticateApiKey::refuse(403, $refusal);
            }

            return response()->json(['data' => [
                'kind' => 'key',
                'name' => $user->name,
                'email' => $user->email,
                'account' => $user->is_admin ? 'admin' : ($user->isCustomer() ? 'customer' : 'member'),
                'role' => $user->adminRole(),
                'role_name' => $user->roleName(),
                'customer' => $user->isCustomer() ? $user->customer?->displayName() : null,
                'locale' => $user->preferredLocale(),
                'key' => [
                    // A command line key's name was written in its approver's
                    // language; the reader's reads better.
                    'name' => $key->kind === ApiKey::CLI && $key->client ? __('Komut satırı: :client', ['client' => $key->client]) : $key->name,
                    'kind' => $key->kind,
                    'scope' => $user->isReadOnly() ? 'read' : $key->scope,
                    'expires_at' => $key->expires_at?->toIso8601String(),
                ],
                'app' => null,
                'server' => $server,
            ]]);
        }

        $token = LaravelApiToken::findPlain($plain);
        if ($token === null) {
            return AuthenticateApiKey::refuse(401, __('API anahtarı yok ya da geçersiz.'));
        }

        return response()->json(['data' => [
            'kind' => 'token',
            'name' => $token->name,
            'email' => null,
            'account' => 'token',
            'role' => $token->ability,
            'role_name' => __(LaravelApiToken::ABILITIES[$token->ability][0] ?? $token->ability),
            'customer' => null,
            'locale' => null,
            'key' => null,
            'app' => $token->app->site->domain,
            'server' => $server,
        ]]);
    }

    /**
     * DELETE /api/v1/me: servik logout. A command line key is closed (as
     * from Hesap güvenliği); other keys and tokens stay as they are,
     * since they are managed in the panel.
     */
    public function destroy(Request $request, ApiKeys $keys): JsonResponse
    {
        $key = $keys->find((string) $request->bearerToken());
        if ($key === null || ! $key->isUsable()) {
            return AuthenticateApiKey::refuse(401, __('API anahtarı yok ya da geçersiz.'));
        }
        if (! $key->isCli()) {
            return response()->json(['message' => __('Bu anahtar panelden yönetiliyor; kapatmak için API anahtarları sayfasını kullanın.'), 'closed' => false]);
        }
        $keys->revoke($key);

        return response()->json(null, 204);
    }
}
