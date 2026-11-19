<?php

namespace App\Services\Monitor;

use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Security\AddressList;
use App\Services\Security\IpAllows;
use App\Services\Ssl\DnsCheck;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Blocking addresses on the whole server (nftables, through the agent).
 *
 * This is one of the two actions Serfex AI may take without asking, so
 * every caller goes through here: a person from the Canlı İzleme or the
 * IP engelleri page, the API, or the AI with source "ai", whose blocks
 * must end within a day.
 *
 * One address at a time goes through block(); lists go through the
 * "many" methods, which hand the agent up to MAX_BATCH addresses per
 * call so the firewall and Nginx change once, not once per address.
 * Fail2ban's bans are rows of the same table but live in Fail2ban: they
 * are lifted and brought back through it, and one that is given a new
 * duration becomes a block on the whole server.
 *
 * @phpstan-type Snapshot array{expires_at: ?string, reason: ?string, source: string, user_id: ?int, kind: ?string, site_id: ?int}
 * @phpstan-type Item array{address: string, minutes: ?int, reason: string, site_id: ?int, kind: ?string, keep: bool}
 * @phpstan-type Outcome array{blocks: Collection<int, IpBlock>, created: list<int>, previous: array<int, Snapshot>, replaced: list<int>, skipped: list<array{text: string, message: string}>}
 */
class IpBlocks
{
    /** The longest block Serfex AI may set on its own. */
    public const AI_MAX_MINUTES = 24 * 60;

    /** How many addresses the agent takes in one call, and one pasted list may hold. */
    public const MAX_BATCH = 1000;

    /** Durations offered in the panel, in minutes; null blocks until removed. */
    public const DURATIONS = [60 => '1 saat', 1440 => '24 saat', 10080 => '7 gün', 43200 => '30 gün', 0 => 'Kalıcı'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Blocks an address or prefix, or changes the block it already has.
     *
     * @param  int|null  $minutes  null or 0: until removed
     * @param  list<string>  $protect  addresses that must stay reachable, such as the caller's own
     *
     * @throws MonitorException when the address cannot be blocked
     * @throws AgentException
     */
    public function block(string $address, ?int $minutes, string $reason = '', ?Site $site = null, ?string $kind = null,
        ?User $by = null, string $source = 'panel', array $protect = []): IpBlock
    {
        $address = self::normalize($address);
        $minutes = self::length($minutes, $source);
        $problem = $this->problem($address, $protect, IpAllow::all(), []);
        if ($problem !== null) {
            throw new MonitorException(match ($problem[0]) {
                // Every visitor of a proxied site comes from Cloudflare's addresses.
                'cloudflare' => __(':address Cloudflare\'in vekil adreslerinden; engellenirse Cloudflare üzerinden gelen bütün ziyaretçiler engellenir. Ziyaretçinin gerçek adresini engelleyin.', ['address' => $address]),
                'mine' => __(':address sizin bağlantınızın adresi; engellerseniz panele ulaşamazsınız.', ['address' => $address]),
                default => __(':address izinli adreslerde (:entry); engellemek için önce oradan kaldırın.', ['address' => $address, 'entry' => $problem[1]]),
            });
        }

        try {
            $result = $this->agent->call('firewall.block', [
                'address' => $address,
                'seconds' => ($minutes ?? 0) * 60,
                'reason' => self::agentText($reason),
            ], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            if ($e->getCode() === -32602) {
                throw new MonitorException(self::agentMessage($e->getMessage()), previous: $e);
            }
            throw $e;
        }

        $data = [
            'address' => (string) ($result['address'] ?? $address),
            'reason' => $reason !== '' ? mb_substr($reason, 0, 200) : null,
            'site_id' => $site?->id,
            'kind' => $kind,
            'source' => $source,
            'user_id' => $by?->id,
            'expires_at' => $minutes ? now()->addMinutes($minutes) : null,
        ];
        // Fail2ban's ban of the same address is its own row and stays.
        $existing = IpBlock::active()->where('source', '!=', 'fail2ban')->where('address', $data['address'])->latest('id')->first();
        if ($existing !== null) {
            $existing->update($data);

            return $existing;
        }

        return IpBlock::create($data);
    }

    /**
     * Lifts a block now.
     *
     * @param  string  $ended  how it ended, for Geçmiş (see the ip_blocks migration)
     *
     * @throws AgentException
     */
    public function unblock(IpBlock $block, ?User $by = null, string $ended = 'user'): void
    {
        if ($block->isActive()) {
            // Fail2ban's bans live in Fail2ban's own table.
            $block->source === 'fail2ban'
                ? $this->agent->call('fail2ban.unban', ['jail' => (string) $block->kind, 'address' => $block->address], config('serfex.agent.job_timeout'))
                : $this->agent->call('firewall.unblock', ['address' => $block->address], config('serfex.agent.job_timeout'));
            $block->update(['removed_at' => now(), 'ended' => $ended, 'removed_by' => $by?->id]);

            return;
        }
        $block->update(['removed_at' => $block->removed_at ?? now()]);
    }

    /**
     * Undoes unblock(): the block comes back until it would have ended.
     * Returns null when its time is already over.
     *
     * @throws AgentException
     */
    public function reblock(IpBlock $block): ?IpBlock
    {
        return $this->reblockMany([$block]) === 1 ? $block->refresh() : null;
    }

    // --- Lists ---------------------------------------------------------

    /**
     * Reads a pasted list and says what blocking it would do, without
     * blocking anything.
     *
     * @param  list<string>  $protect  addresses that must stay reachable, such as the caller's own
     * @return array{new: list<string>, update: Collection<int, IpBlock>, skipped: list<array{text: string, message: string}>, over: bool}
     */
    public function inspect(string $text, array $protect = []): array
    {
        $allows = IpAllow::all();
        $own = IpAllows::serverAddresses($this->agent);
        $active = IpBlock::active()->get();
        $whole = $active->where('source', '!=', 'fail2ban');
        $byAddress = $active->groupBy('address');
        $ranges = $whole->filter(fn (IpBlock $b) => str_contains($b->address, '/'))->pluck('address')->all();
        $singles = $whole->reject(fn (IpBlock $b) => str_contains($b->address, '/'))->pluck('address')->all();

        $new = [];
        $update = new Collection;
        $skipped = [];
        $taken = [];
        $takenRanges = [];
        foreach (AddressList::parse($text) as $entry) {
            $address = $entry['address'];
            $problem = $address === null ? null : $this->problem($address, $protect, $allows, $own);
            $message = match (true) {
                $address === null => (string) $entry['error'],
                $problem !== null => self::reason($problem),
                default => null,
            };
            // The firewall holds no two blocks that overlap.
            if ($message === null && ($other = self::clash($address, $ranges, $singles)) !== null) {
                $message = __('engelli :other ile çakışıyor; önce o engeli kaldırın', ['other' => $other]);
            }
            if ($message === null && ($other = self::clash($address, $takenRanges, str_contains($address, '/') ? $taken : [])) !== null) {
                $message = __('listedeki :other ile çakışıyor', ['other' => $other]);
            }
            if ($message !== null) {
                $skipped[] = ['text' => $entry['text'], 'message' => $message];

                continue;
            }
            $taken[] = $address;
            if (str_contains($address, '/')) {
                $takenRanges[] = $address;
            }
            if ($byAddress->has($address)) {
                $update = $update->concat($byAddress[$address]);
            } else {
                $new[] = $address;
            }
        }

        return ['new' => $new, 'update' => $update, 'skipped' => $skipped, 'over' => count($taken) > self::MAX_BATCH];
    }

    /**
     * Blocks a list of addresses for the same time, with one call to the
     * agent. An address that is already blocked gets the new duration and
     * reason; one that cannot be blocked is skipped and reported.
     *
     * @param  list<string>  $addresses
     * @param  int|null  $minutes  null or 0: until removed
     * @param  list<string>  $protect  addresses that must stay reachable, such as the caller's own
     * @return Outcome
     *
     * @throws MonitorException when the list is too long or the duration is not allowed
     * @throws AgentException when the agent could not block; nothing is stored then
     */
    public function blockMany(array $addresses, ?int $minutes, string $reason = '', ?User $by = null, string $source = 'panel', array $protect = []): array
    {
        $minutes = self::length($minutes, $source);
        $items = [];
        $skipped = [];
        foreach (AddressList::parse(implode("\n", $addresses)) as $entry) {
            if ($entry['address'] === null) {
                $skipped[] = ['text' => $entry['text'], 'message' => (string) $entry['error']];
            } else {
                $items[] = ['address' => $entry['address'], 'minutes' => $minutes, 'reason' => $reason, 'site_id' => null, 'kind' => null, 'keep' => false];
            }
        }
        if (count($items) > self::MAX_BATCH) {
            throw new MonitorException(__('Bir kerede en fazla :n adres engellenebilir; listede :count adres var.', ['n' => number_format(self::MAX_BATCH, 0, ',', '.'), 'count' => number_format(count($items), 0, ',', '.')]));
        }

        return $this->apply($items, $by, $source, $protect, $skipped);
    }

    /**
     * Lifts many blocks: the firewall's in one call per MAX_BATCH,
     * Fail2ban's bans one by one. Blocks that are already over are passed
     * over. Returns the ids of the blocks that were lifted.
     *
     * @param  iterable<IpBlock>  $blocks
     * @param  string  $ended  how they ended, for Geçmiş
     * @return list<int>
     *
     * @throws AgentException when nothing could be lifted
     */
    public function unblockMany(iterable $blocks, ?User $by = null, string $ended = 'user'): array
    {
        $active = (new Collection($blocks))->filter(fn (IpBlock $b) => $b->isActive());
        $done = [];
        $mark = ['removed_at' => now(), 'ended' => $ended, 'removed_by' => $by?->id];
        foreach ($active->where('source', '!=', 'fail2ban')->chunk(self::MAX_BATCH) as $chunk) {
            $this->agent->call('firewall.unblock.many', ['addresses' => $chunk->pluck('address')->unique()->values()->all()], config('serfex.agent.job_timeout'));
            IpBlock::whereKey($chunk->modelKeys())->update($mark);
            $done = [...$done, ...$chunk->modelKeys()];
        }
        $failure = null;
        foreach ($active->where('source', 'fail2ban') as $ban) {
            try {
                $this->agent->call('fail2ban.unban', ['jail' => (string) $ban->kind, 'address' => $ban->address], config('serfex.agent.job_timeout'));
            } catch (AgentException $e) {
                // The others are still lifted; this ban stays in the list.
                $failure = $e;

                continue;
            }
            $ban->update($mark);
            $done[] = $ban->id;
        }
        if ($done === [] && $failure !== null) {
            throw $failure;
        }

        return $done;
    }

    /**
     * Undoes unblockMany(): each block comes back, as the same row, for
     * the time it had left. Blocks whose time is over stay over. Returns
     * how many came back.
     *
     * @param  iterable<IpBlock>  $blocks
     *
     * @throws AgentException
     */
    public function reblockMany(iterable $blocks): int
    {
        $back = ['removed_at' => null, 'ended' => null, 'removed_by' => null];
        $count = 0;
        $rows = [];
        foreach ($blocks as $block) {
            if ($block->removed_at === null || $block->expires_at?->isPast()) {
                continue;
            }
            if ($block->source !== 'fail2ban') {
                $rows[$block->address] = $block;

                continue;
            }
            try {
                $this->agent->call('fail2ban.ban', ['jail' => (string) $block->kind, 'address' => $block->address], config('serfex.agent.job_timeout'));
            } catch (AgentException) {
                continue;
            }
            $block->update($back);
            $count++;
        }
        foreach (array_chunk($rows, self::MAX_BATCH, true) as $chunk) {
            $answer = (array) $this->agent->call('firewall.block.many', ['items' => array_values(array_map(fn (IpBlock $b) => [
                'address' => $b->address, 'seconds' => self::secondsLeft($b->expires_at), 'reason' => self::agentText((string) $b->reason),
            ], $chunk))], config('serfex.agent.job_timeout'));
            foreach ($answer['blocked'] ?? [] as $blocked) {
                if (isset($chunk[(string) $blocked['address']])) {
                    $chunk[(string) $blocked['address']]->update($back);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Gives blocks in force a new duration, counted from now. Who blocked
     * and why stays. A Fail2ban ban only closes the attacked service's
     * port and ends on Fail2ban's clock, so it becomes a block on the
     * whole server instead.
     *
     * @param  iterable<IpBlock>  $blocks
     * @param  int|null  $minutes  null or 0: until removed
     * @return Outcome
     *
     * @throws MonitorException
     * @throws AgentException
     */
    public function changeDuration(iterable $blocks, ?int $minutes, ?User $by = null): array
    {
        $minutes = self::length($minutes, 'panel');
        $items = [];
        foreach ($blocks as $block) {
            if ($block->isActive()) {
                $items[] = ['address' => $block->address, 'minutes' => $minutes, 'reason' => (string) $block->reason,
                    'site_id' => $block->source === 'fail2ban' ? null : $block->site_id, 'kind' => null, 'keep' => true];
            }
        }

        return $this->apply($items, $by, 'panel', []);
    }

    /**
     * "Yeniden engelle" in Geçmiş: blocks the addresses of ended blocks
     * again, each for as long as it was blocked before. An address that is
     * blocked again already is passed over.
     *
     * @param  iterable<IpBlock>  $ended
     * @param  list<string>  $protect
     * @return Outcome
     *
     * @throws AgentException
     */
    public function blockAgain(iterable $ended, ?User $by = null, array $protect = []): array
    {
        $ended = (new Collection($ended))->reject(fn (IpBlock $b) => $b->isActive());
        $blocked = IpBlock::active()->where('source', '!=', 'fail2ban')->whereIn('address', $ended->pluck('address')->unique()->all())->pluck('address')->flip();
        $items = [];
        foreach ($ended as $block) {
            if (! $blocked->has($block->address)) {
                // A ban's jail is not a reason code of a whole-server block.
                $items[] = ['address' => $block->address, 'minutes' => $block->minutes(), 'reason' => (string) $block->reason,
                    'site_id' => $block->site_id, 'kind' => $block->source === 'fail2ban' ? null : $block->kind, 'keep' => false];
            }
        }

        return $this->apply($items, $by, 'panel', $protect);
    }

    /**
     * Undoes the changes blockMany() and changeDuration() made to blocks
     * that existed: each gets back what it had, for the time that had
     * left. One that would be over by now is ended.
     *
     * @param  array<int, Snapshot>  $previous  as those methods returned it
     *
     * @throws AgentException
     */
    public function restore(array $previous): int
    {
        $over = new Collection;
        $rows = [];
        foreach (IpBlock::whereKey(array_keys($previous))->get() as $block) {
            if ($block->isActive() && $block->source !== 'fail2ban') {
                $until = $previous[$block->id]['expires_at'] !== null ? Carbon::parse($previous[$block->id]['expires_at']) : null;
                $until?->isPast() ? $over->push($block) : $rows[$block->address] = $block;
            }
        }
        $apply = fn (IpBlock $b) => $b->update([...$previous[$b->id], 'expires_at' => $previous[$b->id]['expires_at'] !== null ? Carbon::parse($previous[$b->id]['expires_at']) : null]);
        foreach ($over->chunk(self::MAX_BATCH) as $chunk) {
            $this->agent->call('firewall.unblock.many', ['addresses' => $chunk->pluck('address')->values()->all()], config('serfex.agent.job_timeout'));
            $chunk->each($apply);
        }
        $count = $over->count();
        foreach (array_chunk($rows, self::MAX_BATCH, true) as $chunk) {
            $answer = (array) $this->agent->call('firewall.block.many', ['items' => array_values(array_map(fn (IpBlock $b) => [
                'address' => $b->address,
                'seconds' => self::secondsLeft($previous[$b->id]['expires_at'] !== null ? Carbon::parse($previous[$b->id]['expires_at']) : null),
                'reason' => self::agentText((string) $previous[$b->id]['reason']),
            ], $chunk))], config('serfex.agent.job_timeout'));
            foreach ($answer['blocked'] ?? [] as $blocked) {
                if (isset($chunk[(string) $blocked['address']])) {
                    $apply($chunk[(string) $blocked['address']]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Hands the agent the items it may block and writes a row for each
     * address it did block. Nothing is stored for a batch the agent
     * failed on.
     *
     * @param  list<Item>  $items
     * @param  list<string>  $protect
     * @param  list<array{text: string, message: string}>  $skipped  what the caller already left out
     * @return Outcome
     *
     * @throws AgentException
     */
    private function apply(array $items, ?User $by, string $source, array $protect, array $skipped = []): array
    {
        $out = ['blocks' => new Collection, 'created' => [], 'previous' => [], 'replaced' => [], 'skipped' => $skipped];
        $allows = IpAllow::all();
        $ok = [];
        foreach ($items as $item) {
            $problem = $this->problem($item['address'], $protect, $allows, []);
            if ($problem !== null) {
                $out['skipped'][] = ['text' => $item['address'], 'message' => self::reason($problem)];
            } else {
                // Two selected rows may be the same address (a block and a ban).
                $ok[$item['address']] ??= $item;
            }
        }
        foreach (array_chunk(array_values($ok), self::MAX_BATCH) as $chunk) {
            $answer = (array) $this->agent->call('firewall.block.many', ['items' => array_map(fn (array $i) => [
                'address' => $i['address'], 'seconds' => ($i['minutes'] ?? 0) * 60, 'reason' => self::agentText($i['reason']),
            ], $chunk)], config('serfex.agent.job_timeout'));
            $left = [];
            foreach ($answer['skipped'] ?? [] as $s) {
                $left[(string) $s['address']] = true;
                $out['skipped'][] = ['text' => (string) $s['address'], 'message' => self::agentReason((string) ($s['error'] ?? ''))];
            }
            // The agent answers in the order asked, without what it skipped.
            $asked = array_values(array_filter($chunk, fn (array $i) => ! isset($left[$i['address']])));
            $blocked = array_values((array) ($answer['blocked'] ?? []));
            if (count($asked) !== count($blocked)) {
                $byAddress = array_column($chunk, null, 'address');
                $asked = array_map(fn ($b) => $byAddress[(string) $b['address']] ?? null, $blocked);
            }
            $addresses = array_map(fn ($b) => (string) $b['address'], $blocked);
            $existing = IpBlock::active()->where('source', '!=', 'fail2ban')->whereIn('address', $addresses)->orderBy('id')->get()->keyBy('address');
            DB::transaction(function () use ($asked, $addresses, $existing, $by, $source, &$out) {
                foreach ($addresses as $k => $address) {
                    $item = $asked[$k];
                    if ($item === null) {
                        continue;
                    }
                    $expires = $item['minutes'] ? now()->addMinutes($item['minutes']) : null;
                    $row = $existing[$address] ?? null;
                    if ($row !== null) {
                        $out['previous'][$row->id] = self::snapshot($row);
                        $row->update($item['keep'] ? ['expires_at' => $expires] : [
                            'reason' => $item['reason'] !== '' ? mb_substr($item['reason'], 0, 200) : null, 'site_id' => $item['site_id'],
                            'kind' => $item['kind'], 'source' => $source, 'user_id' => $by?->id, 'expires_at' => $expires,
                        ]);
                    } else {
                        $row = IpBlock::create([
                            'address' => $address, 'reason' => $item['reason'] !== '' ? mb_substr($item['reason'], 0, 200) : null,
                            'site_id' => $item['site_id'], 'kind' => $item['kind'], 'source' => $source, 'user_id' => $by?->id, 'expires_at' => $expires,
                        ]);
                        $out['created'][] = $row->id;
                    }
                    $out['blocks']->push($row);
                }
            });
            // The whole server is closed to these addresses now; Fail2ban's
            // ban of one port is over.
            foreach (IpBlock::active()->where('source', 'fail2ban')->whereIn('address', $addresses)->get() as $ban) {
                try {
                    $this->agent->call('fail2ban.unban', ['jail' => (string) $ban->kind, 'address' => $ban->address], config('serfex.agent.job_timeout'));
                } catch (AgentException) {
                    continue; // still banned, still listed
                }
                $ban->update(['removed_at' => now(), 'ended' => 'replaced', 'removed_by' => $by?->id]);
                $out['replaced'][] = $ban->id;
            }
        }

        return $out;
    }

    /**
     * @return Snapshot
     */
    private static function snapshot(IpBlock $block): array
    {
        return [
            'expires_at' => $block->expires_at?->toIso8601String(), 'reason' => $block->reason, 'source' => $block->source,
            'user_id' => $block->user_id, 'kind' => $block->kind, 'site_id' => $block->site_id,
        ];
    }

    /**
     * Seconds until $until, for the agent; 0 when there is no end.
     */
    private static function secondsLeft(?Carbon $until): int
    {
        return $until === null ? 0 : max(60, (int) ceil(now()->diffInSeconds($until)));
    }

    /**
     * The agent takes a reason of 200 bytes.
     */
    private static function agentText(string $reason): string
    {
        return mb_strcut($reason, 0, 200);
    }

    /**
     * @throws MonitorException
     */
    private static function length(?int $minutes, string $source): ?int
    {
        $minutes = $minutes ?: null;
        if ($minutes !== null && ($minutes < 1 || $minutes > 525600)) {
            throw new MonitorException(__('Süre 1 dakika ile 1 yıl arasında olmalı.'));
        }
        if ($source === 'ai' && ($minutes === null || $minutes > self::AI_MAX_MINUTES)) {
            throw new MonitorException(__('Serfex AI bir adresi en fazla 24 saat engelleyebilir.'));
        }

        return $minutes;
    }

    /**
     * Why the panel itself will not block $address: Cloudflare's proxy,
     * the caller's own connection, the server's own address or the
     * allowed list. null when nothing stands in the way.
     *
     * @param  list<string>  $protect
     * @param  iterable<IpAllow>  $allows
     * @param  list<string>  $own
     * @return array{0: string, 1?: string}|null
     */
    private function problem(string $address, array $protect, iterable $allows, array $own): ?array
    {
        $base = explode('/', $address)[0];
        $cloudflare = DnsCheck::isCloudflare($base);
        foreach (DnsCheck::CLOUDFLARE as $cidr) {
            $cloudflare = $cloudflare || self::contains($address, explode('/', $cidr)[0]);
        }
        if ($cloudflare) {
            return ['cloudflare'];
        }
        foreach ($protect as $mine) {
            if ($mine !== '' && self::contains($address, $mine)) {
                return ['mine'];
            }
        }
        foreach ($own as $server) {
            if (self::contains($address, $server)) {
                return ['own'];
            }
        }
        $allow = IpAllows::match($address, $allows);

        return $allow !== null ? ['allowed', $allow->address] : null;
    }

    /**
     * A problem() as it reads after the address in a list.
     *
     * @param  array{0: string, 1?: string}  $problem
     */
    private static function reason(array $problem): string
    {
        return match ($problem[0]) {
            'cloudflare' => __('Cloudflare\'in vekil adreslerinden; engellenirse Cloudflare üzerinden gelen bütün ziyaretçiler engellenir'),
            'mine' => __('şu anki bağlantınızın adresi; engellerseniz panele ulaşamazsınız'),
            'own' => __('sunucunun kendi adresi'),
            default => __('izinli adreslerde (:entry)', ['entry' => $problem[1] ?? '']),
        };
    }

    /**
     * The first of the ranges $address overlaps, or of the single
     * addresses it holds; null when it stands clear of them all.
     *
     * @param  array<int, string>  $ranges
     * @param  array<int, string>  $singles
     */
    private static function clash(string $address, array $ranges, array $singles): ?string
    {
        $base = explode('/', $address)[0];
        foreach ($ranges as $range) {
            if ($range !== $address && (self::contains($range, $base) || self::contains($address, explode('/', $range)[0]))) {
                return $range;
            }
        }
        if (str_contains($address, '/')) {
            foreach ($singles as $single) {
                if ($single !== $address && self::contains($address, $single)) {
                    return $single;
                }
            }
        }

        return null;
    }

    /**
     * Checks an address or prefix and writes it the way the agent does:
     * lower case, host bits cleared, an IPv4 address inside IPv6
     * (::ffff:192.0.2.1) as IPv4, a prefix of one address as that address.
     *
     * @param  bool  $allow  the address is for the allowed list, which only changes the wording
     *
     * @throws MonitorException
     */
    public static function normalize(string $address, bool $allow = false): string
    {
        [$normal, $flaw] = self::read($address);
        if ($normal !== null) {
            return $normal;
        }
        throw new MonitorException(match ($flaw) {
            'address' => __('":address" bir IP adresi değil.', ['address' => trim($address)]),
            'range' => __('":address" geçerli bir adres aralığı değil.', ['address' => trim($address)]),
            'wide' => $allow
                ? __('Aralık çok geniş: en fazla /16 (IPv4) ya da /48 (IPv6) izinlilere eklenebilir.')
                : __('Aralık çok geniş: en fazla /16 (IPv4) ya da /48 (IPv6) engellenebilir.'),
            default => $allow
                ? __('Sunucunun kendisine ait yerel adresler izinlilere eklenemez.')
                : __('Sunucunun kendisine ait yerel adresler engellenemez.'),
        });
    }

    /**
     * What is wrong with an entry of a list, as it reads after the entry;
     * null when it is an address or prefix that may be blocked.
     */
    public static function flaw(string $address): ?string
    {
        return match (self::read($address)[1]) {
            null => null,
            'address' => __('bir IP adresi değil'),
            'range' => __('geçerli bir adres aralığı değil'),
            'wide' => __('aralık çok geniş (en fazla /16, IPv6\'da /48)'),
            default => __('sunucunun yerel adresi'),
        };
    }

    /**
     * @return array{0: ?string, 1: ?string} the address as the agent writes it, or what is wrong: address, range, wide, local
     */
    private static function read(string $address): array
    {
        $address = trim($address);
        [$ip, $bits] = array_pad(explode('/', $address, 2), 2, null);
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($packed === false) {
            return [null, 'address'];
        }
        $mapped = strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff");
        $v4 = strlen($packed) === 4 || $mapped;
        $max = $v4 ? 32 : 128;
        if ($bits !== null && (! ctype_digit($bits) || strlen($bits) > 3 || (int) $bits > ($mapped ? 128 : $max) || ($mapped && (int) $bits < 96))) {
            return [null, 'range'];
        }
        if ($mapped) {
            $packed = substr($packed, 12);
            $bits = $bits === null ? null : (string) ((int) $bits - 96);
        }
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < ($v4 ? 16 : 48)) {
            return [null, 'wide'];
        }
        $packed = self::mask($packed, $bits);
        $text = (string) inet_ntop($packed);
        if (self::contains('127.0.0.0/8', $text) || in_array($text, ['::1', '0.0.0.0', '::'], true)) {
            return [null, 'local'];
        }

        return [$bits === $max ? $text : $text.'/'.$bits, null];
    }

    /**
     * True when $ip falls in $prefix (an address or a prefix).
     */
    public static function contains(string $prefix, string $ip): bool
    {
        [$net, $bits] = array_pad(explode('/', $prefix, 2), 2, null);
        $a = @inet_pton($net);
        $b = @inet_pton($ip);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;

        return self::mask($a, $bits) === self::mask($b, $bits);
    }

    private static function mask(string $packed, int $bits): string
    {
        $out = '';
        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($byte) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $out;
    }

    private static function agentMessage(string $message): string
    {
        return match (true) {
            str_contains($message, "this server's own address") => __('Bu, sunucunun kendi adresi; engellenemez.'),
            str_contains($message, 'in the allowed list') => __('Bu adres izinli adreslerde; engellemek için önce oradan kaldırın.'),
            str_contains($message, 'overlaps') => __('Bu adres zaten engellenmiş bir aralığın içinde.'),
            default => __('Adres engellenemedi: :message', ['message' => $message]),
        };
    }

    /**
     * Why the agent left an address of a batch out, as it reads after the
     * address in a list.
     */
    private static function agentReason(string $error): string
    {
        return match (true) {
            str_contains($error, "this server's own address") => __('sunucunun kendi adresi'),
            str_contains($error, 'in the allowed list') => __('izinli adreslerde'),
            str_contains($error, 'Cloudflare') => __('Cloudflare\'in vekil adreslerinden; engellenirse Cloudflare üzerinden gelen bütün ziyaretçiler engellenir'),
            preg_match('/overlaps (\S+), which is already blocked/', $error, $m) === 1 => __('engelli :other ile çakışıyor; önce o engeli kaldırın', ['other' => $m[1]]),
            preg_match('/overlaps (\S+), which is in the same list/', $error, $m) === 1 => __('listedeki :other ile çakışıyor', ['other' => $m[1]]),
            str_contains($error, 'too wide') => __('aralık çok geniş (en fazla /16, IPv6\'da /48)'),
            default => __('engellenemedi: :message', ['message' => $error]),
        };
    }
}
