<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LaravelApp;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Site;
use App\Models\SiteTraffic;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Services\Customers\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class CustomerUsageTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent;
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_disk_is_measured_for_accounts_on_the_server(): void
    {
        $customer = Customer::factory()->create(['username' => 'kahve']);
        $account = app(Customers::class)->account($customer);
        $account->update(['uid' => 1001]);
        app(Customers::class)->account(Customer::factory()->create(['username' => 'bos'])); // no uid yet
        $this->agent->answers['usage.disk'] = ['kahve' => ['bytes' => 3 * 1024 ** 3, 'files' => 12]];

        $this->artisan('servik:disk-usage')->assertSuccessful();

        $this->assertSame([['users' => ['kahve']]], $this->agent->called('usage.disk'));
        $this->assertSame(3 * 1024 ** 3, $account->fresh()->disk_bytes);
        $this->assertNotNull($account->fresh()->disk_measured_at);
    }

    public function test_disk_counts_files_mail_and_backups_and_blocks_new_things_when_full(): void
    {
        $package = Package::factory()->create(['max_disk_mb' => 1024, 'max_sites' => 10, 'max_mailboxes' => 10]);
        $customer = Customer::factory()->create(['package_id' => $package->id]);
        $account = app(Customers::class)->account($customer);
        $account->update(['disk_bytes' => 600 * 1024 ** 2]);
        $site = Site::factory()->for($account)->create();
        $mail = MailDomain::factory()->create(['name' => $site->domain, 'site_id' => $site->id]);
        Mailbox::factory()->create(['mail_domain_id' => $mail->id, 'usage_bytes' => 300 * 1024 ** 2]);
        $quotas = app(Quotas::class);

        $report = $quotas->report($customer);
        $this->assertSame(900 * 1024 ** 2, $report['disk']['used']);
        $this->assertSame('warn', $report['disk']['level']);
        $this->assertNull($quotas->refusal($customer, 'sites'));

        $app = LaravelApp::factory()->for($site)->create();
        $app->backups()->create(['key' => 'b1', 'run' => 'b1', 'kind' => 'manual', 'parts' => ['files'], 'status' => 'done', 'size' => 200 * 1024 ** 2]);
        $this->assertSame('crit', $quotas->report($customer)['disk']['level']);
        $this->assertStringContainsString('Disk kotası doldu', $quotas->refusal($customer, 'mailboxes'));
        $this->assertStringContainsString('Disk kotası doldu', $quotas->refusal($customer, 'disk'));
    }

    public function test_counts_reach_their_limit_and_unlimited_never_does(): void
    {
        $package = Package::factory()->create(['max_sites' => 1, 'max_laravel_apps' => null]);
        $customer = Customer::factory()->create(['package_id' => $package->id]);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create();
        LaravelApp::factory()->for($site)->create();
        $quotas = app(Quotas::class);

        $report = $quotas->report($customer);
        $this->assertSame('1 / 1', $report['sites']['text']);
        $this->assertSame('ok', $report['sites']['level'], 'a count at its limit is fine');
        $this->assertSame('1 / Sınırsız', $report['laravel_apps']['text']);
        $this->assertNull($report['databases']['used']);
        $this->assertStringContainsString('site sınırına ulaşıldı', $quotas->refusal($customer, 'sites'));
        $this->assertNull($quotas->refusal($customer, 'laravel_apps'));
        $this->assertNull($quotas->refusal(null, 'sites'), 'sites without a customer have no limits');

        $customer->update(['suspended_at' => now()]);
        $this->assertStringContainsString('askıya alınmış', $quotas->refusal($customer->fresh(), 'laravel_apps'));
    }

    public function test_traffic_adds_up_per_month_across_agent_restarts(): void
    {
        $site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        $usage = app(Usage::class);
        $started = now()->subMinute()->toIso8601String();

        $usage->recordTraffic(['started' => $started, 'sites' => [['domain' => 'kahveatolyesi.com.tr', 'traffic' => 1000]]]);
        $usage->recordTraffic(['started' => $started, 'sites' => [['domain' => 'kahveatolyesi.com.tr', 'traffic' => 1500], ['domain' => 'bilinmeyen.com', 'traffic' => 9]]]);
        // The agent restarted and counts from zero again.
        $usage->recordTraffic(['started' => now()->toIso8601String(), 'sites' => [['domain' => 'kahveatolyesi.com.tr', 'traffic' => 200]]]);

        $this->assertSame(1700, (int) SiteTraffic::where('site_id', $site->id)->where('month', now()->startOfMonth()->toDateString())->value('bytes'));
    }

    public function test_traffic_from_before_recording_started_is_not_counted(): void
    {
        $site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        Cache::forget('servik.traffic.counters');

        app(Usage::class)->recordTraffic(['started' => now()->subDays(3)->toIso8601String(), 'sites' => [['domain' => 'kahveatolyesi.com.tr', 'traffic' => 5 * 1024 ** 3]]]);

        $this->assertSame(0, SiteTraffic::where('site_id', $site->id)->count());
    }

    public function test_customer_dashboard_shows_usage_and_warns_when_full(): void
    {
        $package = Package::factory()->create(['name' => 'Mini', 'max_sites' => 1]);
        $customer = Customer::factory()->withLogin()->create(['package_id' => $package->id]);
        Site::factory()->for(app(Customers::class)->account($customer))->count(2)->create();
        $this->actingAs(User::where('customer_id', $customer->id)->sole());

        $this->get('/')->assertOk()
            ->assertSee('Mini paketi')
            ->assertSee('2 / 1')
            ->assertSee('Paketinizin bir sınırı doldu');
    }
}
