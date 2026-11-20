<?php

namespace App\Services\Security;

use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\User;
use App\Services\Agent\AgentException;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Geri al" for the IP engelleri pages. What an action changed is kept
 * for ten minutes under a token that only the person who did it can use,
 * once. The steps of one action, in the order they are undone:
 *
 *  - unallow: ids of allowed entries the action added; they are removed
 *  - forget:  ids of blocks the action created; they are lifted and their
 *             rows deleted, so an undone block leaves no history
 *  - restore: blocks the action changed, as IpBlocks returned them
 *  - reblock: ids of blocks the action ended; they come back
 *  - allow:   entries the action removed from the allowed list
 *  - uncountry: codes of countries the action blocked; their block is
 *             lifted
 *  - country: blocked countries the action lifted, as CountryBlocks
 *             returned them; they come back
 *  - scope:   the scope of the country blocks before the action
 *
 * @phpstan-import-type Snapshot from IpBlocks
 *
 * @phpstan-type Steps array{unallow?: list<int>, forget?: list<int>, restore?: array<int, Snapshot>, reblock?: list<int>, allow?: list<array{address: string, note?: ?string}>, uncountry?: list<string>, country?: list<array{code: string, user_id?: ?int, at?: ?string}>, scope?: ?string}
 */
class BlockUndo
{
    private const MINUTES = 10;

    public function __construct(
        private readonly IpBlocks $blocks,
        private readonly IpAllows $allows,
        private readonly CountryBlocks $countries,
    ) {}

    /**
     * Remembers what to undo. Returns the token for the toast.
     *
     * @param  Steps  $steps
     */
    public function keep(array $steps, User $by): string
    {
        $token = Str::random(32);
        Cache::put(self::key($token, $by), array_filter($steps), now()->addMinutes(self::MINUTES));

        return $token;
    }

    /**
     * Undoes the action behind $token. False when there is nothing to
     * undo: the token is unknown, used, too old or someone else's.
     *
     * @throws AgentException
     * @throws MonitorException
     */
    public function run(string $token, User $by): bool
    {
        /** @var Steps|null $steps */
        $steps = Cache::pull(self::key($token, $by));
        if (! is_array($steps)) {
            return false;
        }
        if (! empty($steps['unallow'])) {
            $this->allows->remove(IpAllow::whereKey($steps['unallow'])->get());
        }
        if (! empty($steps['forget'])) {
            $created = IpBlock::whereKey($steps['forget'])->get();
            $this->blocks->unblockMany($created, $by);
            IpBlock::whereKey($created->modelKeys())->delete();
        }
        if (! empty($steps['restore'])) {
            $this->blocks->restore($steps['restore']);
        }
        if (! empty($steps['reblock'])) {
            $this->blocks->reblockMany(IpBlock::whereKey($steps['reblock'])->get());
        }
        if (! empty($steps['allow'])) {
            $this->allows->addMany($steps['allow'], $by);
        }
        if (! empty($steps['uncountry'])) {
            $this->countries->unblock($steps['uncountry']);
        }
        if (! empty($steps['country']) || ! empty($steps['scope'])) {
            $this->countries->restore($steps['country'] ?? [], $steps['scope'] ?? null);
        }

        return true;
    }

    private static function key(string $token, User $by): string
    {
        return 'ip-blocks.undo:'.$by->id.':'.hash('sha256', $token);
    }
}
