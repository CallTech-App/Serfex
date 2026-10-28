<?php

namespace App\Services\Git;

/**
 * GitLab.com or a GitLab server of your own, through an OAuth application
 * with the api scope (deploy keys and webhooks need it). Tokens last two
 * hours and are refreshed.
 */
class GitLab extends GitProvider
{
    public const DEFAULT_URL = 'https://gitlab.com';

    /** Maintainer: may add deploy keys and webhooks. */
    private const MAINTAINER = 40;

    public function key(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function webUrl(): string
    {
        return rtrim($this->config['url'] ?? '', '/') ?: self::DEFAULT_URL;
    }

    public function registerUrl(): string
    {
        return $this->webUrl().'/-/user_settings/applications';
    }

    private function api(string $path): string
    {
        return $this->webUrl().'/api/v4'.$path;
    }

    private function project(string $repository): string
    {
        return '/projects/'.rawurlencode($repository);
    }

    public function authorizeUrl(string $redirect, string $state): string
    {
        return $this->webUrl().'/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'api',
            'state' => $state,
        ]);
    }

    public function exchange(string $redirect, string $code): array
    {
        return $this->tokens($this->send(fn () => $this->http()->asForm()->post($this->webUrl().'/oauth/token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect,
        ])));
    }

    public function refresh(string $redirect, string $refreshToken): array
    {
        return $this->tokens($this->send(fn () => $this->http()->asForm()->post($this->webUrl().'/oauth/token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $redirect,
        ])));
    }

    public function user(string $token): array
    {
        $user = $this->send(fn () => $this->http($token)->get($this->api('/user')));

        return ['id' => (string) ($user['id'] ?? ''), 'username' => (string) ($user['username'] ?? '')];
    }

    public function repositories(string $token): array
    {
        $repos = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $batch = $this->send(fn () => $this->http($token)->get($this->api('/projects'), [
                'membership' => 'true', 'archived' => 'false', 'order_by' => 'last_activity_at', 'per_page' => 100, 'page' => $page,
            ]));
            foreach ($batch as $p) {
                if (! is_array($p) || ! is_string($p['path_with_namespace'] ?? null) || ! is_string($p['ssh_url_to_repo'] ?? null)) {
                    continue;
                }
                $access = max((int) ($p['permissions']['project_access']['access_level'] ?? 0), (int) ($p['permissions']['group_access']['access_level'] ?? 0));
                $repos[] = [
                    'name' => $p['path_with_namespace'],
                    'ssh_url' => $p['ssh_url_to_repo'],
                    'private' => ($p['visibility'] ?? 'private') !== 'public',
                    'default_branch' => is_string($p['default_branch'] ?? null) ? $p['default_branch'] : null,
                    'admin' => $access >= self::MAINTAINER,
                ];
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $repos;
    }

    public function branches(?string $token, string $repository): array
    {
        $branches = $this->send(fn () => $this->http($token)->get($this->api($this->project($repository).'/repository/branches'), ['per_page' => 100]));

        return array_values(array_filter(array_map(fn ($b) => is_array($b) && is_string($b['name'] ?? null) ? $b['name'] : null, $branches)));
    }

    public function addDeployKey(string $token, string $repository, string $title, string $publicKey): string
    {
        $key = $this->send(fn () => $this->http($token)->post($this->api($this->project($repository).'/deploy_keys'), [
            'title' => $title, 'key' => $publicKey, 'can_push' => false,
        ]));

        return (string) ($key['id'] ?? '');
    }

    public function removeDeployKey(string $token, string $repository, string $id): void
    {
        $this->send(fn () => $this->http($token)->delete($this->api($this->project($repository).'/deploy_keys/'.rawurlencode($id))));
    }

    public function addWebhook(string $token, string $repository, string $url, bool $pullRequests, bool $verifySsl): string
    {
        $hook = $this->send(fn () => $this->http($token)->post($this->api($this->project($repository).'/hooks'), [
            'url' => $url,
            'push_events' => true,
            'merge_requests_events' => $pullRequests,
            'enable_ssl_verification' => $verifySsl,
        ]));

        return (string) ($hook['id'] ?? '');
    }

    public function removeWebhook(string $token, string $repository, string $id): void
    {
        $this->send(fn () => $this->http($token)->delete($this->api($this->project($repository).'/hooks/'.rawurlencode($id))));
    }

    public function commit(?string $token, string $repository, string $ref): array
    {
        $commit = $this->send(fn () => $this->http($token)->get($this->api($this->project($repository).'/repository/commits/'.rawurlencode($ref))));

        return ['sha' => (string) ($commit['id'] ?? ''), 'message' => (string) ($commit['title'] ?? '')];
    }

    public function tags(?string $token, string $repository): array
    {
        $tags = $this->send(fn () => $this->http($token)->get($this->api($this->project($repository).'/repository/tags'), ['per_page' => 100, 'order_by' => 'updated']));

        return array_values(array_filter(array_map(fn ($t) => is_array($t) && is_string($t['name'] ?? null) ? $t['name'] : null, $tags)));
    }
}
