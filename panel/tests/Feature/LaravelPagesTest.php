<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Jobs\DeployLaravelApp;
use App\Jobs\ProvisionSite;
use App\Jobs\RemoveLaravelApp;
use App\Jobs\RunLaravelCommand;
use App\Jobs\SyncLaravelProcesses;
use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\LaravelShow;
use App\Livewire\Laravel\Sections\Commands;
use App\Livewire\Laravel\Sections\Deployments;
use App\Livewire\Laravel\Sections\Environment;
use App\Livewire\Laravel\Sections\Logs;
use App\Livewire\Laravel\Sections\Maintenance;
use App\Livewire\Laravel\Sections\Overview;
use App\Livewire\Laravel\Sections\Queues;
use App\Livewire\Laravel\Sections\Scheduler;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class LaravelPagesTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    private string $env = "APP_NAME=Kargo\nAPP_KEY=base64:abc\nDB_PASSWORD=gizli\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'Sercan']));
        Queue::fake();
        // Sections are lazy; tests ask for them without lazy loading each time (the flag resets after every request).
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturnUsing(fn (string $method, array $params = []) => $this->answer($method, $params))->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /**
     * What a dry-run agent with a healthy application would answer.
     *
     * @param  array<string, mixed>  $params
     */
    private function answer(string $method, array $params): mixed
    {
        return match ($method) {
            'php.versions' => ['8.2', '8.3', '8.4', '8.5'],
            'laravel.health' => ['ok' => true, 'status' => 200, 'ms' => 41],
            'laravel.status' => [
                'exists' => true, 'current' => '2', 'releases' => [['name' => '2'], ['name' => '1']],
                'maintenance' => null, 'workers' => [['worker' => 'default', 'unit' => 'servik-queue-api.hizlikargo.app_default@1.service', 'state' => 'active']],
                'schedule' => ['enabled' => true, 'last_run' => now()->subSeconds(30)->toIso8601String(), 'tasks' => [['command' => 'inspire', 'at' => now()->format('Y-m-d H:i:s'), 'duration' => '4.21ms', 'ok' => true]]],
            ],
            'laravel.probe' => match ($params['kind']) {
                'about' => ['laravel' => '13.6.0', 'environment' => 'production', 'debug' => false, 'queue' => 'database', 'cache' => 'database', 'database' => 'sqlite', 'session' => 'database'],
                'queues' => ['sizes' => [['connection' => '', 'queue' => 'default', 'size' => 15]], 'failed' => 3],
                'failed' => [['id' => '5f0c', 'connection' => 'database', 'queue' => 'default', 'job' => 'App\Jobs\SendManifest', 'error' => 'ConnectionException: timeout', 'failed_at' => '2026-10-18 21:00:00']],
                'schedule' => [['command' => 'inspire', 'description' => '', 'expression' => '0 * * * *', 'next' => now()->addHour()->toIso8601String(), 'timezone' => 'UTC']],
            },
            'laravel.env.get' => ['env' => $this->env, 'example' => "APP_NAME=\nAPP_URL=\n"],
            'laravel.env.set' => ['cached' => true, 'output' => '', 'restarted' => true],
            'laravel.logs' => ['files' => [['name' => 'laravel-2026-10-18.log', 'size' => 120]], 'file' => 'laravel-2026-10-18.log',
                'content' => "[2026-10-18 21:05:13] production.ERROR: Kargo servisine ulaşılamadı\n#0 trace\n[2026-10-18 21:06:00] production.INFO: Eşitlendi\n", 'truncated' => false],
            'laravel.run.read' => ['data' => "\$ php artisan about\nok\n", 'offset' => 22, 'size' => 22],
            'laravel.key' => ['public_key' => 'ssh-ed25519 AAAAC3Nza servik@api.hizlikargo.app'],
            'laravel.maintenance' => ['exit_code' => 0, 'ms' => 120],
            'laravel.queue.restart' => [],
            'laravel.scan' => [['domain' => 'klinikmavi.com', 'path' => 'public_html/randevu', 'laravel' => '11.45.0', 'php_requirement' => '^8.4', 'package_manager' => 'npm']],
            default => throw new \RuntimeException("unexpected agent call {$method}"),
        };
    }

    private function laravelApp(array $attributes = []): LaravelApp
    {
        $site = Site::factory()->for(SystemUser::factory()->state(['name' => 'hizlikargo']))->create(['domain' => 'api.hizlikargo.app', 'web_root' => 'laravel/current/public']);
        $app = LaravelApp::factory()->for($site)->create($attributes + ['current_release' => '2']);
        $app->workers()->create(['name' => 'default']);

        return $app;
    }

    public function test_list_shows_apps_with_version_branch_and_workers(): void
    {
        $app = $this->laravelApp(['php_requirement' => '^8.5']);
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 2, 'kind' => 'deploy', 'status' => DeploymentStatus::Succeeded, 'release' => '2', 'run' => 'deploy-2', 'finished_at' => now()]);

        $this->get(route('laravel.index'))->assertOk()
            ->assertSee('api.hizlikargo.app')
            ->assertSee('13.6')
            ->assertSee('main')
            ->assertSee('1 işçi')
            ->assertSee('composer.json PHP ^8.5 istiyor, site PHP 8.4');
    }

    public function test_install_wizard_installs_a_new_project(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        Site::factory()->create(['domain' => 'taken.com.tr'])->laravelApp()->create(['path' => LaravelApp::MANAGED_PATH, 'status' => LaravelAppStatus::Active, 'source' => 'create']);

        $page = Livewire::withoutLazyLoading()->test(LaravelIndex::class)->call('openInstall')
            ->assertSet('siteId', $site->id)
            ->assertSet('phpVersion', '8.4')
            ->assertViewHas('sites', fn ($sites) => $sites->pluck('domain')->all() === ['kahve.com.tr']);

        $page->set('source', 'git')->call('install')->assertHasErrors(['repository' => 'required_if']);
        $page->set('source', 'create')->set('phpVersion', '8.2')->call('install')->assertHasErrors('phpVersion');
        $page->set('phpVersion', '8.4')->set('kit', 'react')->set('database', 'sqlite')->call('install')->assertHasNoErrors()->assertRedirect();

        $app = $site->laravelApp()->sole();
        $this->assertSame('laravel/react-starter-kit', $app->package);
        Queue::assertPushed(DeployLaravelApp::class);
    }

    public function test_private_repository_shows_the_deploy_key_before_installing(): void
    {
        $site = Site::factory()->for(SystemUser::factory()->state(['name' => 'hizlikargo']))->create(['domain' => 'api.hizlikargo.app']);
        $this->agent->shouldReceive('call')->once()->with('laravel.key', ['domain' => 'api.hizlikargo.app', 'user' => 'hizlikargo', 'php_version' => '8.4'], Mockery::any())
            ->andReturn(['public_key' => 'ssh-ed25519 AAAAC3Nza servik@api.hizlikargo.app']);

        $page = Livewire::withoutLazyLoading()->test(LaravelIndex::class)->call('openInstall', $site->id)
            ->set('source', 'git')->set('repository', 'hizlikargo/api')->set('private', true)->set('database', 'none')
            ->call('install')
            ->assertSet('step', 'key')
            ->assertSee('ssh-ed25519 AAAAC3Nza');
        $this->assertSame(0, LaravelApp::count());

        $page->call('install')->assertRedirect();
        $app = LaravelApp::sole();
        $this->assertSame('git@github.com:hizlikargo/api.git', $app->repository);
        $this->assertSame('ssh-ed25519 AAAAC3Nza servik@api.hizlikargo.app', $app->deploy_key);
    }

    public function test_scan_finds_apps_and_adopts_them_with_undo(): void
    {
        $site = Site::factory()->create(['domain' => 'klinikmavi.com', 'php_version' => '8.3']);

        $page = Livewire::withoutLazyLoading()->test(LaravelIndex::class)->call('scan')
            ->assertSee('klinikmavi.com')
            ->assertSee('Uygulama PHP ^8.4 istiyor')
            ->assertSee('bağlarken düzeltilir');
        $page->call('adopt', 0)->assertDispatched('toast');

        $app = $site->laravelApp()->sole();
        $this->assertSame('public_html/randevu', $app->path);
        $this->assertSame('public_html/randevu/public', $site->fresh()->web_root);

        $page->call('undoAdopt', $app->id, null);
        $this->assertSame(0, LaravelApp::count());
        $this->assertNull($site->fresh()->web_root);
        // ProvisionSite is unique: the queued one applies the final web root.
        Queue::assertPushed(ProvisionSite::class);
    }

    public function test_app_page_and_every_section_render(): void
    {
        $app = $this->laravelApp();
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 2, 'kind' => 'deploy', 'status' => DeploymentStatus::Succeeded, 'release' => '2', 'commit' => str_repeat('c', 40), 'run' => 'deploy-2', 'finished_at' => now()]);
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 1, 'kind' => 'install', 'status' => DeploymentStatus::Succeeded, 'release' => '1', 'run' => 'install-1', 'finished_at' => now()->subDay()]);

        $this->get(route('laravel.show', $app))->assertOk()
            ->assertSee('Genel bakış')->assertSee('Önizleme ortamları')->assertSee('yakında')
            ->assertSee('Komut çalıştır');

        Livewire::withoutLazyLoading()->test(Overview::class, ['appId' => $app->id])->assertSee('200 · 41 ms')->assertSee('#2 · ccccccc')->assertSee('15 bekleyen · 1 işçi')->assertSee('3 başarısız iş');
        Livewire::withoutLazyLoading()->test(Deployments::class, ['appId' => $app->id])->assertSee('hizlikargo/api')->assertSee('Bu sürüme dön')->assertSee($app->webhookUrl());
        Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])->assertSee('APP_NAME')->assertSet('rows.0', ['key' => 'APP_NAME', 'value' => 'Kargo']);
        Livewire::withoutLazyLoading()->test(Commands::class, ['appId' => $app->id])->assertSee('php artisan')->assertSee('migrate --force');
        Livewire::withoutLazyLoading()->test(Queues::class, ['appId' => $app->id])->assertSee('Çalışıyor')->assertSee('15');
        Livewire::withoutLazyLoading()->test(Scheduler::class, ['appId' => $app->id])->assertSee('inspire')->assertSee('0 * * * *')->assertSee('Başarılı');
        Livewire::withoutLazyLoading()->test(Logs::class, ['appId' => $app->id])->assertSee('Kargo servisine ulaşılamadı')
            ->set('level', 'error')->assertDontSee('Eşitlendi');
        Livewire::withoutLazyLoading()->test(Maintenance::class, ['appId' => $app->id])->assertSee('Uygulama açık')->assertSee('Bakım moduna al');
    }

    public function test_install_progress_streams_the_log(): void
    {
        $app = $this->laravelApp(['status' => LaravelAppStatus::Installing, 'current_release' => null]);
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 1, 'kind' => 'install', 'status' => DeploymentStatus::Running, 'run' => 'install-abc']);

        $page = Livewire::withoutLazyLoading()->test(LaravelShow::class, ['app' => $app->id])->assertSee('Kurulum sürüyor')->assertDontSee('Genel bakış');
        $this->assertSame(['data' => "\$ php artisan about\nok\n", 'offset' => 22, 'more' => false, 'done' => false], $page->instance()->readRun('install-abc', 0));
        $this->assertNull($page->instance()->readRun('someone-elses-run', 0));
    }

    public function test_deploy_from_the_page_header(): void
    {
        $app = $this->laravelApp();

        Livewire::withoutLazyLoading()->test(LaravelShow::class, ['app' => $app->id])
            ->call('openDeploy')->assertSet('deploying', true)->assertSet('ref', 'main')
            ->set('ref', 'release/2.8')->set('migrate', false)
            ->call('deploy')
            ->assertSet('section', 'dagitimlar')
            ->assertDispatched('show-deploy-log');

        $deployment = $app->deployments()->sole();
        $this->assertSame('release/2.8', $deployment->ref);
        $this->assertFalse($deployment->migrate);
        $this->assertSame('Sercan', $deployment->triggered_by);
        Queue::assertPushed(DeployLaravelApp::class);

        // Without a repository there is nothing to deploy.
        $app->update(['repository' => null]);
        Livewire::withoutLazyLoading()->test(LaravelShow::class, ['app' => $app->id])->call('openDeploy')->assertSet('deploying', false);
    }

    public function test_env_save_keeps_history_and_can_be_undone(): void
    {
        $app = $this->laravelApp();
        Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])->call('addRow', 'bad key')->call('save')->assertHasErrors('rows.3.key');

        $this->agent->shouldReceive('call')->once()->with('laravel.env.set', Mockery::on(fn ($p) => $p['content'] === "APP_NAME='Hızlı Kargo'\nAPP_KEY=base64:abc\nDB_PASSWORD=gizli\n\nAPP_URL=https://api.hizlikargo.app\n"), Mockery::any())
            ->andReturn(['cached' => true]);

        $page = Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])
            ->set('rows.0.value', 'Hızlı Kargo')
            ->call('addRow', 'APP_URL')
            ->set('rows.3.value', 'https://api.hizlikargo.app')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: '.env kaydedildi, config:cache yenilendi.');

        $version = $app->envVersions()->sole();
        $this->assertSame($this->env, $version->content);
        $this->assertSame('APP_URL eklendi, APP_NAME değişti', $version->summary);
        $this->assertNotSame($this->env, $version->getRawOriginal('content'), 'stored encrypted');

        $this->agent->shouldReceive('call')->once()->with('laravel.env.set', Mockery::on(fn ($p) => $p['content'] === $this->env), Mockery::any())->andReturn(['cached' => true]);
        $page->call('restore', $version->id);
        $this->assertSame(2, $app->envVersions()->count());

    }

    public function test_commands_run_in_the_background(): void
    {
        $app = $this->laravelApp();

        Livewire::withoutLazyLoading()->test(Commands::class, ['appId' => $app->id])
            ->set('command', 'php artisan migrate:status')
            ->call('run')
            ->assertHasNoErrors();
        $command = $app->commands()->sole();
        $this->assertSame(['artisan', 'migrate:status'], [$command->tool, $command->command]);
        Queue::assertPushed(RunLaravelCommand::class);

        Livewire::withoutLazyLoading()->test(Commands::class, ['appId' => $app->id])->set('tool', 'node')->set('packageManager', 'pnpm')->set('command', 'run build')->call('run');
        $this->assertSame('pnpm', $app->commands()->latest('id')->first()->tool);

        Livewire::withoutLazyLoading()->test(Commands::class, ['appId' => $app->id])->set('command', 'about --only="env')->call('run')->assertHasErrors('command');
        Livewire::withoutLazyLoading()->test(Commands::class, ['appId' => $app->id])->set('command', 'route:list')->call('saveCommand');
        $this->assertContains('route:list', $app->fresh()->saved_commands['artisan']);
    }

    public function test_queue_workers_scale_and_apply(): void
    {
        $app = $this->laravelApp();
        $worker = $app->workers()->sole();

        Livewire::withoutLazyLoading()->test(Queues::class, ['appId' => $app->id])->call('scale', $worker->id, 1)->call('scale', $worker->id, 1);
        $this->assertSame(3, $worker->fresh()->processes);
        $this->assertSame('syncing', $app->fresh()->processes_state);
        Queue::assertPushed(SyncLaravelProcesses::class);

        Livewire::withoutLazyLoading()->test(Queues::class, ['appId' => $app->id])->call('openWorker')
            ->set('form.name', 'Reports')->set('form.queue', 'reports, default')->set('form.timeout', 900)
            ->call('saveWorker')->assertHasNoErrors();
        $this->assertSame('reports,default', $app->workers()->where('name', 'reports')->sole()->queue);

        $this->agent->shouldReceive('call')->once()->with('laravel.run', Mockery::on(fn ($p) => $p['args'] === ['queue:retry', '5f0c'] && ! isset($p['run'])), Mockery::any())->andReturn(['exit_code' => 0]);
        Livewire::withoutLazyLoading()->test(Queues::class, ['appId' => $app->id])->call('openFailed')->assertSee('App\Jobs\SendManifest')->call('failedAction', 'retry', '5f0c');
    }

    public function test_scheduler_and_maintenance(): void
    {
        $app = $this->laravelApp(['scheduler' => false]);
        Livewire::withoutLazyLoading()->test(Scheduler::class, ['appId' => $app->id])->call('toggle');
        $this->assertTrue($app->fresh()->scheduler);
        Queue::assertPushed(SyncLaravelProcesses::class);

        Livewire::withoutLazyLoading()->test(Scheduler::class, ['appId' => $app->id])->call('runNow', 'inspire')->assertDispatched('show-section', section: 'komutlar');
        $this->assertSame('inspire', $app->commands()->sole()->command);

        $this->agent->shouldReceive('call')->once()->with('laravel.maintenance', Mockery::on(fn ($p) => $p['down'] === true && $p['secret'] === 'ekip-girisi-2026' && $p['retry'] === 30), Mockery::any())
            ->andReturn(['exit_code' => 0]);
        Livewire::withoutLazyLoading()->test(Maintenance::class, ['appId' => $app->id])->set('secret', 'ekip-girisi-2026')->set('retry', 30)->call('down')->assertHasNoErrors();
        $this->assertSame('ekip-girisi-2026', $app->fresh()->maintenance_secret);
    }

    public function test_removing_needs_the_domain_typed(): void
    {
        $app = $this->laravelApp();

        Livewire::withoutLazyLoading()->test(LaravelShow::class, ['app' => $app->id])->call('openRemove')->set('confirmDomain', 'yanlis')->call('remove')->assertHasErrors('confirmDomain');
        Queue::assertNotPushed(RemoveLaravelApp::class);

        Livewire::withoutLazyLoading()->test(LaravelShow::class, ['app' => $app->id])->call('openRemove')->set('confirmDomain', 'api.hizlikargo.app')->call('remove');
        $this->assertSame(LaravelAppStatus::Removing, $app->fresh()->status);
        Queue::assertPushed(RemoveLaravelApp::class, fn ($job) => $job->removeFiles);
    }

    public function test_site_page_links_to_the_laravel_tool(): void
    {
        $app = $this->laravelApp();
        $other = Site::factory()->create(['domain' => 'kahve.com.tr']);

        $this->get(route('sites.show', $app->site))->assertOk()->assertSee(route('laravel.show', $app), false)->assertSee('Laravel 13.6')
            ->assertSee(route('laravel.show', [$app, 'bolum' => 'dagitimlar']), false);
        $this->get(route('sites.show', $other))->assertOk()->assertSee(route('laravel.index', ['site' => $other->id]), false);
    }
}
