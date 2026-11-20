<?php

namespace Tests\Feature;

use App\Livewire\Security\IpAddressShow;
use App\Models\CountryBlock;
use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Security\CountryBlocks;
use App\Support\When;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güvenlik > IP engelleri > one address: the sites it went to, its newest
 * requests, its blocks, and blocking, unblocking and allowing it there.
 */
class IpAddressPageTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = '45.155.205.233';

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Sercan Akın']);
        $this->actingAs($this->admin);
        $this->agent = new FakeAgent([
            'firewall.block.many' => fn ($p) => ['blocked' => array_map(fn ($i) => ['address' => $i['address']], $p['items']), 'skipped' => []],
            'firewall.unblock.many' => fn ($p) => ['removed' => count($p['addresses'])],
            'fail2ban.status' => FakeAgent::fail2banHoldsItsBans(),
            'firewall.allow.apply' => fn ($p) => ['allowed' => $p['addresses'], 'own' => ['203.0.113.10']],
            'firewall.allow.list' => fn () => ['allowed' => IpAllow::pluck('address')->all(), 'own' => ['203.0.113.10']],
            'geo.lookup' => fn ($p) => ['loaded' => true, 'countries' => array_fill_keys($p['addresses'], 'NL')],
            'logs.address' => fn ($p) => $this->report($p['address']),
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_the_address_page_says_when_the_country_is_blocked(): void
    {
        // Every address is in the Netherlands here.
        $this->get('/security/ip-blocks/address/'.self::ADDRESS)->assertOk()
            ->assertSee('Bu adresten gelen bağlantılar kabul ediliyor.')
            ->assertDontSee('ülkesi engelli');

        CountryBlock::create(['code' => 'NL']);
        $this->get('/security/ip-blocks/address/'.self::ADDRESS)->assertOk()
            ->assertSee('Adresin kendisi engelli değil, ama ülkesi engelli (Hollanda): web siteleri bu adrese 403 sayfası gösterir.')
            ->assertDontSee('Bu adresten gelen bağlantılar kabul ediliyor.')
            ->assertSee('href="'.e(route('security.blocks', ['tab' => 'countries'])).'"', false);

        Setting::put(CountryBlocks::SCOPE_KEY, 'server');
        $this->get('/security/ip-blocks/address/'.self::ADDRESS)->assertOk()
            ->assertSee('Adresin kendisi engelli değil, ama ülkesi engelli (Hollanda): sunucu bu adrese web, DNS ve gelen posta dışında yanıt vermez.');

        // An allowed address passes the country block.
        IpAllow::create(['address' => self::ADDRESS, 'note' => 'Ofis']);
        $this->get('/security/ip-blocks/address/'.self::ADDRESS)->assertOk()
            ->assertSee('Hiçbir zaman engellenmez: Ofis')
            ->assertSee('Ülkesi (Hollanda) engelli; izinli olduğu için ülke engelinden geçer.')
            ->assertDontSee('ama ülkesi engelli');
    }

    public function test_the_page_is_for_administrators_and_takes_addresses_and_ranges(): void
    {
        $this->get('/security/ip-blocks/address/45.155.205.233')->assertOk()->assertSee('45.155.205.233')->assertSee('IP engelleri');
        $this->get('/security/ip-blocks/address/192.0.2.0/24')->assertOk()->assertSee('192.0.2.0/24');
        $this->get('/security/ip-blocks/address/2A06:4880:1000::/48')->assertOk()->assertSee('2a06:4880:1000::/48');
        // Written another way, the address is the one the agent stores.
        $this->get('/security/ip-blocks/address/::ffff:192.0.2.9')->assertOk()->assertSee('192.0.2.9</h1>', false)->assertDontSee('::ffff:192.0.2.9</h1>', false);
        $this->get('/security/ip-blocks/address/bozuk-adres')->assertNotFound();
        $this->get('/security/ip-blocks/address/62.210.0.0/12')->assertNotFound();

        $this->actingAs(User::factory()->member()->create());
        $this->get('/security/ip-blocks/address/45.155.205.233')->assertForbidden();
    }

    public function test_it_shows_the_sites_the_address_went_to_once_the_logs_are_read(): void
    {
        $site = Site::factory()->create(['domain' => 'magazaonline.com.tr']);
        IpBlock::create(['address' => self::ADDRESS, 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now()->subDay(), 'ended' => 'sync']);

        $page = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])
            ->assertSee('Erişim günlükleri okunuyor')
            ->assertDontSee('magazaonline.com.tr');
        $this->assertSame([], $this->agent->called('logs.address'));

        $page->call('load')
            ->assertSee('Hollanda')
            ->assertSee('vps-2281.pfcloud.io')
            ->assertSeeText('4.812 istek')->assertSeeText('2 site')
            ->assertSeeText('4.057 site girişi')
            ->assertSee('magazaonline.com.tr')->assertSee('4.203')->assertSee('1.118')->assertSee('/wp-login.php')->assertSee('3.960')
            ->assertSee('kahveatolyesi.com.tr')->assertSee('/xmlrpc.php')
            ->assertSeeHtml(route('sites.show', $site))
            ->assertDontSee('Erişim günlükleri okunuyor');
        $this->assertSame([['address' => self::ADDRESS, 'limit' => 200]], $this->agent->called('logs.address'));

        // The answer is kept; "Yenile" reads the logs again.
        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('load')->assertSee('4.203');
        $this->assertCount(1, $this->agent->called('logs.address'));
        $page->call('reload');
        $this->assertCount(2, $this->agent->called('logs.address'));
    }

    public function test_the_newest_requests_are_filtered_by_site_reply_and_path(): void
    {
        $page = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('load')
            ->call('showRequests', 'kahveatolyesi.com.tr')
            ->assertSet('tab', 'requests')->assertSet('site', 'kahveatolyesi.com.tr')
            ->assertSee('/.env')->assertSee('python-requests/2.31.0')->assertDontSee('/wp-json/wp/v2/users');

        $page->set('site', '')->set('status', '403')
            ->assertSee('/wp-login.php')->assertDontSee('/.env')
            ->set('status', '4')->assertSee('/.env')->assertSee('/wp-login.php')->assertDontSee('/wp-json/wp/v2/users')
            ->set('status', '')->set('path', 'WP-JSON')
            ->assertSee('/wp-json/wp/v2/users')->assertDontSee('/.env')
            ->set('path', 'yok-böyle-bir-yol')->assertSee('Süzgece uyan istek yok.');
    }

    public function test_requests_and_agents_from_the_logs_are_shown_as_text(): void
    {
        $this->agent->answers['logs.address'] = fn ($p) => ['recent' => [
            ['at' => now()->toIso8601String(), 'domain' => 'magazaonline.com.tr', 'method' => 'GET', 'path' => '/?q=<script>alert(1)</script>', 'status' => 200, 'agent' => '<img src=x onerror=alert(2)>'],
        ]] + $this->report($p['address']);

        $html = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->set('tab', 'requests')->call('load')->html();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
    }

    public function test_a_lookup_that_failed_or_stopped_early_says_so(): void
    {
        $this->agent->answers['logs.address'] = AgentException::rpcError('logs.address', AgentException::OPERATION_FAILED, 'open /var/log/serfex/sites: permission denied');
        $page = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('load')
            ->assertSee('Erişim günlükleri okunamadı')->assertSee('permission denied')->assertSee('Yeniden dene');

        $this->agent->answers['logs.address'] = fn ($p) => ['truncated' => true] + $this->report($p['address']);
        $page->call('reload')
            ->assertDontSee('Erişim günlükleri okunamadı')
            ->assertSee('4.203')
            ->assertSee('Günlükler çok büyük olduğu için tamamı okunamadı')
            // The oldest line that was read is not where the logs start.
            ->assertDontSee('gününden bu yana');
    }

    public function test_times_inside_sentences_keep_month_names_and_the_page_says_how_far_back_the_logs_go(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', config('serfex.timezone')));
        IpBlock::forceCreate(['address' => self::ADDRESS, 'source' => 'panel', 'user_id' => $this->admin->id, 'reason' => 'Tarama', 'created_at' => now()->subDays(3)]);

        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('load')
            ->assertSeeText('Sercan Akın 16 Kas 12:00 engelledi: Tarama')
            ->assertSeeText('İlk 17 Kas 12:00 · son bugün 11:59')
            // How long logs are kept is a setting: the page says what was read.
            ->assertSeeText('Sitelerin erişim günlüklerinden, 5 Kas gününden bu yana.')
            ->assertDontSee('14 gün saklanır');

        $this->admin->forceFill(['locale' => 'en'])->save();
        app()->setLocale('en');
        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('load')
            ->assertSeeText('First Nov 17 12:00 PM · last today 11:59 AM');
    }

    public function test_an_address_that_never_came_says_so(): void
    {
        $this->agent->answers['logs.address'] = ['address' => '198.51.100.9', 'hostname' => '', 'requests' => 0, 'denied' => 0, 'logins' => 0,
            'first' => null, 'last' => null, 'sites' => [], 'recent' => [], 'since' => now()->subDays(14)->toIso8601String(), 'truncated' => false];

        Livewire::test(IpAddressShow::class, ['address' => '198.51.100.9'])->call('load')
            ->assertSee('Bu adresten sitelere istek gelmemiş.')
            ->assertSeeText('Günlükler '.When::dayMonth(now()->subDays(14)).' gününden başlıyor')
            ->set('tab', 'requests')->assertSee('Bu adresten sitelere istek gelmemiş.');
    }

    public function test_the_address_is_blocked_given_another_duration_and_unblocked_from_its_page(): void
    {
        $page = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])
            ->assertSee('Engelli değil')
            ->call('openBlock')->assertSet('blocking', true)->assertSet('minutes', 1440)
            ->set('minutes', 10080)->set('reason', 'Giriş sayfasına dakikada 84 deneme')
            ->call('saveBlock')
            ->assertHasNoErrors()->assertSet('blocking', false)
            ->assertDispatched('toast', message: '45.155.205.233 7 gün engellendi.')
            ->assertDontSee('Engelli değil')
            ->assertSeeText('Sercan Akın')->assertSee('Giriş sayfasına dakikada 84 deneme');

        $block = IpBlock::active()->where('address', self::ADDRESS)->sole();
        $this->assertSame($this->admin->id, $block->user_id);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $block->expires_at->timestamp, 5);

        $page->call('change', 0)->assertDispatched('toast', message: '1 engelin süresi değiştirildi: Kalıcı.')->assertSee('Engelli · kalıcı');
        $this->assertNull($block->fresh()->expires_at);
        $page->call('undo', $this->undoToken($page));
        $this->assertNotNull($block->fresh()->expires_at);

        $page->call('unblock')->assertDispatched('toast', message: '45.155.205.233 adresinin engeli kaldırıldı.')->assertSee('Engelli değil');
        $this->assertSame('user', $block->fresh()->ended);
        $page->call('undo', $this->undoToken($page))->assertDontSee('Engelli değil');
        $this->assertTrue($block->fresh()->isActive());
    }

    public function test_blocking_from_the_page_can_be_undone_and_replaces_a_fail2ban_ban(): void
    {
        $ban = IpBlock::create(['address' => self::ADDRESS, 'source' => 'fail2ban', 'kind' => 'sshd', 'reason' => 'SSH', 'expires_at' => now()->addHour()]);

        $page = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])
            ->assertSee('Fail2ban engeli')
            ->call('openBlock')->set('minutes', 0)->call('saveBlock')
            ->assertDispatched('toast', message: '45.155.205.233 kalıcı olarak engellendi.');

        $this->assertSame('replaced', $ban->fresh()->ended);
        $this->assertSame(1, IpBlock::active()->where('address', self::ADDRESS)->where('source', 'panel')->count());

        $page->call('undo', $this->undoToken($page));
        $this->assertSame(0, IpBlock::where('address', self::ADDRESS)->where('source', 'panel')->count());
        $this->assertTrue($ban->fresh()->isActive());
    }

    public function test_the_address_the_reader_is_connected_from_is_not_blocked(): void
    {
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', self::ADDRESS));

        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])
            ->assertSee('Şu anki bağlantınız')
            ->call('openBlock')->call('saveBlock')
            ->assertHasErrors('minutes')->assertSee('panele ulaşamazsınız')
            ->assertNotDispatched('toast');

        $this->assertSame(0, IpBlock::count());
    }

    public function test_a_ban_on_the_readers_own_address_does_not_become_a_block_on_the_whole_server(): void
    {
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', self::ADDRESS));
        $ban = IpBlock::create(['address' => self::ADDRESS, 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);

        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('change', 0)
            ->assertSee('şu anki bağlantınızın adresi')
            ->assertNotDispatched('toast');

        $this->assertSame([], $this->agent->called('firewall.block.many'));
        $this->assertSame([$ban->id], IpBlock::active()->pluck('id')->all());
    }

    public function test_a_read_only_administrator_sees_what_the_address_did(): void
    {
        IpBlock::create(['address' => self::ADDRESS, 'source' => 'panel']);
        $this->actingAs(User::factory()->create(['role' => 'readonly']));

        $this->get('/security/ip-blocks/address/'.self::ADDRESS)->assertOk()->assertSee('Hollanda');
        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('load')
            ->assertSee('magazaonline.com.tr')->assertSee('/wp-login.php');

        // Looking is all.
        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('unblock');
        $this->assertTrue(IpBlock::sole()->isActive());
    }

    public function test_a_lookup_that_stopped_early_and_found_nothing_does_not_say_the_address_never_came(): void
    {
        $this->agent->answers['logs.address'] = ['address' => self::ADDRESS, 'hostname' => '', 'requests' => 0, 'denied' => 0, 'logins' => 0,
            'first' => null, 'last' => null, 'sites' => [], 'recent' => [], 'since' => now()->subDays(2)->toIso8601String(), 'truncated' => true];

        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->call('load')
            ->assertSee('Günlükler çok büyük olduğu için tamamı okunamadı')
            ->assertSee('Okunan bölümde bu adres yok')
            ->assertDontSee('Bu adresten sitelere istek gelmemiş')
            ->assertDontSee('gününden başlıyor');
    }

    public function test_the_address_is_allowed_and_taken_off_the_list_from_its_page(): void
    {
        $block = IpBlock::create(['address' => self::ADDRESS, 'source' => 'panel']);

        $page = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])
            ->call('allow')
            ->assertDispatched('toast', message: '45.155.205.233 izinlilere eklendi, engeli kaldırıldı.')
            ->assertSee('İzinli');
        $this->assertSame([self::ADDRESS], IpAllow::pluck('address')->all());
        $this->assertSame('allowed', $block->fresh()->ended);

        $page->call('undo', $this->undoToken($page));
        $this->assertSame(0, IpAllow::count());
        $this->assertTrue($block->fresh()->isActive());

        $page->call('allow')->call('unallow')->assertDispatched('toast', message: '45.155.205.233 izinlilerden kaldırıldı.');
        $this->assertSame(0, IpAllow::count());
        $page->call('undo', $this->undoToken($page));
        $this->assertSame([self::ADDRESS], IpAllow::pluck('address')->all());
    }

    public function test_an_address_inside_a_blocked_or_an_allowed_range_says_which(): void
    {
        IpBlock::create(['address' => '45.155.0.0/16', 'source' => 'panel']);
        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])
            ->assertSeeText('45.155.0.0/16 aralığıyla engelli')
            ->assertSeeHtml(route('security.blocks.address', '45.155.0.0/16'));

        IpAllow::create(['address' => '198.51.100.0/24', 'note' => 'Ofis']);
        Livewire::test(IpAddressShow::class, ['address' => '198.51.100.9'])
            ->assertSeeText('198.51.100.0/24 aralığıyla izinli')
            ->call('unallow');
        // Only the entry of the address itself is removed from its page.
        $this->assertSame(1, IpAllow::count());
    }

    public function test_the_blocks_of_the_address_are_listed_newest_first(): void
    {
        IpBlock::forceCreate(['address' => self::ADDRESS, 'source' => 'ai', 'reason' => 'Dakikada 980 istek', 'created_at' => now()->subDays(3), 'updated_at' => now(), 'expires_at' => now()->subDays(2)]);
        IpBlock::create(['address' => self::ADDRESS, 'source' => 'fail2ban', 'kind' => 'serfex-panel', 'reason' => 'Panel girişi', 'removed_at' => now()->subDay(), 'ended' => 'user', 'removed_by' => $this->admin->id]);
        IpBlock::create(['address' => self::ADDRESS, 'source' => 'panel', 'user_id' => $this->admin->id, 'reason' => 'Giriş sayfasına dakikada 84 deneme']);
        IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'reason' => 'Başka adres']);

        $page = Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->set('tab', 'blocks')->assertDontSee('Başka adres');

        $html = $page->html();
        $at = array_map(fn (string $text) => strpos($html, $text),
            ['Yürürlükte', 'Panel girişi', 'Yalnızca panel portu', 'Sercan Akın kaldırdı', 'Dakikada 980 istek', 'Süresi doldu']);
        $this->assertNotContains(false, $at);
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at);
    }

    public function test_the_history_says_how_long_each_block_was_set_for_in_the_panel_s_own_words(): void
    {
        $block = fn (?int $minutes) => IpBlock::forceCreate(['address' => self::ADDRESS, 'source' => 'panel', 'created_at' => now()->subYear(), 'updated_at' => now(),
            'expires_at' => $minutes === null ? null : now()->subYear()->addMinutes($minutes), 'removed_at' => now()->subYear()->addMinute(), 'ended' => 'user']);
        $labels = [];
        foreach ([null, 60, 1440, 10080, 43200, 46, 150, 180, 2880, 3000] as $minutes) {
            $labels[] = $block($minutes)->durationLabel();
        }
        $this->assertSame(['Kalıcı', '1 saat', '24 saat', '7 gün', '30 gün', '46 dk', '2 sa 30 dk', '3 sa', '2 gün', '2 gün 2 sa'], $labels);

        Livewire::test(IpAddressShow::class, ['address' => self::ADDRESS])->set('tab', 'blocks')
            ->assertSee('24 saat')->assertSee('46 dk')->assertDontSee('1 gün 0 sa')->assertDontSee('0 sa 46 dk');
    }

    /**
     * What the agent found in the access logs.
     *
     * @return array<string, mixed>
     */
    private function report(string $address): array
    {
        $at = fn (int $minutes) => now()->subMinutes($minutes)->toIso8601String();
        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/118.0 Safari/537.36';

        return [
            'address' => $address, 'hostname' => 'vps-2281.pfcloud.io',
            'requests' => 4812, 'denied' => 1130, 'logins' => 4057,
            'first' => $at(2 * 24 * 60), 'last' => $at(1),
            'sites' => [
                ['domain' => 'magazaonline.com.tr', 'requests' => 4203, 'denied' => 1118, 'top_path' => '/wp-login.php', 'top_count' => 3960, 'statuses' => ['200' => 2842, '403' => 1118, '404' => 243], 'last' => $at(1)],
                ['domain' => 'kahveatolyesi.com.tr', 'requests' => 609, 'denied' => 12, 'top_path' => '/xmlrpc.php', 'top_count' => 401, 'statuses' => ['200' => 506, '403' => 12, '404' => 91], 'last' => $at(25)],
            ],
            'recent' => [
                ['at' => $at(1), 'domain' => 'magazaonline.com.tr', 'method' => 'POST', 'path' => '/wp-login.php', 'status' => 403, 'agent' => $browser],
                ['at' => $at(2), 'domain' => 'magazaonline.com.tr', 'method' => 'GET', 'path' => '/wp-json/wp/v2/users', 'status' => 200, 'agent' => $browser],
                ['at' => $at(25), 'domain' => 'kahveatolyesi.com.tr', 'method' => 'GET', 'path' => '/.env', 'status' => 404, 'agent' => 'python-requests/2.31.0'],
            ],
            'since' => $at(14 * 24 * 60), 'truncated' => false,
        ];
    }

    private function undoToken($page): string
    {
        $toast = collect($page->effects['dispatches'] ?? [])->last(fn ($d) => $d['name'] === 'toast' && isset($d['params']['undo']));
        $this->assertNotNull($toast, 'no toast with Undo was shown');

        return $toast['params']['undo']['args'][0];
    }
}
