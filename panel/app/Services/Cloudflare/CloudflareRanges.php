<?php

namespace App\Services\Cloudflare;

use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Ssl\DnsCheck;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The addresses Cloudflare's proxy connects from, as Cloudflare publishes
 * them. The agent trusts them for the visitor's real address (Nginx's
 * real_ip) and refuses to block them.
 */
class CloudflareRanges
{
    public const SOURCES = ['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Fetch the list and give it to the agent. A list that cannot be read
     * keeps the one in force. Returns the number of ranges sent.
     *
     * @throws AgentException
     */
    public function update(): int
    {
        $ranges = $this->fetch();
        $this->agent->call('web.edge.apply', ['ranges' => $ranges ?? []]);
        if ($ranges !== null) {
            Setting::put('cloudflare.ips_updated_at', now()->toIso8601String());
        }

        return count($ranges ?? DnsCheck::CLOUDFLARE);
    }

    /**
     * Cloudflare's current ranges, or null when they cannot be read or do
     * not look right (a handful of IPv4 and IPv6 networks).
     *
     * @return list<string>|null
     */
    public function fetch(): ?array
    {
        $out = [];
        foreach (self::SOURCES as $url) {
            try {
                $res = Http::timeout(15)->connectTimeout(8)->get($url);
            } catch (Throwable) {
                return null;
            }
            if (! $res->successful()) {
                return null;
            }
            $found = 0;
            foreach (preg_split('/\s+/', trim($res->body())) ?: [] as $line) {
                if ($line !== '' && self::isRange($line)) {
                    $out[] = $line;
                    $found++;
                }
            }
            if ($found === 0) {
                return null;
            }
        }

        return count($out) >= 4 && count($out) <= 200 ? array_values(array_unique($out)) : null;
    }

    private static function isRange(string $cidr): bool
    {
        if (! preg_match('#^([0-9a-f.:]+)/(\d{1,3})$#i', $cidr, $m) || filter_var($m[1], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $v6 = str_contains($m[1], ':');

        return (int) $m[2] >= ($v6 ? 16 : 8) && (int) $m[2] <= ($v6 ? 128 : 32);
    }
}
