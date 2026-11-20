<?php

namespace Tests\Feature;

use App\Exceptions\ReadOnlyAccount;
use App\Models\CountryBlock;
use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteStatItem;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Monitor\MonitorException;
use App\Services\Security\CountryBlocks;
use App\Services\Security\ScopeRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güvenlik > IP engelleri > Ülkeler, below the page: the list of blocked
 * countries, its scope and what the agent is told.
 */
class CountryBlocksTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Sercan Akın']);
        $this->actingAs($this->admin);
        $this->agent = new FakeAgent([
            'firewall.countries.apply' => fn ($p) => self::held($p['countries'], $p['scope']),
            // The agent holds the list the panel last gave it.
            'firewall.countries.list' => fn () => self::held(CountryBlock::orderBy('code')->pluck('code')->all(), (string) Setting::get(CountryBlocks::SCOPE_KEY, 'web')),
            // Everyone connects from Türkiye.
            'geo.lookup' => fn ($p) => ['loaded' => true, 'countries' => array_fill_keys($p['addresses'], 'TR')],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /**
     * What the agent answers.
     *
     * @param  list<string>  $countries
     * @return array<string, mixed>
     */
    private static function held(array $countries, string $scope, bool $loaded = true): array
    {
        return [
            'countries' => $countries, 'scope' => $scope, 'ranges' => array_fill_keys($countries, 12),
            'loaded' => $loaded, 'updated' => $loaded ? '2026-11-18T03:10:00Z' : null, 'sites' => 2, 'nginx' => true,
        ];
    }

    private function countries(): CountryBlocks
    {
        return app(CountryBlocks::class);
    }

    public function test_blocking_sends_the_whole_list_with_the_scope(): void
    {
        $this->assertSame('web', $this->countries()->scope());
        $this->assertSame([], $this->countries()->codes());

        $first = $this->countries()->block(['RU'], 'web', $this->admin);
        $this->assertSame(['blocked' => ['RU'], 'skipped' => [], 'scope' => 'web'], $first);

        $second = $this->countries()->block(['kp', 'CN'], 'server', $this->admin);
        $this->assertSame(['CN', 'KP'], $second['blocked']);
        // The scope before the call, for Undo.
        $this->assertSame('web', $second['scope']);

        $this->assertSame([
            ['countries' => ['RU'], 'scope' => 'web'],
            ['countries' => ['CN', 'KP', 'RU'], 'scope' => 'server'],
        ], $this->agent->called('firewall.countries.apply'));
        $this->assertSame(['CN', 'KP', 'RU'], $this->countries()->codes());
        $this->assertSame('server', $this->countries()->scope());
        $this->assertSame($this->admin->id, CountryBlock::where('code', 'KP')->value('user_id'));
    }

    public function test_the_callers_country_is_left_out(): void
    {
        $result = $this->countries()->block(['TR', 'RU'], null, $this->admin, ['88.230.4.10']);

        $this->assertSame(['RU'], $result['blocked']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('Türkiye', $result['skipped'][0]['text']);
        $this->assertStringContainsString('buradan bağlısınız', $result['skipped'][0]['message']);
        $this->assertSame(['RU'], $this->countries()->codes());
        $this->assertSame('TR', $this->countries()->home(['88.230.4.10']));
        $this->assertNull($this->countries()->home([]));
    }

    public function test_what_is_not_a_country_and_what_is_blocked_already_are_left_out(): void
    {
        $this->countries()->block(['RU']);
        $result = $this->countries()->block(['RU', 'XX', 'EU', ' de', 'DE', 'Almanya']);

        $this->assertSame(['DE'], $result['blocked']);
        $this->assertSame(['Rusya', 'XX', 'EU', 'Almanya'], array_column($result['skipped'], 'text'));
        $this->assertStringContainsString('zaten engelli', $result['skipped'][0]['message']);
        $this->assertStringContainsString('ülke kodu değil', $result['skipped'][1]['message']);
        $this->assertSame(['DE', 'RU'], $this->countries()->codes());

        // Nothing new: the agent is not asked again.
        $calls = count($this->agent->called('firewall.countries.apply'));
        $this->assertSame([], $this->countries()->block(['RU'])['blocked']);
        $this->assertCount($calls, $this->agent->called('firewall.countries.apply'));
    }

    public function test_nothing_is_stored_when_the_agent_refuses(): void
    {
        $this->agent->answers['firewall.countries.apply'] = AgentException::rpcError('firewall.countries.apply', AgentException::OPERATION_FAILED, 'nft: Could not process rule');
        try {
            $this->countries()->block(['RU'], 'server');
            $this->fail('the refusal was swallowed');
        } catch (AgentException $e) {
            $this->assertSame('nft: Could not process rule', $e->forPeople());
        }
        $this->assertSame([], $this->countries()->codes());
        $this->assertSame('web', $this->countries()->scope());
    }

    public function test_without_the_country_table_nothing_is_asked_of_the_agent(): void
    {
        $this->agent->answers['firewall.countries.list'] = self::held([], 'web', loaded: false);
        try {
            $this->countries()->block(['RU']);
            $this->fail('blocked without a table');
        } catch (MonitorException $e) {
            $this->assertStringContainsString('Ülke tablosu henüz indirilmedi', $e->getMessage());
        }
        $this->assertSame([], $this->agent->called('firewall.countries.apply'));
        $this->assertSame([], $this->countries()->codes());
    }

    public function test_unblocking_sends_what_is_left_and_returns_what_undo_needs(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        $this->countries()->block(['RU', 'CN'], 'web', $this->admin);
        $this->travelTo('2026-11-21 10:00:00');

        $lifted = $this->countries()->unblock(['ru', 'FR']);

        $this->assertCount(1, $lifted);
        $this->assertSame('RU', $lifted[0]['code']);
        $this->assertSame($this->admin->id, $lifted[0]['user_id']);
        $this->assertStringStartsWith('2026-11-20T09:00:00', $lifted[0]['at']);
        $calls = $this->agent->called('firewall.countries.apply');
        $this->assertSame(['countries' => ['CN'], 'scope' => 'web'], end($calls));
        $this->assertSame(['CN'], $this->countries()->codes());

        // Nothing of it is blocked: no call.
        $this->assertSame([], $this->countries()->unblock(['FR']));
        $this->assertCount(count($calls), $this->agent->called('firewall.countries.apply'));
    }

    public function test_a_block_stays_when_the_agent_fails_to_lift_it(): void
    {
        $this->countries()->block(['RU']);
        $this->agent->answers['firewall.countries.apply'] = new AgentUnavailableException('connection refused');
        try {
            $this->countries()->unblock(['RU']);
            $this->fail('lifted without the agent');
        } catch (AgentException) {
        }
        $this->assertSame(['RU'], $this->countries()->codes());
    }

    public function test_changing_the_scope_sends_the_list_again(): void
    {
        $this->countries()->block(['RU']);

        $this->assertSame('web', $this->countries()->setScope('server'));
        $calls = $this->agent->called('firewall.countries.apply');
        $this->assertSame(['countries' => ['RU'], 'scope' => 'server'], end($calls));
        $this->assertSame('server', $this->countries()->scope());

        // The same scope again: nothing to do.
        $this->assertSame('server', $this->countries()->setScope('server'));
        $this->assertCount(count($calls), $this->agent->called('firewall.countries.apply'));

        $this->expectException(MonitorException::class);
        $this->countries()->setScope('planet');
    }

    public function test_the_scope_stays_when_the_agent_refuses_it(): void
    {
        $this->countries()->block(['RU']);
        $this->agent->answers['firewall.countries.apply'] = AgentException::rpcError('firewall.countries.apply', AgentException::OPERATION_FAILED, 'nft: Could not process rule');
        try {
            $this->countries()->setScope('server');
            $this->fail('the refusal was swallowed');
        } catch (AgentException) {
        }
        $this->assertSame('web', $this->countries()->scope());
    }

    public function test_the_whole_server_is_refused_while_the_callers_country_is_blocked(): void
    {
        // A colleague elsewhere blocked Türkiye for the web sites; the
        // panel stays open, so I can still sign in from there.
        $this->countries()->block(['TR', 'RU'], 'web');
        $calls = count($this->agent->called('firewall.countries.apply'));

        try {
            $this->countries()->setScope('server', ['88.230.4.10']);
            $this->fail('the scope was widened over the caller');
        } catch (ScopeRefused $e) {
            $this->assertStringContainsString('Bağlı olduğunuz ülke (Türkiye) engelli', $e->getMessage());
        }
        try {
            $this->countries()->block(['CN'], 'server', $this->admin, ['88.230.4.10']);
            $this->fail('a block widened the scope over the caller');
        } catch (ScopeRefused $e) {
            $this->assertStringContainsString('Bağlı olduğunuz ülke (Türkiye) engelli', $e->getMessage());
        }

        $this->assertSame('web', $this->countries()->scope());
        $this->assertSame(['RU', 'TR'], $this->countries()->codes());
        $this->assertCount($calls, $this->agent->called('firewall.countries.apply'));

        // For the web sites another country can still be blocked.
        $this->assertSame(['CN'], $this->countries()->block(['CN'], 'web', $this->admin, ['88.230.4.10'])['blocked']);
    }

    public function test_an_allowed_caller_may_widen_the_scope_over_the_own_country(): void
    {
        $this->countries()->block(['TR'], 'web');
        // The firewall accepts allowed addresses before it judges a country.
        IpAllow::create(['address' => '88.230.4.0/24']);

        $this->assertSame('web', $this->countries()->setScope('server', ['88.230.4.10']));
        $this->assertSame('server', $this->countries()->scope());
    }

    public function test_the_scope_is_not_guarded_where_it_is_already_the_whole_server(): void
    {
        // Already in force: blocking one more country changes nothing for the caller.
        $this->countries()->block(['TR'], 'server');

        $this->assertSame(['CN'], $this->countries()->block(['CN'], 'server', $this->admin, ['88.230.4.10'])['blocked']);
        $this->assertSame('server', $this->countries()->setScope('server', ['88.230.4.10']));
    }

    public function test_restore_puts_rows_and_scope_back(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        $this->countries()->block(['RU', 'CN'], 'web', $this->admin);
        $this->travelTo('2026-11-21 10:00:00');
        $lifted = $this->countries()->unblock(['RU']);
        $this->countries()->setScope('server');

        $this->countries()->restore($lifted, 'web');

        $row = CountryBlock::where('code', 'RU')->firstOrFail();
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame('2026-11-20 09:00:00', $row->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('web', $this->countries()->scope());
        $calls = $this->agent->called('firewall.countries.apply');
        $this->assertSame(['countries' => ['CN', 'RU'], 'scope' => 'web'], end($calls));

        // A country blocked again meanwhile keeps its newer row.
        $this->countries()->restore($lifted);
        $this->assertSame(1, CountryBlock::where('code', 'RU')->count());
    }

    public function test_off_lifts_everything(): void
    {
        $this->countries()->block(['RU', 'CN'], 'server');

        $this->assertSame(['CN', 'RU'], $this->countries()->off());

        $calls = $this->agent->called('firewall.countries.apply');
        // The scope is kept for the next block.
        $this->assertSame(['countries' => [], 'scope' => 'server'], end($calls));
        $this->assertSame(0, CountryBlock::count());
        $this->assertSame('server', $this->countries()->scope());
    }

    public function test_off_tells_the_agent_even_when_the_panel_lists_nothing(): void
    {
        // The console's way out must work when panel and agent disagree.
        $this->assertSame([], $this->countries()->off());
        $this->assertSame([['countries' => [], 'scope' => 'web']], $this->agent->called('firewall.countries.apply'));
    }

    public function test_a_read_only_administrator_cannot_block(): void
    {
        $this->countries()->block(['RU']);
        $calls = count($this->agent->called('firewall.countries.apply'));
        $this->actingAs(User::factory()->create(['role' => 'readonly']));

        foreach ([
            fn () => $this->countries()->block(['CN']),
            fn () => $this->countries()->unblock(['RU']),
            fn () => $this->countries()->setScope('server'),
        ] as $change) {
            try {
                $change();
                $this->fail('a read-only administrator changed the list');
            } catch (ReadOnlyAccount) {
            }
        }
        $this->assertSame(['RU'], $this->countries()->codes());
        $this->assertSame('web', $this->countries()->scope());
        $this->assertCount($calls, $this->agent->called('firewall.countries.apply'));
    }

    public function test_traffic_adds_up_thirty_days_of_every_site(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $a = Site::factory()->create();
        $b = Site::factory()->create();
        $item = fn (Site $site, string $day, string $value, int $requests, int $visitors, string $kind = 'country') => SiteStatItem::create([
            'site_id' => $site->id, 'day' => $day, 'kind' => $kind, 'value' => $value, 'requests' => $requests, 'visitors' => $visitors,
        ]);
        $item($a, '2026-11-20', 'RU', 100, 10);
        $item($a, '2026-11-19', 'RU', 50, 5);
        $item($b, '2026-11-20', 'RU', 7, 1);
        $item($b, '2026-10-22', 'TR', 900, 80);
        // Outside the thirty days, the rest of a day, unknown addresses and another kind.
        $item($a, '2026-10-21', 'RU', 5000, 500);
        $item($a, '2026-11-20', '', 40, 4);
        $item($a, '2026-11-20', '-', 30, 3);
        $item($a, '2026-11-20', 'RU', 9999, 999, 'page');

        $this->assertSame([
            'RU' => ['requests' => 157, 'visitors' => 16],
            'TR' => ['requests' => 900, 'visitors' => 80],
        ], $this->countries()->traffic());
    }

    public function test_blocked_addresses_are_counted_per_country(): void
    {
        IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'country' => 'RU']);
        IpBlock::create(['address' => '192.0.2.2', 'source' => 'fail2ban', 'kind' => 'sshd', 'country' => 'RU', 'expires_at' => now()->addHour()]);
        IpBlock::create(['address' => '192.0.2.3', 'source' => 'panel', 'country' => 'CN']);
        // Over, lifted, unknown and not looked up yet.
        IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'country' => 'RU', 'expires_at' => now()->subMinute()]);
        IpBlock::create(['address' => '192.0.2.5', 'source' => 'panel', 'country' => 'RU', 'removed_at' => now()]);
        IpBlock::create(['address' => '192.0.2.6', 'source' => 'panel', 'country' => 'ZZ']);
        IpBlock::create(['address' => '192.0.2.7', 'source' => 'panel']);

        $this->assertEquals(['RU' => 2, 'CN' => 1], $this->countries()->blockedAddresses());
    }

    public function test_status_says_what_the_agent_holds_and_asks_once_a_minute(): void
    {
        $this->countries()->block(['RU']);
        Cache::forget(CountryBlocks::STATUS_KEY);
        $asked = count($this->agent->called('firewall.countries.list'));

        $status = $this->countries()->status();
        $this->assertTrue($status['loaded']);
        $this->assertTrue($status['reachable']);
        $this->assertSame(['RU' => 12], $status['ranges']);
        $this->assertSame('2026-11-18 03:10', $status['updated']->utc()->format('Y-m-d H:i'));

        $this->countries()->status();
        $this->assertCount($asked + 1, $this->agent->called('firewall.countries.list'));
        $this->countries()->status(fresh: true);
        $this->assertCount($asked + 2, $this->agent->called('firewall.countries.list'));
    }

    public function test_status_is_quiet_when_the_agent_is_away(): void
    {
        $this->agent->answers['firewall.countries.list'] = new AgentUnavailableException('connection refused');

        $this->assertSame(['loaded' => false, 'updated' => null, 'ranges' => [], 'reachable' => false], $this->countries()->status());
        // Asked again next time.
        $this->countries()->status();
        $this->assertCount(2, $this->agent->called('firewall.countries.list'));
    }

    public function test_an_agent_that_lost_the_list_gets_it_back(): void
    {
        // After a restore or a move the panel knows the list and the agent does not.
        CountryBlock::create(['code' => 'RU']);
        Setting::put(CountryBlocks::SCOPE_KEY, 'server');
        $this->agent->answers['firewall.countries.list'] = self::held([], 'web');

        $status = $this->countries()->status();

        $this->assertSame([['countries' => ['RU'], 'scope' => 'server']], $this->agent->called('firewall.countries.apply'));
        $this->assertSame(['RU' => 12], $status['ranges']);
    }

    public function test_a_read_only_administrator_never_makes_the_panel_send_the_list(): void
    {
        CountryBlock::create(['code' => 'RU']);
        $this->agent->answers['firewall.countries.list'] = self::held([], 'web');
        $this->actingAs(User::factory()->create(['role' => 'readonly']));

        $this->assertTrue($this->countries()->status()['loaded']);
        $this->assertSame([], $this->agent->called('firewall.countries.apply'));
    }

    public function test_the_list_is_not_sent_while_someone_else_is_changing_it(): void
    {
        CountryBlock::create(['code' => 'RU']);
        // Another request has sent a longer list and has not stored its row yet.
        $this->agent->answers['firewall.countries.list'] = self::held(['CN', 'RU'], 'web');
        $busy = Cache::lock(CountryBlocks::LOCK, 30);
        $this->assertTrue($busy->get());

        $this->countries()->status();
        $this->assertSame([], $this->agent->called('firewall.countries.apply'));

        $busy->release();
    }
}
