<?php

namespace App\Livewire\Account;

use App\Livewire\Attributes\ForEveryAccount;
use App\Services\Security\Sessions;
use App\Services\Security\TwoFactor;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Hesap güvenliği: the signed-in account's own language, two-step login
 * and the browsers it is signed in on. Every account has it, administrators,
 * customers and Laravel team members alike.
 */
#[Layout('components.layouts.app')]
#[Title('Hesap güvenliği · Servik')]
#[ForEveryAccount]
class AccountSecurity extends Component
{
    /** This person's language; '' follows the server's default. */
    public string $locale = '';

    public bool $settingUp = false;

    public function mount(): void
    {
        $this->locale = (string) auth()->user()->locale;
    }

    public function saveLocale(): void
    {
        $this->validate(['locale' => ['nullable', 'in:'.implode(',', array_keys(Locales::all()))]], [], ['locale' => __('dil')]);
        $this->switchLocale($this->locale ?: null);
    }

    public function undoLocale(?string $previous): void
    {
        $this->switchLocale(Locales::supports($previous) ? $previous : null, undo: false);
    }

    /**
     * Saves the choice and reloads the page in that language; the toast
     * after the reload still offers Undo.
     */
    private function switchLocale(?string $locale, bool $undo = true): void
    {
        $user = auth()->user();
        $previous = $user->locale;
        $user->forceFill(['locale' => $locale])->save();
        Locales::apply(Locales::forUser($user));
        session()->flash('toast', [
            'message' => $undo ? __('Dil kaydedildi.') : __('Dil geri alındı.'),
            'undo' => $undo ? ['name' => $this->getName(), 'method' => 'undoLocale', 'args' => [$previous]] : null,
        ]);
        $this->redirect(route('account.security'));
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

    public function revokeOthers(Sessions $sessions): void
    {
        $n = $sessions->revokeOthers(auth()->user(), session()->getId());
        $this->dispatch('toast', message: $n ? __(':n oturum kapatıldı.', ['n' => $n]) : __('Başka oturum yok.'));
    }

    public function render(TwoFactor $twoFactor, Sessions $sessions): View
    {
        $user = auth()->user();

        return view('livewire.account.security', [
            'user' => $user,
            'required' => $twoFactor->mustSetUp($user) || session('two_factor_required'),
            'qr' => $this->secret ? $twoFactor->qrSvg($user, $this->secret) : null,
            'codesLeft' => $twoFactor->recoveryCodesLeft($user),
            'sessions' => $sessions->of($user, session()->getId()),
            'sessionsAvailable' => $sessions->available(),
        ]);
    }
}
