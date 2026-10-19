<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Jobs\BackupLaravelApp;
use App\Jobs\CreateLaravelPreview;
use App\Jobs\DeleteLaravelPreview;
use App\Jobs\DeleteSite;
use App\Jobs\DeployLaravelApp;
use App\Jobs\ProvisionSite;
use App\Jobs\PurgeLaravelBackup;
use App\Jobs\RestoreLaravelApp;
use App\Livewire\Laravel\Sections\Commands;
use App\Livewire\Laravel\Sections\Maintenance;
use App\Models\DnsZone;
use App\Models\LaravelApp;
use App\Models\LaravelBackup;
use App\Models\LaravelCommand;
use App\Models\LaravelDeployment;
use App\Models\LaravelPreview;
use App\Models\LaravelProcess;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\Zones;
use App\Services\Laravel\Backups;
use App\Services\Laravel\Previews;
use App\Services\Laravel\Processes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class LaravelAdvancedTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'laravel.status' => ['exists' => true, 'processes' => [], 'info' => ['packages' => []]],
            'laravel.env.get' => ['env' => "APP_NAME=Kargo\nAPP_KEY=base64:parent\nDB_CONNECTION=pgsql\nDB_PASSWORD=gizli\nQUEUE_CONNECTION=redis\nSTRIPE_KEY=pk_live_x\n"],
            'site.apply' => ['ok' => true],
            'system.addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function laravelApp(array $attributes = []): LaravelApp
    {
        $site = Site::factory()->for(SystemUser::factory()->state(['name' => 'hizlikargo']))
            ->create(['domain' => 'hizlikargo.app', 'web_root' => 'laravel/current/public', 'php_version' => '8.4']);

        return LaravelApp::factory()->for($site)->create($attributes);
    }

    private function backup(LaravelApp $app, string $kind = 'manual', string $status = 'succeeded', ?Carbon $at = null): LaravelBackup
    {
        $backup = $app->backups()->create([
            'key' => ($at ?? now())->format('Ymd-His').'-'.fake()->unique()->lexify('????????????????'),
            'kind' => $kind, 'status' => $status, 'parts' => ['code', 'env', 'storage', 'database'], 'run' => 'backup-'.fake()->unique()->lexify('????????'),
        ]);
        if ($at !== null) {
            $backup->forceFill(['created_at' => $at])->save();
        }

        return $backup;
    }

    // --- Preview environments -------------------------------------------

    public function test_preview_names_follow_the_pull_request_or_branch(): void
    {
        $this->assertSame('pr-212', Previews::nameFor('feature/odeme', 212));
        $this->assertSame('feature-yeni-odeme', Previews::nameFor('feature/yeni_odeme'));
        $this->assertSame('preview', Previews::nameFor('///'));
    }

    public function test_a_pull_request_webhook_opens_updates_and_closes_a_preview(): void
    {
        $app = $this->laravelApp(['previews_enabled' => true]);
        $url = '/hooks/deploy/'.$app->webhook_token;
        $event = fn (string $action, string $fork = 'hizlikargo/api') => ['action' => $action, 'pull_request' => [
            'number' => 212, 'title' => 'Yeni ödeme akışı', 'html_url' => 'https://github.com/hizlikargo/api/pull/212',
            'head' => ['ref' => 'feature/odeme', 'repo' => ['full_name' => $fork]], 'base' => ['repo' => ['full_name' => 'hizlikargo/api']],
        ]];

        $this->postJson($url, $event('opened'), ['X-GitHub-Event' => 'pull_request'])
            ->assertStatus(202)->assertJson(['preview' => 'pr-212', 'url' => 'https://pr-212.hizlikargo.app']);
        $preview = $app->previews()->sole();
        $this->assertSame(['feature/odeme', 'creating', 'GitHub pull request', 'webhook'], [$preview->branch, $preview->status, $preview->created_by, $preview->source]);
        Queue::assertPushed(CreateLaravelPreview::class, fn ($job) => $job->preview->is($preview));

        // Forks never get one; neither do apps with previews off.
        $this->postJson($url, ['action' => 'opened'] + $event('opened', 'saldirgan/api'), ['X-GitHub-Event' => 'pull_request'])->assertStatus(202);
        $this->assertSame(1, $app->previews()->count());

        $this->postJson($url, $event('labeled'), ['X-GitHub-Event' => 'pull_request'])->assertStatus(202)->assertJsonMissing(['preview' => 'pr-212']);

        $this->postJson($url, $event('closed'), ['X-GitHub-Event' => 'pull_request'])->assertStatus(202)->assertJson(['preview' => 'pr-212']);
        $this->assertSame('deleting', $preview->fresh()->status);
        Queue::assertPushed(DeleteLaravelPreview::class);

        $app->update(['previews_enabled' => false]);
        $this->postJson($url, ['object_attributes' => ['action' => 'open', 'iid' => 7, 'title' => 'x', 'source_branch' => 'fix', 'source_project_id' => 1, 'target_project_id' => 1]],
            ['X-Gitlab-Event' => 'Merge Request Hook'])->assertStatus(202)->assertJson(['message' => 'Preview environments are off for this application.']);
    }

    public function test_previews_respect_the_limit_and_need_a_repository(): void
    {
        $app = $this->laravelApp(['preview_limit' => 1]);
        $previews = app(Previews::class);
        $previews->create($app, 'feature/a');

        $this->expectExceptionMessage('En fazla 1 önizleme');
        $previews->create($app, 'feature/b');
    }

    public function test_the_preview_job_creates_a_site_dns_label_and_a_preview_app(): void
    {
        Queue::fakeExcept([ProvisionSite::class]);
        $app = $this->laravelApp(['preview_seed' => true, 'preview_env' => "STRIPE_KEY=pk_test_x\n", 'frontend' => true]);
        $zone = DnsZone::factory()->create(['name' => 'hizlikargo.app', 'site_id' => $app->site_id]);
        $preview = app(Previews::class)->create($app, 'feature/odeme', pr: 212, title: 'Ödeme');

        (new CreateLaravelPreview($preview))->handle($this->agent, app(Zones::class));

        $preview->refresh();
        $site = $preview->site;
        $this->assertSame('pr-212.hizlikargo.app', $site->domain);
        $this->assertSame($app->site->system_user_id, $site->system_user_id);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertTrue($zone->records()->where('name', 'pr-212')->exists());

        $child = $preview->previewApp;
        $this->assertTrue($child->isPreview());
        $this->assertSame('feature/odeme', $child->branch);
        $env = $child->install_env;
        $this->assertSame('staging', $env['APP_ENV']);
        $this->assertSame('', $env['APP_KEY']);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame('sync', $env['QUEUE_CONNECTION']);
        $this->assertSame('pk_test_x', $env['STRIPE_KEY']);
        $this->assertSame('Kargo (pr-212)', $env['APP_NAME']);
        $this->assertStringEndsWith('/web/pr-212.hizlikargo.app/laravel/shared/database/database.sqlite', $env['DB_DATABASE']);
        Queue::assertPushed(DeployLaravelApp::class, fn ($job) => $job->deployment->laravel_app_id === $child->id && $job->deployment->kind === 'install');

        // Previews stay out of the application's own lists.
        $this->assertSame(0, $app->attentionCount());
    }

    public function test_deleting_a_preview_removes_its_site_and_dns_label(): void
    {
        Queue::fakeExcept([ProvisionSite::class, DeleteSite::class]);
        $app = $this->laravelApp();
        $zone = DnsZone::factory()->create(['name' => 'hizlikargo.app', 'site_id' => $app->site_id]);
        $preview = app(Previews::class)->create($app, 'feature/odeme', pr: 5);
        (new CreateLaravelPreview($preview))->handle($this->agent, app(Zones::class));
        $siteId = $preview->fresh()->site_id;

        (new DeleteLaravelPreview($preview->fresh()))->handle(app(Zones::class));

        $this->assertNull(Site::find($siteId));
        $this->assertNull(LaravelPreview::find($preview->id));
        $this->assertFalse($zone->records()->where('name', 'pr-5')->exists());
        $this->assertContains('site.delete', $this->agent->methods());
        $this->assertFalse($this->agent->called('site.delete')[0]['remove_user'] ?? false);
    }

    // --- Processes ------------------------------------------------------

    public function test_octane_and_reverb_reach_nginx_and_horizon_replaces_the_workers(): void
    {
        $app = $this->laravelApp();
        $app->workers()->create(['name' => 'default', 'queue' => 'default', 'processes' => 2]);
        $app->processes()->create(['name' => 'octane', 'kind' => 'octane', 'server' => 'frankenphp', 'port' => 20000]);
        $app->processes()->create(['name' => 'reverb', 'kind' => 'reverb', 'port' => 20001]);
        $app->processes()->create(['name' => 'horizon', 'kind' => 'horizon']);
        $app->processes()->create(['name' => 'pdf-worker', 'kind' => 'custom', 'command' => 'php artisan pdf:listen --sleep="3"']);

        $processes = new Processes($this->agent);
        $processes->apply($app->fresh());
        $processes->applyWeb($app->fresh());

        $this->assertSame(['laravel.queue.apply', 'laravel.schedule.apply', 'laravel.process.apply', 'site.apply'], $this->agent->methods());
        $this->assertSame([], $this->agent->called('laravel.queue.apply')[0]['workers']);
        $sent = collect($this->agent->called('laravel.process.apply')[0]['processes'])->keyBy('name');
        $this->assertSame(['php', 'artisan', 'pdf:listen', '--sleep=3'], $sent['pdf-worker']['command']);
        $this->assertSame(500, $sent['octane']['max_requests']);
        $spec = $this->agent->called('site.apply')[0];
        $this->assertSame(['port' => 20000], $spec['proxy']);
        $this->assertSame([['path' => '/app/', 'port' => 20001], ['path' => '/apps/', 'port' => 20001]], $spec['websockets']);

        $this->assertSame(20002, LaravelProcess::freePort());
    }

    // --- Backups --------------------------------------------------------

    public function test_a_backup_records_its_parts_and_prunes_old_scheduled_ones(): void
    {
        $app = $this->laravelApp(['backup_keep' => 2]);
        foreach ([3, 2, 1] as $days) {
            $this->backup($app, 'scheduled', at: now()->subDays($days));
        }
        $this->agent->answers['laravel.backup.create'] = ['parts' => ['code' => 10, 'env' => 1, 'database' => 20], 'skipped' => ['storage' => 'boş'], 'size' => 31, 'release' => '7', 'database' => 'pgsql'];

        $backup = app(Backups::class)->createNow($app, ['code', 'env', 'storage', 'database'], 'scheduled');

        $this->assertSame('succeeded', $backup->status);
        $this->assertSame(['code', 'env', 'database'], $backup->parts);
        $this->assertSame('storage: boş', $backup->error);
        $this->assertSame(['7', 'pgsql', 31], [$backup->release, $backup->database_driver, $backup->size]);
        $this->assertSame(2, $app->backups()->where('kind', 'scheduled')->count());
        $this->assertCount(2, $this->agent->called('laravel.backup.delete'));
    }

    public function test_a_failed_backup_says_why(): void
    {
        $app = $this->laravelApp();
        $this->agent->answers['laravel.backup.create'] = AgentException::rpcError('laravel.backup.create', -32000, 'disk dolu');

        $backup = app(Backups::class)->createNow($app, ['database'], 'manual');

        $this->assertSame(['failed', 'disk dolu'], [$backup->status, $backup->error]);
    }

    public function test_deleting_a_backup_can_be_undone_until_it_is_purged(): void
    {
        $app = $this->laravelApp();
        $backup = $this->backup($app);
        $backups = app(Backups::class);

        $backups->delete($backup);
        Queue::assertPushed(PurgeLaravelBackup::class);
        $this->assertTrue($backups->undoDelete($backup->fresh()));
        $this->assertSame('succeeded', $backup->fresh()->status);

        $backups->purge($backup);
        $this->assertNull(LaravelBackup::find($backup->id));
    }

    public function test_scheduled_backups_run_once_a_day_after_their_time(): void
    {
        $app = $this->laravelApp(['backup_schedule' => 'daily', 'backup_time' => '03:00']);
        $backups = app(Backups::class);

        $this->assertTrue($backups->due($app, Carbon::parse('2026-10-19 02:00')));
        $this->backup($app, 'scheduled', at: Carbon::parse('2026-10-18 03:05'));
        $this->assertFalse($backups->due($app, Carbon::parse('2026-10-19 02:59')));
        $this->assertTrue($backups->due($app, Carbon::parse('2026-10-19 03:00')));

        Carbon::setTestNow('2026-10-19 03:02');
        $this->artisan('servik:laravel-backups')->assertSuccessful();
        Queue::assertPushed(BackupLaravelApp::class, fn ($job) => $job->backup->kind === 'scheduled');
        Carbon::setTestNow();

        $app->update(['backup_schedule' => 'off']);
        $this->assertFalse($backups->due($app->fresh(), Carbon::parse('2026-10-20 04:00')));
    }

    public function test_restore_backs_up_the_current_state_first(): void
    {
        $app = $this->laravelApp();
        $backup = $this->backup($app);
        $this->agent->answers['laravel.backup.create'] = ['parts' => ['database' => 5], 'size' => 5];
        $this->agent->answers['laravel.backup.restore'] = ['current' => '4'];
        app(Backups::class)->restore($backup, ['code', 'database'], true, User::factory()->create(['name' => 'Sercan']));
        Queue::assertPushed(RestoreLaravelApp::class);

        (new RestoreLaravelApp($backup->fresh(), ['code', 'database'], true, 'Sercan'))->handle($this->agent, app(Backups::class));

        $this->assertSame(['laravel.backup.create', 'laravel.backup.restore'], array_values(array_filter($this->agent->methods(), fn ($m) => str_starts_with($m, 'laravel.backup'))));
        $this->assertSame('succeeded', $backup->fresh()->restore_status);
        $this->assertSame('4', $app->fresh()->current_release);
        $safety = $app->backups()->where('kind', 'restore')->sole();
        $this->assertSame('Sercan', $safety->user_name);
    }

    public function test_restore_stops_when_the_safety_backup_fails(): void
    {
        $app = $this->laravelApp();
        $backup = $this->backup($app);
        $this->agent->answers['laravel.backup.create'] = AgentException::rpcError('laravel.backup.create', -32000, 'disk dolu');
        app(Backups::class)->restore($backup, ['database'], true);

        (new RestoreLaravelApp($backup->fresh(), ['database'], true))->handle($this->agent, app(Backups::class));

        $this->assertSame('failed', $backup->fresh()->restore_status);
        $this->assertStringContainsString('disk dolu', $backup->fresh()->restore_error);
        $this->assertSame([], $this->agent->called('laravel.backup.restore'));
    }

    public function test_a_deploy_stops_when_its_database_backup_fails(): void
    {
        $app = $this->laravelApp(['backup_before_deploy' => true]);
        $this->agent->answers['laravel.backup.create'] = AgentException::rpcError('laravel.backup.create', -32000, 'pg_dump hata verdi');
        $deployment = LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 2, 'kind' => 'deploy', 'status' => DeploymentStatus::Queued, 'run' => 'deploy-2']);

        (new DeployLaravelApp($deployment))->handle($this->agent, new Processes($this->agent), new Backups($this->agent));

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString('pg_dump hata verdi', $deployment->fresh()->error);
        $this->assertSame([], $this->agent->called('laravel.deploy'));
    }

    public function test_a_backup_downloads_in_chunks(): void
    {
        $app = $this->laravelApp();
        $backup = $this->backup($app);
        $this->agent->answers['laravel.backup.read'] = fn ($p) => $p['offset'] === 0
            ? ['data' => base64_encode('ilk-'), 'offset' => 4, 'size' => 8]
            : ['data' => base64_encode('son!'), 'offset' => 8, 'size' => 8];
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('laravel.backup.download', [$app, $backup]));

        $response->assertOk();
        $this->assertSame('ilk-son!', $response->streamedContent());
        $this->assertStringContainsString('.tar', (string) $response->headers->get('Content-Disposition'));
    }

    // --- Maintenance ----------------------------------------------------

    public function test_maintenance_through_nginx_sends_the_allow_list_and_page(): void
    {
        $app = $this->laravelApp();
        $this->actingAs(User::factory()->create());

        Livewire::withoutLazyLoading()->test(Maintenance::class, ['appId' => $app->id])
            ->set('mode', 'custom')->set('html', '<h1>Birazdan döneriz</h1>')->set('allow', "203.0.113.7, 10.0.0.0/24\n203.0.113.7")
            ->call('down')->assertHasNoErrors();

        $app->refresh();
        $this->assertTrue($app->maintenance_on);
        $this->assertSame(['203.0.113.7', '10.0.0.0/24'], $app->maintenanceAllowList());
        $spec = $this->agent->called('site.apply')[0]['maintenance'];
        $this->assertSame(['203.0.113.7', '10.0.0.0/24'], $spec['allow']);
        $this->assertSame('<h1>Birazdan döneriz</h1>', $spec['html']);

        Livewire::withoutLazyLoading()->test(Maintenance::class, ['appId' => $app->id])->call('up');
        $this->assertFalse($app->fresh()->maintenance_on);
        $this->assertArrayNotHasKey('maintenance', $this->agent->called('site.apply')[1]);
    }

    public function test_maintenance_rejects_bad_addresses_and_reverts_when_nginx_refuses(): void
    {
        $app = $this->laravelApp();
        $this->actingAs(User::factory()->create());
        $this->assertTrue(Maintenance::validAddress('2001:db8::/48'));
        $this->assertFalse(Maintenance::validAddress('10.0.0.0/33'));

        Livewire::withoutLazyLoading()->test(Maintenance::class, ['appId' => $app->id])->set('allow', 'ofis')->call('down')->assertHasErrors('allow');
        $this->assertFalse($app->fresh()->maintenance_on);

        $this->agent->answers['site.apply'] = AgentException::rpcError('site.apply', -32000, 'nginx -t başarısız');
        Livewire::withoutLazyLoading()->test(Maintenance::class, ['appId' => $app->id])->call('down')->assertSet('agentError', fn ($e) => str_contains((string) $e, 'nginx -t'));
        $this->assertFalse($app->fresh()->maintenance_on);
    }

    // --- Tinker ---------------------------------------------------------

    public function test_a_tinker_session_starts_takes_code_and_stops(): void
    {
        $app = $this->laravelApp();
        $this->agent->answers['laravel.tinker.send'] = ['running' => true];
        $this->actingAs(User::factory()->create());

        $page = Livewire::withoutLazyLoading()->test(Commands::class, ['appId' => $app->id])->set('tool', 'tinker')->call('startTinker');
        $session = LaravelCommand::where('tool', 'tinker')->sole();
        $this->assertSame('running', $session->status);
        $this->assertSame($session->run, $this->agent->called('laravel.tinker.start')[0]['session']);

        $page->call('startTinker');
        $this->assertSame(1, LaravelCommand::where('tool', 'tinker')->count());

        $page->set('code', 'User::count()')->call('send')->assertHasNoErrors()->assertSet('code', '');
        $this->assertSame('User::count()', $this->agent->called('laravel.tinker.send')[0]['code']);

        $page->call('stopTinker');
        $this->assertSame('finished', $session->fresh()->status);
        $this->assertCount(1, $this->agent->called('laravel.tinker.stop'));
    }
}
