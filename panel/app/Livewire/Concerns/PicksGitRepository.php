<?php

namespace App\Livewire\Concerns;

use App\Models\GitAccount;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitProviderException;
use App\Services\Git\GitProviders;

/**
 * The repository picker of Git dağıtımı and the Laravel forms: with a
 * connected GitHub, GitLab or Bitbucket account the repository and the
 * branch are chosen from lists instead of typed. The component says which
 * host it is picking from (pickingFrom()) and where the OAuth sign-in
 * comes back to (pickerReturnPath()); it has a $branch property.
 */
trait PicksGitRepository
{
    /** Full name of the repository picked from the list. */
    public string $pickedRepo = '';

    /** @var list<string> branches of the picked repository */
    public array $pickedBranches = [];

    /** Why the branches could not be read. */
    public ?string $branchError = null;

    /** The host being picked from, or null when the address is typed. */
    abstract protected function pickingFrom(): ?string;

    /** Where the OAuth sign-in returns to, so the form opens again. */
    abstract protected function pickerReturnPath(string $provider): string;

    /**
     * The signed-in user's account on $provider.
     */
    protected function gitAccount(string $provider): ?GitAccount
    {
        return GitAccount::where('user_id', auth()->id())->where('provider', $provider)->first();
    }

    /**
     * The hosts an administrator set up, by key => label.
     *
     * @return array<string, string>
     */
    protected function gitHosts(): array
    {
        return array_intersect_key(GitProviders::LABELS, app(GitProviders::class)->configured());
    }

    public function updatedPickedRepo(): void
    {
        $this->pickedBranches = [];
        $this->branchError = null;
        $provider = $this->pickingFrom();
        $account = $provider ? $this->gitAccount($provider) : null;
        if ($account === null || $this->pickedRepo === '') {
            return;
        }
        $accounts = app(GitAccounts::class);
        try {
            $repo = $accounts->repository($account, $this->pickedRepo);
            if ($repo === null) {
                $this->pickedRepo = '';

                return;
            }
            $this->pickedBranches = $accounts->branches($account, $repo['name']);
            $default = $repo['default_branch'];
            $this->branch = $default !== null && in_array($default, $this->pickedBranches, true) ? $default : ($this->pickedBranches[0] ?? $default ?? 'main');
        } catch (GitProviderException $e) {
            $this->branchError = $e->getMessage();
        }
    }

    /**
     * Reads the account's repositories again (a repository created a
     * minute ago is not in the kept list yet).
     */
    public function refreshRepositories(): void
    {
        $provider = $this->pickingFrom();
        $account = $provider ? $this->gitAccount($provider) : null;
        if ($account === null) {
            return;
        }
        try {
            app(GitAccounts::class)->repositories($account, fresh: true);
        } catch (GitProviderException) {
            // render() shows it.
        }
    }

    protected function resetPicker(): void
    {
        $this->pickedRepo = '';
        $this->pickedBranches = [];
        $this->branchError = null;
    }

    /**
     * The picked repository with its account, or null with an error on
     * the field when nothing (valid) is picked.
     *
     * @return array{account: GitAccount, repo: array{name: string, ssh_url: string, private: bool, default_branch: ?string, admin: bool}}|null
     */
    protected function pickedRepository(string $field = 'pickedRepo'): ?array
    {
        $provider = $this->pickingFrom();
        $account = $provider ? $this->gitAccount($provider) : null;
        if ($account === null) {
            $this->addError($field, __(':provider hesabınızı bağlayın ya da depo adresini elle yazın.', ['provider' => GitProviders::LABELS[$provider] ?? 'Git']));

            return null;
        }
        try {
            $repo = $this->pickedRepo === '' ? null : app(GitAccounts::class)->repository($account, $this->pickedRepo);
        } catch (GitProviderException $e) {
            $this->addError($field, $e->getMessage());

            return null;
        }
        if ($repo === null) {
            $this->addError($field, __('Listeden bir depo seçin.'));

            return null;
        }

        return ['account' => $account, 'repo' => $repo];
    }

    /**
     * What the picker partial shows: the account, its repositories or why
     * they could not be read.
     *
     * @return array{provider: string, label: string, account: ?GitAccount, repos: list<array<string, mixed>>, error: ?string, expired: bool, connect: string}|null
     */
    protected function picker(): ?array
    {
        $provider = $this->pickingFrom();
        if ($provider === null) {
            return null;
        }
        $account = $this->gitAccount($provider);
        $repos = [];
        $error = null;
        $expired = false;
        if ($account !== null) {
            try {
                $repos = app(GitAccounts::class)->repositories($account);
            } catch (GitProviderException $e) {
                $error = $e->getMessage();
                $expired = $e->expired;
            }
        }

        return [
            'provider' => $provider,
            'label' => GitProviders::LABELS[$provider],
            'account' => $account,
            'repos' => $repos,
            'error' => $error,
            'expired' => $expired,
            'connect' => route('git.connect', [$provider, 'return' => $this->pickerReturnPath($provider)]),
        ];
    }
}
