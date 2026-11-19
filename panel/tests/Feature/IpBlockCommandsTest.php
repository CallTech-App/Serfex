<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\IpBlock;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * IP engelleri from the server's console: `serfex firewall unblock`
 * (serfex:unblock) for an administrator who is kept out, and the nightly
 * clean-up of Geçmiş (serfex:blocks-prune).
 */
class IpBlockCommandsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new FakeAgent([
            'firewall.unblock.many' => fn ($p) => ['removed' => 0],
            'fail2ban.status' => ['installed' => true, 'running' => true, 'jails' => []],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /**
     * Runs serfex:unblock --json and returns its status and what it printed.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function unblock(string $address): array
    {
        $status = Artisan::call('serfex:unblock', ['address' => $address, '--json' => true]);

        return [$status, json_decode(trim(Artisan::output()), true) ?? []];
    }

    public function test_the_console_lifts_the_block_and_the_bans_of_an_address(): void
    {
        $block = IpBlock::create(['address' => '203.0.113.77', 'source' => 'panel', 'reason' => 'Tarama']);
        $ban = IpBlock::create(['address' => '203.0.113.77', 'source' => 'fail2ban', 'kind' => 'serfex-panel', 'expires_at' => now()->addHour()]);
        $other = IpBlock::create(['address' => '203.0.113.78', 'source' => 'panel']);
        // Fail2ban still holds the ban the panel knows.
        $this->agent->answers['fail2ban.status']['jails'] = [['name' => 'serfex-panel', 'banned' => [['address' => '203.0.113.77', 'until' => $ban->expires_at->toIso8601String()]]]];
        Setting::put('components.installed', ['fail2ban']);

        [$status, $out] = $this->unblock(' 203.0.113.77 ');

        $this->assertSame(0, $status);
        $this->assertSame(['address' => '203.0.113.77', 'removed' => 2, 'lifted' => ['203.0.113.77', '203.0.113.77 (Fail2ban: Panel girişi)']], $out);
        $this->assertSame([['addresses' => ['203.0.113.77']]], $this->agent->called('firewall.unblock.many'));
        $this->assertSame([['jail' => 'serfex-panel', 'address' => '203.0.113.77']], $this->agent->called('fail2ban.unban'));
        $this->assertFalse($block->fresh()->isActive());
        $this->assertFalse($ban->fresh()->isActive());
        $this->assertTrue($other->fresh()->isActive());

        // Geçmiş says where it was lifted from, and so does İşlem günlüğü.
        $this->assertSame(['console', null], [$block->fresh()->ended, $block->fresh()->removed_by]);
        $this->assertSame('Sunucu konsolundan kaldırıldı', $block->fresh()->endedLabel());
        $entry = AuditEntry::sole();
        $this->assertSame(['Sunucu konsolu', 'system', '203.0.113.77 adresinin engeli sunucu konsolundan kaldırıldı.'], [$entry->actor, $entry->via, $entry->message]);
    }

    public function test_the_console_lifts_the_range_that_holds_the_address(): void
    {
        $range = IpBlock::create(['address' => '198.51.100.0/24', 'source' => 'panel']);
        $elsewhere = IpBlock::create(['address' => '198.51.101.0/24', 'source' => 'panel']);
        $this->agent->answers['firewall.unblock.many'] = fn ($p) => ['removed' => in_array('198.51.100.0/24', $p['addresses'], true) ? 1 : 0];

        [$status, $out] = $this->unblock('198.51.100.9');

        $this->assertSame(0, $status);
        $this->assertSame(['address' => '198.51.100.9', 'removed' => 1, 'lifted' => ['198.51.100.0/24']], $out);
        $this->assertFalse($range->fresh()->isActive());
        $this->assertTrue($elsewhere->fresh()->isActive());
    }

    public function test_a_ban_fail2ban_made_a_moment_ago_is_lifted_too(): void
    {
        // The panel reads Fail2ban's bans once a minute; this one is not in its list yet.
        Setting::put('components.installed', ['fail2ban']);
        $this->agent->answers['fail2ban.status']['jails'] = [['name' => 'sshd', 'banned' => [['address' => '192.0.2.44', 'until' => now()->addMinutes(10)->toIso8601String()]]]];

        [$status, $out] = $this->unblock('192.0.2.44');

        $this->assertSame(0, $status);
        $this->assertSame(1, $out['removed']);
        $this->assertSame([['jail' => 'sshd', 'address' => '192.0.2.44']], $this->agent->called('fail2ban.unban'));
        $this->assertFalse(IpBlock::sole()->isActive());
    }

    public function test_a_block_only_the_firewall_knows_is_lifted(): void
    {
        // The panel's database was restored; the firewall still blocks the address.
        $this->agent->answers['firewall.unblock.many'] = ['removed' => 1];

        [$status, $out] = $this->unblock('192.0.2.50');

        $this->assertSame(0, $status);
        $this->assertSame(['address' => '192.0.2.50', 'removed' => 1, 'lifted' => ['192.0.2.50']], $out);
    }

    public function test_an_address_that_is_not_blocked_is_said_so(): void
    {
        [$status, $out] = $this->unblock('192.0.2.60');
        $this->assertSame(0, $status);
        $this->assertSame(['address' => '192.0.2.60', 'removed' => 0, 'lifted' => []], $out);
        $this->assertSame(0, AuditEntry::count());

        $this->artisan('serfex:unblock', ['address' => '192.0.2.60'])->expectsOutputToContain('192.0.2.60 engelli değil.')->assertSuccessful();
        $this->artisan('serfex:unblock', ['address' => '2001:DB8::0:5'])->expectsOutputToContain('2001:db8::5 engelli değil.')->assertSuccessful();
    }

    public function test_what_is_not_an_address_is_refused_before_the_agent_is_asked(): void
    {
        [$status, $out] = $this->unblock('example.com');

        $this->assertSame(1, $status);
        $this->assertSame(['error' => '"example.com" bir IP adresi değil.'], $out);
        $this->assertSame([], $this->agent->called('firewall.unblock.many'));
        $this->artisan('serfex:unblock', ['address' => '10.0.0.0/33'])->expectsOutputToContain('geçerli bir adres aralığı değil')->assertFailed();
    }

    public function test_a_block_that_could_not_be_lifted_fails_the_command(): void
    {
        IpBlock::create(['address' => '203.0.113.77', 'source' => 'panel']);
        $this->agent->answers['firewall.unblock.many'] = new AgentUnavailableException('no socket');

        [$status, $out] = $this->unblock('203.0.113.77');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Engel kaldırılamadı', $out['error']);
        $this->assertTrue(IpBlock::sole()->isActive());

        // The block went, a ban stayed: the address is still kept out.
        Setting::put('components.installed', ['fail2ban']);
        $this->agent->answers['firewall.unblock.many'] = ['removed' => 1];
        $this->agent->answers['fail2ban.status']['jails'] = [['name' => 'sshd', 'banned' => [['address' => '203.0.113.77', 'until' => now()->addHour()->toIso8601String()]]]];
        $this->agent->answers['fail2ban.unban'] = AgentException::rpcError('fail2ban.unban', AgentException::OPERATION_FAILED, 'fail2ban-client timed out');

        [$status, $out] = $this->unblock('203.0.113.77');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('203.0.113.77 hâlâ engelli', $out['error']);
        $this->assertStringContainsString('Fail2ban: SSH', $out['error']);
    }

    public function test_history_is_kept_for_ninety_days(): void
    {
        $row = fn (string $address, array $data) => IpBlock::forceCreate(['address' => $address, 'source' => 'panel'] + $data);
        $gone = [
            $row('192.0.2.1', ['removed_at' => now()->subDays(91), 'ended' => 'user']),
            $row('192.0.2.2', ['expires_at' => now()->subDays(91)]),
            // Lifted long ago, although it was set to last longer.
            $row('192.0.2.3', ['removed_at' => now()->subDays(120), 'expires_at' => now()->addYear()]),
        ];
        $kept = [
            $row('192.0.2.4', ['removed_at' => now()->subDays(89), 'ended' => 'user']),
            $row('192.0.2.5', ['expires_at' => now()->subDays(89)]),
            // In force, however old.
            $row('192.0.2.6', ['created_at' => now()->subDays(400)]),
            $row('192.0.2.7', ['created_at' => now()->subDays(400), 'expires_at' => now()->addDay()]),
            $row('192.0.2.8', ['removed_at' => now()->subDays(2), 'expires_at' => now()->subDay(), 'ended' => 'allowed']),
        ];

        $this->artisan('serfex:blocks-prune')->expectsOutputToContain('3 eski engel kaydı silindi.')->assertSuccessful();

        foreach ($gone as $block) {
            $this->assertModelMissing($block);
        }
        foreach ($kept as $block) {
            $this->assertModelExists($block);
        }
    }

    public function test_history_is_cleaned_every_night(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'serfex:blocks-prune'));

        $this->assertNotNull($event);
        $this->assertSame('20 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
