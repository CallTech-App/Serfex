<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SiteJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_records_the_agent_result(): void
    {
        $site = Site::factory()->provisioning()->for(SystemUser::factory()->state(['name' => 'kahve']))
            ->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr']]);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('site.apply', ['domain' => 'kahve.com.tr', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => ['www.kahve.com.tr']], 120.0)
            ->andReturn(['uid' => 1001, 'home' => '/home/kahve', 'document_root' => '/home/kahve/web/kahve.com.tr/public_html']);

        (new ProvisionSite($site))->handle($agent);

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('/home/kahve/web/kahve.com.tr/public_html', $site->document_root);
        $this->assertNotNull($site->provisioned_at);
        $this->assertSame(1001, $site->systemUser->uid);
    }

    public function test_provisioning_failure_is_shown_on_the_site(): void
    {
        $site = Site::factory()->provisioning()->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('site.apply', -32000, 'nginx rejected the configuration'));

        (new ProvisionSite($site))->handle($agent);

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

        (new DeleteSite($site, removeUser: true))->handle($agent);

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

        (new DeleteSite($site, removeUser: true))->handle($agent);

        $this->assertSame(1, Site::count());
        $this->assertTrue($user->exists());
    }

    public function test_delete_failure_keeps_the_site(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::unavailable('/run/servik-agent/agent.sock', 'Connection refused'));

        (new DeleteSite($site))->handle($agent);

        $this->assertSame(SiteStatus::Failed, $site->fresh()->status);
    }
}
