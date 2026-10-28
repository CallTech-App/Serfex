<?php

namespace App\Services\Git;

/**
 * Bitbucket Cloud through an OAuth consumer. Its permissions are set on
 * the consumer itself: Account read, Repositories admin (deploy keys) and
 * Webhooks read and write. Tokens last two hours and are refreshed.
 */
class Bitbucket extends GitProvider
{
    private const API = 'https://api.bitbucket.org/2.0';

    public function key(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function webUrl(): string
    {
        return 'https://bitbucket.org';
    }

    public function registerUrl(): string
    {
        return 'https://bitbucket.org/account/workspaces/';
    }

    public function authorizeUrl(string $redirect, string $state): string
    {
        return 'https://bitbucket.org/site/oauth2/authorize?'.http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    public function exchange(string $redirect, string $code): array
    {
        return $this->tokens($this->send(fn () => $this->http()->asForm()->withBasicAuth($this->clientId(), $this->config['client_secret'])
            ->post('https://bitbucket.org/site/oauth2/access_token', ['grant_type' => 'authorization_code', 'code' => $code])));
    }

    public function refresh(string $redirect, string $refreshToken): array
    {
        return $this->tokens($this->send(fn () => $this->http()->asForm()->withBasicAuth($this->clientId(), $this->config['client_secret'])
            ->post('https://bitbucket.org/site/oauth2/access_token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken])));
    }

    public function user(string $token): array
    {
        $user = $this->send(fn () => $this->http($token)->get(self::API.'/user'));

        return ['id' => (string) ($user['uuid'] ?? $user['account_id'] ?? ''), 'username' => (string) ($user['username'] ?? $user['nickname'] ?? '')];
    }

    public function repositories(string $token): array
    {
        $repos = [];
        $url = self::API.'/repositories?'.http_build_query(['role' => 'member', 'pagelen' => 100, 'sort' => '-updated_on']);
        for ($page = 1; $page <= self::MAX_PAGES && $url !== null; $page++) {
            $batch = $this->send(fn () => $this->http($token)->get($url));
            foreach ((array) ($batch['values'] ?? []) as $r) {
                $ssh = collect((array) ($r['links']['clone'] ?? []))->firstWhere('name', 'ssh')['href'] ?? null;
                if (! is_string($r['full_name'] ?? null) || ! is_string($ssh)) {
                    continue;
                }
                $repos[] = [
                    'name' => $r['full_name'],
                    'ssh_url' => $ssh,
                    'private' => (bool) ($r['is_private'] ?? true),
                    'default_branch' => is_string($r['mainbranch']['name'] ?? null) ? $r['mainbranch']['name'] : null,
                    // Bitbucket does not say here; adding the key tells.
                    'admin' => true,
                ];
            }
            $next = $batch['next'] ?? null;
            $url = is_string($next) && str_starts_with($next, self::API.'/') ? $next : null;
        }

        return $repos;
    }

    public function branches(string $token, string $repository): array
    {
        $branches = $this->send(fn () => $this->http($token)->get(self::API."/repositories/{$repository}/refs/branches", ['pagelen' => 100]));

        return array_values(array_filter(array_map(fn ($b) => is_array($b) && is_string($b['name'] ?? null) ? $b['name'] : null, (array) ($branches['values'] ?? []))));
    }

    public function addDeployKey(string $token, string $repository, string $title, string $publicKey): string
    {
        $key = $this->send(fn () => $this->http($token)->post(self::API."/repositories/{$repository}/deploy-keys", [
            'key' => $publicKey, 'label' => $title,
        ]));

        return (string) ($key['id'] ?? '');
    }

    public function removeDeployKey(string $token, string $repository, string $id): void
    {
        $this->send(fn () => $this->http($token)->delete(self::API."/repositories/{$repository}/deploy-keys/".rawurlencode($id)));
    }

    public function addWebhook(string $token, string $repository, string $url, bool $pullRequests, bool $verifySsl): string
    {
        $events = ['repo:push'];
        if ($pullRequests) {
            array_push($events, 'pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected');
        }
        $hook = $this->send(fn () => $this->http($token)->post(self::API."/repositories/{$repository}/hooks", [
            'description' => 'Serfex',
            'url' => $url,
            'active' => true,
            'events' => $events,
            'skip_cert_verification' => ! $verifySsl,
        ]));

        return (string) ($hook['uuid'] ?? '');
    }

    public function removeWebhook(string $token, string $repository, string $id): void
    {
        $this->send(fn () => $this->http($token)->delete(self::API."/repositories/{$repository}/hooks/".rawurlencode($id)));
    }
}
