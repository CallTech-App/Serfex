<?php

namespace App\Services\Security;

use App\Models\CountryBlock;
use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Setting;
use App\Models\SiteStatItem;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Monitor\MonitorException;
use App\Support\Countries;
use App\Support\ReadOnlyGuard;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ülkeler: the countries whose visitors the server refuses. The list
 * lives in country_blocks and its scope in one setting; the agent gets
 * the whole list and the scope whenever either changes, and works the
 * addresses out from its country table (where an address is registered,
 * the same table as the sites' statistics).
 *
 * Scope "web": every site answers 403, also behind Cloudflare's proxy.
 * Scope "server": the firewall also drops their new connections, except
 * to the web ports, DNS and incoming mail.
 *
 * Allowed addresses, the server's own addresses and Cloudflare's ranges
 * always pass.
 */
class CountryBlocks
{
    /** @var list<string> */
    public const SCOPES = ['web', 'server'];

    /** The setting that holds the scope; "web" until someone chooses. */
    public const SCOPE_KEY = 'security.country_scope';

    /** Held while the list is changed and handed to the agent, so two changes cannot cross. */
    public const LOCK = 'country-blocks.push';

    /** What the agent last said it holds, for a minute. */
    public const STATUS_KEY = 'country-blocks.status';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly AddressGeo $geo,
    ) {}

    public function scope(): string
    {
        $scope = Setting::get(self::SCOPE_KEY, 'web');

        return in_array($scope, self::SCOPES, true) ? $scope : 'web';
    }

    /**
     * The blocked countries' codes, in the order of the alphabet.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return CountryBlock::orderBy('code')->pluck('code')->all();
    }

    /**
     * Blocks countries, and sets the scope when one is given. What is
     * not a country, is blocked already or is the country the caller is
     * connected from is skipped with a sentence saying why. When nothing
     * is left to block nothing changes, the scope neither.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $mine  the caller's addresses
     * @return array{blocked: list<string>, skipped: list<array{text: string, message: string}>, scope: string} "scope" is the scope before the call
     *
     * @throws ScopeRefused when the scope would become the whole server while the caller's own country is blocked
     * @throws MonitorException when the country table is not there yet, the scope is not one, or someone else is changing the list
     * @throws AgentException when the agent could not apply the list; nothing is stored then
     */
    public function block(array $codes, ?string $scope = null, ?User $by = null, array $mine = []): array
    {
        $before = $this->scope();
        $scope = self::checked($scope ?? $before);
        $home = $this->home($mine);
        $known = $this->codes();
        $new = [];
        $skipped = [];
        foreach ($codes as $text) {
            $text = trim((string) $text);
            $code = strtoupper($text);
            if (in_array($code, $new, true)) {
                continue;
            }
            if (! Countries::valid($code)) {
                $skipped[] = ['text' => $text, 'message' => __('Bir ülke kodu değil.')];
            } elseif (in_array($code, $known, true)) {
                $skipped[] = ['text' => Countries::name($code), 'message' => __('Bu ülke zaten engelli.')];
            } elseif ($code === $home) {
                $skipped[] = ['text' => Countries::name($code), 'message' => __('Şu an buradan bağlısınız; bağlı olduğunuz ülke engellenemez.')];
            } else {
                $new[] = $code;
            }
        }
        if ($new === []) {
            return ['blocked' => [], 'skipped' => $skipped, 'scope' => $before];
        }
        if ($scope !== $before) {
            $this->guardCaller($scope, $home, $mine);
        }
        $status = $this->status(fresh: true);
        if ($status['reachable'] && ! $status['loaded']) {
            throw new MonitorException(__('Ülke tablosu henüz indirilmedi; birkaç dakika sonra yeniden deneyin.'));
        }
        sort($new);
        // The rows are kept only when the agent took the list.
        self::alone(fn () => DB::transaction(function () use ($new, $scope, $before, $by) {
            foreach ($new as $code) {
                CountryBlock::firstOrCreate(['code' => $code], ['user_id' => $by?->id]);
            }
            if ($scope !== $before) {
                Setting::put(self::SCOPE_KEY, $scope);
            }
            $this->push();
        }));

        return ['blocked' => $new, 'skipped' => $skipped, 'scope' => $before];
    }

    /**
     * Lifts the block on countries. Returns what restore() needs to put
     * them back; countries that are not blocked are passed over.
     *
     * @param  list<string>  $codes
     * @return list<array{code: string, user_id: ?int, at: string}>
     *
     * @throws AgentException when the agent could not apply the list; nothing is lifted then
     * @throws MonitorException when someone else is changing the list
     */
    public function unblock(array $codes): array
    {
        $codes = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes)));
        if (CountryBlock::whereIn('code', $codes)->doesntExist()) {
            return [];
        }

        return self::alone(fn () => DB::transaction(function () use ($codes) {
            $rows = CountryBlock::whereIn('code', $codes)->orderBy('code')->get();
            $lifted = $rows->map(fn (CountryBlock $b) => ['code' => $b->code, 'user_id' => $b->user_id, 'at' => $b->created_at->toIso8601String()])->all();
            CountryBlock::whereKey($rows->modelKeys())->delete();
            if ($lifted !== []) {
                $this->push();
            }

            return $lifted;
        }));
    }

    /**
     * Sets the scope for every blocked country. Returns the scope before
     * the call.
     *
     * @param  list<string>  $mine  the caller's addresses
     *
     * @throws ScopeRefused when it is the whole server while the caller's own country is blocked
     * @throws MonitorException when it is not a scope, or someone else is changing the list
     * @throws AgentException when the agent could not apply it; the scope stays then
     */
    public function setScope(string $scope, array $mine = []): string
    {
        $scope = self::checked($scope);
        $before = $this->scope();
        if ($scope === $before) {
            return $before;
        }
        $this->guardCaller($scope, $this->home($mine), $mine);
        self::alone(fn () => DB::transaction(function () use ($scope) {
            Setting::put(self::SCOPE_KEY, $scope);
            $this->push();
        }));

        return $before;
    }

    /**
     * A country blocked for the web sites leaves the panel open, so its
     * own administrators still sign in. The whole server as scope would
     * shut the caller out of the panel and SSH: refused, unless the
     * caller's address is allowed (the firewall accepts those before it
     * judges a country).
     *
     * @param  list<string>  $mine
     *
     * @throws ScopeRefused
     */
    private function guardCaller(string $scope, ?string $home, array $mine): void
    {
        if ($scope !== 'server' || $home === null || ! in_array($home, $this->codes(), true)) {
            return;
        }
        $allows = IpAllow::all();
        foreach ($mine as $address) {
            if (IpAllows::match((string) $address, $allows) !== null) {
                return;
            }
        }
        throw new ScopeRefused(__('Bağlı olduğunuz ülke (:country) engelli. Kapsam bütün sunucu olursa panele ve SSH\'a siz de giremezsiniz; önce bu ülkenin engelini kaldırın ya da adresinizi İzinli adresler\'e ekleyin.', ['country' => Countries::name($home)]));
    }

    /**
     * Undo: puts lifted countries back with their date and the person who
     * blocked them, and the scope when one is given. A country blocked
     * again meanwhile keeps the row it has.
     *
     * @param  list<array{code: string, user_id?: ?int, at?: ?string}>  $entries
     *
     * @throws AgentException
     * @throws MonitorException
     */
    public function restore(array $entries, ?string $scope = null): void
    {
        $scope = $scope === null ? null : self::checked($scope);
        self::alone(fn () => DB::transaction(function () use ($entries, $scope) {
            $known = $this->codes();
            $changed = false;
            foreach ($entries as $entry) {
                $code = strtoupper((string) ($entry['code'] ?? ''));
                if (! Countries::valid($code) || in_array($code, $known, true)) {
                    continue;
                }
                $by = $entry['user_id'] ?? null;
                $row = new CountryBlock(['code' => $code, 'user_id' => $by !== null && User::whereKey($by)->exists() ? (int) $by : null]);
                $at = isset($entry['at']) ? CarbonImmutable::parse($entry['at']) : now();
                $row->created_at = $at;
                $row->updated_at = $at;
                $row->save();
                $known[] = $code;
                $changed = true;
            }
            if ($scope !== null && $scope !== $this->scope()) {
                Setting::put(self::SCOPE_KEY, $scope);
                $changed = true;
            }
            if ($changed) {
                $this->push();
            }
        }));
    }

    /**
     * The console's way out (`serfex firewall countries off`): every
     * block is lifted, the scope is kept for the next one. The agent is
     * told also when the panel lists nothing, in case the two disagree.
     *
     * @return list<string> the countries that were blocked
     *
     * @throws AgentException
     * @throws MonitorException
     */
    public function off(): array
    {
        return self::alone(fn () => DB::transaction(function () {
            $codes = $this->codes();
            CountryBlock::query()->delete();
            $this->push();

            return $codes;
        }));
    }

    /**
     * What the agent holds: whether it has its country table, when the
     * table was downloaded, and how many ranges each blocked country has
     * in it. Asked once a minute at most; never throws.
     *
     * When the agent's list differs from the panel's (after a restore, or
     * a move to another server) it is given the panel's. Not for a
     * read-only administrator, who changes nothing, and not while someone
     * is changing the list: what they send is the list.
     *
     * @return array{loaded: bool, updated: ?CarbonImmutable, ranges: array<string, int>, reachable: bool}
     */
    public function status(bool $fresh = false): array
    {
        $held = $fresh ? null : Cache::get(self::STATUS_KEY);
        if (! is_array($held)) {
            try {
                $held = $this->remember($this->reconciled((array) $this->agent->call('firewall.countries.list', [], 15)));
            } catch (AgentException) {
                return ['loaded' => false, 'updated' => null, 'ranges' => [], 'reachable' => false];
            }
        }

        return [
            'loaded' => (bool) $held['loaded'],
            'updated' => $held['updated'] !== null ? CarbonImmutable::parse($held['updated']) : null,
            'ranges' => $held['ranges'],
            'reachable' => true,
        ];
    }

    /**
     * The country the caller is connected from, which cannot be blocked;
     * null when the table does not know their address.
     *
     * @param  list<string>  $mine
     */
    public function home(array $mine): ?string
    {
        $found = array_values($this->geo->lookup(array_values($mine)));

        return $found[0] ?? null;
    }

    /**
     * What every site together got from each country in the last $days
     * days, today included: code => requests and visitors (a visitor who
     * came on two days, or to two sites, counts twice).
     *
     * @return array<string, array{requests: int, visitors: int}>
     */
    public function traffic(int $days = 30): array
    {
        $rows = SiteStatItem::query()->toBase()
            ->where('kind', 'country')
            ->where('day', '>=', today()->subDays($days - 1)->toDateString())
            ->whereRaw("value ~ '^[A-Z]{2}$'")
            ->groupBy('value')->orderBy('value')
            ->selectRaw('value, sum(requests) as requests, sum(visitors) as visitors')
            ->get();
        $traffic = [];
        foreach ($rows as $row) {
            $traffic[$row->value] = ['requests' => (int) $row->requests, 'visitors' => (int) $row->visitors];
        }

        return $traffic;
    }

    /**
     * How many addresses of each country are blocked one by one now.
     *
     * @return array<string, int>
     */
    public function blockedAddresses(): array
    {
        return IpBlock::active()->whereNotNull('country')->where('country', '!=', AddressGeo::UNKNOWN)
            ->toBase()->groupBy('country')->selectRaw('country, count(*) as n')
            ->pluck('n', 'country')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * @throws MonitorException
     */
    private static function checked(string $scope): string
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new MonitorException(__('Kapsam "web" ya da "server" olabilir.'));
        }

        return $scope;
    }

    /**
     * Runs a change to the list while no other change runs.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     *
     * @throws MonitorException when another change has not finished after half a minute
     */
    private static function alone(Closure $change): mixed
    {
        try {
            return Cache::lock(self::LOCK, 180)->block(30, $change);
        } catch (LockTimeoutException) {
            throw new MonitorException(__('Engelli ülkeler şu anda başka bir işlemle değiştiriliyor; birazdan yeniden deneyin.'));
        }
    }

    /**
     * Gives the agent the list and the scope as they stand.
     *
     * @throws AgentException
     */
    private function push(): void
    {
        $this->remember((array) $this->agent->call('firewall.countries.apply', ['countries' => $this->codes(), 'scope' => $this->scope()], config('serfex.agent.job_timeout')));
    }

    /**
     * The agent's answer after it was given the panel's list, when it
     * held another one.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     *
     * @throws AgentUnavailableException
     */
    private function reconciled(array $answer): array
    {
        $lock = ReadOnlyGuard::active() ? null : Cache::lock(self::LOCK, 180);
        if (! $lock?->get()) {
            return $answer;
        }
        try {
            $wanted = $this->codes();
            $scope = $this->scope();
            $held = array_map('strval', (array) ($answer['countries'] ?? []));
            sort($held);
            if ($held !== $wanted || ($wanted !== [] && ($answer['scope'] ?? 'web') !== $scope)) {
                $answer = (array) $this->agent->call('firewall.countries.apply', ['countries' => $wanted, 'scope' => $scope], config('serfex.agent.job_timeout'));
            }
        } catch (AgentUnavailableException $e) {
            throw $e;
        } catch (AgentException) {
            // The agent is there and refused (no country table yet); the
            // page says so, and the list is offered again next time.
        } finally {
            $lock->release();
        }

        return $answer;
    }

    /**
     * Keeps what the agent said for a minute.
     *
     * @param  array<string, mixed>  $answer
     * @return array{loaded: bool, updated: ?string, ranges: array<string, int>}
     */
    private function remember(array $answer): array
    {
        $held = [
            'loaded' => (bool) ($answer['loaded'] ?? false),
            'updated' => isset($answer['updated']) && is_string($answer['updated']) ? $answer['updated'] : null,
            'ranges' => array_map('intval', (array) ($answer['ranges'] ?? [])),
        ];
        Cache::put(self::STATUS_KEY, $held, now()->addSeconds(60));

        return $held;
    }
}
