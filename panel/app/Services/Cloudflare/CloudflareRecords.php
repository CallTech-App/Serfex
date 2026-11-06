<?php

namespace App\Services\Cloudflare;

use App\Models\DnsRecord;
use App\Support\DnsRecords;

/**
 * Turns records between the panel's form (relative names, absolute host
 * names with a trailing dot, priority apart) and Cloudflare's (full names,
 * no trailing dot, SRV and CAA in a data object).
 */
class CloudflareRecords
{
    /** Cloudflare's "automatic" TTL; proxied records always have it. */
    public const AUTO_TTL = 1;

    /**
     * A Cloudflare record in the panel's form, or null for a type the
     * panel does not keep (HTTPS, SVCB, ...), which is left alone there.
     *
     * @param  array<string, mixed>  $cf
     * @return array{name: string, type: string, content: string, priority: ?int, ttl: int, proxied: ?bool}|null
     */
    public static function fromCloudflare(array $cf, string $zone, int $defaultTtl): ?array
    {
        $type = strtoupper((string) ($cf['type'] ?? ''));
        if (! in_array($type, DnsRecords::TYPES, true)) {
            return null;
        }
        $name = DnsRecords::normalizeName((string) ($cf['name'] ?? ''), $zone);
        if ($name === null || ($type === 'NS' && $name === '@')) {
            return null;
        }
        $data = is_array($cf['data'] ?? null) ? $cf['data'] : [];
        $content = trim((string) ($cf['content'] ?? ''));
        $priority = null;
        switch ($type) {
            case 'CNAME':
            case 'NS':
            case 'MX':
                $content = $content === '.' ? '.' : rtrim(mb_strtolower($content), '.').'.';
                if ($type === 'MX') {
                    $priority = (int) ($cf['priority'] ?? 0);
                }
                break;
            case 'AAAA':
                $packed = @inet_pton($content);
                $content = $packed !== false ? (string) inet_ntop($packed) : $content;
                break;
            case 'TXT':
                $content = self::unquote($content);
                break;
            case 'SRV':
                if ($data !== []) {
                    $priority = (int) ($data['priority'] ?? 0);
                    $target = (string) ($data['target'] ?? '.');
                    $content = (int) ($data['weight'] ?? 0).' '.(int) ($data['port'] ?? 0).' '.($target === '.' ? '.' : rtrim(mb_strtolower($target), '.').'.');
                } else {
                    $priority = (int) ($cf['priority'] ?? 0);
                    $parts = preg_split('/\s+/', $content) ?: [];
                    if (count($parts) === 4) {
                        // "priority weight port target"
                        $priority = (int) array_shift($parts);
                    }
                    if (count($parts) !== 3) {
                        return null;
                    }
                    $content = (int) $parts[0].' '.(int) $parts[1].' '.($parts[2] === '.' ? '.' : rtrim(mb_strtolower($parts[2]), '.').'.');
                }
                break;
            case 'CAA':
                if ($data !== []) {
                    $content = (int) ($data['flags'] ?? 0).' '.strtolower((string) ($data['tag'] ?? 'issue')).' "'.trim((string) ($data['value'] ?? ''), '"').'"';
                }
                break;
        }
        $ttl = (int) ($cf['ttl'] ?? self::AUTO_TTL);

        return [
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'priority' => $priority,
            'ttl' => $ttl === self::AUTO_TTL ? $defaultTtl : max(DnsRecords::MIN_TTL, min(DnsRecords::MAX_TTL, $ttl)),
            'proxied' => in_array($type, DnsRecord::PROXIABLE, true) ? (bool) ($cf['proxied'] ?? false) : null,
        ];
    }

    /**
     * The body Cloudflare takes for a panel record.
     *
     * @return array<string, mixed>
     */
    public static function toCloudflare(DnsRecord $r, string $zone): array
    {
        $proxied = $r->canBeProxied() && (bool) $r->proxied;
        $body = [
            'type' => $r->type,
            'name' => DnsRecords::fqdn($r->name, $zone),
            'ttl' => $proxied ? self::AUTO_TTL : $r->ttl,
        ];
        if ($r->canBeProxied()) {
            $body['proxied'] = $proxied;
        }
        switch ($r->type) {
            case 'CNAME':
            case 'NS':
            case 'MX':
                $body['content'] = $r->content === '.' ? '.' : rtrim($r->content, '.');
                if ($r->type === 'MX') {
                    $body['priority'] = $r->priority ?? 0;
                }
                break;
            case 'SRV':
                [$weight, $port, $target] = array_pad(preg_split('/\s+/', $r->content) ?: [], 3, '.');
                $body['data'] = [
                    'priority' => $r->priority ?? 0,
                    'weight' => (int) $weight,
                    'port' => (int) $port,
                    'target' => $target === '.' ? '.' : rtrim($target, '.'),
                ];
                break;
            case 'CAA':
                if (preg_match('/^(\d+)\s+(\S+)\s+"?(.*?)"?$/', $r->content, $m)) {
                    $body['data'] = ['flags' => (int) $m[1], 'tag' => $m[2], 'value' => $m[3]];
                } else {
                    $body['content'] = $r->content;
                }
                break;
            default:
                $body['content'] = $r->content;
        }

        return $body;
    }

    /**
     * What makes two records the same, whichever side they are on.
     *
     * @param  array{name: string, type: string, content: string, priority: ?int}|DnsRecord  $r
     */
    public static function identity(array|DnsRecord $r): string
    {
        $r = $r instanceof DnsRecord ? $r->toRecord() : $r;
        $content = in_array($r['type'], ['CNAME', 'NS', 'MX', 'SRV'], true) ? mb_strtolower($r['content']) : $r['content'];

        return $r['name'].'|'.$r['type'].'|'.$content.'|'.($r['priority'] ?? '');
    }

    /**
     * A fingerprint of everything Serfex writes, to tell later whether the
     * panel's copy changed since the last sync.
     */
    public static function hash(DnsRecord $r): string
    {
        return sha1(json_encode([$r->name, $r->type, $r->content, $r->priority, $r->ttl, $r->canBeProxied() ? (bool) $r->proxied : null]));
    }

    /**
     * TXT content without the quotes Cloudflare may show it in: "a" "b"
     * is the one value ab.
     */
    public static function unquote(string $content): string
    {
        if (! str_starts_with($content, '"') || ! str_ends_with($content, '"') || strlen($content) < 2) {
            return $content;
        }
        if (! preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"\s*/', $content, $m, PREG_SET_ORDER)) {
            return $content;
        }
        $joined = implode('', array_map(fn ($x) => $x[0], $m));
        if (trim($joined) !== trim($content)) {
            return $content;
        }

        return (string) preg_replace('/\\\\(.)/s', '$1', implode('', array_map(fn ($x) => $x[1], $m)));
    }
}
