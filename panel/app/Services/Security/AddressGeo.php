<?php

namespace App\Services\Security;

use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Stats\SiteStats;
use Illuminate\Support\Facades\Cache;

/**
 * The country of blocked and allowed addresses, from the agent's country
 * table (where an address is registered; the same table as the sites'
 * statistics). A row is looked up once, the first time it is shown, and
 * keeps its country.
 */
class AddressGeo
{
    /** An address the table does not know: private, reserved or too new. */
    public const UNKNOWN = 'ZZ';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Sets the country of the rows that have none. Nothing is stored
     * while the agent has no country table or cannot be reached; the rows
     * are asked about again next time.
     *
     * @param  iterable<IpBlock|IpAllow>  $rows
     */
    public function fill(iterable $rows): void
    {
        $missing = [];
        foreach ($rows as $row) {
            if ($row->country === null) {
                $missing[] = $row;
            }
        }
        if ($missing === []) {
            return;
        }
        try {
            $answer = (array) $this->agent->call('geo.lookup', ['addresses' => array_values(array_unique(array_map(fn ($r) => $r->address, $missing)))], 15);
        } catch (AgentException) {
            return;
        }
        if (! ($answer['loaded'] ?? false)) {
            return;
        }
        foreach ($missing as $row) {
            $code = strtoupper((string) ($answer['countries'][$row->address] ?? ''));
            $row->country = preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : self::UNKNOWN;
            // Learning the country is not a change to the block.
            $row->timestamps = false;
            $row->save();
            $row->timestamps = true;
        }
    }

    /**
     * The countries of addresses that are not rows, such as the server's
     * own: address => code, without the ones the table does not know.
     * Asked from the agent once a day.
     *
     * @param  list<string>  $addresses
     * @return array<string, string>
     */
    public function lookup(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }
        $key = 'address-geo:'.md5(implode(',', $addresses));
        $known = Cache::get($key);
        if (is_array($known)) {
            return $known;
        }
        try {
            $answer = (array) $this->agent->call('geo.lookup', ['addresses' => array_values($addresses)], 15);
        } catch (AgentException) {
            return [];
        }
        if (! ($answer['loaded'] ?? false)) {
            return [];
        }
        $known = [];
        foreach ($addresses as $address) {
            $code = strtoupper((string) ($answer['countries'][$address] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $known[$address] = $code;
            }
        }
        Cache::put($key, $known, now()->addDay());

        return $known;
    }

    /**
     * "Rusya" for RU, in the reader's language; '' when the country is
     * not known.
     */
    public static function name(?string $code): string
    {
        return $code === null || $code === self::UNKNOWN ? '' : SiteStats::country($code);
    }
}
