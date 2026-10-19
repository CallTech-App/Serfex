<?php

namespace Tests\Feature;

use App\Livewire\Monitor\LiveMonitor;
use App\Models\IpBlock;
use App\Models\MonitorSample;
use App\Models\ResourceLimit;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\LiveStats;
use App\Services\Monitor\MonitorException;
use App\Services\Monitor\ResourceLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class LiveMonitorTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $shop;

    private Site $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->shop = Site::factory()->for(SystemUser::factory()->create(['name' => 'magaza']), 'systemUser')->create(['domain' => 'magazaonline.com.tr']);
        $this->blog = Site::factory()->for(SystemUser::factory()->create(['name' => 'blog']), 'systemUser')->create(['domain' => 'gunluk.net']);
        $this->agent = new FakeAgent([
            'monitor.live' => fn () => $this->snapshot(),
            'firewall.block' => fn ($p) => ['address' => $p['address']],
            'php.restart' => ['stopped' => 3],
            'mail.queue' => [
                'installed' => true, 'active' => 1, 'deferred' => 40, 'hold' => 0,
                'domains' => [['domain' => 'gunluk.net', 'active' => 1, 'deferred' => 40, 'hold' => 0, 'oldest' => now()->subHour()->toIso8601String(), 'reason' => 'Connection timed out']],
                'messages' => [],
            ],
            'mail.queue.hold' => ['messages' => 41],
            'mail.queue.release' => ['messages' => 41],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /**
     * @return array<string, mixed>
     */
    private function site(string $domain, string $user, array $extra = []): array
    {
        return array_replace_recursive([
            'domain' => $domain, 'user' => $user, 'cpu' => 5.0, 'memory' => 100 * 1048576, 'io' => 0, 'processes' => 2,
            'php' => ['known' => true, 'busy' => 1, 'workers' => 2, 'max' => 10, 'queue' => 0, 'max_reached' => false],
            'requests' => 2.5, 'client_errors' => 0, 'server_errors' => 0,
            'db' => ['connections' => 0, 'active' => 0, 'longest' => 0], 'mail_per_hour' => 0, 'top_ips' => [], 'limit' => null,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'at' => now()->toIso8601String(), 'window' => 10, 'interval' => 2,
            'server' => ['cpus' => 4, 'memory_total' => 8 << 30, 'load' => [0.5, 0.4, 0.3], 'uptime' => 90000, 'cpu' => 30, 'memory' => 3 << 30],
            'sites' => [
                $this->site('gunluk.net', 'blog', ['cpu' => 40.0, 'mail_per_hour' => 450]),
                $this->site('magazaonline.com.tr', 'magaza', [
                    'cpu' => 95.0, 'requests' => 160,
                    'php' => ['busy' => 10, 'workers' => 10, 'max_reached' => true, 'queue' => 20],
                    'top_ips' => [['ip' => '45.155.205.233', 'requests' => 620], ['ip' => '88.230.149.171', 'requests' => 2]],
                ]),
            ],
            'accounts' => [['user' => 'ajans', 'cpu' => 1.5, 'memory' => 50 << 20, 'io' => 0, 'processes' => 1, 'mail_per_hour' => 0, 'db' => ['connections' => 1, 'active' => 0, 'longest' => 0], 'sites' => ['a.com', 'b.com']]],
            'services' => [
                ['unit' => 'servik-web.service', 'name' => 'Servik paneli (web)', 'group' => 'servik', 'cpu' => 2, 'memory' => 140 << 20, 'io' => 0, 'processes' => 3],
                ['unit' => 'nginx.service', 'name' => 'Nginx', 'group' => 'shared', 'cpu' => 5, 'memory' => 64 << 20, 'io' => 0, 'processes' => 5],
            ],
            'suspects' => [
                ['ip' => '45.155.205.233', 'target' => 'magazaonline.com.tr', 'kind' => 'flood', 'detail' => '', 'count' => 620, 'peak' => 620, 'since' => now()->subMinutes(3)->toIso8601String(), 'last' => now()->toIso8601String()],
                ['ip' => '45.155.205.233', 'target' => 'magazaonline.com.tr', 'kind' => 'login', 'detail' => '/wp-login.php', 'count' => 600, 'peak' => 600, 'since' => now()->subMinutes(3)->toIso8601String(), 'last' => now()->toIso8601String()],
                ['ip' => '203.0.113.77', 'target' => '', 'kind' => 'mail', 'detail' => 'imap', 'count' => 88, 'peak' => 88, 'since' => now()->subMinutes(9)->toIso8601String(), 'last' => now()->toIso8601String()],
            ],
            'databases' => ['mysql'],
        ];
    }

    public function test_sites_are_listed_worst_first_with_the_attacked_site_on_top(): void
    {
        $this->get('/monitor')
            ->assertOk()
            ->assertSeeInOrder(['magazaonline.com.tr', 'Saldırı', 'gunluk.net', 'Yoğun posta'])
            ->assertSee('10 / 10')
            ->assertSee('20 istek sırada')
            ->assertSee('Hesabın sitelerin dışındaki işlemleri (2 site)');

        // The menu shows how many things need a look: the attacker of the
        // shop, the mail attacker and the busy mail site.
        $this->assertSame(3, Cache::get(LiveStats::ATTENTION_KEY));
        $this->get('/')->assertSee('3 site veya saldırı ilgilenmenizi bekliyor');
    }

    public function test_an_unreachable_agent_is_explained(): void
    {
        $this->agent->answers['monitor.live'] = new AgentUnavailableException('no socket');

        $this->get('/monitor')->assertOk()->assertSee('Sunucu ajanına ulaşılamıyor');
    }

    public function test_attacks_tab_merges_rules_per_address_and_shows_blocks(): void
    {
        IpBlock::create(['address' => '198.51.100.0/24', 'reason' => 'Tarama', 'source' => 'panel', 'expires_at' => now()->addMinutes(30)]);

        Livewire::test(LiveMonitor::class)
            ->call('setTab', 'saldirilar')
            ->assertSee('İstek seli, Giriş denemesi · /wp-login.php')
            ->assertSee('Posta girişi denemesi · IMAP')
            ->assertSee('88')
            ->assertSee('198.51.100.0/24')
            ->assertSee('30 dk kaldı');
    }

    public function test_blocking_an_address_from_the_dialog_and_undoing_it(): void
    {
        $page = Livewire::test(LiveMonitor::class)
            ->call('openBlock', '45.155.205.233', $this->shop->id, 'flood', 'İstek seli')
            ->set('block.minutes', 1440)
            ->call('saveBlock')
            ->assertHasNoErrors()
            ->assertSet('blocking', false)
            ->assertDispatched('toast', message: '45.155.205.233 24 saat engellendi.');

        $this->assertSame([['address' => '45.155.205.233', 'seconds' => 86400, 'reason' => 'İstek seli']], $this->agent->called('firewall.block'));
        $block = IpBlock::sole();
        $this->assertSame($this->shop->id, $block->site_id);
        $this->assertTrue($block->expires_at->between(now()->addMinutes(1439), now()->addMinutes(1441)));

        $page->call('unblock', $block->id);
        $this->assertSame([['address' => '45.155.205.233']], $this->agent->called('firewall.unblock'));
        $this->assertNotNull($block->fresh()->removed_at);
    }

    public function test_bad_addresses_are_refused_before_the_agent_is_asked(): void
    {
        foreach (['example.com' => 'bir IP adresi değil', '10.0.0.0/8' => 'Aralık çok geniş', '127.0.0.1' => 'yerel adresler'] as $address => $message) {
            Livewire::test(LiveMonitor::class)
                ->call('openBlock', $address)
                ->call('saveBlock')
                ->assertHasErrors('block.address')
                ->assertSee($message);
        }
        $this->assertSame([], $this->agent->called('firewall.block'));
    }

    public function test_the_agents_refusal_is_shown(): void
    {
        $this->agent->answers['firewall.block'] = new AgentException('192.0.2.9 overlaps 192.0.2.0/24, which is already blocked', AgentException::INVALID_PARAMS);

        Livewire::test(LiveMonitor::class)
            ->call('openBlock', '192.0.2.9')
            ->call('saveBlock')
            ->assertHasErrors('block.address')
            ->assertSee('Bu adres zaten engellenmiş bir aralığın içinde.');
        $this->assertSame(0, IpBlock::count());
    }

    public function test_the_site_dialog_blocks_every_attacker_once(): void
    {
        Livewire::test(LiveMonitor::class)
            ->call('open', $this->shop->id)
            ->assertSee('1 adres bu siteye saldırıyor')
            ->assertSee('88.230.149.171')
            ->call('blockAttackers')
            ->assertDispatched('toast', message: '1 adres 1 saat engellendi.');

        $this->assertSame([['address' => '45.155.205.233', 'seconds' => 3600, 'reason' => 'İstek seli, Giriş denemesi · /wp-login.php']], $this->agent->called('firewall.block'));
        $this->assertSame('flood', IpBlock::sole()->kind);
    }

    public function test_a_temporary_limit_is_applied_and_lifted(): void
    {
        $page = Livewire::test(LiveMonitor::class)
            ->call('open', $this->shop->id)
            ->set('limit', ['cpu' => 50, 'memory' => 1024, 'php' => 4, 'minutes' => 60])
            ->call('applyLimit')
            ->assertDispatched('toast', message: 'magazaonline.com.tr 1 saat sınırlandı: CPU %50 · 1 GB bellek · 4 PHP işçisi.');

        $this->assertSame([['domain' => 'magazaonline.com.tr', 'cpu' => 50, 'memory_mb' => 1024, 'php_workers' => 4, 'seconds' => 3600]], $this->agent->called('monitor.limit.apply'));
        $limit = ResourceLimit::sole();
        $this->assertTrue($limit->isActive());

        $page->assertSee('Sınırlı: CPU %50 · 1 GB bellek · 4 PHP işçisi')
            ->call('liftLimit', $limit->id);
        $this->assertSame([['domain' => 'magazaonline.com.tr']], $this->agent->called('monitor.limit.remove'));
        $this->assertFalse($limit->fresh()->isActive());
    }

    public function test_a_new_limit_replaces_the_old_one(): void
    {
        $limits = app(ResourceLimits::class);
        $first = $limits->apply($this->shop, 100, null, null, 30);
        $second = $limits->apply($this->shop, 25, null, 2, 15);

        $this->assertFalse($first->fresh()->isActive());
        $this->assertSame($second->id, $this->shop->activeLimit()?->id);
    }

    public function test_limits_without_a_resource_are_refused(): void
    {
        Livewire::test(LiveMonitor::class)
            ->call('open', $this->shop->id)
            ->set('limit', ['cpu' => 0, 'memory' => 0, 'php' => 0, 'minutes' => 30])
            ->call('applyLimit')
            ->assertSet('actionError', 'CPU, bellek ya da PHP işçisinden en az birini sınırlayın.');
        $this->assertSame([], $this->agent->called('monitor.limit.apply'));
    }

    public function test_restarting_php(): void
    {
        Livewire::test(LiveMonitor::class)
            ->call('open', $this->shop->id)
            ->call('restartPhp')
            ->assertDispatched('toast', message: 'magazaonline.com.tr için 3 PHP işçisi durduruldu; yeni istekler yeni işçilerle karşılanıyor.');
        $this->assertSame([['domain' => 'magazaonline.com.tr']], $this->agent->called('php.restart'));
    }

    public function test_the_ai_is_held_to_short_reversible_actions(): void
    {
        try {
            app(IpBlocks::class)->block('192.0.2.1', null, source: 'ai');
            $this->fail('a permanent AI block was allowed');
        } catch (MonitorException) {
        }
        try {
            app(ResourceLimits::class)->apply($this->shop, 50, null, null, 360, source: 'ai');
            $this->fail('a six-hour AI limit was allowed');
        } catch (MonitorException) {
        }
        $this->assertSame('ai', app(IpBlocks::class)->block('192.0.2.1', 60, source: 'ai')->source);
        $this->assertSame('ai', app(ResourceLimits::class)->apply($this->shop, 50, null, null, 120, source: 'ai')->source);
    }

    public function test_the_callers_own_address_is_never_blocked(): void
    {
        $this->expectException(MonitorException::class);
        app(IpBlocks::class)->block('198.51.100.0/24', 60, protect: ['198.51.100.7']);
    }

    public function test_mail_queue_by_sender_domain_with_hold_and_release(): void
    {
        Livewire::test(LiveMonitor::class)
            ->call('setTab', 'posta')
            ->assertSee('gunluk.net')
            ->assertSee('Connection timed out')
            ->call('hold', 'gunluk.net')
            ->assertDispatched('toast', message: 'gunluk.net: 41 ileti bekletmeye alındı.')
            ->call('release', 'gunluk.net')
            ->call('flushQueue');

        $this->assertSame([['domain' => 'gunluk.net']], $this->agent->called('mail.queue.hold'));
        $this->assertSame([['domain' => 'gunluk.net']], $this->agent->called('mail.queue.release'));
        $this->assertCount(1, $this->agent->called('mail.queue.flush'));
    }

    public function test_mail_queue_when_postfix_is_missing(): void
    {
        $this->agent->answers['mail.queue'] = ['installed' => false, 'active' => 0, 'deferred' => 0, 'hold' => 0, 'domains' => [], 'messages' => []];

        Livewire::test(LiveMonitor::class)->call('setTab', 'posta')->assertSee('Posta sunucusu kurulu değil');
    }

    public function test_system_tab_separates_servik_from_shared_services(): void
    {
        $this->get('/monitor?sekme=sistem')
            ->assertSeeInOrder(['kendi hizmetleri', 'servik-web.service', 'Paylaşılan hizmetler', 'nginx.service'])
            ->assertSee('4 çekirdek')
            ->assertSee('1 gün 1 sa');
    }

    public function test_long_ranges_use_the_recorded_history(): void
    {
        MonitorSample::insert([
            ['recorded_at' => now()->subMinutes(30), 'site_id' => $this->blog->id, 'cpu' => 10, 'memory' => 0, 'io' => 0, 'requests' => 1, 'server_errors' => 0, 'php_busy' => 1, 'php_max' => 10, 'db_active' => 0, 'mail_per_hour' => 0],
            ['recorded_at' => now()->subMinutes(20), 'site_id' => $this->blog->id, 'cpu' => 20, 'memory' => 0, 'io' => 0, 'requests' => 3, 'server_errors' => 0, 'php_busy' => 1, 'php_max' => 10, 'db_active' => 0, 'mail_per_hour' => 0],
            ['recorded_at' => now()->subHours(3), 'site_id' => $this->blog->id, 'cpu' => 90, 'memory' => 0, 'io' => 0, 'requests' => 0, 'server_errors' => 0, 'php_busy' => 1, 'php_max' => 10, 'db_active' => 0, 'mail_per_hour' => 0],
        ]);

        Livewire::withQueryParams(['aralik' => '1h'])->test(LiveMonitor::class)
            ->assertSee('Son 1 saat ortalaması.')
            ->assertSee('%15');
    }

    public function test_the_recorder_keeps_a_day_of_per_minute_samples(): void
    {
        MonitorSample::insert(['recorded_at' => now()->subDays(2), 'site_id' => $this->shop->id, 'cpu' => 1, 'memory' => 0, 'io' => 0, 'requests' => 0, 'server_errors' => 0, 'php_busy' => null, 'php_max' => null, 'db_active' => 0, 'mail_per_hour' => 0]);

        $this->artisan('servik:monitor-record')->assertSuccessful();

        $this->assertSame([['window' => 60]], $this->agent->called('monitor.live'));
        $this->assertSame(3, MonitorSample::count(), 'two sites and the server; the old row is gone');
        $this->assertEquals(95.0, MonitorSample::where('site_id', $this->shop->id)->value('cpu'));
        $this->assertSame(10.0, (float) MonitorSample::where('site_id', $this->shop->id)->value('php_busy'));
    }

    public function test_members_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->member()->create());

        $this->get('/monitor')->assertForbidden();
    }
}
