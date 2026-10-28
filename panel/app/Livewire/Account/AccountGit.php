<?php

namespace App\Livewire\Account;

use App\Livewire\Attributes\ForEveryAccount;
use App\Models\GitAccount;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitProviders;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Git hesapları: the GitHub, GitLab and Bitbucket accounts the signed-in
 * account connected, to pick repositories from a list in Git dağıtımı and
 * the Laravel install. Every account connects its own.
 */
#[Layout('components.layouts.app')]
#[Title('Git hesapları · Serfex')]
#[ForEveryAccount]
class AccountGit extends Component
{
    public ?int $disconnectingId = null;

    public function disconnect(GitAccounts $accounts): void
    {
        $account = GitAccount::where('user_id', auth()->id())->find($this->disconnectingId);
        $this->disconnectingId = null;
        if ($account === null) {
            return;
        }
        $accounts->disconnect($account);
        $this->dispatch('toast', message: __(':provider hesabının bağlantısı kaldırıldı: :name', ['provider' => GitProviders::LABELS[$account->provider] ?? $account->provider, 'name' => $account->username]));
    }

    public function render(GitProviders $providers): View
    {
        $accounts = GitAccount::where('user_id', auth()->id())->withCount(['gitRepositories', 'laravelApps'])->get()->keyBy('provider');
        $configured = $providers->configured();

        return view('livewire.account.git', [
            'rows' => collect(GitProviders::LABELS)
                ->filter(fn ($label, $key) => isset($configured[$key]) || $accounts->has($key))
                ->map(fn ($label, $key) => ['label' => $label, 'account' => $accounts->get($key), 'enabled' => isset($configured[$key])]),
            'disconnecting' => $this->disconnectingId ? $accounts->firstWhere('id', $this->disconnectingId) : null,
        ]);
    }
}
