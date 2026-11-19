<?php

namespace App\Services\Security;

use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Services\Ssl\DnsCheck;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * İzinli adresler: addresses and prefixes that are never blocked. The
 * list lives in ip_allows; the agent gets the whole list whenever it
 * changes and keeps it in the firewall (accepted before any block), in
 * Nginx (allowed before any deny) and in Fail2ban's ignoreip. A block
 * on an address that becomes allowed is lifted; a wider block around it
 * stays, the allowed address is let through it.
 *
 * The server's own addresses and Cloudflare's proxy ranges are always
 * allowed and are not on the list.
 */
class IpAllows
{
    /** How many entries the list holds (the agent's limit for one call). */
    public const MAX = 1000;

    /** The server's own addresses, as the agent last reported them. */
    private const OWN_CACHE = 'ip-allows.own';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly IpBlocks $blocks,
    ) {}

    /**
     * Allows one address or prefix.
     *
     * @return array{allow: IpAllow, lifted: list<int>} the entry, and the ids of the blocks it lifted
     *
     * @throws MonitorException when it cannot be allowed
     * @throws AgentException when the agent could not apply the list; nothing is stored then
     */
    public function add(string $address, string $note = '', ?User $by = null): array
    {
        $result = $this->addMany([['address' => $address, 'note' => $note]], $by);
        if ($result['allows'] === []) {
            throw new MonitorException($result['skipped'][0]['message']);
        }

        return ['allow' => IpAllow::findOrFail($result['allows'][0]), 'lifted' => $result['lifted']];
    }

    /**
     * Allows several addresses with one call to the agent. An entry that
     * cannot be allowed is skipped with a sentence saying why.
     *
     * @param  list<array{address: string, note?: ?string}>  $entries
     * @return array{allows: list<int>, lifted: list<int>, skipped: list<array{text: string, message: string}>}
     *
     * @throws MonitorException when the list would grow past MAX
     * @throws AgentException
     */
    public function addMany(array $entries, ?User $by = null): array
    {
        $known = IpAllow::all();
        $own = $this->own();
        $new = [];
        $skipped = [];
        foreach ($entries as $entry) {
            try {
                $address = $this->check((string) $entry['address'], $known, $own);
            } catch (MonitorException $e) {
                $skipped[] = ['text' => trim((string) $entry['address']), 'message' => $e->getMessage()];

                continue;
            }
            $known->push(new IpAllow(['address' => $address]));
            $new[$address] = mb_substr(trim((string) ($entry['note'] ?? '')), 0, 200);
        }
        if ($new === []) {
            return ['allows' => [], 'lifted' => [], 'skipped' => $skipped];
        }
        if ($known->count() > self::MAX) {
            throw new MonitorException(__('En fazla :n izinli adres olabilir.', ['n' => number_format(self::MAX, 0, ',', '.')]));
        }
        // The rows are kept only when the agent took the list.
        $ids = DB::transaction(function () use ($new, $by) {
            $ids = [];
            foreach ($new as $address => $note) {
                $ids[] = IpAllow::create(['address' => $address, 'note' => $note !== '' ? $note : null, 'user_id' => $by?->id])->id;
            }
            $this->push();

            return $ids;
        });

        return ['allows' => $ids, 'lifted' => $this->blocks->unblockMany($this->blockedInside(array_keys($new)), $by, 'allowed'), 'skipped' => $skipped];
    }

    /**
     * Takes entries off the list. Returns them as addMany() takes them,
     * for Undo.
     *
     * @param  iterable<IpAllow>  $allows
     * @return list<array{address: string, note: ?string}>
     *
     * @throws AgentException when the agent could not apply the list; nothing is removed then
     */
    public function remove(iterable $allows): array
    {
        $allows = new Collection($allows);
        if ($allows->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($allows) {
            $removed = $allows->map(fn (IpAllow $a) => ['address' => $a->address, 'note' => $a->note])->values()->all();
            IpAllow::whereKey($allows->modelKeys())->delete();
            $this->push();

            return $removed;
        });
    }

    /**
     * The entry that allows $address, an address or a prefix; null when
     * the list does not cover all of it.
     */
    public function covering(string $address): ?IpAllow
    {
        return self::match($address, IpAllow::all());
    }

    /**
     * @param  iterable<IpAllow>  $allows
     */
    public static function match(string $address, iterable $allows): ?IpAllow
    {
        foreach ($allows as $allow) {
            // A prefix is covered only by an entry at least as wide.
            if (IpBlocks::covers($allow->address, $address)) {
                return $allow;
            }
        }

        return null;
    }

    /**
     * The server's own addresses, which are always allowed.
     *
     * @return list<string>
     */
    public function own(): array
    {
        return self::serverAddresses($this->agent);
    }

    /**
     * The server's own addresses, asked from the agent every ten minutes
     * at most; an empty list while the agent cannot be reached. When the
     * agent's list of allowed addresses differs from the panel's (after a
     * restore, or a move to another server) it is given the panel's.
     *
     * @return list<string>
     */
    public static function serverAddresses(AgentClient $agent): array
    {
        $own = Cache::get(self::OWN_CACHE);
        if (is_array($own)) {
            return $own;
        }
        try {
            $answer = (array) $agent->call('firewall.allow.list', [], 15);
            $wanted = IpAllow::orderBy('id')->pluck('address')->all();
            if (array_diff($wanted, (array) ($answer['allowed'] ?? [])) !== [] || array_diff((array) ($answer['allowed'] ?? []), $wanted) !== []) {
                $answer = (array) $agent->call('firewall.allow.apply', ['addresses' => $wanted], config('serfex.agent.job_timeout'));
            }
        } catch (AgentException) {
            return [];
        }
        $own = array_values(array_map('strval', (array) ($answer['own'] ?? [])));
        Cache::put(self::OWN_CACHE, $own, now()->addMinutes(10));

        return $own;
    }

    /**
     * Gives the agent the list as it stands.
     *
     * @throws AgentException
     */
    private function push(): void
    {
        $answer = (array) $this->agent->call('firewall.allow.apply', ['addresses' => IpAllow::orderBy('id')->pluck('address')->all()], config('serfex.agent.job_timeout'));
        if (isset($answer['own'])) {
            Cache::put(self::OWN_CACHE, array_values(array_map('strval', (array) $answer['own'])), now()->addMinutes(10));
        }
    }

    /**
     * @param  Collection<int, IpAllow>  $known
     * @param  list<string>  $own
     *
     * @throws MonitorException
     */
    private function check(string $address, Collection $known, array $own): string
    {
        $address = IpBlocks::normalize($address, allow: true);
        $base = explode('/', $address)[0];
        $cloudflare = DnsCheck::isCloudflare($base);
        foreach (DnsCheck::CLOUDFLARE as $cidr) {
            $cloudflare = $cloudflare || IpBlocks::contains($address, explode('/', $cidr)[0]);
        }
        if ($cloudflare) {
            throw new MonitorException(__(':address Cloudflare\'in vekil adreslerinden; bunlar zaten her zaman izinlidir.', ['address' => $address]));
        }
        if (in_array($address, $own, true)) {
            throw new MonitorException(__(':address sunucunun kendi adresi; zaten her zaman izinlidir.', ['address' => $address]));
        }
        $entry = self::match($address, $known);
        if ($entry !== null) {
            throw new MonitorException($entry->address === $address
                ? __(':address zaten izinli.', ['address' => $address])
                : __(':address zaten izinli (:entry).', ['address' => $address, 'entry' => $entry->address]));
        }

        return $address;
    }

    /**
     * The blocks in force on addresses inside the given allowed entries.
     *
     * @param  list<string>  $allowed
     * @return Collection<int, IpBlock>
     */
    private function blockedInside(array $allowed): Collection
    {
        $ranges = array_values(array_filter($allowed, fn (string $a) => str_contains($a, '/')));
        $blocks = IpBlock::active()->whereIn('address', $allowed)->get();
        if ($ranges === []) {
            return $blocks;
        }
        $entries = array_map(fn (string $a) => new IpAllow(['address' => $a]), $ranges);

        return $blocks->concat(IpBlock::active()->whereNotIn('address', $allowed)->get()->filter(fn (IpBlock $b) => self::match($b->address, $entries) !== null));
    }
}
