<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Jobs\DeleteSite;
use App\Jobs\DeployLaravelApp;
use App\Jobs\ProvisionSite;
use App\Jobs\RemoveLaravelApp;
use App\Jobs\RollbackLaravelApp;
use App\Jobs\RunLaravelCommand;
use App\Jobs\SyncLaravelProcesses;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\Zones;
use App\Services\Laravel\Backups;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\Processes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class LaravelJobsTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::factory()->for(SystemUser::factory()->state(['name' => 'hizlikargo']))->create(['domain' => 'api.hizlikargo.app', 'php_version' => '8.3']);
    }

    private function deploy(LaravelDeployment $deployment, AgentClient $agent): void
    {
        (new DeployLaravelApp($deployment))->handle($agent, new Processes($agent), new Backups($agent));
    }

    public function test_install_creates_the_app_and_queues_its_first_deploy(): void
    {
        Queue::fake();
        $site = $this->site();

        $app = app(LaravelApps::class)->install($site, [
            'source' => 'create', 'kit' => 'livewire', 'php_version' => '8.4', 'database' => 'sqlite',
            'frontend' => true, 'package_manager' => 'pnpm', 'worker' => true, 'scheduler' => true,
        ]);

        $this->assertSame(LaravelAppStatus::Installing, $app->status);
        $this->assertSame('laravel/livewire-starter-kit', $app->package);
        $this->assertSame(LaravelApp::MANAGED_PATH, $app->path);
        $this->assertTrue($app->scheduler);
        $this->assertSame(['default'], $app->workers()->pluck('name')->all());
        $this->assertSame('8.4', $site->fresh()->php_version);
        $env = $app->fresh()->install_env;
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame('/home/hizlikargo/web/api.hizlikargo.app/laravel/shared/database/database.sqlite', $env['DB_DATABASE']);
        $this->assertSame('http://api.hizlikargo.app', $env['APP_URL']);
        $this->assertSame('false', $env['APP_DEBUG']);
        // Stored encrypted.
        $this->assertStringNotContainsString('sqlite', (string) $app->getRawOriginal('install_env'));

        $deployment = $app->deployments()->sole();
        $this->assertSame('install', $deployment->kind);
        $this->assertSame(1, $deployment->number);
        Queue::assertPushed(DeployLaravelApp::class, fn ($job) => $job->deployment->is($deployment));
    }

    public function test_first_deploy_points_the_site_at_the_release_starts_processes_and_checks_health(): void
    {
        Queue::fake();
        $site = $this->site();
        $app = app(LaravelApps::class)->install($site, [
            'source' => 'git', 'repository' => 'https://github.com/hizlikargo/api.git', 'branch' => 'main', 'database' => 'existing',
            'db' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'kargo', 'username' => 'kargo', 'password' => 'gizli'],
            'frontend' => false, 'worker' => true, 'scheduler' => false,
        ]);
        $deployment = $app->deployments()->sole();

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('laravel.deploy', Mockery::on(function (array $spec) {
            return $spec['domain'] === 'api.hizlikargo.app' && $spec['user'] === 'hizlikargo' && $spec['release'] === '1'
                && $spec['source'] === ['type' => 'git', 'repository' => 'https://github.com/hizlikargo/api.git', 'ref' => 'main']
                && $spec['health'] === null && $spec['seed_from'] === '' && $spec['rollback'] === true
                && ((array) $spec['env'])['DB_PASSWORD'] === 'gizli' && $spec['build']['frontend'] === false;
        }), 1900.0)->andReturn([
            'release' => '1', 'commit' => str_repeat('a', 40), 'message' => 'İlk sürüm', 'laravel' => '13.6.0', 'php_requirement' => '^8.3',
            'package_manager' => 'npm', 'current' => '1', 'rolled_back' => false, 'releases' => [],
        ]);
        $agent->shouldReceive('call')->once()->with('site.apply', Mockery::on(fn ($s) => $s['web_root'] === 'laravel/current/public'), 120.0)
            ->andReturn(['document_root' => '/home/hizlikargo/web/api.hizlikargo.app/laravel/current/public']);
        $agent->shouldReceive('call')->once()->with('laravel.queue.apply', Mockery::on(fn ($s) => $s['path'] === 'laravel/current' && $s['workers'][0]['name'] === 'default'), 120.0)->andReturn([]);
        $agent->shouldReceive('call')->once()->with('laravel.schedule.apply', Mockery::on(fn ($s) => $s['enabled'] === false), 120.0)->andReturn([]);
        $agent->shouldReceive('call')->once()->with('laravel.process.apply', Mockery::on(fn ($s) => $s['processes'] === []), 120.0)->andReturn([]);
        $agent->shouldReceive('call')->once()->with('laravel.health', ['domain' => 'api.hizlikargo.app', 'path' => '/up'], 120.0)->andReturn(['ok' => true, 'status' => 200, 'ms' => 41]);

        $this->deploy($deployment, $agent);

        $deployment->refresh();
        $app->refresh();
        $this->assertSame(DeploymentStatus::Succeeded, $deployment->status);
        $this->assertSame(200, $deployment->health_status);
        $this->assertSame('aaaaaaa', $deployment->shortCommit());
        $this->assertSame(LaravelAppStatus::Active, $app->status);
        $this->assertSame('1', $app->current_release);
        $this->assertSame('13.6.0', $app->laravel_version);
        $this->assertNull($app->install_env, 'the database password is not kept after the install');
        $this->assertSame('laravel/current/public', $site->fresh()->web_root);
        $this->assertSame('ok', $app->processes_state);
    }

    public function test_a_failed_health_check_after_the_first_release_is_reported(): void
    {
        $site = $this->site();
        $app = LaravelApp::factory()->for($site)->create(['status' => LaravelAppStatus::Installing, 'current_release' => null, 'source' => 'create', 'repository' => null, 'package' => 'laravel/laravel']);
        $deployment = LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 1, 'kind' => 'install', 'run' => 'install-x']);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('laravel.deploy', Mockery::on(fn ($s) => $s['source'] === ['type' => 'create', 'package' => 'laravel/laravel']), Mockery::any())
            ->andReturn(['release' => '1', 'current' => '1', 'laravel' => '13.6.0']);
        $agent->shouldReceive('call')->with('site.apply', Mockery::any(), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.queue.apply', Mockery::any(), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.schedule.apply', Mockery::any(), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.process.apply', Mockery::any(), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.health', Mockery::any(), Mockery::any())->andReturn(['ok' => false, 'status' => 500, 'ms' => 12]);

        $this->deploy($deployment, $agent);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Unhealthy, $deployment->status);
        $this->assertSame('Sağlık kontrolü başarısız: HTTP 500', $deployment->error);
        $this->assertSame('Yeni proje: laravel/laravel', $deployment->message);
        $this->assertSame(LaravelAppStatus::Active, $app->fresh()->status);
    }

    public function test_later_deploys_check_health_in_the_agent_and_record_a_rollback(): void
    {
        Queue::fake();
        $app = LaravelApp::factory()->for($this->site())->create(['current_release' => '3']);
        $deployment = app(LaravelApps::class)->deploy($app, 'v2.1.0', triggeredBy: 'GitHub webhook');

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('laravel.deploy', Mockery::on(fn ($s) => $s['health'] === ['path' => '/up'] && $s['source']['ref'] === 'v2.1.0'
            && (array) $s['env'] === [] && $s['keep'] === 5), Mockery::any())
            ->andReturn(['release' => (string) $deployment->number, 'current' => '3', 'rolled_back' => true, 'problem' => 'health check failed: HTTP 500', 'health' => ['ok' => false, 'status' => 500, 'ms' => 9]]);

        $this->deploy($deployment, $agent);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::RolledBack, $deployment->status);
        $this->assertSame('GitHub webhook', $deployment->triggered_by);
        $this->assertSame('3', $app->fresh()->current_release);
    }

    public function test_an_app_found_on_the_site_moves_to_releases_on_its_first_deploy(): void
    {
        Queue::fake();
        $site = $this->site();
        $app = LaravelApp::factory()->for($site)->create(['path' => 'public_html', 'source' => 'existing', 'current_release' => null]);
        $deployment = app(LaravelApps::class)->deploy($app, 'main');

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('laravel.deploy', Mockery::on(fn ($s) => $s['seed_from'] === 'public_html' && $s['health'] === null), Mockery::any())
            ->andReturn(['release' => '1', 'current' => '1']);
        $agent->shouldReceive('call')->with('site.apply', Mockery::on(fn ($s) => $s['web_root'] === 'laravel/current/public'), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.queue.apply', Mockery::on(fn ($s) => $s['path'] === 'laravel/current'), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.schedule.apply', Mockery::any(), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.process.apply', Mockery::any(), Mockery::any())->andReturn([]);
        $agent->shouldReceive('call')->with('laravel.health', Mockery::any(), Mockery::any())->andReturn(['ok' => true, 'status' => 200, 'ms' => 5]);

        $this->deploy($deployment, $agent);

        $this->assertSame(LaravelApp::MANAGED_PATH, $app->fresh()->path);
        $this->assertSame(DeploymentStatus::Succeeded, $deployment->fresh()->status);
    }

    public function test_install_failure_marks_the_app_and_can_be_retried(): void
    {
        Queue::fake();
        $app = app(LaravelApps::class)->install($this->site(), ['source' => 'create', 'database' => 'none', 'frontend' => false, 'worker' => false, 'scheduler' => false]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('laravel.deploy', -32000, 'composer install failed with exit code 2'));

        $this->deploy($app->deployments()->sole(), $agent);

        $app->refresh();
        $this->assertSame(LaravelAppStatus::Failed, $app->status);
        $this->assertSame('composer install failed with exit code 2', $app->last_error);
        $this->assertSame(DeploymentStatus::Failed, $app->deployments()->sole()->status);
        $this->assertSame('file', $app->install_env['SESSION_DRIVER'], 'kept for the retry');

        app(LaravelApps::class)->retryInstall($app);
        $this->assertSame(LaravelAppStatus::Installing, $app->fresh()->status);
        $this->assertSame(2, $app->deployments()->count());
    }

    public function test_rollback_puts_an_earlier_release_live(): void
    {
        Queue::fake();
        $app = LaravelApp::factory()->for($this->site())->create(['current_release' => '5']);
        $to = LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 4, 'kind' => 'deploy', 'status' => DeploymentStatus::Succeeded, 'release' => '4', 'run' => 'deploy-4']);
        $deployment = app(LaravelApps::class)->rollback($app, $to);
        Queue::assertPushed(RollbackLaravelApp::class);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('laravel.rollback', Mockery::on(fn ($s) => $s['release'] === '4' && $s['domain'] === 'api.hizlikargo.app'), 120.0)
            ->andReturn(['previous' => '5', 'current' => '4']);
        (new RollbackLaravelApp($deployment))->handle($agent);

        $this->assertSame('4', $app->fresh()->current_release);
        $this->assertSame(DeploymentStatus::Succeeded, $deployment->fresh()->status);
        $this->assertSame('rollback', $deployment->kind);
    }

    public function test_commands_run_with_split_arguments(): void
    {
        Queue::fake();
        $app = LaravelApp::factory()->for($this->site())->create();
        $command = app(LaravelApps::class)->runCommand($app, 'artisan', 'tinker --execute="echo 1;"');
        Queue::assertPushed(RunLaravelCommand::class);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('laravel.run', Mockery::on(fn ($s) => $s['tool'] === 'artisan' && $s['args'] === ['tinker', '--execute=echo 1;']
            && $s['run'] === $command->run && $s['path'] === 'laravel/current'), 1900.0)->andReturn(['exit_code' => 3, 'ms' => 80]);
        (new RunLaravelCommand($command))->handle($agent);

        $command->refresh();
        $this->assertSame('failed', $command->status);
        $this->assertSame(3, $command->exit_code);
    }

    public function test_process_sync_records_failures(): void
    {
        $app = LaravelApp::factory()->for($this->site())->create(['processes_state' => 'syncing']);
        $app->workers()->create(['name' => 'default', 'processes' => 2]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('laravel.queue.apply', Mockery::any(), Mockery::any())->andThrow(AgentException::rpcError('laravel.queue.apply', -32000, 'systemctl enable failed'));

        (new SyncLaravelProcesses($app))->handle(new Processes($agent));

        $this->assertSame('failed', $app->fresh()->processes_state);
        $this->assertSame('systemctl enable failed', $app->fresh()->processes_error);
    }

    public function test_remove_stops_processes_and_serves_public_html_again(): void
    {
        $site = $this->site();
        $site->update(['web_root' => 'laravel/current/public']);
        $app = LaravelApp::factory()->for($site)->create();

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('laravel.remove', Mockery::on(fn ($s) => $s['remove_files'] === true), 120.0)->andReturn(['removed' => true]);
        $agent->shouldReceive('call')->once()->with('site.apply', Mockery::on(fn ($s) => ! isset($s['web_root'])), 120.0)->andReturn(['document_root' => '/home/hizlikargo/web/api.hizlikargo.app/public_html']);
        (new RemoveLaravelApp($app, removeFiles: true))->handle($agent);

        $this->assertNull(LaravelApp::find($app->id));
        $this->assertNull($site->fresh()->web_root);
    }

    public function test_adopting_points_the_site_at_the_public_directory(): void
    {
        Queue::fake();
        $site = $this->site();
        $app = app(LaravelApps::class)->adopt($site, ['path' => 'public_html/randevu', 'laravel' => '11.45.0', 'php_requirement' => '^8.2', 'package_manager' => '']);

        $this->assertSame('existing', $app->source);
        $this->assertFalse($app->isManaged());
        $this->assertSame('public_html/randevu/public', $site->fresh()->web_root);
        Queue::assertPushed(ProvisionSite::class);
    }

    public function test_deleting_a_site_stops_its_laravel_processes_first(): void
    {
        $site = $this->site();
        LaravelApp::factory()->for($site)->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->ordered()->with('laravel.remove', Mockery::on(fn ($s) => $s['remove_files'] === false && $s['domain'] === 'api.hizlikargo.app'), 120.0)->andReturn(['removed' => true]);
        $agent->shouldReceive('call')->once()->ordered()->with('site.delete', Mockery::any(), 120.0)->andReturn(['user_removed' => true]);

        (new DeleteSite($site))->handle($agent, app(Zones::class));

        $this->assertNull(Site::find($site->id));
        $this->assertSame(0, LaravelApp::count());
    }

    public function test_webhook_deploys_pushes_to_the_app_branch(): void
    {
        Queue::fake();
        $app = LaravelApp::factory()->for($this->site())->create(['auto_deploy' => true]);
        $url = '/hooks/deploy/'.$app->webhook_token;

        $this->postJson($url, ['ref' => 'refs/heads/develop'], ['X-GitHub-Event' => 'push'])->assertStatus(202)->assertJsonMissing(['deployment' => 1]);
        Queue::assertNothingPushed();

        $this->postJson($url, ['ref' => 'refs/heads/main'], ['X-GitHub-Event' => 'push'])->assertStatus(202)->assertJson(['deployment' => 1]);
        $this->assertSame('GitHub webhook', $app->deployments()->sole()->triggered_by);
        Queue::assertPushed(DeployLaravelApp::class);

        // One at a time.
        $this->postJson($url, ['ref' => 'refs/heads/main'], ['X-GitHub-Event' => 'push'])->assertStatus(409);
        $this->postJson($url, [], ['X-GitHub-Event' => 'ping'])->assertOk()->assertJson(['message' => 'pong']);
        $this->postJson('/hooks/deploy/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_webhook_from_ci_deploys_a_tag_even_when_automatic_deploys_are_off(): void
    {
        Queue::fake();
        $app = LaravelApp::factory()->for($this->site())->create(['auto_deploy' => false]);

        $this->post('/hooks/deploy/'.$app->webhook_token.'?ref=v2.0.0')->assertStatus(202);
        $this->assertSame('v2.0.0', $app->deployments()->sole()->ref);

        // A provider push is ignored while automatic deploys are off.
        LaravelDeployment::query()->update(['status' => DeploymentStatus::Succeeded]);
        $this->postJson('/hooks/deploy/'.$app->webhook_token, ['ref' => 'refs/heads/main'])->assertStatus(202);
        $this->assertSame(1, $app->deployments()->count());
    }
}
