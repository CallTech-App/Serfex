<?php

namespace Tests\RealHost;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Sites\SiteIndex;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel and a real agent (running as root) on a machine with Nginx
 * and PHP-FPM: a customer gets an existing site, fills their disk quota and
 * is suspended and brought back. Runs only with SERFEX_REALHOST=1 and the
 * agent's socket in SERFEX_AGENT_SOCKET (see the CI job).
 */
class CustomerRealHostTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'm9-real.test';

    private ?Site $site = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('SERFEX_REALHOST') !== '1') {
            $this->markTestSkipped('SERFEX_REALHOST is not set.');
        }
        $this->withoutVite();
        Setting::put('ssl.auto', false);
    }

    protected function tearDown(): void
    {
        if ($this->site !== null) {
            DeleteSite::dispatchSync($this->site->fresh(), removeUser: true, removeZone: false, removeMail: false);
        }
        parent::tearDown();
    }

    /**
     * The site's front page once it answers with $status: Nginx swaps its
     * workers a moment after a reload.
     */
    private function fetch(int $status = 200): Response
    {
        for ($i = 0; $i < 50; $i++) {
            $response = Http::withHeaders(['Host' => self::DOMAIN])->timeout(10)->get('http://127.0.0.1/');
            if ($response->status() === $status) {
                break;
            }
            usleep(100_000);
        }

        return $response;
    }

    public function test_customer_site_quota_and_suspension_on_a_real_server(): void
    {
        $this->actingAs(User::factory()->create());

        // A site that belongs to no one yet.
        $account = SystemUser::create(['name' => 'sfxm9']);
        $this->site = $account->sites()->create(['domain' => self::DOMAIN, 'aliases' => [], 'php_version' => '8.3', 'status' => SiteStatus::Provisioning]);
        ProvisionSite::dispatchSync($this->site);
        $this->assertSame(SiteStatus::Active, $this->site->fresh()->status, (string) $this->site->fresh()->last_error);
        $this->assertSame(200, $this->fetch()->status());

        // A customer with a 2 MB disk gets it.
        $customers = app(Customers::class);
        $package = Package::factory()->create(['max_disk_mb' => 2, 'max_sites' => 5]);
        [$customer] = $customers->create(['name' => 'Gerçek Müşteri', 'email' => 'm9@example.org', 'username' => 'sfxm9c', 'package_id' => $package->id]);
        $customers->attach($customer, $account->fresh());
        $this->assertTrue($customer->owns($this->site->fresh()));

        // The agent measures the home directory; 3 MB more fills the quota.
        $this->artisan('serfex:disk-usage')->assertSuccessful();
        $this->assertLessThan(2 * 1024 ** 2, $account->fresh()->disk_bytes);
        $this->assertNull(app(Quotas::class)->refusal($customer, 'sites'));
        $docroot = $this->site->fresh()->document_root ?: '/home/sfxm9/web/'.self::DOMAIN.'/public_html';
        exec('dd if=/dev/urandom of='.escapeshellarg($docroot.'/blob.bin').' bs=1M count=3 status=none', $out, $code);
        $this->assertSame(0, $code);
        $this->artisan('serfex:disk-usage')->assertSuccessful();
        $this->assertGreaterThanOrEqual(3 * 1024 ** 2, $account->fresh()->disk_bytes);
        $this->assertStringContainsString('Disk kotası doldu', app(Quotas::class)->refusal($customer->fresh(), 'sites'));

        $this->actingAs($customer->user);
        Livewire::test(SiteIndex::class)->call('openCreate')->assertSee('Disk kotası doldu');
        $this->assertSame(200, $this->fetch()->status(), 'a full disk does not stop the site');

        // Suspended: the suspension page; back: the site again.
        $customers->suspend($customer->fresh());
        $this->assertSame(SiteStatus::Active, $this->site->fresh()->status, (string) $this->site->fresh()->last_error);
        $response = $this->fetch(503);
        $this->assertSame(503, $response->status());
        $this->assertStringContainsString('hizmet dışı', $response->body());

        $customers->unsuspend($customer->fresh());
        $this->assertSame(200, $this->fetch()->status());
    }
}
