<?php

namespace App\Livewire\Account;

use App\Livewire\Attributes\ForEveryAccount;
use App\Models\ApiKey;
use App\Services\Security\PasswordReset;
use App\Services\Security\Sessions;
use App\Services\Security\TwoFactor;
use App\Services\Settings\ApiKeys;
use App\Services\Settings\OutgoingMail;
use App\Support\Impersonation;
use App\Support\Locales;
use App\Support\When;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Hesap güvenliği: the signed-in account's own password, two-step login,
 * the browsers it is signed in on and the command lines (serfex login).
 * Every account has it, administrators, customers and Laravel team
 * members alike. Name, e-mail and language are on Hesap bilgileri.
 */
#[Layout('components.layouts.app')]
#[Title('Hesap güvenliği · Serfex')]
#[ForEveryAccount]
class AccountSecurity extends Component
{
    public bool $settingUp = false;

    public bool $changingPassword = false;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public function openPassword(): void
    {
        $this->currentPassword = $this->newPassword = $this->newPassword_confirmation = '';
        $this->resetValidation();
        $this->changingPassword = true;
    }

    /**
     * Sets a new password and closes the account's other browser sessions;
     * this one stays signed in. The person is told by mail.
     */
    public function changePassword(PasswordReset $resets, Sessions $sessions, OutgoingMail $mail): void
    {
        $user = auth()->user();
        $this->resetValidation();
        if (Impersonation::active()) {
            $this->addError('currentPassword', __('Müşteri olarak çalışırken parola değiştirilemez.'));

            return;
        }
        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', __('Parola yanlış.'));

            return;
        }
        $this->validate([
            'newPassword' => ['required', 'string', 'confirmed', 'max:128', Password::min($resets->minLength($user)), 'different:currentPassword'],
        ], ['newPassword.different' => __('Yeni parola şimdikinden farklı olmalı.')], ['newPassword' => __('yeni parola')]);

        $user->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();
        $closed = $sessions->revokeOthers($user, session()->getId());
        $this->changingPassword = false;
        $this->currentPassword = $this->newPassword = $this->newPassword_confirmation = '';
        if ($mail->problem() === null) {
            $ip = request()->ip();
            Locales::using($user->preferredLocale(), fn () => $mail->send([$user->email], __('Serfex parolanız değiştirildi'), implode("\n", [
                __('Merhaba :name,', ['name' => $user->name]),
                '',
                __(':email hesabınızın parolası :when tarihinde Hesap güvenliği sayfasından değiştirildi (:ip). Diğer oturumlarınız kapatıldı.', ['email' => $user->email, 'when' => When::dateTime(now()), 'ip' => $ip ?? '-']),
                '',
                __('Bunu siz yapmadıysanız hemen sunucu yöneticinize haber verin.'),
                '',
            ])));
        }
        $this->dispatch('toast', message: $closed
            ? trans_choice('Parolanız değiştirildi; diğer :count oturum kapatıldı.', $closed)
            : __('Parolanız değiştirildi.'));
    }

    /** The new secret while setting up; it is saved only once confirmed. */
    #[Locked]
    public ?string $secret = null;

    public string $code = '';

    /** @var list<string>|null recovery codes, shown once */
    public ?array $recoveryCodes = null;

    public bool $disabling = false;

    public string $password = '';

    public function startSetup(TwoFactor $twoFactor): void
    {
        if (auth()->user()->hasTwoFactor()) {
            return;
        }
        $this->secret = $twoFactor->newSecret();
        $this->code = '';
        $this->resetValidation();
        $this->settingUp = true;
    }

    public function confirmSetup(TwoFactor $twoFactor): void
    {
        $user = auth()->user();
        if ($this->secret === null || $user->hasTwoFactor()) {
            return;
        }
        $this->resetValidation();
        $codes = $twoFactor->enable($user, $this->secret, $this->code);
        if ($codes === null) {
            $this->addError('code', __('Kod doğru değil. Uygulamadaki güncel 6 haneli kodu yazın; telefonun saati doğru olmalı.'));

            return;
        }
        $this->settingUp = false;
        $this->secret = null;
        $this->code = '';
        $this->recoveryCodes = array_map([TwoFactor::class, 'format'], $codes);
        session()->forget('two_factor_required');
    }

    public function regenerate(TwoFactor $twoFactor): void
    {
        if (auth()->user()->hasTwoFactor()) {
            $this->recoveryCodes = array_map([TwoFactor::class, 'format'], $twoFactor->regenerate(auth()->user()));
        }
    }

    public function openDisable(): void
    {
        $this->password = '';
        $this->resetValidation();
        $this->disabling = true;
    }

    public function disable(TwoFactor $twoFactor): void
    {
        $user = auth()->user();
        $this->resetValidation();
        if ($twoFactor->isRequiredFor($user)) {
            $this->addError('password', __('Sunucu yöneticisi iki adımlı doğrulamayı bu hesap için zorunlu tuttu.'));

            return;
        }
        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', __('Parola yanlış.'));

            return;
        }
        $twoFactor->disable($user);
        $this->disabling = false;
        $this->password = '';
        $this->dispatch('toast', message: __('İki adımlı doğrulama kapatıldı.'));
    }

    public function revoke(string $id, Sessions $sessions): void
    {
        if ($id === session()->getId()) {
            return;
        }
        $sessions->revoke(auth()->user(), $id);
        $this->dispatch('toast', message: __('Oturum kapatıldı.'));
    }

    /**
     * Signs a command line out: its key stops working at once.
     */
    public function closeCli(int $id, ApiKeys $keys): void
    {
        $key = $this->cliKeys()->find($id);
        if ($key === null) {
            return;
        }
        $keys->revoke($key);
        $this->dispatch('toast',
            message: __(':client komut satırı oturumu kapatıldı.', ['client' => $key->client]),
            source: $this->getId(),
            undo: ['method' => 'reopenCli', 'args' => [$key->id]],
        );
    }

    public function reopenCli(int $id, ApiKeys $keys): void
    {
        $key = ApiKey::where('user_id', auth()->id())->where('kind', ApiKey::CLI)->find($id);
        if ($key !== null && $keys->restore($key)) {
            $this->dispatch('toast', message: __('Komut satırı oturumu yeniden açıldı.'));
        }
    }

    /**
     * @return Builder<ApiKey>
     */
    private function cliKeys(): Builder
    {
        return ApiKey::where('user_id', auth()->id())->where('kind', ApiKey::CLI)->usable();
    }

    public function revokeOthers(Sessions $sessions): void
    {
        $n = $sessions->revokeOthers(auth()->user(), session()->getId());
        $this->dispatch('toast', message: $n ? __(':n oturum kapatıldı.', ['n' => $n]) : __('Başka oturum yok.'));
    }

    public function render(TwoFactor $twoFactor, Sessions $sessions, PasswordReset $resets): View
    {
        $user = auth()->user();

        return view('livewire.account.security', [
            'user' => $user,
            'required' => $twoFactor->mustSetUp($user) || session('two_factor_required'),
            'qr' => $this->secret ? $twoFactor->qrSvg($user, $this->secret) : null,
            'codesLeft' => $twoFactor->recoveryCodesLeft($user),
            'sessions' => $sessions->of($user, session()->getId()),
            'sessionsAvailable' => $sessions->available(),
            'cliKeys' => $this->cliKeys()->latest('id')->get(),
            'minLength' => $resets->minLength($user),
        ]);
    }
}
