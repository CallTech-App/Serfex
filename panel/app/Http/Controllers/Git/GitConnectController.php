<?php

namespace App\Http\Controllers\Git;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLog;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitProviderException;
use App\Services\Git\GitProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connecting a GitHub, GitLab or Bitbucket account with OAuth: "Bağlan"
 * sends the user to the host, which sends them back to the callback with
 * a code. The state ties the answer to the browser and the user who
 * asked; the flow always runs on the panel address registered on the
 * host, so the session is there when they come back.
 */
class GitConnectController extends Controller
{
    private const SESSION = 'git_oauth';

    public function redirect(Request $request, string $provider, GitProviders $providers): RedirectResponse
    {
        $host = $providers->get($provider);
        $back = $this->returnPath($request->query('return'));
        if ($request->user()->isReadOnly()) {
            return redirect($back)->with('toast', __('Salt okunur hesaplar Git hesabı bağlayamaz.'));
        }
        if ($host === null) {
            return redirect($back)->with('toast', __(':provider bağlantısı bu sunucuda açık değil.', ['provider' => GitProviders::LABELS[$provider]]));
        }
        $callback = $providers->callbackUrl($provider);
        $base = $this->origin($callback);
        if ($base !== $request->getSchemeAndHttpHost()) {
            // Start again on the registered address, so the session the
            // callback checks is the one that started.
            return redirect()->away($base.$request->getRequestUri());
        }
        $state = Str::random(40);
        $request->session()->put(self::SESSION, ['state' => $state, 'provider' => $provider, 'return' => $back, 'user' => $request->user()->id, 'at' => now()->timestamp]);

        return redirect()->away($host->authorizeUrl($callback, $state));
    }

    public function callback(Request $request, string $provider, GitProviders $providers, GitAccounts $accounts): RedirectResponse
    {
        $flow = $request->session()->pull(self::SESSION);
        $back = $this->returnPath($flow['return'] ?? null);
        $state = $request->query('state');
        if (! is_array($flow) || ! is_string($state) || ! hash_equals((string) $flow['state'], $state) || $flow['provider'] !== $provider
            || $flow['user'] !== $request->user()->id || now()->timestamp - (int) $flow['at'] > 900) {
            return redirect($back)->with('toast', __('Bağlantı isteği geçersiz ya da süresi dolmuş; yeniden deneyin.'));
        }
        $host = $providers->get($provider);
        $code = $request->query('code');
        if ($host === null || ! is_string($code) || $code === '') {
            return redirect($back)->with('toast', __(':provider erişim izni verilmedi.', ['provider' => GitProviders::LABELS[$provider]]));
        }
        try {
            $account = $accounts->connect($request->user(), $host, $code);
        } catch (GitProviderException $e) {
            return redirect($back)->with('toast', $e->getMessage());
        }
        AuditLog::record(__(':provider hesabını bağladı: :name', ['provider' => $host->label(), 'name' => $account->username]), extra: ['section' => __('Hesap bilgileri')]);

        return redirect($back)->with('toast', __(':provider hesabı bağlandı: :name', ['provider' => $host->label(), 'name' => $account->username]));
    }

    /**
     * A path inside the panel to go back to; anything else goes to the
     * account's Git page.
     */
    private function returnPath(mixed $path): string
    {
        return is_string($path) && preg_match('~^/(?![/\\\\])[^\s]*$~', $path) ? $path : route('account.git', absolute: false);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
