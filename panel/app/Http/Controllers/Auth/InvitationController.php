<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Accepting an invitation (an administrator's or a customer's login, or a
 * place on a Laravel application's team): the person sets a password and
 * is signed in. Links work for seven days.
 */
class InvitationController extends Controller
{
    public const DAYS = 7;

    public function show(string $token): View
    {
        $user = $this->invited($token);

        return view('auth.invitation', ['user' => $user, 'token' => $token]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $user = $this->invited($token);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ], [], ['name' => __('ad soyad'), 'password' => 'parola']);
        $user->forceFill([
            'name' => $data['name'],
            'password' => $data['password'],
            'invite_token' => null,
            'invited_at' => null,
            'email_verified_at' => now(),
        ])->save();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($user->is_admin || $user->customer_id !== null ? 'dashboard' : 'laravel.index');
    }

    private function invited(string $token): User
    {
        $user = User::where('invite_token', hash('sha256', $token))->first();
        abort_if($user === null || $user->removed_at !== null || $user->invited_at === null || $user->invited_at->lt(now()->subDays(self::DAYS)), 404);

        return $user;
    }
}
