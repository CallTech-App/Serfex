<?php

namespace Tests\Feature;

use App\Jobs\SyncDnsZone;
use App\Jobs\SyncMailSenders;
use App\Livewire\Sites\SiteIndex;
use App\Models\Customer;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\Zones;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Unique jobs take a lock row in cache_locks when dispatched. On
 * PostgreSQL, taking one inside a transaction while its row already
 * exists (the same job still waiting in the queue) aborted the
 * transaction: "Site ekle" for updates.roifex.com ended in a 500
 * (SQLSTATE 25P02) because the new site queued SyncMailSenders.
 */
class UniqueJobLocksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // As installed: cache and its locks in PostgreSQL.
        config(['cache.default' => 'database']);
        $this->clearLocks();
        Queue::fake();
        app(MailSettings::class)->saveServer(['setup_at' => now()->toIso8601String()]);
    }

    protected function tearDown(): void
    {
        $this->clearLocks();
        parent::tearDown();
    }

    /**
     * Locks live on their own connection, outside the test's transaction.
     */
    private function clearLocks(): void
    {
        DB::connection(config('cache.stores.database.lock_connection') ?? config('database.default'))->table('cache_locks')->delete();
    }

    private function agent(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('ping')->andReturn(true);
        $agent->shouldReceive('call')->with('php.versions')->andReturn(['8.4']);
        $agent->shouldReceive('call')->with('system.addresses')->andReturn(['ipv4' => ['203.0.113.10'], 'ipv6' => []]);
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_a_site_is_added_while_a_mail_sender_sync_is_waiting(): void
    {
        $this->actingAs(User::factory()->create());
        $this->agent();
        $customer = Customer::factory()->create(['username' => 'roifex']);
        $account = SystemUser::factory()->create(['name' => 'roifex', 'customer_id' => $customer->id]);
        $site = Site::factory()->for($account)->create(['domain' => 'roifex.com']);
        DnsZone::factory()->withRecords()->create(['name' => 'roifex.com', 'site_id' => $site->id]);
        // An earlier change queued a sync that has not run yet.
        SyncMailSenders::dispatch();
        Queue::assertPushed(SyncMailSenders::class, 1);

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('customerId', (string) $customer->id)
            ->set('domain', 'updates.roifex.com')
            ->call('create')
            ->assertHasNoErrors();

        $new = Site::where('domain', 'updates.roifex.com')->firstOrFail();
        $this->assertTrue($new->systemUser->is($account));
        $this->assertContains('updates A 203.0.113.10', DnsZone::where('name', 'roifex.com')->first()->records->map(fn ($r) => "{$r->name} {$r->type} {$r->content}")->all());
        $this->assertTrue(MailDomain::where('name', 'updates.roifex.com')->exists());
        // The waiting sync reads the new site when it runs.
        Queue::assertPushed(SyncMailSenders::class, 1);
    }

    public function test_other_changes_in_a_transaction_survive_a_waiting_unique_job(): void
    {
        $site = Site::factory()->create(['domain' => 'roifex.com']);
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'roifex.com', 'site_id' => $site->id]);
        SyncMailSenders::dispatch();
        SyncDnsZone::dispatch($zone);

        DB::transaction(function () use ($site, $zone) {
            Subdomain::create(['site_id' => $site->id, 'name' => 'blog.roifex.com', 'web_root' => 'subdomains/blog']);
            SiteRedirect::create(['site_id' => $site->id, 'kind' => SiteRedirect::DOMAIN, 'source' => 'roifex.net', 'with_www' => true, 'target' => 'https://roifex.com']);
            MailDomain::factory()->for($site)->create(['name' => 'roifex.com']);
            $site->update(['domain' => 'www-roifex.com']);
            app(Zones::class)->change($zone, fn (DnsZone $z) => $z->records()->create(['name' => 'shop', 'type' => 'A', 'content' => '203.0.113.11', 'ttl' => 300]));
        });

        $this->assertSame('www-roifex.com', $site->fresh()->domain);
        $this->assertTrue(Subdomain::where('name', 'blog.roifex.com')->exists());
        $this->assertTrue($zone->records()->where('name', 'shop')->exists());
        Queue::assertPushed(SyncMailSenders::class, 1);
        Queue::assertPushed(SyncDnsZone::class, 1);
    }

    public function test_a_rolled_back_change_takes_no_lock(): void
    {
        try {
            DB::transaction(function () {
                Site::factory()->create(['domain' => 'geri.test']);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        Queue::assertNotPushed(SyncMailSenders::class);

        Site::factory()->create(['domain' => 'sonraki.test']);
        Queue::assertPushed(SyncMailSenders::class, 1);
    }

    public function test_a_lock_whose_job_never_ran_expires_after_ten_minutes(): void
    {
        SyncMailSenders::dispatch();
        SyncMailSenders::dispatch();
        Queue::assertPushed(SyncMailSenders::class, 1);

        $this->travel(11)->minutes();
        Site::factory()->create(['domain' => 'yeni.test']);
        Queue::assertPushed(SyncMailSenders::class, 2);
    }
}
