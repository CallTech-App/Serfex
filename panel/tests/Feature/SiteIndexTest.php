<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Sites\SiteIndex;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SiteIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
    }

    private function agent(bool $online = true): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('ping')->andReturn($online);
        if ($online) {
            $agent->shouldReceive('call')->with('php.versions')->andReturn(['8.3', '8.4', '8.5']);
        } else {
            $agent->shouldReceive('call')->andThrow(AgentException::unavailable('/run/servik-agent/agent.sock', 'No such file or directory'));
        }
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_lists_sites(): void
    {
        Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        Site::factory()->provisioning()->create(['domain' => 'klinikmavi.com']);

        $this->get('/sites')
            ->assertOk()
            ->assertSee('kahveatolyesi.com.tr')
            ->assertSee('klinikmavi.com')
            ->assertSee('Yayında')
            ->assertSee('Hazırlanıyor');
    }

    public function test_search_filters_by_domain_and_user(): void
    {
        Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        Site::factory()->for(SystemUser::factory()->state(['name' => 'klinik']))->create(['domain' => 'example.org']);

        Livewire::test(SiteIndex::class)
            ->set('search', 'kahve')
            ->assertSee('kahveatolyesi.com.tr')
            ->assertDontSee('example.org')
            ->set('search', 'klinik')
            ->assertSee('example.org')
            ->assertDontSee('kahveatolyesi.com.tr');
    }

    public function test_creates_a_site_and_queues_provisioning(): void
    {
        $this->agent();

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->assertSet('phpVersion', '8.4')
            ->set('domain', ' Kahveatolyesi.com.tr ')
            ->assertSet('systemUser', 'kahveatolyesi')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('creating', false)
            ->assertDispatched('toast');

        $site = Site::sole();
        $this->assertSame('kahveatolyesi.com.tr', $site->domain);
        $this->assertSame(['www.kahveatolyesi.com.tr'], $site->aliases);
        $this->assertSame('kahveatolyesi', $site->systemUser->name);
        $this->assertSame(SiteStatus::Provisioning, $site->status);
        Queue::assertPushed(ProvisionSite::class, fn ($job) => $job->site->is($site));
    }

    public function test_reuses_an_existing_system_user(): void
    {
        $this->agent();
        $user = SystemUser::factory()->create(['name' => 'kahve']);

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'eski.kahveatolyesi.com.tr')
            ->set('systemUser', 'kahve')
            ->set('wwwAlias', false)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertTrue(Site::sole()->systemUser->is($user));
        $this->assertSame([], Site::sole()->aliases);
    }

    public function test_converts_international_domains(): void
    {
        $this->agent();

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'kahveatölyesi.com.tr')
            ->set('systemUser', 'kahve')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame('xn--kahveatlyesi-bjb.com.tr', Site::sole()->domain);
    }

    public function test_validates_input(): void
    {
        $this->agent();
        Site::factory()->create(['domain' => 'taken.com']);

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'not a domain')
            ->set('systemUser', 'Root!')
            ->set('phpVersion', '7.4')
            ->call('create')
            ->assertHasErrors(['domain', 'systemUser', 'phpVersion']);

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'taken.com')
            ->set('systemUser', 'www-data')
            ->call('create')
            ->assertHasErrors(['domain' => 'unique', 'systemUser' => 'not_in']);

        $this->assertSame(1, Site::count());
        Queue::assertNothingPushed();
    }

    public function test_explains_when_the_agent_is_down(): void
    {
        $this->agent(online: false);

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->assertSet('phpVersions', [])
            ->assertSee('Sunucu ajanına ulaşılamıyor');

        $this->assertSame(0, Site::count());
    }

    public function test_undo_removes_the_new_site(): void
    {
        $site = Site::factory()->provisioning()->create();

        Livewire::test(SiteIndex::class)->call('undoCreate', $site->id);

        $this->assertSame(SiteStatus::Deleting, $site->fresh()->status);
        Queue::assertPushed(DeleteSite::class, fn ($job) => $job->site->is($site) && $job->removeUser);
    }
}
