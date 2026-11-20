<?php

namespace App\Services\Security;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;

/**
 * What an address did on the sites: the agent reads every site's access
 * logs for it, which can take seconds on a busy server, so an answer is
 * kept for five minutes.
 */
class AddressVisits
{
    /** How many of the newest requests are asked for. */
    public const RECENT = 200;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * logs.address: requests, refused requests and sign-in attempts in
     * total, per site, and the newest requests.
     *
     * @param  bool  $fresh  ask the agent even when an answer is kept
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function for(string $address, bool $fresh = false): array
    {
        $key = 'ip-visits:'.$address;
        if ($fresh) {
            Cache::forget($key);
        }

        // The agent stops reading after 20 seconds and says so.
        return Cache::remember($key, now()->addMinutes(5), fn () => (array) $this->agent->call('logs.address', ['address' => $address, 'limit' => self::RECENT], 40));
    }
}
