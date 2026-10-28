<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Security\LoginLog;
use App\Services\Security\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

use function Illuminate\Support\defer;

/**
 * "Parolamı unuttum": asking for a link by e-mail, and setting a new
 * password from it. The answer to the request never says whether the
 * address has an account. The new password does not sign the person in:
 * they sign in with it, two-step code included.
 */
class PasswordResetController extends Controller
{
    public function create(PasswordReset $resets): View
    {
        return view('auth.forgot-password', ['mailProblem' => $resets->mailProblem() !== null, 'minutes' => PasswordReset::MINUTES]);
    }

    public function store(Request $request, PasswordReset $resets): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:255']], [], ['email' => 'e-posta'])['email'];
        if ($resets->mailProblem() !== null) {
            return back()->withInput();
        }
        // After the answer, so a known address doesn't answer slower.
        $ip = $request->ip();
        defer(fn () => $resets->request($email, $ip));

        return back()->with('status', __('Bu adrese ait bir hesap varsa parola sıfırlama bağlantısını gönderdik. Gelen kutunuza ve istenmeyen klasörüne bakın; bağlantı :minutes dakika geçerlidir.', ['minutes' => PasswordReset::MINUTES]));
    }

    public function edit(Request $request, PasswordReset $resets, string $token): View
    {
        $user = $resets->account((string) $request->query('email', ''));

        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'valid' => $resets->valid($user, $token),
            'minLength' => $user !== null ? $resets->minLength($user) : 10,
        ]);
    }

    public function update(Request $request, PasswordReset $resets): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:100'], 'email' => ['required', 'string', 'email', 'max:255']]);
        $user = $resets->account((string) $request->input('email'));
        if (! $resets->valid($user, (string) $request->input('token'))) {
            LoginLog::failed($request->ip(), (string) $request->input('email'), 'reset link');

            return redirect()->route('password.request')->withErrors(['email' => __('Bu sıfırlama bağlantısı geçersiz ya da süresi dolmuş. Yeni bir bağlantı isteyin.')]);
        }
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min($resets->minLength($user))],
        ], [], ['password' => 'parola']);
        $resets->reset($user, $data['password'], $request->ip());

        return redirect()->route('login')
            ->withInput(['email' => $user->email])
            ->with('status', __('Parolanız değiştirildi. Yeni parolanızla giriş yapın.'));
    }
}
