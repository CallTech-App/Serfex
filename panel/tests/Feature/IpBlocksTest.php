<?php

namespace Tests\Feature;

use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Services\Security\AddressGeo;
use App\Services\Security\AddressVisits;
use App\Services\Security\Fail2ban;
use App\Services\Security\IpAllows;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güvenlik > IP engelleri: blocking and unblocking in bulk, the allowed
 * list and the history, below the page.
 */
class IpBlocksTest extends TestCase
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
            'firewall.block' => fn ($p) => ['address' => $p['address']],
            'firewall.block.many' => fn ($p) => ['blocked' => array_map(fn ($i) => ['address' => $i['address']], $p['items']), 'skipped' => []],
            'firewall.unblock.many' => fn ($p) => ['removed' => count($p['addresses'])],
            'fail2ban.status' => FakeAgent::fail2banHoldsItsBans(),
            'firewall.allow.apply' => fn ($p) => ['allowed' => $p['addresses'], 'own' => ['203.0.113.10', '2001:db8::10']],
            // The agent holds the list the panel last gave it.
            'firewall.allow.list' => fn () => ['allowed' => IpAllow::pluck('address')->all(), 'own' => ['203.0.113.10', '2001:db8::10']],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_history_holds_blocks_that_ran_out_or_were_lifted_and_says_how_they_ended(): void
    {
        $active = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'expires_at' => now()->addHour()]);
        $forever = IpBlock::create(['address' => '192.0.2.2', 'source' => 'panel']);
        $ranOut = IpBlock::create(['address' => '192.0.2.3', 'source' => 'ai', 'expires_at' => now()->subMinute()]);
        $lifted = IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'removed_at' => now(), 'ended' => 'user', 'removed_by' => $this->admin->id]);
        $allowed = IpBlock::create(['address' => '192.0.2.5', 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now(), 'ended' => 'allowed']);
        $replaced = IpBlock::create(['address' => '192.0.2.6', 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now(), 'ended' => 'replaced']);
        $byFail2ban = IpBlock::create(['address' => '192.0.2.7', 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now(), 'ended' => 'sync']);
        // A block lifted before this version has no "ended".
        $old = IpBlock::create(['address' => '192.0.2.8', 'source' => 'panel', 'removed_at' => now()->subDay()]);

        $this->assertEqualsCanonicalizing(
            [$ranOut->id, $lifted->id, $allowed->id, $replaced->id, $byFail2ban->id, $old->id],
            IpBlock::ended()->pluck('id')->all(),
        );
        $this->assertSame('Süresi doldu', $ranOut->endedLabel());
        $this->assertSame('Sercan Akın kaldırdı', $lifted->endedLabel());
        $this->assertSame('İzinlilere eklendi', $allowed->endedLabel());
        $this->assertSame('Süresi değiştirildi', $replaced->endedLabel());
        $this->assertSame('Fail2ban kaldırdı', $byFail2ban->endedLabel());
        $this->assertSame('Kaldırıldı', $old->endedLabel());
        $this->assertTrue($ranOut->endedAt()->equalTo($ranOut->expires_at));
        $this->assertTrue($lifted->endedAt()->equalTo($lifted->removed_at));

        $this->assertSame(60, $active->minutes());
        $this->assertNull($forever->minutes());
    }

    public function test_an_address_is_on_the_allowed_list_once(): void
    {
        $allow = IpAllow::create(['address' => '88.230.14.52', 'note' => 'Ofis', 'user_id' => $this->admin->id, 'country' => 'TR']);
        $this->assertSame('Sercan Akın', $allow->user->name);
        $this->expectException(UniqueConstraintViolationException::class);
        IpAllow::create(['address' => '88.230.14.52']);
    }

    public function test_changing_the_duration_leaves_a_ban_on_the_callers_own_address_alone(): void
    {
        // Banned on the mail port only (an old password in a mail client): the panel still answers this address.
        $mine = IpBlock::create(['address' => '88.230.14.52', 'source' => 'fail2ban', 'kind' => 'dovecot', 'expires_at' => now()->addHour()]);
        $other = IpBlock::create(['address' => '192.0.2.9', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);

        $result = $this->blocks()->changeDuration([$mine, $other], 0, $this->admin, ['88.230.14.52']);

        $this->assertSame(['192.0.2.9'], array_column($this->agent->called('firewall.block.many')[0]['items'], 'address'));
        $this->assertSame(['192.0.2.9'], $result['blocks']->pluck('address')->all());
        $this->assertSame('88.230.14.52', $result['skipped'][0]['text']);
        $this->assertStringContainsString('şu anki bağlantınızın adresi', $result['skipped'][0]['message']);
        $this->assertSame('fail2ban', IpBlock::active()->where('address', '88.230.14.52')->sole()->source);
    }

    public function test_a_long_list_is_checked_quickly_against_thousands_of_blocked_ranges(): void
    {
        $now = now();
        $rows = [];
        foreach (range(0, 3999) as $i) {
            $rows[] = ['address' => '11.'.intdiv($i, 250).'.'.($i % 250).'.0/24', 'source' => 'panel', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            IpBlock::insert($chunk);
        }
        $lines = [];
        foreach (range(0, 499) as $k) {
            $lines[] = '11.0.'.($k % 250).'.'.(7 + intdiv($k, 250));   // inside a blocked range
            $lines[] = '12.0.'.($k % 250).'.'.(7 + intdiv($k, 250));   // free
        }

        $start = hrtime(true);
        $check = $this->blocks()->inspect(implode("\n", $lines));
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertCount(500, $check['new']);
        $this->assertCount(500, $check['skipped']);
        $this->assertSame('overlap', $check['skipped'][0]['why']);
        $this->assertStringContainsString('engelli 11.0.0.0/24 ile çakışıyor', $check['skipped'][0]['message']);
        $this->assertLessThan(1.5, $seconds, 'checking 1,000 addresses against 4,000 blocked ranges took '.round($seconds, 1).' s');
    }

    public function test_overlaps_are_found_in_both_directions_for_addresses_and_ranges_of_both_families(): void
    {
        IpBlock::create(['address' => '198.51.100.0/24', 'source' => 'panel']);
        IpBlock::create(['address' => '203.0.114.77', 'source' => 'ai']);
        IpBlock::create(['address' => '2001:db8:5::/48', 'source' => 'panel']);
        IpBlock::create(['address' => '2001:db8:9::1', 'source' => 'panel']);
        // Fail2ban's ban is not a block on the whole server: nothing overlaps it.
        IpBlock::create(['address' => '192.0.2.200', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);

        $check = $this->blocks()->inspect(implode("\n", [
            '198.51.100.9',          // inside a blocked range
            '198.51.0.0/16',         // around a blocked range
            '198.51.100.128/25',     // inside a blocked range, as a range
            '203.0.114.0/24',        // around a blocked address
            '2001:db8:5:1::7',       // inside a blocked IPv6 range
            '2001:db8:9::/64',       // around a blocked IPv6 address
            '198.51.100.0/24',       // the blocked range itself: its duration changes
            '203.0.114.77',          // the blocked address itself
            '192.0.2.0/24',          // around a Fail2ban ban only
            '198.51.101.1',          // next to the blocked range
            '10.9.0.0/16', '10.9.3.3', '10.8.0.5', '10.8.0.0/24', // overlaps inside the list itself
        ]));

        $why = array_column($check['skipped'], 'message', 'text');
        $this->assertSame([
            '198.51.100.9' => 'engelli 198.51.100.0/24 ile çakışıyor; önce o engeli kaldırın',
            '198.51.0.0/16' => 'engelli 198.51.100.0/24 ile çakışıyor; önce o engeli kaldırın',
            '198.51.100.128/25' => 'engelli 198.51.100.0/24 ile çakışıyor; önce o engeli kaldırın',
            '203.0.114.0/24' => 'engelli 203.0.114.77 ile çakışıyor; önce o engeli kaldırın',
            '2001:db8:5:1::7' => 'engelli 2001:db8:5::/48 ile çakışıyor; önce o engeli kaldırın',
            '2001:db8:9::/64' => 'engelli 2001:db8:9::1 ile çakışıyor; önce o engeli kaldırın',
            '10.9.3.3' => 'listedeki 10.9.0.0/16 ile çakışıyor',
            '10.8.0.0/24' => 'listedeki 10.8.0.5 ile çakışıyor',
        ], $why);
        $this->assertSame(['192.0.2.0/24', '198.51.101.1', '10.9.0.0/16', '10.8.0.5'], $check['new']);
        $this->assertEqualsCanonicalizing(['198.51.100.0/24', '203.0.114.77'], $check['update']->pluck('address')->all());
    }

    public function test_undoing_an_unblock_leaves_an_address_alone_that_was_blocked_again_meanwhile(): void
    {
        $old = IpBlock::create(['address' => '192.0.2.7', 'source' => 'panel', 'expires_at' => now()->addMinutes(30)]);
        $ban = IpBlock::create(['address' => '192.0.2.8', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addMinutes(30)]);
        $this->blocks()->unblockMany([$old, $ban], $this->admin);
        // Before "Geri al" is pressed, Serfex AI blocks the first for good and Fail2ban bans the second again.
        $forever = $this->blocks()->block('192.0.2.7', null, 'Dakikada 900 istek', null, 'ai');
        $again = IpBlock::create(['address' => '192.0.2.8', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);
        $asked = count($this->agent->called('firewall.block.many'));

        $this->assertSame(0, $this->blocks()->reblockMany([$old->fresh(), $ban->fresh()]));

        // The firewall is not given the old remaining time, and each address is in the list once.
        $this->assertCount($asked, $this->agent->called('firewall.block.many'));
        $this->assertSame([], $this->agent->called('fail2ban.ban'));
        $this->assertSame([$forever->id], IpBlock::active()->where('address', '192.0.2.7')->pluck('id')->all());
        $this->assertSame([$again->id], IpBlock::active()->where('address', '192.0.2.8')->pluck('id')->all());
        $this->assertFalse($old->fresh()->isActive());
    }

    public function test_allowing_an_address_lifts_a_ban_fail2ban_made_a_moment_ago(): void
    {
        // The panel reads Fail2ban's bans once a minute; this one is not in its list yet.
        Setting::put('components.installed', ['fail2ban']);
        $this->agent->answers['fail2ban.status'] = ['installed' => true, 'running' => true, 'jails' => [
            ['name' => 'sshd', 'banned' => [['address' => '88.230.14.52', 'until' => now()->addMinutes(10)->toIso8601String()]]],
        ]];

        $result = $this->allows()->add('88.230.14.52', 'Ofis', $this->admin);

        $this->assertSame([['jail' => 'sshd', 'address' => '88.230.14.52']], $this->agent->called('fail2ban.unban'));
        $this->assertCount(1, $result['lifted']);
        $this->assertSame('allowed', IpBlock::sole()->ended);
    }

    public function test_a_read_only_administrator_never_makes_the_panel_send_the_allowed_list(): void
    {
        IpAllow::create(['address' => '78.224.63.12']);
        // The agent holds another list than the panel.
        $this->agent->answers['firewall.allow.list'] = ['allowed' => [], 'own' => ['203.0.113.10']];
        $this->actingAs(User::factory()->create(['role' => 'readonly']));

        $this->assertSame(['203.0.113.10'], IpAllows::serverAddresses($this->agent));
        $this->assertSame([], $this->agent->called('firewall.allow.apply'));

        // The next administrator who can change things does.
        Cache::flush();
        $this->actingAs($this->admin);
        IpAllows::serverAddresses($this->agent);
        $this->assertSame([['addresses' => ['78.224.63.12']]], $this->agent->called('firewall.allow.apply'));
    }

    public function test_the_allowed_list_is_not_sent_while_someone_else_is_changing_it(): void
    {
        IpAllow::create(['address' => '78.224.63.12']);
        // Another request has sent a list with a new entry and has not stored its row yet.
        $this->agent->answers['firewall.allow.list'] = ['allowed' => ['78.224.63.12', '192.0.2.50'], 'own' => ['203.0.113.10']];
        $busy = Cache::lock(IpAllows::LOCK, 30);
        $this->assertTrue($busy->get());

        $this->assertSame(['203.0.113.10'], IpAllows::serverAddresses($this->agent));
        $this->assertSame([], $this->agent->called('firewall.allow.apply'));

        $busy->release();
    }

    private function blocks(): IpBlocks
    {
        return app(IpBlocks::class);
    }

    private function allows(): IpAllows
    {
        return app(IpAllows::class);
    }

    /**
     * @param  list<array{text: string, message: string}>  $skipped
     */
    private static function reason(array $skipped, string $text): string
    {
        foreach ($skipped as $s) {
            if ($s['text'] === $text) {
                return $s['message'];
            }
        }

        return '(not skipped)';
    }

    // --- Checking a pasted list ---

    public function test_a_pasted_list_is_checked_before_anything_is_blocked(): void
    {
        $ai = IpBlock::create(['address' => '185.220.101.47', 'source' => 'ai', 'reason' => 'Dakikada 1.240 istek', 'expires_at' => now()->addDay()]);
        $ban = IpBlock::create(['address' => '103.153.79.6', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);
        IpBlock::create(['address' => '198.51.100.0/24', 'source' => 'panel']);
        IpBlock::create(['address' => '192.0.2.77', 'source' => 'panel']);
        IpBlock::create(['address' => '45.9.150.221', 'source' => 'panel', 'removed_at' => now()]); // over: blocking it is new
        IpAllow::create(['address' => '88.230.99.0/24']);

        $text = "45.9.150.221\n45.9.148.0/24\n2a0b:f4c0:16c:4::1\n185.220.101.47\n103.153.79.6\n88.230.14.52\n88.230.99.5\n"
            ."104.16.1.1\n203.0.113.10\n62.210.0.0/12\nbozuk-satir\n198.51.100.9\n192.0.2.0/24\n";
        $check = $this->blocks()->inspect($text, ['88.230.14.52']);

        $this->assertSame(['45.9.150.221', '45.9.148.0/24', '2a0b:f4c0:16c:4::1'], $check['new']);
        $this->assertEqualsCanonicalizing([$ai->id, $ban->id], $check['update']->pluck('id')->all());
        $this->assertFalse($check['over']);
        $this->assertStringContainsString('bağlantınızın adresi', self::reason($check['skipped'], '88.230.14.52'));
        $this->assertStringContainsString('izinli adreslerde', self::reason($check['skipped'], '88.230.99.5'));
        $this->assertStringContainsString('Cloudflare', self::reason($check['skipped'], '104.16.1.1'));
        $this->assertStringContainsString('sunucunun kendi adresi', self::reason($check['skipped'], '203.0.113.10'));
        $this->assertStringContainsString('aralık çok geniş', self::reason($check['skipped'], '62.210.0.0/12'));
        $this->assertStringContainsString('bir IP adresi değil', self::reason($check['skipped'], 'bozuk-satir'));
        $this->assertStringContainsString('198.51.100.0/24', self::reason($check['skipped'], '198.51.100.9'));
        $this->assertStringContainsString('192.0.2.77', self::reason($check['skipped'], '192.0.2.0/24'));
        $this->assertCount(8, $check['skipped']);
        $this->assertSame([], $this->agent->called('firewall.block.many'));
        $this->assertSame([], $this->agent->called('firewall.block'));
    }

    public function test_a_list_over_the_limit_is_said_to_be_over(): void
    {
        $text = '';
        for ($i = 0; $i <= IpBlocks::MAX_BATCH; $i++) {
            $text .= '198.51.'.intdiv($i, 250).'.'.($i % 250 + 1)."\n";
        }
        $check = $this->blocks()->inspect($text);
        $this->assertTrue($check['over']);
        $this->assertCount(IpBlocks::MAX_BATCH + 1, $check['new']);
        $this->expectException(MonitorException::class);
        $this->blocks()->blockMany($check['new'], 60);
    }

    // --- Blocking in bulk ---

    public function test_blocking_a_list_asks_the_agent_once_and_writes_a_row_per_address(): void
    {
        $result = $this->blocks()->blockMany(['45.9.150.221', '45.9.148.7/24', '2A0B:F4C0:16C:4::1'], 10080, 'Kötü bot listesi', $this->admin);

        $this->assertSame([[
            'items' => [
                ['address' => '45.9.150.221', 'seconds' => 604800, 'reason' => 'Kötü bot listesi'],
                ['address' => '45.9.148.0/24', 'seconds' => 604800, 'reason' => 'Kötü bot listesi'],
                ['address' => '2a0b:f4c0:16c:4::1', 'seconds' => 604800, 'reason' => 'Kötü bot listesi'],
            ],
        ]], $this->agent->called('firewall.block.many'));
        $this->assertSame(3, IpBlock::active()->count());
        $this->assertSame(IpBlock::orderBy('id')->pluck('id')->all(), $result['created']);
        $this->assertSame([], $result['skipped']);
        $block = IpBlock::where('address', '45.9.148.0/24')->sole();
        $this->assertSame(['Kötü bot listesi', 'panel', $this->admin->id], [$block->reason, $block->source, $block->user_id]);
        $this->assertTrue($block->expires_at->between(now()->addMinutes(10079), now()->addMinutes(10081)));

        // Kalıcı: no end.
        $this->blocks()->blockMany(['192.0.2.9'], 0, '', $this->admin);
        $this->assertNull(IpBlock::where('address', '192.0.2.9')->sole()->expires_at);
        $this->assertSame(0, $this->agent->called('firewall.block.many')[1]['items'][0]['seconds']);
    }

    public function test_an_address_already_blocked_gets_the_new_duration_and_what_it_had_is_remembered(): void
    {
        $until = now()->addHours(5)->startOfSecond();
        $ai = IpBlock::create(['address' => '185.220.101.47', 'source' => 'ai', 'reason' => 'Dakikada 1.240 istek', 'kind' => 'flood', 'expires_at' => $until]);

        $result = $this->blocks()->blockMany(['185.220.101.47'], 10080, 'Liste', $this->admin);

        $this->assertSame(1, IpBlock::count());
        $this->assertSame([], $result['created']);
        $ai->refresh();
        $this->assertSame(['Liste', 'panel', $this->admin->id], [$ai->reason, $ai->source, $ai->user_id]);
        $this->assertTrue($ai->expires_at->isAfter(now()->addDays(6)));
        $before = $result['previous'][$ai->id];
        $this->assertSame(['ai', 'Dakikada 1.240 istek', 'flood', null], [$before['source'], $before['reason'], $before['kind'], $before['user_id']]);
        $this->assertTrue($until->equalTo($before['expires_at']));

        // Undo: the block is what it was, for the time it had left.
        $this->assertSame(1, $this->blocks()->restore($result['previous']));
        $ai->refresh();
        $this->assertSame(['Dakikada 1.240 istek', 'ai', null], [$ai->reason, $ai->source, $ai->user_id]);
        $this->assertTrue($until->equalTo($ai->expires_at));
        $item = $this->agent->called('firewall.block.many')[1]['items'][0];
        $this->assertSame('185.220.101.47', $item['address']);
        $this->assertEqualsWithDelta(5 * 3600, $item['seconds'], 5);
    }

    public function test_undoing_a_change_to_a_block_that_would_be_over_by_now_ends_it(): void
    {
        $block = IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'expires_at' => now()->addMinute()]);
        $result = $this->blocks()->blockMany(['192.0.2.4'], 0);
        $this->travel(3)->minutes();
        $this->assertSame(1, $this->blocks()->restore($result['previous']));
        $this->assertFalse($block->fresh()->isActive());
        $this->assertSame([['addresses' => ['192.0.2.4']]], $this->agent->called('firewall.unblock.many'));
        $this->assertSame('Süresi doldu', $block->fresh()->endedLabel());
    }

    public function test_nothing_is_written_when_the_agent_cannot_be_reached_or_the_firewall_refuses(): void
    {
        foreach ([new AgentUnavailableException('connection refused'), new AgentException('netlink: Error: Could not process rule', -32000)] as $failure) {
            $this->agent->answers['firewall.block.many'] = $failure;
            try {
                $this->blocks()->blockMany(['192.0.2.1', '192.0.2.2'], 60, 'x', $this->admin);
                $this->fail('the failure was swallowed');
            } catch (AgentException $e) {
                $this->assertSame($failure, $e);
            }
            $this->assertSame(0, IpBlock::count());
        }
    }

    public function test_what_the_agent_skips_is_reported_and_not_stored(): void
    {
        $this->agent->answers['firewall.block.many'] = fn ($p) => [
            'blocked' => [['address' => '192.0.2.1']],
            'skipped' => [
                ['address' => '192.0.2.9', 'error' => '192.0.2.9 overlaps 192.0.2.0/24, which is already blocked; remove that block first'],
                ['address' => '203.0.113.77', 'error' => "203.0.113.77 cannot be blocked: it is this server's own address 203.0.113.77"],
                ['address' => '198.51.100.3', 'error' => '198.51.100.3 cannot be blocked: it is in the allowed list (198.51.100.0/24)'],
            ],
        ];
        $result = $this->blocks()->blockMany(['192.0.2.1', '192.0.2.9', '203.0.113.77', '198.51.100.3'], 60);
        $this->assertSame(['192.0.2.1'], IpBlock::pluck('address')->all());
        $this->assertSame('engelli 192.0.2.0/24 ile çakışıyor; önce o engeli kaldırın', self::reason($result['skipped'], '192.0.2.9'));
        $this->assertStringContainsString('sunucunun kendi adresi', self::reason($result['skipped'], '203.0.113.77'));
        $this->assertStringContainsString('izinli adreslerde', self::reason($result['skipped'], '198.51.100.3'));
    }

    public function test_a_fail2ban_ban_blocked_from_a_list_becomes_a_block_on_the_whole_server(): void
    {
        $ban = IpBlock::create(['address' => '103.153.79.6', 'source' => 'fail2ban', 'kind' => 'sshd', 'reason' => 'Fail2ban', 'expires_at' => now()->addHour()]);
        $result = $this->blocks()->blockMany(['103.153.79.6'], 1440, 'Liste', $this->admin);

        $this->assertSame([['jail' => 'sshd', 'address' => '103.153.79.6']], $this->agent->called('fail2ban.unban'));
        $this->assertSame([$ban->id], $result['replaced']);
        $ban->refresh();
        $this->assertSame(['replaced', $this->admin->id], [$ban->ended, $ban->removed_by]);
        $this->assertFalse($ban->isActive());
        $new = IpBlock::active()->sole();
        $this->assertSame(['103.153.79.6', 'panel', null], [$new->address, $new->source, $new->kind]);
        $this->assertSame([$new->id], $result['created']);
    }

    public function test_more_addresses_than_one_call_takes_go_in_batches(): void
    {
        $ended = [];
        for ($i = 0; $i < IpBlocks::MAX_BATCH + 5; $i++) {
            $ended[] = IpBlock::create(['address' => '198.51.'.intdiv($i, 250).'.'.($i % 250 + 1), 'source' => 'panel', 'removed_at' => now()]);
        }
        $result = $this->blocks()->blockAgain($ended, $this->admin);
        $calls = $this->agent->called('firewall.block.many');
        $this->assertSame([IpBlocks::MAX_BATCH, 5], array_map(fn ($c) => count($c['items']), $calls));
        $this->assertCount(IpBlocks::MAX_BATCH + 5, $result['created']);
    }

    // --- Unblocking in bulk ---

    public function test_unblocking_many_asks_the_firewall_once_and_passes_over_blocks_that_are_already_over(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);
        $b = IpBlock::create(['address' => '192.0.2.2', 'source' => 'ai', 'expires_at' => now()->addHour()]);
        $ban = IpBlock::create(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);
        $ranOut = IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'expires_at' => now()->subMinute()]);
        $lifted = IpBlock::create(['address' => '192.0.2.5', 'source' => 'panel', 'removed_at' => now()->subHour(), 'ended' => 'user']);

        $done = $this->blocks()->unblockMany([$a, $b, $ban, $ranOut, $lifted], $this->admin);

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $ban->id], $done);
        $this->assertSame([['addresses' => ['192.0.2.1', '192.0.2.2']]], $this->agent->called('firewall.unblock.many'));
        $this->assertSame([['jail' => 'sshd', 'address' => '192.0.2.3']], $this->agent->called('fail2ban.unban'));
        foreach ([$a, $b, $ban] as $block) {
            $block->refresh();
            $this->assertSame(['user', $this->admin->id], [$block->ended, $block->removed_by]);
            $this->assertNotNull($block->removed_at);
        }
        $this->assertNull($ranOut->fresh()->removed_at);
        $this->assertTrue($lifted->removed_at->equalTo($lifted->fresh()->removed_at));
    }

    public function test_blocks_stay_when_the_agent_fails_to_unblock(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);
        $this->agent->answers['firewall.unblock.many'] = new AgentUnavailableException('connection refused');
        try {
            $this->blocks()->unblockMany([$a], $this->admin);
            $this->fail('the failure was swallowed');
        } catch (AgentException) {
        }
        $this->assertTrue($a->fresh()->isActive());
    }

    public function test_undoing_an_unblock_brings_the_same_blocks_back_for_the_time_they_had_left(): void
    {
        $timed = IpBlock::create(['address' => '192.0.2.1', 'source' => 'ai', 'reason' => 'Sel', 'expires_at' => now()->addHours(2)]);
        $forever = IpBlock::create(['address' => '192.0.2.2', 'source' => 'panel', 'user_id' => $this->admin->id]);
        $ban = IpBlock::create(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);
        $short = IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'expires_at' => now()->addMinute()]);
        $done = $this->blocks()->unblockMany([$timed, $forever, $ban, $short], $this->admin);
        $this->travel(2)->minutes(); // the short one would be over by now

        $this->assertSame(3, $this->blocks()->reblockMany(IpBlock::whereKey($done)->get()));

        $this->assertSame(5, IpBlock::count() + 1, 'no second row may appear for a block that came back');
        foreach ([$timed, $forever, $ban] as $block) {
            $block->refresh();
            $this->assertTrue($block->isActive(), $block->address);
            $this->assertSame([null, null], [$block->ended, $block->removed_by]);
        }
        $this->assertSame('ai', $timed->source);
        $this->assertFalse($short->fresh()->isActive());
        $items = $this->agent->called('firewall.block.many')[0]['items'];
        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_column($items, 'address'));
        $this->assertEqualsWithDelta(2 * 3600 - 120, $items[0]['seconds'], 5);
        $this->assertSame(0, $items[1]['seconds']);
        $this->assertSame([['jail' => 'sshd', 'address' => '192.0.2.3']], $this->agent->called('fail2ban.ban'));
    }

    // --- Changing the duration ---

    public function test_changing_the_duration_keeps_who_blocked_and_turns_fail2ban_bans_into_blocks_on_the_whole_server(): void
    {
        $until = now()->addHours(3)->startOfSecond();
        $ai = IpBlock::create(['address' => '192.0.2.1', 'source' => 'ai', 'reason' => 'Sel', 'expires_at' => $until]);
        $ban = IpBlock::create(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'reason' => 'Fail2ban: SSH', 'expires_at' => now()->addHour()]);
        $over = IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'expires_at' => now()->subMinute()]);

        $result = $this->blocks()->changeDuration([$ai, $ban, $over], 0, $this->admin);

        $ai->refresh();
        $this->assertSame(['ai', 'Sel', null], [$ai->source, $ai->reason, $ai->user_id]);
        $this->assertNull($ai->expires_at);
        $this->assertTrue($until->equalTo($result['previous'][$ai->id]['expires_at']));
        $this->assertSame('replaced', $ban->fresh()->ended);
        $new = IpBlock::active()->where('address', '192.0.2.3')->sole();
        $this->assertSame(['panel', 'Fail2ban: SSH', $this->admin->id, null], [$new->source, $new->reason, $new->user_id, $new->expires_at]);
        $this->assertSame([$new->id], $result['created']);
        $this->assertSame([$ban->id], $result['replaced']);
        $this->assertFalse($over->fresh()->isActive());
        $this->assertSame(['192.0.2.1', '192.0.2.3'], array_column($this->agent->called('firewall.block.many')[0]['items'], 'address'));
    }

    // --- History ---

    public function test_blocking_again_from_history_uses_the_length_the_block_had(): void
    {
        $day = IpBlock::forceCreate(['address' => '192.0.2.1', 'source' => 'ai', 'reason' => 'Sel', 'kind' => 'flood', 'expires_at' => now()->subHour(), 'created_at' => now()->subHours(25)]);
        $forever = IpBlock::create(['address' => '192.0.2.2', 'source' => 'panel', 'removed_at' => now()->subDay(), 'ended' => 'user']);
        $ban = IpBlock::forceCreate(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'reason' => 'Fail2ban: SSH', 'expires_at' => now()->subMinutes(5), 'created_at' => now()->subMinutes(65)]);
        $again = IpBlock::create(['address' => '192.0.2.5', 'source' => 'panel', 'removed_at' => now()->subDay()]);
        IpBlock::create(['address' => '192.0.2.5', 'source' => 'panel']); // blocked again since
        $stillOn = IpBlock::create(['address' => '192.0.2.6', 'source' => 'panel']);

        $result = $this->blocks()->blockAgain([$day, $forever, $ban, $again, $stillOn], $this->admin);

        $items = $this->agent->called('firewall.block.many')[0]['items'];
        $this->assertSame(['192.0.2.1', '192.0.2.2', '192.0.2.3'], array_column($items, 'address'));
        $this->assertSame([86400, 0, 3600], array_column($items, 'seconds'));
        $this->assertCount(3, $result['created']);
        $new = IpBlock::active()->where('address', '192.0.2.1')->sole();
        $this->assertSame(['panel', 'Sel', 'flood', $this->admin->id], [$new->source, $new->reason, $new->kind, $new->user_id]);
        $this->assertNull(IpBlock::active()->where('address', '192.0.2.3')->sole()->kind);
        $this->assertSame(1, IpBlock::active()->where('address', '192.0.2.5')->count());
        $this->assertFalse($day->fresh()->isActive(), 'the old row stays in the history');
    }

    // --- The allowed list ---

    public function test_allowing_an_address_gives_the_agent_the_whole_list_and_lifts_the_blocks_on_it(): void
    {
        IpAllow::create(['address' => '34.74.90.64/28']);
        $block = IpBlock::create(['address' => '88.230.14.52', 'source' => 'panel']);
        $ban = IpBlock::create(['address' => '88.230.14.52', 'source' => 'fail2ban', 'kind' => 'serfex-panel', 'expires_at' => now()->addHour()]);
        $wide = IpBlock::create(['address' => '88.230.0.0/16', 'source' => 'panel']);
        $other = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);
        $result = $this->allows()->add('88.230.14.52', 'Ofis (sabit IP)', $this->admin);

        $this->assertSame([['addresses' => ['34.74.90.64/28', '88.230.14.52']]], $this->agent->called('firewall.allow.apply'));
        $this->assertSame(['88.230.14.52', 'Ofis (sabit IP)', $this->admin->id], [$result['allow']->address, $result['allow']->note, $result['allow']->user_id]);
        $this->assertEqualsCanonicalizing([$block->id, $ban->id], $result['lifted']);
        $this->assertSame(['allowed', 'allowed'], [$block->fresh()->ended, $ban->fresh()->ended]);
        $this->assertSame([['addresses' => ['88.230.14.52']]], $this->agent->called('firewall.unblock.many'));
        $this->assertSame([['jail' => 'serfex-panel', 'address' => '88.230.14.52']], $this->agent->called('fail2ban.unban'));
        // The wider block stays: the allowed address is let through it.
        $this->assertTrue($wide->fresh()->isActive());
        $this->assertTrue($other->fresh()->isActive());
    }

    public function test_an_allowed_range_lifts_every_block_inside_it(): void
    {
        $in = IpBlock::create(['address' => '198.51.100.7', 'source' => 'ai', 'expires_at' => now()->addHour()]);
        $inRange = IpBlock::create(['address' => '198.51.100.128/25', 'source' => 'panel']);
        $out = IpBlock::create(['address' => '198.51.101.7', 'source' => 'panel']);
        $result = $this->allows()->add('198.51.100.9/24');
        $this->assertSame('198.51.100.0/24', $result['allow']->address);
        $this->assertEqualsCanonicalizing([$in->id, $inRange->id], $result['lifted']);
        $this->assertTrue($out->fresh()->isActive());
    }

    public function test_an_allowed_address_is_not_blocked_by_hand_by_serfex_ai_or_from_a_list(): void
    {
        IpAllow::create(['address' => '88.230.14.0/24']);
        foreach (['panel', 'ai', 'api'] as $source) {
            try {
                $this->blocks()->block('88.230.14.52', 60, source: $source);
                $this->fail($source.' blocked an allowed address');
            } catch (MonitorException $e) {
                $this->assertStringContainsString('izinli adreslerde', $e->getMessage());
            }
        }
        $result = $this->blocks()->blockMany(['88.230.14.52', '88.230.14.0/24', '192.0.2.1'], 60);
        $this->assertSame(['192.0.2.1'], IpBlock::pluck('address')->all());
        $this->assertCount(2, $result['skipped']);
        $this->assertSame([], $this->agent->called('firewall.block'));
        // A wider range around it may be blocked.
        $this->blocks()->block('88.230.0.0/16', 60);
        $this->assertSame('88.230.14.0/24', $this->allows()->covering('88.230.14.52')?->address);
        $this->assertNull($this->allows()->covering('88.230.15.1'));
        $this->assertNull($this->allows()->covering('88.230.0.0/16'));
    }

    public function test_what_is_always_allowed_or_already_on_the_list_is_refused(): void
    {
        IpAllow::create(['address' => '34.74.90.64/28']);
        foreach ([
            '104.16.1.1' => 'Cloudflare',
            '203.0.113.10' => 'sunucunun kendi adresi',
            '10.0.0.0/8' => 'Aralık çok geniş',
            '127.0.0.1' => 'yerel adresler',
            'ofis' => 'bir IP adresi değil',
            '34.74.90.64/28' => 'zaten izinli',
            '34.74.90.70' => 'zaten izinli',
        ] as $address => $message) {
            try {
                $this->allows()->add((string) $address);
                $this->fail($address.' was accepted');
            } catch (MonitorException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), (string) $address);
            }
        }
        $this->assertSame(1, IpAllow::count());
        $this->assertSame([], $this->agent->called('firewall.allow.apply'));
    }

    public function test_the_allowed_list_is_unchanged_when_the_agent_fails(): void
    {
        $block = IpBlock::create(['address' => '88.230.14.52', 'source' => 'panel']);
        $this->agent->answers['firewall.allow.apply'] = new AgentUnavailableException('connection refused');
        try {
            $this->allows()->add('88.230.14.52');
            $this->fail('the failure was swallowed');
        } catch (AgentException) {
        }
        $this->assertSame(0, IpAllow::count());
        $this->assertTrue($block->fresh()->isActive());
    }

    public function test_removing_allowed_addresses_gives_the_agent_the_shorter_list_and_they_can_come_back(): void
    {
        $a = IpAllow::create(['address' => '88.230.14.52', 'note' => 'Ofis', 'user_id' => $this->admin->id]);
        $b = IpAllow::create(['address' => '34.74.90.64/28']);
        $removed = $this->allows()->remove([$a]);
        $this->assertSame([['address' => '88.230.14.52', 'note' => 'Ofis']], $removed);
        $this->assertSame([['addresses' => ['34.74.90.64/28']]], $this->agent->called('firewall.allow.apply'));
        $this->assertSame([$b->id], IpAllow::pluck('id')->all());

        $back = $this->allows()->addMany($removed, $this->admin);
        $this->assertCount(1, $back['allows']);
        $this->assertSame('Ofis', IpAllow::where('address', '88.230.14.52')->sole()->note);
    }

    public function test_the_servers_own_addresses_come_from_the_agent_and_are_remembered(): void
    {
        $this->assertSame(['203.0.113.10', '2001:db8::10'], $this->allows()->own());
        $this->allows()->own();
        $this->assertCount(1, $this->agent->called('firewall.allow.list'));
        $this->assertSame([], $this->agent->called('firewall.allow.apply'));

        // The agent cannot be reached: no addresses, and it is asked again next time.
        Cache::flush();
        $this->agent->answers['firewall.allow.list'] = new AgentUnavailableException('connection refused');
        $this->assertSame([], $this->allows()->own());
        $this->assertSame([], $this->allows()->own());
        $this->assertCount(3, $this->agent->called('firewall.allow.list'));
    }

    public function test_an_agent_that_lost_the_allowed_list_gets_it_back(): void
    {
        // After a restore or a move the panel knows the list and the agent does not.
        IpAllow::create(['address' => '88.230.14.52']);
        IpAllow::create(['address' => '34.74.90.64/28']);
        $this->agent->answers['firewall.allow.list'] = ['allowed' => ['34.74.90.64/28'], 'own' => ['203.0.113.10']];
        $this->assertSame(['203.0.113.10', '2001:db8::10'], $this->allows()->own());
        $this->assertSame([['addresses' => ['88.230.14.52', '34.74.90.64/28']]], $this->agent->called('firewall.allow.apply'));
    }

    // --- Countries ---

    public function test_countries_are_looked_up_once_for_rows_that_have_none(): void
    {
        $this->agent->answers['geo.lookup'] = fn ($p) => ['loaded' => true, 'countries' => array_combine($p['addresses'], array_map(
            fn ($a) => ['45.155.205.233' => 'RU', '88.230.0.0/16' => 'TR'][$a] ?? '', $p['addresses']))];
        $ru = IpBlock::create(['address' => '45.155.205.233', 'source' => 'panel']);
        $tr = IpAllow::create(['address' => '88.230.0.0/16']);
        $unknown = IpBlock::create(['address' => '10.9.8.7', 'source' => 'panel']);
        $known = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'country' => 'DE']);
        $touched = $ru->updated_at;

        $this->travel(5)->minutes();
        app(AddressGeo::class)->fill([$ru, $tr, $unknown, $known]);
        app(AddressGeo::class)->fill([$ru->fresh(), $tr->fresh(), $unknown->fresh(), $known->fresh()]);

        $this->assertSame([['addresses' => ['45.155.205.233', '88.230.0.0/16', '10.9.8.7']]], $this->agent->called('geo.lookup'));
        $this->assertSame(['RU', 'TR', 'ZZ', 'DE'], [$ru->fresh()->country, $tr->fresh()->country, $unknown->fresh()->country, $known->fresh()->country]);
        $this->assertTrue($touched->equalTo($ru->fresh()->updated_at), 'looking a country up is not a change to the block');
        $this->assertSame('Rusya', AddressGeo::name('RU'));
        $this->assertSame('', AddressGeo::name('ZZ'));
        $this->assertSame('', AddressGeo::name(null));
    }

    public function test_without_a_country_table_or_an_agent_nothing_is_stored(): void
    {
        $block = IpBlock::create(['address' => '45.155.205.233', 'source' => 'panel']);
        $this->agent->answers['geo.lookup'] = ['loaded' => false, 'countries' => ['45.155.205.233' => '']];
        app(AddressGeo::class)->fill([$block]);
        $this->assertNull($block->fresh()->country);
        $this->agent->answers['geo.lookup'] = new AgentUnavailableException('connection refused');
        app(AddressGeo::class)->fill([$block]);
        $this->assertNull($block->fresh()->country);
    }

    // --- Fail2ban and visits ---

    public function test_a_ban_fail2ban_lifted_itself_says_so_in_the_history(): void
    {
        $this->agent->answers['fail2ban.status'] = ['installed' => true, 'running' => true, 'current' => true, 'jails' => [['name' => 'sshd', 'banned' => []]]];
        $ban = IpBlock::create(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);
        app(Fail2ban::class)->sync();
        $this->assertSame('Fail2ban kaldırdı', $ban->fresh()->endedLabel());
    }

    public function test_what_an_address_did_is_asked_once_in_five_minutes(): void
    {
        $this->agent->answers['logs.address'] = fn ($p) => ['address' => $p['address'], 'requests' => 7, 'sites' => [], 'recent' => []];
        $visits = app(AddressVisits::class);
        $this->assertSame(7, $visits->for('45.155.205.233')['requests']);
        $visits->for('45.155.205.233');
        $this->assertSame([['address' => '45.155.205.233', 'limit' => 200]], $this->agent->called('logs.address'));
        $visits->for('45.155.205.233', fresh: true);
        $this->assertCount(2, $this->agent->called('logs.address'));
        $this->travel(6)->minutes();
        $visits->for('45.155.205.233');
        $this->assertCount(3, $this->agent->called('logs.address'));
    }
}
