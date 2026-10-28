<?php

namespace App\Services\Git;

use App\Models\GitAccount;
use App\Models\GitRepository;
use App\Models\LaravelApp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Connected Git accounts: signing in with OAuth, keeping the token fresh,
 * listing repositories and branches for the picker, and adding (and later
 * removing) Serfex's deploy key and webhook on the repository picked.
 */
class GitAccounts
{
    /** Minutes a repository list is kept before it is read again. */
    public const LIST_MINUTES = 5;

    public function __construct(private readonly GitProviders $providers) {}

    /**
     * Saves the account the host signed in after the user allowed access.
     */
    public function connect(User $user, GitProvider $provider, string $code): GitAccount
    {
        $tokens = $provider->exchange($this->providers->callbackUrl($provider->key()), $code);
        $remote = $provider->user($tokens['access_token']);
        $account = GitAccount::updateOrCreate(['user_id' => $user->id, 'provider' => $provider->key()], [
            'remote_id' => $remote['id'],
            'username' => $remote['username'] ?: $remote['id'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_in'] ? now()->addSeconds($tokens['expires_in']) : null,
        ]);
        Cache::forget($this->listKey($account));

        return $account;
    }

    /**
     * The account's provider, or null when an administrator has turned it
     * off since.
     */
    public function provider(GitAccount $account): ?GitProvider
    {
        return $this->providers->get($account->provider);
    }

    /**
     * The access token, refreshed first when it is about to run out.
     *
     * @throws GitProviderException
     */
    public function token(GitAccount $account): string
    {
        if ($account->expiresSoon()) {
            $provider = $this->required($account);
            if ($account->refresh_token === null) {
                throw new GitProviderException(__(':provider oturumunun süresi dolmuş ya da izni geri alınmış; hesabı yeniden bağlayın.', ['provider' => $provider->label()]), 401, true);
            }
            try {
                $tokens = $provider->refresh($this->providers->callbackUrl($provider->key()), $account->refresh_token);
            } catch (GitProviderException $e) {
                throw new GitProviderException(__(':provider oturumunun süresi dolmuş ya da izni geri alınmış; hesabı yeniden bağlayın.', ['provider' => $provider->label()]), $e->status, true);
            }
            $account->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
                'expires_at' => $tokens['expires_in'] ? now()->addSeconds($tokens['expires_in']) : null,
            ]);
        }

        return $account->access_token;
    }

    /**
     * The repositories of the account, kept for a few minutes.
     *
     * @return list<array{name: string, ssh_url: string, private: bool, default_branch: ?string, admin: bool}>
     *
     * @throws GitProviderException
     */
    public function repositories(GitAccount $account, bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget($this->listKey($account));
        }

        return Cache::remember($this->listKey($account), now()->addMinutes(self::LIST_MINUTES),
            fn () => $this->required($account)->repositories($this->token($account)));
    }

    /**
     * One repository of the account's list, by its full name.
     *
     * @return array{name: string, ssh_url: string, private: bool, default_branch: ?string, admin: bool}|null
     *
     * @throws GitProviderException
     */
    public function repository(GitAccount $account, string $name): ?array
    {
        foreach ($this->repositories($account) as $repo) {
            if ($repo['name'] === $name) {
                return $repo;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     *
     * @throws GitProviderException
     */
    public function branches(GitAccount $account, string $repository): array
    {
        return $this->required($account)->branches($this->token($account), $repository);
    }

    /**
     * Adds the site's deploy key and the panel's webhook to the picked
     * repository and remembers both on $model, so they can be removed
     * again. Returns what could not be done; the caller shows the key to
     * add by hand when the key was refused.
     *
     * @return array{key: bool, hook: bool, errors: list<string>}
     */
    public function link(GitRepository|LaravelApp $model, GitAccount $account, string $repository, string $publicKey, string $hookUrl, bool $pullRequests): array
    {
        $model->update(['git_account_id' => $account->id, 'remote_repository' => $repository, 'remote_key_id' => null, 'remote_hook_id' => null]);
        $errors = [];
        $key = false;
        try {
            $model->update(['remote_key_id' => $this->addDeployKey($account, $repository, $this->keyTitle($model->site->domain), $publicKey)]);
            $key = true;
        } catch (GitProviderException $e) {
            $errors[] = __('Dağıtım anahtarı eklenemedi: :reason', ['reason' => $e->getMessage()]);
        }
        $hook = $this->linkWebhook($model, $hookUrl, $pullRequests);
        if ($hook !== null) {
            $errors[] = $hook;
        }

        return ['key' => $key, 'hook' => $hook === null, 'errors' => $errors];
    }

    /**
     * Adds the panel's webhook to the repository $model was picked from;
     * returns why it could not, or null.
     */
    public function linkWebhook(GitRepository|LaravelApp $model, string $hookUrl, bool $pullRequests): ?string
    {
        $account = $model->gitAccount()->first();
        if ($account === null || $model->remote_repository === null) {
            return null;
        }
        try {
            $model->update(['remote_hook_id' => $this->addWebhook($account, $model->remote_repository, $hookUrl, $pullRequests)]);

            return null;
        } catch (GitProviderException $e) {
            return __('Webhook eklenemedi: :reason', ['reason' => $e->getMessage()]);
        }
    }

    /**
     * Adds a read-only deploy key; returns its id on the host.
     *
     * @throws GitProviderException
     */
    public function addDeployKey(GitAccount $account, string $repository, string $title, string $publicKey): string
    {
        return $this->required($account)->addDeployKey($this->token($account), $repository, $title, trim($publicKey));
    }

    /**
     * @throws GitProviderException
     */
    public function addWebhook(GitAccount $account, string $repository, string $url, bool $pullRequests): string
    {
        return $this->required($account)->addWebhook($this->token($account), $repository, $url, $pullRequests, $this->providers->panelHasTrustedCertificate());
    }

    /**
     * The name the deploy key gets on the host.
     */
    public function keyTitle(string $domain): string
    {
        return 'Serfex · '.$domain;
    }

    /**
     * Removes the deploy key and webhook Serfex added, as far as the host
     * lets it; the repository itself is never touched. Nothing is thrown:
     * what is left behind does no harm (the key's private half is gone).
     */
    public function unlink(GitRepository|LaravelApp $model): void
    {
        $account = $model->gitAccount()->first();
        if ($account !== null && $model->remote_repository !== null && ($provider = $this->provider($account)) !== null) {
            try {
                $token = $this->token($account);
                if ($model->remote_key_id) {
                    $this->quietly(fn () => $provider->removeDeployKey($token, $model->remote_repository, $model->remote_key_id));
                }
                if ($model->remote_hook_id) {
                    $this->quietly(fn () => $provider->removeWebhook($token, $model->remote_repository, $model->remote_hook_id));
                }
            } catch (GitProviderException) {
                // The account no longer signs in; leave them.
            }
        }
        if ($model->exists) {
            $model->update(['git_account_id' => null, 'remote_repository' => null, 'remote_key_id' => null, 'remote_hook_id' => null]);
        }
    }

    /**
     * Forgets the account. Repositories picked with it keep deploying
     * (they use their deploy key), but Serfex can no longer remove that
     * key and webhook for you.
     */
    public function disconnect(GitAccount $account): void
    {
        Cache::forget($this->listKey($account));
        $account->delete();
    }

    private function required(GitAccount $account): GitProvider
    {
        return $this->provider($account) ?? throw new GitProviderException(__(':provider bağlantısı bu sunucuda kapatılmış.', ['provider' => GitProviders::LABELS[$account->provider] ?? $account->provider]));
    }

    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (Throwable) {
            // Already removed, or no longer allowed.
        }
    }

    private function listKey(GitAccount $account): string
    {
        return 'serfex.git.repos.'.$account->id;
    }
}
