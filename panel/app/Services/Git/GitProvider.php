<?php

namespace App\Services\Git;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One Git host Serfex signs in to with OAuth: the OAuth endpoints, and the
 * few API calls the repository picker needs (the user's repositories and
 * branches, a read-only deploy key and a push webhook on the picked one).
 *
 * Repositories are named by their full path ("owner/name",
 * "group/sub/name", "workspace/slug").
 */
abstract class GitProvider
{
    /** Pages of 100 repositories read at most. */
    protected const MAX_PAGES = 5;

    /**
     * @param  array{client_id: string, client_secret: string, url?: string}  $config
     */
    public function __construct(protected readonly array $config) {}

    abstract public function key(): string;

    abstract public function label(): string;

    /** The web address of the host, https://github.com or a GitLab server. */
    abstract public function webUrl(): string;

    /** Where an administrator creates the OAuth application. */
    abstract public function registerUrl(): string;

    abstract public function authorizeUrl(string $redirect, string $state): string;

    /**
     * The tokens for the code the host sent back.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    abstract public function exchange(string $redirect, string $code): array;

    /**
     * New tokens from a refresh token.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    abstract public function refresh(string $redirect, string $refreshToken): array;

    /**
     * @return array{id: string, username: string}
     */
    abstract public function user(string $token): array;

    /**
     * The repositories the account may use, most recently changed first.
     *
     * @return list<array{name: string, ssh_url: string, private: bool, default_branch: ?string, admin: bool}>
     */
    abstract public function repositories(string $token): array;

    /**
     * @return list<string>
     */
    abstract public function branches(?string $token, string $repository): array;

    /** Adds a read-only deploy key; returns its id. */
    abstract public function addDeployKey(string $token, string $repository, string $title, string $publicKey): string;

    abstract public function removeDeployKey(string $token, string $repository, string $id): void;

    /**
     * Adds a webhook for pushes (and pull requests when $pullRequests),
     * signed with $secret; returns its id.
     */
    abstract public function addWebhook(string $token, string $repository, string $url, string $secret, bool $pullRequests, bool $verifySsl): string;

    abstract public function removeWebhook(string $token, string $repository, string $id): void;

    /**
     * The commit a branch or tag points at. Without a token only public
     * repositories can be read.
     *
     * @return array{sha: string, message: string}
     */
    abstract public function commit(?string $token, string $repository, string $ref): array;

    /**
     * The repository's tags, newest first.
     *
     * @return list<string>
     */
    abstract public function tags(?string $token, string $repository): array;

    /**
     * The newest published release: its tag and the files attached to it
     * (url downloads one with the token and Accept: application/octet-stream).
     *
     * @return array{tag: string, assets: list<array{name: string, url: string, size: int, sha256: ?string}>}
     */
    public function latestRelease(?string $token, string $repository): array
    {
        throw new GitProviderException(__(':provider sürüm paketlerini listelemiyor; paketin adresini yazın.', ['provider' => $this->label()]));
    }

    public function clientId(): string
    {
        return $this->config['client_id'];
    }

    protected function http(?string $token = null): PendingRequest
    {
        $request = Http::timeout(15)->connectTimeout(5)->acceptJson()->withUserAgent('Serfex');

        return $token !== null ? $request->withToken($token) : $request;
    }

    /**
     * Sends the request and returns the decoded JSON, or throws a
     * GitProviderException that says what went wrong.
     *
     * @return array<mixed>
     */
    protected function send(callable $request): array
    {
        try {
            /** @var Response $response */
            $response = $request();
        } catch (ConnectionException) {
            throw new GitProviderException(__(':host adresine ulaşılamadı.', ['host' => parse_url($this->webUrl(), PHP_URL_HOST)]));
        }
        if ($response->successful()) {
            return (array) ($response->json() ?? []);
        }
        $status = $response->status();
        $detail = $this->errorDetail($response);
        if ($status === 401) {
            throw new GitProviderException(__(':provider oturumunun süresi dolmuş ya da izni geri alınmış; hesabı yeniden bağlayın.', ['provider' => $this->label()]), 401, true);
        }

        throw new GitProviderException(match (true) {
            $status === 403, $status === 404 => __(':provider bu işlem için izin vermedi (bu depoda yönetici yetkiniz olmayabilir).', ['provider' => $this->label()]).($detail ? " ({$detail})" : ''),
            default => __(':provider isteği reddetti: :reason', ['provider' => $this->label(), 'reason' => $detail ?: "HTTP {$status}"]),
        }, $status);
    }

    private function errorDetail(Response $response): string
    {
        $json = $response->json();
        $message = match (true) {
            ! is_array($json) => null,
            is_string($json['message'] ?? null) => $json['message'],
            is_string($json['error_description'] ?? null) => $json['error_description'],
            is_string($json['error']['message'] ?? null) => $json['error']['message'],
            is_array($json['message'] ?? null) => json_encode($json['message']),
            is_string($json['error'] ?? null) => $json['error'],
            default => null,
        };
        if (isset($json['errors'][0]['message']) && is_string($json['errors'][0]['message'])) {
            $message .= ': '.$json['errors'][0]['message'];
        }

        return mb_strimwidth((string) $message, 0, 200, '…');
    }

    /**
     * @param  array<mixed>  $json
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    protected function tokens(array $json): array
    {
        if (! is_string($json['access_token'] ?? null) || $json['access_token'] === '') {
            $reason = is_string($json['error_description'] ?? null) ? $json['error_description'] : (is_string($json['error'] ?? null) ? $json['error'] : 'no token');

            throw new GitProviderException(__(':provider oturum açmayı tamamlamadı: :reason', ['provider' => $this->label(), 'reason' => $reason]));
        }

        return [
            'access_token' => $json['access_token'],
            'refresh_token' => is_string($json['refresh_token'] ?? null) ? $json['refresh_token'] : null,
            'expires_in' => is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : null,
        ];
    }
}
