<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\LoginLog;
use App\Services\Security\TwoFactor;
use App\Services\Setup\FirstRun;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signing in: e-mail and password, then the authenticator app's code for
 * accounts with two-step login. Failures are limited per account and per
 * address, and logged for Fail2ban.
 */
class LoginController extends Controller
{
    /** Failed logins an address may make in ten minutes, over all accounts. */
    private const PER_IP = 30;

    /** How long the second step may wait after the password. */
    private const CHALLENGE_MINUTES = 10;

    public function create(FirstRun $firstRun): View
    {
        return view('auth.login', ['needsSetup' => $firstRun->needsAdministrator()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [], ['email' => 'e-posta', 'password' => 'parola']);

        $key = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());
        $ipKey = 'login-ip|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, self::PER_IP)) {
            $wait = max(RateLimiter::availableIn($key), RateLimiter::tooManyAttempts($ipKey, self::PER_IP) ? RateLimiter::availableIn($ipKey) : 0);

            throw ValidationException::withMessages([
                'email' => __('Çok fazla deneme yapıldı. :wait saniye sonra tekrar deneyin.', ['wait' => $wait]),
            ]);
        }

        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($credentials);
        if (! $user instanceof User || $user->removed_at !== null || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ipKey, 600);
            LoginLog::failed($request->ip(), $credentials['email']);

            throw ValidationException::withMessages([
                'email' => __('E-posta ya da parola hatalı.'),
            ]);
        }
        $provider->rehashPasswordIfRequired($user, $credentials);

        RateLimiter::clear($key);
        if ($user->customer_id !== null && ($user->customer === null || $user->customer->isSuspended())) {
            throw ValidationException::withMessages(['email' => __(Impersonation::SUSPENDED)]);
        }
        $request->session()->regenerate();
        if ($user->hasTwoFactor()) {
            $request->session()->put('login.two_factor', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'until' => now()->addMinutes(self::CHALLENGE_MINUTES)->getTimestamp(),
            ]);

            return redirect()->route('login.two-factor');
        }
        Auth::guard('web')->login($user, $request->boolean('remember'));

        return $this->home($user);
    }

    /**
     * The second step: the code from the authenticator app, or a
     * recovery code.
     */
    public function challenge(Request $request): View|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor');
    }

    public function verify(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pending($request);
        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => __('Doğrulama süresi doldu; yeniden giriş yapın.')]);
        }
        $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'kod']);
        $key = 'login-2fa|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('Çok fazla deneme yapıldı. :key saniye sonra tekrar deneyin.', ['key' => RateLimiter::availableIn($key)])]);
        }
        if (! $twoFactor->verify($user, (string) $request->input('code'))) {
            RateLimiter::hit($key, 300);
            RateLimiter::hit('login-ip|'.$request->ip(), 600);
            LoginLog::failed($request->ip(), $user->email, 'two-factor code');

            throw ValidationException::withMessages(['code' => __('Kod doğru değil ya da süresi geçmiş.')]);
        }
        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('login.two_factor')['remember'];
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return $this->home($user);
    }

    /**
     * Where a sign-in goes: where they were going, else the setup wizard
     * the first time a full administrator signs in to a new panel, else
     * Genel Bakış.
     */
    private function home(User $user): RedirectResponse
    {
        $firstRun = app(FirstRun::class);
        $going = (string) session('url.intended', '');
        if ($user->isFullAdmin() && ! $firstRun->started() && ($going === '' || $going === route('dashboard'))) {
            $firstRun->start();

            return redirect()->route('setup');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function pending(Request $request): ?User
    {
        $pending = $request->session()->get('login.two_factor');
        if (! is_array($pending) || ($pending['until'] ?? 0) < now()->getTimestamp()) {
            return null;
        }
        $user = User::find($pending['id'] ?? 0);

        return $user?->hasTwoFactor() ? $user : null;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
