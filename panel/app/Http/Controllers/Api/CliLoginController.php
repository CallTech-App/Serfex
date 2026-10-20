<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cli\CliLogins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The command line's side of signing in through the browser (see
 * CliLogins): no key needed, only the secret of the sign-in.
 */
class CliLoginController extends Controller
{
    /**
     * POST /api/v1/cli/login {client}: a new sign-in, its code and the
     * page to approve it on.
     */
    public function start(Request $request, CliLogins $logins): JsonResponse
    {
        $data = $request->validate(['client' => ['nullable', 'string', 'max:120']]);
        [$login, $device] = $logins->start((string) ($data['client'] ?? ''), $request->ip());

        return response()->json([
            'code' => $login->code,
            'device' => $device,
            'url' => route('cli.approve', ['kod' => $login->code]),
            'interval' => CliLogins::INTERVAL,
            'expires_in' => CliLogins::MINUTES * 60,
        ], 201);
    }

    /**
     * POST /api/v1/cli/login/token {device}: 202 while it waits, 200 with
     * the key once approved, 403 refused, 410 expired.
     */
    public function collect(Request $request, CliLogins $logins): JsonResponse
    {
        $data = $request->validate(['device' => ['required', 'string', 'max:100']]);
        $result = $logins->collect($data['device']);

        return match ($result['status'] ?? null) {
            'approved' => response()->json(['status' => 'approved', 'key' => $result['key'], 'name' => $result['user']->name, 'email' => $result['user']->email]),
            'pending' => response()->json(['status' => 'pending', 'message' => __('Tarayıcıdaki onay bekleniyor.')], 202),
            'denied' => response()->json(['status' => 'denied', 'message' => __('Giriş tarayıcıda reddedildi.')], 403),
            default => response()->json(['status' => 'expired', 'message' => __('Bu girişin süresi doldu; servik login ile yeniden başlayın.')], 410),
        };
    }
}
