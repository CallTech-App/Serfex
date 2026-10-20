<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Settings\PanelSettings;
use App\Services\Setup\FirstRun;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The setup link install.sh prints: the first administrator sets their
 * name, e-mail and password, is signed in and goes on to the setup
 * wizard. A used or expired link explains how to get a new one.
 */
class SetupController extends Controller
{
    public function show(FirstRun $firstRun, string $token): View|Response
    {
        if (! $firstRun->validToken($token)) {
            return response()->view('auth.setup-link', ['used' => ! $firstRun->needsAdministrator()], 410);
        }

        return view('auth.setup', ['token' => $token]);
    }

    public function store(Request $request, FirstRun $firstRun, PanelSettings $settings, string $token): RedirectResponse|Response
    {
        if (! $firstRun->validToken($token)) {
            return response()->view('auth.setup-link', ['used' => ! $firstRun->needsAdministrator()], 410);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'locale' => ['required', Rule::in(array_keys(Locales::all()))],
        ], [], ['name' => __('ad soyad'), 'email' => 'e-posta', 'password' => 'parola', 'locale' => __('dil')]);

        $user = $firstRun->createAdministrator($data['name'], $data['email'], $data['password'], null);
        // The first administrator's language becomes the server's.
        $settings->setLocale($data['locale']);
        $firstRun->start();
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('setup');
    }
}
