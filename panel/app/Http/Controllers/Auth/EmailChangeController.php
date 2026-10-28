<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\EmailChange;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The link mailed to a new sign-in address. Opening it shows a button;
 * the address changes only when the button is pressed, so a mail scanner
 * that opens links changes nothing. It works signed in or not, on any
 * device: the link itself proves the mailbox.
 */
class EmailChangeController extends Controller
{
    public function show(Request $request, EmailChange $changes, string $token): View
    {
        $user = $changes->account($token);
        $this->speak($request, $user);

        return view('auth.confirm-email', ['token' => $token, 'user' => $user, 'hours' => EmailChange::HOURS]);
    }

    public function store(Request $request, EmailChange $changes, string $token): RedirectResponse|View
    {
        $user = $changes->account($token);
        $this->speak($request, $user);
        if ($user === null) {
            return view('auth.confirm-email', ['token' => $token, 'user' => null, 'hours' => EmailChange::HOURS]);
        }
        try {
            $changes->confirm($user, $request->ip());
        } catch (RuntimeException $e) {
            return view('auth.confirm-email', ['token' => $token, 'user' => null, 'hours' => EmailChange::HOURS, 'error' => $e->getMessage()]);
        }
        $message = __('E-posta adresiniz :email olarak değişti.', ['email' => $user->email]);
        if ($request->user()?->is($user)) {
            return redirect()->route('account.profile')->with('toast', ['message' => $message]);
        }

        // The sign-in page reads in the person's language too.
        $request->session()->put('locale', Locales::forUser($user));

        return redirect()->route('login')->withInput(['email' => $user->email])->with('status', $message.' '.__('Yeni adresinizle giriş yapın.'));
    }

    /**
     * Signed out, the page reads in the language of the account the link
     * is for, unless one was picked below the form.
     */
    private function speak(Request $request, ?User $user): void
    {
        if ($user !== null && $request->user() === null && ! Locales::supports($request->session()->get('locale'))) {
            Locales::apply(Locales::forUser($user));
        }
    }
}
