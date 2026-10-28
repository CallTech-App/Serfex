<?php

namespace App\Services\Git;

/**
 * GitHub through an OAuth App. Scopes: repo (private repositories and
 * their deploy keys) and admin:repo_hook (the push webhook).
 */
class GitHub extends GitProvider
{
    private const API = 'https://api.github.com';

    public function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function webUrl(): string
    {
        return 'https://github.com';
    }

    public function registerUrl(): string
    {
        return 'https://github.com/settings/applications/new';
    }

    public function authorizeUrl(string $redirect, string $state): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirect,
            'scope' => 'repo admin:repo_hook',
            'state' => $state,
            'allow_signup' => 'false',
        ]);
    }

    public function exchange(string $redirect, string $code): array
    {
        return $this->tokens($this->send(fn () => $this->http()->asForm()->post('https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $redirect,
        ])));
    }

    public function refresh(string $redirect, string $refreshToken): array
    {
        return $this->tokens($this->send(fn () => $this->http()->asForm()->post('https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->config['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ])));
    }

    public function user(string $token): array
    {
        $user = $this->send(fn () => $this->http($token)->get(self::API.'/user'));

        return ['id' => (string) ($user['id'] ?? ''), 'username' => (string) ($user['login'] ?? '')];
    }

    public function repositories(string $token): array
    {
        $repos = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $batch = $this->send(fn () => $this->http($token)->get(self::API.'/user/repos', [
                'per_page' => 100, 'page' => $page, 'sort' => 'pushed', 'affiliation' => 'owner,collaborator,organization_member',
            ]));
            foreach ($batch as $r) {
                if (! is_array($r) || ! is_string($r['full_name'] ?? null) || ! is_string($r['ssh_url'] ?? null) || ($r['archived'] ?? false)) {
                    continue;
                }
                $repos[] = [
                    'name' => $r['full_name'],
                    'ssh_url' => $r['ssh_url'],
                    'private' => (bool) ($r['private'] ?? false),
                    'default_branch' => is_string($r['default_branch'] ?? null) ? $r['default_branch'] : null,
                    'admin' => (bool) ($r['permissions']['admin'] ?? false),
                ];
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $repos;
    }

    public function branches(string $token, string $repository): array
    {
        $branches = $this->send(fn () => $this->http($token)->get(self::API."/repos/{$repository}/branches", ['per_page' => 100]));

        return array_values(array_filter(array_map(fn ($b) => is_array($b) && is_string($b['name'] ?? null) ? $b['name'] : null, $branches)));
    }

    public function addDeployKey(string $token, string $repository, string $title, string $publicKey): string
    {
        $key = $this->send(fn () => $this->http($token)->post(self::API."/repos/{$repository}/keys", [
            'title' => $title, 'key' => $publicKey, 'read_only' => true,
        ]));

        return (string) ($key['id'] ?? '');
    }

    public function removeDeployKey(string $token, string $repository, string $id): void
    {
        $this->send(fn () => $this->http($token)->delete(self::API."/repos/{$repository}/keys/".rawurlencode($id)));
    }

    public function addWebhook(string $token, string $repository, string $url, bool $pullRequests, bool $verifySsl): string
    {
        $hook = $this->send(fn () => $this->http($token)->post(self::API."/repos/{$repository}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => $pullRequests ? ['push', 'pull_request'] : ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'insecure_ssl' => $verifySsl ? '0' : '1'],
        ]));

        return (string) ($hook['id'] ?? '');
    }

    public function removeWebhook(string $token, string $repository, string $id): void
    {
        $this->send(fn () => $this->http($token)->delete(self::API."/repos/{$repository}/hooks/".rawurlencode($id)));
    }
}
