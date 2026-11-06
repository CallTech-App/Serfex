<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare's API as far as the panel uses it, kept in memory: zones with
 * their records, SSL mode and DNSSEC. Every request is recorded.
 */
class FakeCloudflare
{
    /** @var array<string, array<string, mixed>> zone id => zone */
    public array $zones = [];

    /** @var array<string, array<string, array<string, mixed>>> zone id => record id => record */
    public array $records = [];

    /** @var array<string, string> */
    public array $ssl = [];

    /** @var list<string> "METHOD path" */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $purges = [];

    public string $tokenStatus = 'active';

    public bool $tokenValid = true;

    /** Requests refused with this error, by "METHOD path" prefix. @var array<string, array{0: int, 1: int, 2: string}> */
    public array $refuse = [];

    private int $next = 1;

    public static function install(): self
    {
        $cf = new self;
        Http::fake(['api.cloudflare.com/*' => fn (Request $r) => $cf->handle($r)]);

        return $cf;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function addZone(string $name, string $status = 'active', array $records = [], string $id = ''): string
    {
        $id = $id !== '' ? $id : str_pad(dechex(count($this->zones) + 1), 32, 'a', STR_PAD_LEFT);
        $this->zones[$id] = [
            'id' => $id,
            'name' => $name,
            'status' => $status,
            'name_servers' => ['ada.ns.cloudflare.com', 'rex.ns.cloudflare.com'],
            'original_name_servers' => ['ns1.eskihosting.com', 'ns2.eskihosting.com'],
            'account' => ['id' => 'acc1', 'name' => 'CallTech Ana Hesap'],
        ];
        $this->records[$id] = [];
        $this->ssl[$id] = 'full';
        foreach ($records as $r) {
            $this->put($id, $r);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public function put(string $zoneId, array $r, ?string $id = null): array
    {
        $id ??= 'rec'.$this->next++;
        $r = ['id' => $id, 'ttl' => 1, 'proxied' => false, ...$r, 'modified_on' => $r['modified_on'] ?? now()->subHour()->toIso8601String()];
        if (isset($r['name']) && ! str_contains((string) $r['name'], '.')) {
            $r['name'] = $r['name'] === '@' ? $this->zones[$zoneId]['name'] : $r['name'].'.'.$this->zones[$zoneId]['name'];
        }
        $this->records[$zoneId][$id] = $r;

        return $r;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function find(string $zoneId, string $type, ?string $name = null): array
    {
        return array_values(array_filter($this->records[$zoneId] ?? [], fn ($r) => $r['type'] === $type && ($name === null || $r['name'] === $name)));
    }

    public function count(string $method, string $pathPart = ''): int
    {
        return count(array_filter($this->requests, fn ($r) => str_starts_with($r, $method.' ') && str_contains($r, $pathPart)));
    }

    private function handle(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $path = substr($path, strlen('/client/v4'));
        $method = $request->method();
        $this->requests[] = $method.' '.$path;
        foreach ($this->refuse as $prefix => [$status, $code, $message]) {
            if (str_starts_with($method.' '.$path, $prefix)) {
                return $this->error($status, $code, $message);
            }
        }
        if (! $this->tokenValid) {
            return $this->error(403, 10000, 'Authentication error');
        }
        $body = $request->data();

        if ($path === '/user/tokens/verify') {
            return $this->ok(['id' => 'tok', 'status' => $this->tokenStatus]);
        }
        if ($path === '/accounts') {
            return $this->ok([['id' => 'acc1', 'name' => 'CallTech Ana Hesap']]);
        }
        if ($path === '/zones' && $method === 'GET') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $list = array_values(array_filter($this->zones, fn ($z) => ! isset($q['name']) || $z['name'] === $q['name']));

            return $this->ok($list, ['total_count' => count($list), 'total_pages' => 1]);
        }
        if ($path === '/zones' && $method === 'POST') {
            $id = $this->addZone((string) $body['name'], 'pending');

            return $this->ok($this->zones[$id]);
        }
        if (! preg_match('#^/zones/([0-9a-f]+)(/.*)?$#', $path, $m) || ! isset($this->zones[$m[1]])) {
            return $this->error(404, 7003, 'Could not route');
        }
        $zoneId = $m[1];
        $rest = $m[2] ?? '';

        return match (true) {
            $rest === '' => $this->ok($this->zones[$zoneId]),
            $rest === '/activation_check' => $this->ok(['id' => $zoneId]),
            $rest === '/settings/ssl' && $method === 'GET' => $this->ok(['id' => 'ssl', 'value' => $this->ssl[$zoneId]]),
            $rest === '/settings/ssl' => $this->ok(['id' => 'ssl', 'value' => $this->ssl[$zoneId] = (string) $body['value']]),
            $rest === '/dnssec' && $method === 'GET' => $this->ok(['status' => 'disabled']),
            $rest === '/dnssec' => $this->ok(['status' => $body['status'] === 'active' ? 'pending' : 'disabled', 'ds' => $body['status'] === 'active' ? 'example.com. 3600 IN DS 2371 13 2 ABCD' : null]),
            $rest === '/purge_cache' => $this->purge($body),
            $rest === '/dns_records' && $method === 'GET' => $this->ok(array_values($this->records[$zoneId]), ['total_count' => count($this->records[$zoneId]), 'total_pages' => 1]),
            $rest === '/dns_records' && $method === 'POST' => $this->ok($this->put($zoneId, [...$body, 'modified_on' => now()->toIso8601String()])),
            str_starts_with($rest, '/dns_records/') => $this->record($zoneId, substr($rest, 13), $method, $body),
            default => $this->error(404, 7003, 'Could not route'),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function record(string $zoneId, string $id, string $method, array $body): mixed
    {
        if (! isset($this->records[$zoneId][$id])) {
            return $this->error(404, 81044, 'Record does not exist.');
        }
        if ($method === 'DELETE') {
            unset($this->records[$zoneId][$id]);

            return $this->ok(['id' => $id]);
        }

        return $this->ok($this->put($zoneId, [...$body, 'modified_on' => now()->toIso8601String()], $id));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function purge(array $body): mixed
    {
        $this->purges[] = $body;

        return $this->ok(['id' => 'purge']);
    }

    private function ok(mixed $result, ?array $info = null): mixed
    {
        return Http::response(array_filter(['success' => true, 'errors' => [], 'result' => $result, 'result_info' => $info], fn ($v) => $v !== null));
    }

    private function error(int $status, int $code, string $message): mixed
    {
        return Http::response(['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'result' => null], $status);
    }
}
