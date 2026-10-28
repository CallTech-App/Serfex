<?php

namespace Tests\Feature;

use App\Jobs\RunSiteCommand;
use App\Livewire\Sites\SiteCaching;
use App\Livewire\Sites\SitePackages;
use App\Models\Customer;
use App\Models\LaravelApp;
use App\Models\LaravelProcess;
use App\Models\Site;
use App\Models\SiteCommand;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Önbellek ve hız (page cache, browser cache, compression) and Composer
 * ve Node.js on any site.
 */
class SiteCacheAndToolsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RunSiteCommand::class]);
        $this->agent = new FakeAgent([
            'site.apply' => ['document_root' => '/home/kahve/web/kahveatolyesi.com.tr/public_html'],
            'site.cache.status' => ['files' => 42, 'bytes' => 3 << 20],
            'site.cache.purge' => ['files' => 42, 'bytes' => 3 << 20],
            'site.tool.scan' => [
                'composer' => '2.8.12',
                'node' => ['22' => 'v22.21.1', '24' => 'v24.11.0'],
                'default_node' => 'v24.11.0',
                'projects' => [
                    ['path' => 'app', 'composer' => false, 'composer_lock' => false, 'vendor' => false, 'node' => true, 'manager' => 'pnpm', 'lock' => true, 'modules' => false, 'scripts' => ['build', 'dev']],
                    ['path' => 'public_html', 'composer' => true, 'composer_lock' => true, 'vendor' => false, 'node' => false, 'lock' => false, 'modules' => false, 'scripts' => []],
                ],
            ],
            'site.tool.run' => ['exit_code' => 0, 'ms' => 1200],
            'laravel.run.read' => ['data' => "$ pnpm run build\nbuilt in 1.2s\n", 'offset' => 30, 'size' => 30],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr', 'provisioned_at' => now()]);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_site_tools_link_the_pages(): void
    {
        $this->get(route('sites.show', $this->site))->assertOk()
            ->assertSee(route('sites.cache', $this->site))
            ->assertSee(route('sites.packages', ['site' => $this->site, 'tool' => 'composer']))
            ->assertSee(route('sites.packages', ['site' => $this->site, 'tool' => 'node']));
        $this->get(route('sites.cache', $this->site))->assertOk()->assertSee('Sayfa önbelleği');
        $this->get(route('sites.packages', ['site' => $this->site, 'tool' => 'node']))->assertOk()->assertSee('Node.js 22 (v22.21.1)');
    }

    public function test_caching_is_off_until_turned_on_and_can_be_undone(): void
    {
        $this->assertArrayNotHasKey('cache', $this->site->agentSpec());

        $page = Livewire::test(SiteCaching::class, ['site' => $this->site->id])
            ->assertSet('page', false)
            ->set('page', true)
            ->set('pageMinutes', 30)
            ->set('exclude', "/magaza/sepet\n\n/uye")
            ->set('browser', true)
            ->set('gzip', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');
        $token = collect($page->effects['dispatches'] ?? [])->firstWhere('name', 'toast')['params']['undo']['args'][0] ?? null;

        $cache = $this->site->fresh()->cache;
        $this->assertSame(['page' => true, 'page_minutes' => 30, 'exclude' => ['/magaza/sepet', '/uye'], 'browser' => true, 'browser_days' => 30, 'gzip' => true], $cache);
        $applied = $this->agent->called('site.apply');
        $this->assertSame($cache, end($applied)['cache']);

        $page->call('purge')->assertDispatched('toast', message: 'Önbellek temizlendi: 42 sayfa silindi.');
        $this->assertSame([['domain' => 'kahveatolyesi.com.tr']], $this->agent->called('site.cache.purge'));
        $this->get(route('sites.cache', $this->site))->assertSee('42 sayfa')->assertSee('X-Serfex-Cache');

        $this->assertNotNull($token);
        $page->call('undo', $token);
        $this->assertNull($this->site->fresh()->cache);
        $this->assertArrayNotHasKey('cache', $this->site->fresh()->agentSpec());
    }

    public function test_bad_paths_and_octane_are_refused(): void
    {
        Livewire::test(SiteCaching::class, ['site' => $this->site->id])
            ->set('page', true)
            ->set('exclude', '/ok'."\n".'/bad path')
            ->call('save')
            ->assertHasErrors('exclude');

        $app = LaravelApp::factory()->create(['site_id' => $this->site->id]);
        LaravelProcess::create(['laravel_app_id' => $app->id, 'kind' => 'octane', 'name' => 'octane', 'port' => 8100, 'enabled' => true]);
        Livewire::test(SiteCaching::class, ['site' => $this->site->id])
            ->set('page', true)
            ->call('save')
            ->assertHasErrors('page');
        $this->assertNull($this->site->fresh()->cache);
    }

    public function test_a_refused_configuration_is_not_kept(): void
    {
        $this->agent->answers['site.apply'] = AgentException::rpcError('site.apply', AgentException::CONFIG_REJECTED, 'nginx rejected the configuration: bad');

        Livewire::test(SiteCaching::class, ['site' => $this->site->id])
            ->set('gzip', true)
            ->call('save')
            ->assertHasErrors('page');
        $this->assertNull($this->site->fresh()->cache);
    }

    public function test_commands_run_in_the_chosen_folder_with_the_sites_node_line(): void
    {
        $page = Livewire::test(SitePackages::class, ['site' => $this->site->id])
            ->set('tool', 'node')
            ->assertSet('path', 'app')
            ->assertSet('packageManager', 'pnpm')
            ->assertSee('run dev')
            ->set('node', '22')
            ->call('saveNode')
            ->assertDispatched('toast');
        $this->assertSame('22', $this->site->fresh()->node_version);

        $page->set('command', 'pnpm run build')->call('run')->assertHasNoErrors();
        $command = SiteCommand::firstOrFail();
        $this->assertSame(['pnpm', 'app', 'run build'], [$command->tool, $command->path, $command->command]);
        Queue::assertPushed(RunSiteCommand::class);

        (new RunSiteCommand($command))->handle($this->agent);
        $this->assertSame('succeeded', $command->fresh()->status);
        $this->assertSame([
            'domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => $this->site->php_version, 'node' => '22',
            'path' => 'app', 'run' => $command->run, 'tool' => 'pnpm', 'args' => ['run', 'build'],
        ], $this->agent->called('site.tool.run')[0]);
        $this->assertSame('built in 1.2s', trim(explode("\n", $page->call('readRun', $command->run)->effects['returns'][0]['data'] ?? '')[1] ?? ''));

        // A line that is not installed is not kept.
        $page->set('node', '18')->call('saveNode');
        $this->assertSame('22', $this->site->fresh()->node_version);
    }

    public function test_composer_runs_in_the_folder_with_composer_json(): void
    {
        Livewire::test(SitePackages::class, ['site' => $this->site->id])
            ->assertSet('path', 'public_html')
            ->assertSee('vendor/ kurulmamış')
            ->call('pick', 'install --no-dev --optimize-autoloader')
            ->call('run')
            ->assertHasNoErrors();
        $this->assertSame(['composer', 'public_html'], [SiteCommand::first()->tool, SiteCommand::first()->path]);

        Livewire::test(SitePackages::class, ['site' => $this->site->id])
            ->set('path', '../other')
            ->set('command', 'install')
            ->call('run')
            ->assertHasErrors('path');
    }

    public function test_customers_need_the_command_feature(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->actingAs($customer->user);

        $this->get(route('sites.cache', $mine))->assertOk();
        $this->get(route('sites.packages', $mine))->assertForbidden();
        $this->get(route('sites.show', $mine))->assertDontSee(route('sites.packages', ['site' => $mine, 'tool' => 'composer']));

        $customer->package->update(['allow_shell' => true]);
        $this->actingAs($customer->user->fresh());
        $this->get(route('sites.packages', $mine))->assertOk();
        $this->get(route('sites.packages', $this->site))->assertNotFound();
    }
}
