<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Enums\ZoneStatus;
use App\Jobs\DeleteDnsZone;
use App\Jobs\DeleteSite;
use App\Jobs\IssueCertificate;
use App\Jobs\ProvisionSite;
use App\Models\DnsZone;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\Zones;
use App\Services\Ssl\Certificates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SiteJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_records_the_agent_result(): void
    {
        Queue::fake();
        $site = Site::factory()->provisioning()->for(SystemUser::factory()->state(['name' => 'kahve']))
            ->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr']]);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('site.apply', ['domain' => 'kahve.com.tr', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => ['www.kahve.com.tr']], 120.0)
            ->andReturn(['uid' => 1001, 'home' => '/home/kahve', 'document_root' => '/home/kahve/web/kahve.com.tr/public_html']);

        (new ProvisionSite($site))->handle($agent, app(Certificates::class));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('/home/kahve/web/kahve.com.tr/public_html', $site->document_root);
        $this->assertNotNull($site->provisioned_at);
        $this->assertSame(1001, $site->systemUser->uid);

        // A new site asks for its certificate.
        $certificate = $site->certificates()->sole();
        $this->assertSame(['kahve.com.tr', 'www.kahve.com.tr'], $certificate->domains);
        Queue::assertPushed(IssueCertificate::class, fn ($job) => $job->certificate->is($certificate) && $job->auto);
    }

    public function test_provisioning_failure_is_shown_on_the_site(): void
    {
        $site = Site::factory()->provisioning()->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('site.apply', -32000, 'nginx rejected the configuration'));

        (new ProvisionSite($site))->handle($agent, app(Certificates::class));

        $site->refresh();
        $this->assertSame(SiteStatus::Failed, $site->status);
        $this->assertStringContainsString('nginx rejected the configuration', $site->last_error);
    }

    public function test_delete_removes_site_and_account(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('site.delete', Mockery::on(fn ($p) => $p['remove_files'] && $p['remove_user']), 120.0)
            ->andReturn(['user_removed' => true]);

        (new DeleteSite($site, removeUser: true))->handle($agent, app(Zones::class));

        $this->assertSame(0, Site::count());
        $this->assertSame(0, SystemUser::count());
    }

    public function test_delete_keeps_an_account_that_still_has_sites(): void
    {
        $user = SystemUser::factory()->create();
        $site = Site::factory()->for($user)->create(['status' => SiteStatus::Deleting]);
        Site::factory()->for($user)->create();

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('site.delete', Mockery::on(fn ($p) => $p['remove_user'] === false), 120.0)
            ->andReturn(['user_removed' => false]);

        (new DeleteSite($site, removeUser: true))->handle($agent, app(Zones::class));

        $this->assertSame(1, Site::count());
        $this->assertTrue($user->exists());
    }

    public function test_delete_failure_keeps_the_site(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::unavailable('/run/servik-agent/agent.sock', 'Connection refused'));

        (new DeleteSite($site))->handle($agent, app(Zones::class));

        $this->assertSame(SiteStatus::Failed, $site->fresh()->status);
    }

    public function test_delete_removes_the_zone_when_asked(): void
    {
        Queue::fake();
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $zone = DnsZone::factory()->create(['name' => $site->domain, 'site_id' => $site->id]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('site.delete', Mockery::any(), 120.0)->andReturn(['user_removed' => true]);

        (new DeleteSite($site, removeZone: true))->handle($agent, app(Zones::class));

        $this->assertSame(ZoneStatus::Deleting, $zone->fresh()->status);
        Queue::assertPushed(DeleteDnsZone::class, fn ($job) => $job->zone->is($zone));
    }

    public function test_delete_keeps_the_zone_as_dns_only_by_default(): void
    {
        Queue::fake();
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $zone = DnsZone::factory()->create(['name' => $site->domain, 'site_id' => $site->id]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('site.delete', Mockery::any(), 120.0)->andReturn(['user_removed' => true]);

        (new DeleteSite($site))->handle($agent, app(Zones::class));

        $zone->refresh();
        $this->assertSame(ZoneStatus::Active, $zone->status);
        $this->assertNull($zone->site_id);
        Queue::assertNotPushed(DeleteDnsZone::class);
    }
}
