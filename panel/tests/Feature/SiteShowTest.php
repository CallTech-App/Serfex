<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Sites\SiteShow;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_detail_page_shows_the_site(): void
    {
        $site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr', 'document_root' => '/home/kahve/web/kahveatolyesi.com.tr/public_html']);

        $this->get(route('sites.show', $site))
            ->assertOk()
            ->assertSee('kahveatolyesi.com.tr')
            ->assertSee('Yayında')
            ->assertSee('/home/kahve/web/kahveatolyesi.com.tr/public_html')
            ->assertSee('Site araçları');
    }

    public function test_delete_requires_typing_the_domain(): void
    {
        Queue::fake();
        $site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);

        Livewire::test(SiteShow::class, ['site' => $site->id])
            ->call('openDelete')
            ->set('confirmDomain', 'kahve')
            ->call('delete')
            ->assertHasErrors('confirmDomain');
        Queue::assertNothingPushed();

        Livewire::test(SiteShow::class, ['site' => $site->id])
            ->call('openDelete')
            ->set('confirmDomain', 'kahveatolyesi.com.tr')
            ->set('removeUser', false)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertSame(SiteStatus::Deleting, $site->fresh()->status);
        Queue::assertPushed(DeleteSite::class, fn (DeleteSite $job) => $job->site->is($site) && $job->removeUser === false);
    }

    public function test_returns_to_the_list_once_the_site_is_gone(): void
    {
        $site = Site::factory()->create();
        $component = Livewire::test(SiteShow::class, ['site' => $site->id]);

        $site->delete();

        $component->call('refreshStatus')->assertRedirect(route('sites.index'));
    }

    public function test_failed_site_can_be_retried(): void
    {
        Queue::fake();
        $site = Site::factory()->create(['status' => SiteStatus::Failed, 'last_error' => 'nginx rejected the configuration']);

        Livewire::test(SiteShow::class, ['site' => $site->id])
            ->assertSee('nginx rejected the configuration')
            ->call('retry');

        $this->assertSame(SiteStatus::Provisioning, $site->fresh()->status);
        Queue::assertPushed(ProvisionSite::class);
    }
}
