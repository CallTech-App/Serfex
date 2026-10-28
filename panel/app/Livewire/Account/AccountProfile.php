<?php

namespace App\Livewire\Account;

use App\Livewire\Attributes\ForEveryAccount;
use App\Models\Customer;
use App\Services\Security\EmailChange;
use App\Services\Settings\OutgoingMail;
use App\Support\Impersonation;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Hesap bilgileri: the signed-in account's own name, e-mail address,
 * phone (a customer's) and language. Every account has it, administrators,
 * customers and Laravel team members alike.
 *
 * A new e-mail address asks for the current password and counts once the
 * link mailed to it is opened (see EmailChange).
 */
#[Layout('components.layouts.app')]
#[Title('Hesap bilgileri · Serfex')]
#[ForEveryAccount]
class AccountProfile extends Component
{
    public string $name = '';

    public string $phone = '';

    /** This person's language; '' follows the server's default. */
    public string $locale = '';

    public bool $changingEmail = false;

    public string $newEmail = '';

    public string $password = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->phone = (string) ($user->isCustomer() ? $user->customer?->phone : '');
        $this->locale = (string) $user->locale;
    }

    public function saveName(): void
    {
        $this->name = trim(preg_replace('/\s+/u', ' ', $this->name) ?? '');
        $this->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => __('ad soyad')]);
        $this->rename($this->name, undo: true);
    }

    public function undoName(string $previous): void
    {
        $this->rename(Str::limit(trim($previous), 100, ''), undo: false);
        $this->name = auth()->user()->name;
    }

    private function rename(string $name, bool $undo): void
    {
        $user = auth()->user();
        $previous = $user->name;
        if ($name === '' || $name === $previous) {
            return;
        }
        $user->forceFill(['name' => $name])->save();
        if ($user->isCustomer()) {
            // A customer's login and record carry the same name.
            Customer::whereKey($user->customer_id)->update(['name' => $name]);
        }
        $this->dispatch('toast',
            message: $undo ? __('Adınız :name olarak kaydedildi.', ['name' => $name]) : __('Ad geri alındı.'),
            source: $undo ? $this->getId() : null,
            undo: $undo ? ['method' => 'undoName', 'args' => [$previous]] : null,
        );
    }

    public function savePhone(): void
    {
        $user = auth()->user();
        if (! $user->isCustomer()) {
            return;
        }
        $this->phone = trim($this->phone);
        $this->validate(['phone' => ['nullable', 'max:40', 'regex:/^[0-9+() .-]+$/']], ['phone.regex' => __('Telefon numarası geçersiz.')], ['phone' => 'telefon']);
        $previous = $user->customer?->phone;
        Customer::whereKey($user->customer_id)->update(['phone' => $this->phone ?: null]);
        $this->dispatch('toast', message: __('Telefon kaydedildi.'), source: $this->getId(), undo: ['method' => 'undoPhone', 'args' => [$previous]]);
    }

    public function undoPhone(?string $previous): void
    {
        $user = auth()->user();
        if (! $user->isCustomer() || ($previous !== null && ! preg_match('/^[0-9+() .-]{1,40}$/', $previous))) {
            return;
        }
        Customer::whereKey($user->customer_id)->update(['phone' => $previous]);
        $this->phone = (string) $previous;
        $this->dispatch('toast', message: __('Telefon geri alındı.'));
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
        $this->redirect(route('account.profile'));
    }

    public function openEmail(): void
    {
        $this->newEmail = $this->password = '';
        $this->resetValidation();
        $this->changingEmail = true;
    }

    public function changeEmail(EmailChange $changes): void
    {
        $user = auth()->user();
        $this->resetValidation();
        if (Impersonation::active()) {
            $this->addError('newEmail', __('Müşteri olarak çalışırken e-posta değiştirilemez. Müşterinin adresini Müşteriler sayfasından değiştirin.'));

            return;
        }
        $this->newEmail = Str::lower(trim($this->newEmail));
        $this->validate(['newEmail' => ['required', 'email', 'max:190']], [], ['newEmail' => __('yeni e-posta')]);
        if ($this->newEmail === Str::lower($user->email)) {
            $this->addError('newEmail', __('Bu zaten şimdiki adresiniz.'));

            return;
        }
        if (! $changes->available($user, $this->newEmail)) {
            $this->addError('newEmail', __('Bu e-posta adresiyle bir panel hesabı zaten var.'));

            return;
        }
        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', __('Parola yanlış.'));

            return;
        }
        $old = $user->email;
        try {
            $result = $changes->start($user, $this->newEmail, request()->ip());
        } catch (RuntimeException $e) {
            $this->addError('newEmail', $e->getMessage());

            return;
        }
        $this->changingEmail = false;
        $this->password = '';
        $this->dispatch('toast', message: $result === 'sent'
            ? __(':email adresine doğrulama bağlantısı gönderildi.', ['email' => $this->newEmail])
            : __('E-posta adresiniz :old yerine :email oldu. Panel e-posta gönderemediği için yeni adres doğrulanmadı.', ['old' => $old, 'email' => $this->newEmail]));
    }

    public function resendEmail(EmailChange $changes): void
    {
        $user = auth()->user();
        if ($user->pending_email === null) {
            return;
        }
        try {
            $changes->resend($user, request()->ip());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: __(':email adresine doğrulama bağlantısı yeniden gönderildi.', ['email' => $user->pending_email]));
    }

    public function cancelEmail(EmailChange $changes): void
    {
        $user = auth()->user();
        if ($user->pending_email === null) {
            return;
        }
        $email = $user->pending_email;
        $changes->cancel($user);
        $this->dispatch('toast', message: __(':email adresine geçiş iptal edildi.', ['email' => $email]));
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.account.profile', [
            'user' => $user,
            'customer' => $user->isCustomer() ? $user->customer : null,
            'pendingExpired' => $user->pending_email !== null && $user->pending_email_at?->lte(now()->subHours(EmailChange::HOURS)),
            'impersonating' => Impersonation::active(),
            'mailWorks' => app(OutgoingMail::class)->problem() === null,
        ]);
    }
}
