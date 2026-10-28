<?php

namespace Tests\Feature;

use App\Livewire\Monitor\LiveMonitor;
use App\Livewire\Sites\SiteDisk;
use App\Livewire\Sites\SiteStatistics;
use App\Mail\PanelMail;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailStatDay;
use App\Models\MonitorSample;
use App\Models\NotificationEvent;
use App\Models\Package;
use App\Models\ServerStat;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteStatDay;
use App\Models\SiteStatHour;
use App\Models\SiteStatItem;
use App\Models\SiteTraffic;
use App\Models\SystemUser;
use App\Models\UsageNotice;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Customers;
use App\Services\Customers\UsageNotices;
use App\Services\Settings\Notifications;
use App\Services\Stats\ServerHistory;
use App\Services\Stats\SiteStats;
use App\Services\Stats\StatsCollector;
use App\Services\Stats\StatsRetention;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * İstatistikler: collecting what the agent counted, the site's statistics
 * and disk pages, mail traffic, the server's history, the customer's usage
 * report and the usage warnings.
 */
class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['serfex.timezone' => 'Europe/Istanbul', 'serfex.disk_paths' => []]);
        $this->agent = new FakeAgent;
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
        $this->site = Site::factory()->for(SystemUser::factory()->create(['name' => 'kahve', 'uid' => 1001]), 'systemUser')->create(['domain' => 'kahveatolyesi.com.tr']);
    }

    /**
     * A batch as stats.collect returns it.
     *
     * @param  list<array<string, mixed>>  $hours
     */
    private function batch(string $id, array $hours, array $mail = []): array
    {
        return ['id' => $id, 'countries' => true, 'sites' => [['domain' => 'kahveatolyesi.com.tr', 'hours' => $hours], ['domain' => 'bilinmeyen.com', 'hours' => $hours]], 'mail' => $mail];
    }

    private function hour(string $at, int $requests, int $bytes, int $visitors, array $items = []): array
    {
        return ['hour' => $at, 'requests' => $requests, 'bytes' => $bytes, 'visitors' => $visitors, 'pages' => intdiv($requests, 2),
            'bot_requests' => 1, 'bot_bytes' => 10, 's2xx' => $requests - 1, 's3xx' => 0, 's4xx' => 1, 's5xx' => 0, 'items' => $items];
    }

    public function test_a_batch_is_stored_once_in_hours_days_items_mail_and_monthly_traffic(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));
        $batch = $this->batch('e1-1', [
            // 21:00 UTC is already the next day in Istanbul.
            $this->hour('2026-10-19T20:00:00Z', 10, 1000, 4, ['page' => ['/' => ['r' => 4, 'b' => 400, 'v' => 0]], 'country' => ['TR' => ['r' => 9, 'b' => 900, 'v' => 4]]]),
            $this->hour('2026-10-19T21:00:00Z', 6, 600, 2, ['page' => ['/' => ['r' => 2, 'b' => 200, 'v' => 0], '/sepet' => ['r' => 1, 'b' => 50, 'v' => 0]], 'bot' => ['Googlebot' => ['r' => 1, 'b' => 10, 'v' => 0]]]),
        ], [['domain' => 'KahveAtolyesi.com.tr', 'hours' => [['hour' => '2026-10-20T08:00:00Z', 'sent' => 3, 'received' => 7, 'rejected' => 2, 'bounced' => 1]]]]);
        $this->agent->answers['stats.collect'] = $batch;

        $this->artisan('serfex:stats-collect')->assertSuccessful();
        $this->assertSame([['ack' => '']], $this->agent->called('stats.collect'));
        $this->assertSame('e1-1', Setting::get(StatsCollector::ACK_KEY));
        $this->assertSame(2, SiteStatHour::where('site_id', $this->site->id)->count());
        $days = SiteStatDay::where('site_id', $this->site->id)->orderBy('day')->get();
        $this->assertSame(['2026-10-19', '2026-10-20'], $days->map(fn ($d) => $d->day->toDateString())->all());
        $this->assertSame([10, 6], $days->pluck('requests')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1600, (int) SiteTraffic::where('site_id', $this->site->id)->where('month', '2026-10-01')->value('bytes'));
        $this->assertSame(3, SiteStatItem::where('site_id', $this->site->id)->where('kind', 'page')->count());
        $this->assertSame(['sent' => 3, 'received' => 7, 'rejected' => 2, 'bounced' => 1],
            MailStatDay::where('domain', 'kahveatolyesi.com.tr')->sole()->only(['sent', 'received', 'rejected', 'bounced']));

        // Handed out again (not acknowledged yet): the next call names it
        // and nothing is counted twice.
        $this->artisan('serfex:stats-collect')->assertSuccessful();
        $this->assertSame(['ack' => 'e1-1'], $this->agent->called('stats.collect')[1]);
        $this->assertSame(1600, (int) SiteTraffic::where('site_id', $this->site->id)->value('bytes'));

        // A new batch adds to the same hour and day.
        $this->agent->answers['stats.collect'] = $this->batch('e1-2', [$this->hour('2026-10-19T21:00:00Z', 4, 400, 1, ['page' => ['/' => ['r' => 1, 'b' => 100, 'v' => 0]]])]);
        $this->artisan('serfex:stats-collect')->assertSuccessful();
        $this->assertSame(10, (int) SiteStatHour::where('site_id', $this->site->id)->where('hour', '2026-10-19 21:00:00')->value('requests'));
        $this->assertSame(3, (int) SiteStatItem::where('site_id', $this->site->id)->where('value', '/')->where('day', '2026-10-20')->value('requests'));
        $this->assertSame(2000, (int) SiteTraffic::where('site_id', $this->site->id)->value('bytes'));
        $this->assertTrue(SiteStats::countriesReady());

        // Nothing new.
        $this->agent->answers['stats.collect'] = ['id' => '', 'sites' => [], 'mail' => [], 'countries' => false];
        $this->artisan('serfex:stats-collect')->assertSuccessful();
        $this->assertSame('e1-2', Setting::get(StatsCollector::ACK_KEY));
        $this->assertFalse(SiteStats::countriesReady());
    }

    private function seedDays(): void
    {
        $today = CarbonImmutable::now('Europe/Istanbul')->startOfDay();
        foreach ([0, 1, 2, 40] as $ago) {
            $day = $today->subDays($ago)->toDateString();
            SiteStatDay::create(['site_id' => $this->site->id, 'day' => $day, 'requests' => 1000, 'bytes' => 50 << 20, 'visitors' => 120, 'pages' => 400, 'bot_requests' => 250, 'bot_bytes' => 5 << 20, 's2xx' => 900, 's4xx' => 100]);
            foreach ([['page', '/urunler', 300, 30 << 20, 0], ['page', '/iletisim', 100, 1 << 20, 0], ['referrer', 'google.com', 80, 0, 0], ['country', 'TR', 700, 0, 100], ['country', 'DE', 50, 0, 20], ['country', '-', 10, 0, 1], ['bot', 'Googlebot', 200, 4 << 20, 0], ['bot', 'other-tool', 50, 1 << 20, 0]] as [$kind, $value, $r, $b, $v]) {
                SiteStatItem::create(['site_id' => $this->site->id, 'day' => $day, 'kind' => $kind, 'value' => $value, 'requests' => $r, 'bytes' => $b, 'visitors' => $v]);
            }
        }
    }

    public function test_the_statistics_page_shows_totals_chart_and_top_lists(): void
    {
        $this->seedDays();
        Cache::forever(StatsCollector::COUNTRIES_KEY, true);

        $this->get(route('sites.stats', $this->site))->assertOk()
            ->assertSee('İstatistikler')
            ->assertSee('360</span> ziyaretçi', false)
            ->assertSee('3.000</span> istek', false)
            ->assertSee('botlar isteklerin %25')
            ->assertSeeInOrder(['/urunler', '/iletisim']);

        Livewire::test(SiteStatistics::class, ['site' => $this->site->id])
            ->call('switchTab', 'country')
            ->assertSee('Türkiye')->assertSee('Almanya')->assertSee('Bilinmiyor')
            ->call('switchTab', 'bot')
            ->assertSee('Googlebot')->assertSee('Diğer araçlar ve kütüphaneler')
            ->set('period', '12m')
            ->assertSee('480</span> ziyaretçi', false)
            ->set('period', 'today')
            ->assertSee('120</span> ziyaretçi', false)
            ->set('period', 'yok')
            ->assertSet('period', '30d');

        $this->get(route('sites.show', $this->site))->assertOk()->assertSee('İstatistikler')->assertSee('Disk kullanımı');
    }

    public function test_an_empty_period_explains_itself(): void
    {
        $this->get(route('sites.stats', $this->site))->assertOk()->assertSee('Bu dönem için istatistik yok');
    }

    public function test_customers_see_only_their_own_statistics_and_disk(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'klinik']);
        $own = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'klinikmavi.com']);
        $this->actingAs($customer->user);

        $this->get(route('sites.stats', $own))->assertOk();
        $this->get(route('sites.disk', $own))->assertOk();
        $this->get(route('sites.stats', $this->site))->assertNotFound();
        $this->get(route('sites.disk', $this->site))->assertNotFound();
        $this->get(route('usage'))->assertOk()->assertSee('Paket sınırları');
        $this->get(route('customers.usage', $customer))->assertForbidden();
    }

    public function test_disk_page_breaks_the_site_down_and_measures_on_request(): void
    {
        $this->site->update(['disk_bytes' => 300 << 20]);
        $mail = MailDomain::factory()->create(['site_id' => $this->site->id, 'name' => 'kahveatolyesi.com.tr']);
        Mailbox::factory()->create(['mail_domain_id' => $mail->id, 'usage_bytes' => 100 << 20]);
        Database::factory()->create(['site_id' => $this->site->id, 'system_user_id' => $this->site->system_user_id, 'size_bytes' => 50 << 20]);

        $this->get(route('sites.disk', $this->site))->assertOk()
            ->assertSeeInOrder(['Web dosyaları', '300 MB', 'E-posta', '100 MB', 'Veritabanları', '50 MB', 'Toplam', '450 MB']);

        $this->agent->answers['usage.disk'] = ['kahve' => ['bytes' => 900 << 20, 'files' => 10, 'sites' => ['kahveatolyesi.com.tr' => 700 << 20]]];
        Livewire::test(SiteDisk::class, ['site' => $this->site->id])->call('measure')->assertSee('700 MB');
        $this->assertSame(700 << 20, (int) $this->site->fresh()->disk_bytes);
        $this->assertSame(900 << 20, (int) $this->site->systemUser->fresh()->disk_bytes);
    }

    public function test_mail_traffic_tab_lists_the_last_thirty_days(): void
    {
        $domain = MailDomain::factory()->create(['site_id' => $this->site->id, 'name' => 'kahveatolyesi.com.tr']);
        MailStatDay::create(['domain' => 'kahveatolyesi.com.tr', 'day' => now('Europe/Istanbul')->toDateString(), 'sent' => 12, 'received' => 34, 'rejected' => 5, 'bounced' => 1]);
        MailStatDay::create(['domain' => 'kahveatolyesi.com.tr', 'day' => now('Europe/Istanbul')->subDays(45)->toDateString(), 'sent' => 999]);

        $this->get(route('mail.show', ['domain' => $domain, 'tab' => 'traffic']))->assertOk()
            ->assertSee('Posta trafiği')
            ->assertSee('34</span> gelen', false)
            ->assertSee('12</span> giden', false)
            ->assertDontSee('>999<', false);
    }

    public function test_server_minutes_roll_up_into_hours_and_show_on_the_system_tab(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:30', 'UTC'));
        foreach (range(0, 59) as $m) {
            MonitorSample::create(['recorded_at' => now()->subHour()->startOfHour()->addMinutes($m), 'site_id' => null, 'cpu' => $m < 30 ? 20 : 40, 'memory' => 4 << 30,
                'memory_total' => 16 << 30, 'net_rx' => 1000, 'net_tx' => 2000, 'disk_used' => 40 << 30, 'disk_total' => 100 << 30, 'load' => 1.5]);
        }
        MonitorSample::create(['recorded_at' => now()->startOfMinute(), 'site_id' => null, 'cpu' => 90, 'memory' => 1]);

        $this->assertSame(1, app(ServerHistory::class)->rollup());
        $this->assertSame(0, app(ServerHistory::class)->rollup(), 'an hour is rolled up once, the current one not yet');
        $hour = ServerStat::sole();
        $this->assertSame('2026-10-20 11:00:00', $hour->hour->toDateTimeString());
        $this->assertEquals(30, $hour->cpu);
        $this->assertEquals(40, $hour->cpu_max);
        $this->assertSame(3600 * 1000, (int) $hour->net_rx);
        $this->assertSame(40 << 30, (int) $hour->disk_used);

        ServerStat::create(['hour' => now()->subDays(2)->startOfHour(), 'cpu' => 10, 'cpu_max' => 15, 'memory' => 2 << 30, 'memory_max' => 2 << 30, 'memory_total' => 16 << 30, 'disk_used' => 39 << 30, 'disk_total' => 100 << 30, 'net_rx' => 5, 'net_tx' => 5, 'load' => 1]);
        $this->agent->answers['monitor.live'] = ['at' => now()->toIso8601String(), 'window' => 10, 'interval' => 2,
            'server' => ['cpus' => 4, 'memory_total' => 16 << 30, 'load' => [1, 1, 1], 'uptime' => 9000, 'cpu' => 30, 'memory' => 4 << 30, 'net_rx' => 0, 'net_tx' => 0],
            'sites' => [], 'accounts' => [], 'services' => [], 'suspects' => [], 'databases' => []];
        Livewire::test(LiveMonitor::class)->call('setTab', 'system')
            ->assertSee('Kaynak geçmişi')
            ->set('span', '7d')
            ->assertSee('Saatlik ortalamalar')
            ->assertSee('40 GB / 100 GB (%40)');
        // The panel's real cache store refuses objects: the cached history must come back intact.
        config(['cache.default' => 'database']);
        foreach ([1, 2] as $ignored) {
            Livewire::test(LiveMonitor::class)->call('setTab', 'system')->set('span', '30d')->assertSee('Kaynak geçmişi');
        }

        // Genel Bakış charts the last 24 hours from the same cached history.
        $this->get('/')->assertSee('Sunucu kaynakları')->assertSee('en yüksek %90');
    }

    public function test_the_monitor_records_the_servers_network_memory_and_disk(): void
    {
        config(['serfex.disk_paths' => ['/']]);
        $this->agent->answers['monitor.live'] = ['at' => now()->toIso8601String(), 'window' => 60, 'interval' => 2, 'started' => now()->toIso8601String(),
            'server' => ['cpus' => 4, 'memory_total' => 16 << 30, 'load' => [1.25, 1, 1], 'uptime' => 9000, 'cpu' => 30, 'memory' => 4 << 30, 'net_rx' => 1234.5, 'net_tx' => 99],
            'sites' => [], 'accounts' => [], 'services' => [], 'suspects' => [], 'databases' => []];
        $this->artisan('serfex:monitor-record')->assertSuccessful();
        $row = MonitorSample::whereNull('site_id')->sole();
        $this->assertEquals(1234.5, $row->net_rx);
        $this->assertEquals(1.25, $row->load);
        $this->assertSame(16 << 30, (int) $row->memory_total);
        $this->assertGreaterThan(0, (int) $row->disk_total);
    }

    public function test_top_lists_fold_into_their_largest_values(): void
    {
        $day = now('Europe/Istanbul')->subDays(2)->toDateString();
        foreach (range(1, 8) as $i) {
            SiteStatItem::create(['site_id' => $this->site->id, 'day' => $day, 'kind' => 'page', 'value' => '/p'.$i, 'requests' => $i * 10, 'bytes' => $i]);
        }
        SiteStatItem::create(['site_id' => $this->site->id, 'day' => $day, 'kind' => 'page', 'value' => '', 'requests' => 5, 'bytes' => 1]);

        $this->assertSame(5, app(StatsRetention::class)->fold($day, 3, 4));
        $values = SiteStatItem::where('site_id', $this->site->id)->orderByDesc('requests')->pluck('requests', 'value')->map(fn ($v) => (int) $v)->all();
        $this->assertSame(['' => 5 + 10 + 20 + 30 + 40 + 50, '/p8' => 80, '/p7' => 70, '/p6' => 60], $values);

        SiteStatHour::create(['site_id' => $this->site->id, 'hour' => now()->subDays(40)]);
        SiteStatDay::create(['site_id' => $this->site->id, 'day' => now()->subMonths(26)->toDateString()]);
        $this->artisan('serfex:stats-prune')->assertSuccessful();
        $this->assertSame(0, SiteStatHour::count());
        $this->assertSame(0, SiteStatDay::count());
    }

    public function test_the_site_list_shows_and_sorts_by_this_months_traffic(): void
    {
        $other = Site::factory()->create(['domain' => 'aaa-blog.com']);
        SiteTraffic::create(['site_id' => $this->site->id, 'month' => now('Europe/Istanbul')->startOfMonth()->toDateString(), 'bytes' => 5 << 30]);

        $this->get(route('sites.index'))->assertOk()->assertSeeInOrder(['aaa-blog.com', 'kahveatolyesi.com.tr'])->assertSee('5 GB');
        $this->get(route('sites.index', ['sort' => 'traffic']))->assertOk()->assertSeeInOrder(['kahveatolyesi.com.tr', 'aaa-blog.com']);
        $this->assertNotNull($other);
    }

    public function test_the_usage_report_breaks_disk_and_traffic_down(): void
    {
        $package = Package::factory()->create(['name' => 'Mini', 'max_disk_mb' => 1024, 'max_traffic_gb' => 10]);
        $customer = Customer::factory()->withLogin()->create(['package_id' => $package->id, 'username' => 'klinik']);
        $account = app(Customers::class)->account($customer);
        $account->update(['disk_bytes' => 600 << 20]);
        $site = Site::factory()->for($account)->create(['domain' => 'klinikmavi.com', 'disk_bytes' => 500 << 20]);
        SiteTraffic::create(['site_id' => $site->id, 'month' => now('Europe/Istanbul')->startOfMonth()->toDateString(), 'bytes' => 9 << 30]);

        $this->get(route('customers.usage', ['customer' => $customer, 'tab' => 'disk']))->assertOk()
            ->assertSee('klinikmavi.com')->assertSee('500 MB')->assertSee('Site klasörleri dışındaki dosyalar')->assertSee('600 MB');
        $this->get(route('customers.usage', ['customer' => $customer, 'tab' => 'traffic']))->assertOk()
            ->assertSee('Bu ay 9 GB / 10 GB')->assertSee('İstatistikler');
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Disk ve trafik ayrıntısı');
        $this->get(route('usage'))->assertNotFound();

        $this->actingAs($customer->user);
        $this->get('/')->assertOk()->assertSee('Kullanım raporu');
        $this->get(route('usage', ['tab' => 'traffic']))->assertOk()->assertSee('Bu ay 9 GB / 10 GB');
    }

    public function test_customers_are_mailed_once_per_level_and_admins_told_when_traffic_runs_out(): void
    {
        Mail::fake();
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.serfex.test', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        $package = Package::factory()->create(['max_traffic_gb' => 10, 'max_disk_mb' => null]);
        $customer = Customer::factory()->withLogin()->create(['package_id' => $package->id, 'username' => 'klinik', 'email' => 'ali@klinikmavi.com']);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'klinikmavi.com']);
        $traffic = SiteTraffic::create(['site_id' => $site->id, 'month' => now('Europe/Istanbul')->startOfMonth()->toDateString(), 'bytes' => 9 << 30]);
        $notices = app(UsageNotices::class);

        $this->assertSame(1, $notices->check());
        $this->assertSame(0, $notices->check(), 'warned once');
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->hasTo($customer->user->email) && $m->title === 'Aylık trafik sınırınıza yaklaştınız (%90)');

        $traffic->update(['bytes' => 11 << 30]);
        $this->assertSame(1, $notices->check());
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->title === 'Aylık trafik sınırınızı aştınız');
        $this->assertSame(['crit', 'warn'], UsageNotice::orderBy('level')->pluck('level')->all());

        // The administrators hear about it too.
        app(Notifications::class)->check();
        $this->assertSame('traffic', NotificationEvent::where('key', 'traffic:customer:'.$customer->id)->value('kind'));

        // A new month: usage is back to normal, the warnings can come again.
        $traffic->update(['bytes' => 1 << 30]);
        $this->assertSame(0, $notices->check());
        $this->assertSame(0, UsageNotice::count());
    }
}
