<?php

namespace App\Livewire\Account;

use App\Livewire\Attributes\ForEveryAccount;
use App\Models\ApiKey;
use App\Models\CliLogin;
use App\Services\Cli\CliLogins;
use App\Support\Impersonation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /cli/onay: the person approves a `serfex login` from the browser they
 * are signed in with. The page shows the code the command line printed,
 * the computer's name and where it asked from; approving makes a key that
 * acts as this person (read-only if they choose so).
 */
#[Layout('components.layouts.app')]
#[Title('Komut satırı girişi · Serfex')]
#[ForEveryAccount]
class CliApprove extends Component
{
    #[Url(as: 'kod')]
    public string $code = '';

    /** The code typed when the link had none. */
    public string $typed = '';

    public string $scope = 'write';

    /** approved or denied, once done. */
    #[Locked]
    public ?string $done = null;

    public function mount(): void
    {
        if (auth()->user()->isReadOnly()) {
            $this->scope = 'read';
        }
    }

    public function lookup(CliLogins $logins): void
    {
        $login = $logins->find($this->typed);
        if ($login === null || ! $login->isPending()) {
            $this->addError('typed', __('Bu kodla bekleyen bir giriş yok. Kodu komut satırında göründüğü gibi yazın; süresi dolduysa serfex login ile yeniden başlayın.'));

            return;
        }
        $this->code = $login->code;
    }

    public function approve(CliLogins $logins): void
    {
        $login = $this->login($logins);
        if ($login === null || ! $login->isPending()) {
            return;
        }
        $this->validate(['scope' => ['required', 'in:'.implode(',', array_keys(ApiKey::SCOPES))]]);
        if (Impersonation::active()) {
            $this->addError('scope', __('Müşteri olarak görüntülerken komut satırı girişi onaylanamaz; müşterinin kendisi onaylamalı.'));

            return;
        }
        $logins->approve($login, auth()->user(), $this->scope);
        $this->done = 'approved';
    }

    public function deny(CliLogins $logins): void
    {
        $login = $this->login($logins);
        if ($login === null || ! $login->isPending()) {
            return;
        }
        $logins->deny($login, auth()->user());
        $this->done = 'denied';
    }

    private function login(CliLogins $logins): ?CliLogin
    {
        return $this->code === '' ? null : $logins->find($this->code);
    }

    public function render(CliLogins $logins): View
    {
        return view('livewire.account.cli-approve', [
            'login' => $this->login($logins),
            'user' => auth()->user(),
        ]);
    }
}
