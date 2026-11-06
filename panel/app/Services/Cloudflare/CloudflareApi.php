<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare's v4 API, the calls the panel makes with one API token:
 * zones, DNS records, the SSL mode, DNSSEC and cache purges.
 *
 * Failures throw CloudflareException with Cloudflare's own message, which
 * is shown to people as it is ("Authentication error (10000)").
 */
class CloudflareApi
{
    public const BASE = 'https://api.cloudflare.com/client/v4';

    /** Cloudflare's API, or another one for development (SERFEX_CLOUDFLARE_API). */
    private readonly string $base;

    public function __construct(private readonly string $token)
    {
        $this->base = rtrim((string) (config('serfex.cloudflare.api') ?: self::BASE), '/');
    }

    public static function for(string $token): self
    {
        return new self($token);
    }

    /**
     * The token's status ("active", "disabled", "expired").
     *
     * @throws CloudflareException
     */
    public function verify(): string
    {
        return (string) ($this->get('/user/tokens/verify')['status'] ?? '');
    }

    /**
     * The accounts the token sees.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws CloudflareException
     */
    public function accounts(): array
    {
        return array_map(fn ($a) => ['id' => (string) $a['id'], 'name' => (string) $a['name']], $this->all('/accounts'));
    }

    /**
     * Zones the token sees, optionally only $name.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CloudflareException
     */
    public function zones(?string $name = null): array
    {
        return $this->all('/zones', array_filter(['name' => $name]));
    }

    /**
     * How many zones the token sees.
     *
     * @throws CloudflareException
     */
    public function zoneCount(): int
    {
        $res = $this->send(fn () => $this->http()->get($this->base.'/zones', ['per_page' => 5, 'page' => 1]));

        return (int) ($res['result_info']['total_count'] ?? count($res['result'] ?? []));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function zone(string $id): array
    {
        return $this->get('/zones/'.$id);
    }

    /**
     * Add a zone to the account (needs Zone → Zone → Edit).
     *
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function createZone(string $accountId, string $name): array
    {
        return $this->result(fn () => $this->http()->post($this->base.'/zones', [
            'name' => $name,
            'account' => ['id' => $accountId],
            'type' => 'full',
        ]));
    }

    /**
     * Ask Cloudflare to look at the registrar's name servers again.
     *
     * @throws CloudflareException
     */
    public function activationCheck(string $zoneId): void
    {
        $this->result(fn () => $this->http()->put($this->base.'/zones/'.$zoneId.'/activation_check'));
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws CloudflareException
     */
    public function records(string $zoneId): array
    {
        return $this->all('/zones/'.$zoneId.'/dns_records');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function createRecord(string $zoneId, array $record): array
    {
        return $this->result(fn () => $this->http()->post($this->base.'/zones/'.$zoneId.'/dns_records', $record));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function updateRecord(string $zoneId, string $id, array $record): array
    {
        return $this->result(fn () => $this->http()->put($this->base.'/zones/'.$zoneId.'/dns_records/'.$id, $record));
    }

    /**
     * @throws CloudflareException
     */
    public function deleteRecord(string $zoneId, string $id): void
    {
        try {
            $this->result(fn () => $this->http()->delete($this->base.'/zones/'.$zoneId.'/dns_records/'.$id));
        } catch (CloudflareException $e) {
            // Gone already is what was wanted.
            if ($e->status !== 404 && $e->getCode() !== 81044) {
                throw $e;
            }
        }
    }

    /**
     * The zone's SSL/TLS mode: off, flexible, full or strict.
     *
     * @throws CloudflareException
     */
    public function sslMode(string $zoneId): string
    {
        return (string) ($this->get('/zones/'.$zoneId.'/settings/ssl')['value'] ?? '');
    }

    /**
     * @throws CloudflareException
     */
    public function setSslMode(string $zoneId, string $mode): void
    {
        $this->result(fn () => $this->http()->patch($this->base.'/zones/'.$zoneId.'/settings/ssl', ['value' => $mode]));
    }

    /**
     * @return array<string, mixed> status (active, pending, disabled, ...), ds, digest, ...
     *
     * @throws CloudflareException
     */
    public function dnssec(string $zoneId): array
    {
        return $this->get('/zones/'.$zoneId.'/dnssec');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function setDnssec(string $zoneId, bool $on): array
    {
        return $this->result(fn () => $this->http()->patch($this->base.'/zones/'.$zoneId.'/dnssec', ['status' => $on ? 'active' : 'disabled']));
    }

    /**
     * Purge the zone's cache: everything, or only the pages of $hosts.
     *
     * @param  list<string>  $hosts
     *
     * @throws CloudflareException
     */
    public function purge(string $zoneId, array $hosts = []): void
    {
        $body = $hosts === [] ? ['purge_everything' => true] : ['hosts' => array_values($hosts)];
        $this->result(fn () => $this->http()->post($this->base.'/zones/'.$zoneId.'/purge_cache', $body));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    private function get(string $path, array $query = []): array
    {
        return $this->result(fn () => $this->http()->get($this->base.$path, $query));
    }

    /**
     * Every page of a list.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws CloudflareException
     */
    private function all(string $path, array $query = []): array
    {
        $out = [];
        for ($page = 1; $page <= 50; $page++) {
            $res = $this->send(fn () => $this->http()->get($this->base.$path, [...$query, 'per_page' => $path === '/accounts' ? 50 : 500, 'page' => $page]));
            foreach ($res['result'] ?? [] as $row) {
                $out[] = $row;
            }
            $pages = (int) ($res['result_info']['total_pages'] ?? 1);
            if ($page >= $pages || ($res['result'] ?? []) === []) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    private function result(callable $call): array
    {
        $result = $this->send($call)['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new CloudflareException(__('Cloudflare\'e ulaşılamadı: :reason', ['reason' => $e->getMessage()]), 0, 0);
        }
        $body = $response->json();
        if (! is_array($body) || ! ($body['success'] ?? false)) {
            $error = $body['errors'][0] ?? null;
            $message = $error !== null
                ? trim(($error['message'] ?? '').' ('.($error['code'] ?? 0).')')
                : 'HTTP '.$response->status();

            throw new CloudflareException('Cloudflare: '.$message, (int) ($error['code'] ?? 0), $response->status());
        }

        return $body;
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->asJson()->timeout(20)->connectTimeout(8);
    }
}
