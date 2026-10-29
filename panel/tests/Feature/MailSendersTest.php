<?php

namespace Tests\Feature;

use App\Jobs\SyncMailSenders;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailSenders;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Each site's system user may send mail only as its own domains
 * (mail.senders.apply), so a customer cannot send as another customer's.
 */
class MailSendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_system_user_gets_the_domains_of_its_sites(): void
    {
        Queue::fake();
        $alice = SystemUser::factory()->create(['name' => 'alice']);
        $site = Site::factory()->for($alice)->create(['domain' => 'alice.test', 'aliases' => ['www.alice.test', 'Eski-Alice.test']]);
        Subdomain::create(['site_id' => $site->id, 'name' => 'blog.alice.test', 'web_root' => 'subdomains/blog']);
        SiteRedirect::create(['site_id' => $site->id, 'kind' => SiteRedirect::DOMAIN, 'source' => 'alice.net', 'with_www' => true, 'target' => 'https://alice.test']);
        MailDomain::factory()->for($site)->create(['name' => 'alice.test']);
        Site::factory()->for($alice)->create(['domain' => 'ikinci.test']);
        SystemUser::factory()->create(['name' => 'bos']);

        $this->assertSame([
            'alice' => ['alice.net', 'alice.test', 'blog.alice.test', 'eski-alice.test', 'ikinci.test', 'www.alice.net', 'www.alice.test'],
            'bos' => [],
        ], app(MailSenders::class)->logins());
    }

    public function test_sync_waits_for_the_mail_server_and_then_sends_the_limits(): void
    {
        Queue::fake();
        Site::factory()->for(SystemUser::factory()->create(['name' => 'alice']))->create(['domain' => 'alice.test']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldNotReceive('call');
        $this->app->instance(AgentClient::class, $agent);
        $this->assertFalse(app(MailSenders::class)->sync());

        app(MailSettings::class)->saveServer(['setup_at' => now()->toIso8601String()]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('mail.senders.apply', Mockery::on(fn ($p) => (array) $p['logins'] === ['alice' => ['alice.test']]), Mockery::any())->andReturn(['changed' => true, 'logins' => 1]);
        $this->app->instance(AgentClient::class, $agent);
        $this->assertTrue(app(MailSenders::class)->sync());

        // The hourly command reports an agent failure.
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('mail.senders.apply', -32000, 'postfix check failed'));
        $this->app->instance(AgentClient::class, $agent);
        $this->artisan('serfex:mail-senders')->assertFailed();
    }

    public function test_changes_to_names_queue_a_sync(): void
    {
        Queue::fake();
        $site = Site::factory()->create();
        Queue::assertNotPushed(SyncMailSenders::class, 'nothing to limit before the mail server is set up');

        app(MailSettings::class)->saveServer(['setup_at' => now()->toIso8601String()]);
        $site = Site::factory()->create();
        Queue::assertPushed(SyncMailSenders::class);

        Queue::fake();
        $site->update(['last_error' => 'x', 'disk_bytes' => 10]);
        Queue::assertNotPushed(SyncMailSenders::class);
        $site->update(['aliases' => ['www.'.$site->domain]]);
        Queue::assertPushed(SyncMailSenders::class);

        Queue::fake();
        $sub = Subdomain::create(['site_id' => $site->id, 'name' => 'blog.'.$site->domain, 'web_root' => 'subdomains/blog']);
        Queue::assertPushed(SyncMailSenders::class);

        Queue::fake();
        $sub->delete();
        Queue::assertPushed(SyncMailSenders::class);
    }
}
