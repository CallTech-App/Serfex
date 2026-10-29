<?php

namespace Tests\Feature;

use App\Jobs\RunPanelUpdate;
use App\Livewire\Settings\UpdatesPage;
use App\Models\Backup;
use App\Models\ComponentOperation;
use App\Models\Customer;
use App\Models\PanelUpdate;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Backups\Backups;
use App\Services\Components\Components;
use App\Services\Updates\Updates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güncellemeler: finding newer releases, updating with a panel backup
 * first, following the run through the restart, returning to the
 * previous release, the notices in the menu and on the dashboard.
 */
class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, mixed> update.status */
    private array $status;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Process::fake(['*pg_dump*' => Process::result('-- dump')]);
        $this->status = [
            'installed' => true,
            'version' => '0.1.0',
            'agent' => '0.1.0',
            'arch' => 'amd64',
            'previous' => '',
            'channel' => 'stable',
            'checked_at' => now()->subHour()->toIso8601String(),
            'releases' => [[
                'version' => '0.2.0',
                'tag' => 'v0.2.0',
                'name' => 'Serfex 0.2.0',
                'notes' => "## Yenilikler\n\n- Güncellemeler sayfası",
                'published_at' => now()->subDay()->toIso8601String(),
                'prerelease' => false,
                'url' => 'https://github.com/CallTech-App/Serfex/releases/tag/v0.2.0',
                'size' => 31457280,
            ]],
        ];
        $this->agent = new FakeAgent([
            'update.status' => fn () => $this->status,
            'update.check' => fn ($p) => ['channel' => $p['channel']] + $this->status,
            'update.start' => fn ($p) => ['run' => $p['run']],
            'update.rollback' => fn ($p) => ['run' => $p['run']],
            'update.run.read' => ['data' => "▸ Serfex 0.2.0 indiriliyor\n", 'offset' => 31, 'size' => 31, 'done' => false],
            'backup.create' => fn ($p) => [
                'key' => $p['key'],
                'items' => array_map(fn ($i) => ['name' => $i['name'], 'size' => 1000, 'parts' => ['database' => 10, 'settings' => 5], 'warnings' => []], $p['items']),
                'size' => 1000,
                'local' => true,
                'remote' => false,
                'seconds' => 3,
            ],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_page_shows_the_installed_and_the_newer_release(): void
    {
        $this->get(route('settings.updates'))
            ->assertOk()
            ->assertSee('Güncellemeler')
            ->assertSee('0.1.0')
            ->assertSee('0.2.0')
            ->assertSee('Güncellemeler sayfası')
            ->assertSee('Kararlı');
    }

    public function test_checking_now_asks_the_agent_for_the_chosen_channel(): void
    {
        Livewire::test(UpdatesPage::class)
            ->call('check')
            ->assertDispatched('toast', message: 'Serfex 0.2.0 hazır.');
        $this->assertSame([['channel' => 'stable']], $this->agent->called('update.check'));
    }

    public function test_the_channel_can_be_changed_and_undone(): void
    {
        Livewire::test(UpdatesPage::class)
            ->set('channel', 'beta')
            ->call('saveChannel')
            ->assertDispatched('toast');
        $this->assertSame('beta', app(Updates::class)->channel());
        $this->assertSame('beta', $this->agent->called('update.check')[0]['channel']);

        Livewire::test(UpdatesPage::class)->call('undoChannel', 'stable');
        $this->assertSame('stable', app(Updates::class)->channel());
    }

    public function test_updating_takes_a_panel_backup_then_starts_the_agents_run(): void
    {
        Livewire::test(UpdatesPage::class)
            ->call('ask', 'upgrade')
            ->assertSet('confirming', 'upgrade')
            ->call('confirm')
            ->assertSet('refused', null);

        $update = PanelUpdate::sole();
        $this->assertSame(['upgrade', '0.1.0', '0.2.0', 'queued'], [$update->kind, $update->from_version, $update->to_version, $update->status]);
        $this->assertMatchesRegularExpression('/^upd-[a-z0-9]{20}$/', $update->run);
        Queue::assertPushed(RunPanelUpdate::class);

        (new RunPanelUpdate($update))->handle(app(Updates::class));
        $update->refresh();
        $this->assertSame('running', $update->status);
        $backup = Backup::findOrFail($update->backup_id);
        $this->assertSame(['panel', 'update', 'succeeded'], [$backup->scope, $backup->trigger, $backup->status]);
        $this->assertSame('server', $this->agent->called('backup.create')[0]['scope']);
        $this->assertSame([['version' => '0.2.0', 'run' => $update->run]], $this->agent->called('update.start'));
        // The agent's run comes after the backup.
        $methods = $this->agent->methods();
        $this->assertLessThan(array_search('update.start', $methods, true), array_search('backup.create', $methods, true));
    }

    public function test_a_failed_panel_backup_stops_the_update(): void
    {
        $this->agent->answers['backup.create'] = AgentException::rpcError('backup.create', AgentException::OPERATION_FAILED, 'disk full');
        $update = app(Updates::class)->start(auth()->user());
        (new RunPanelUpdate($update))->handle(app(Updates::class));

        $update->refresh();
        $this->assertSame('failed', $update->status);
        $this->assertStringContainsString('Panel yedeği alınamadı', $update->error);
        $this->assertSame([], $this->agent->called('update.start'));
    }

    public function test_it_refuses_while_other_work_runs_or_on_a_development_install(): void
    {
        $updates = app(Updates::class);

        ComponentOperation::create(['component' => 'redis', 'action' => 'install', 'run' => 'install-redis-1', 'status' => 'running']);
        $this->assertStringContainsString('Yazılım Yönetimi', $updates->start(null));
        ComponentOperation::query()->delete();

        Backup::create(['key' => 'b1', 'scope' => 'server', 'type' => 'full', 'trigger' => 'manual', 'status' => 'running', 'run' => 'bk-1']);
        $this->assertStringContainsString('yedekleme', $updates->start(null));
        Backup::query()->delete();

        $this->status['installed'] = false;
        $this->assertStringContainsString('install.sh', $updates->start(null));
        $this->status['installed'] = true;

        $this->status['releases'] = [];
        $this->assertStringContainsString('yeni bir sürüm yok', $updates->start(null));

        $this->status['previous'] = '';
        $this->assertStringContainsString('önceki sürüm', $updates->rollback(null));

        $this->agent->answers['update.status'] = new AgentUnavailableException('no socket');
        $this->assertStringContainsString('ulaşılamıyor', $updates->start(null));
        $this->assertSame(0, PanelUpdate::count());
    }

    public function test_while_an_update_runs_backups_and_software_changes_wait(): void
    {
        PanelUpdate::create(['kind' => 'upgrade', 'from_version' => '0.1.0', 'to_version' => '0.2.0', 'run' => 'upd-aaaaaaaaaaaaaaaaaaaa', 'status' => 'running', 'started_at' => now()]);
        $this->assertSame(Backups::UPDATING, app(Backups::class)->startServer(null));
        $this->assertSame(Backups::UPDATING, app(Backups::class)->startSite(Site::factory()->create(), null));
        $this->assertStringContainsString('Serfex güncelleniyor', (string) app(Components::class)->start('redis', 'install', null));
        $this->assertStringContainsString('sürüyor', app(Updates::class)->start(null));
    }

    public function test_reconcile_records_how_the_agents_run_ended(): void
    {
        $update = PanelUpdate::create(['kind' => 'upgrade', 'from_version' => '0.1.0', 'to_version' => '0.2.0', 'run' => 'upd-bbbbbbbbbbbbbbbbbbbb', 'status' => 'running', 'started_at' => now()]);
        $this->status['running'] = $update->run;
        app(Updates::class)->reconcile();
        $this->assertSame('running', $update->refresh()->status);

        unset($this->status['running']);
        $this->status['last'] = ['run' => $update->run, 'kind' => 'upgrade', 'from' => '0.1.0', 'to' => '0.2.0', 'status' => 'rolled_back', 'error' => 'health check failed: /up answered 500'];
        app(Updates::class)->reconcile();
        $update->refresh();
        $this->assertSame('rolled_back', $update->status);
        $this->assertSame('health check failed: /up answered 500', $update->error);
        $this->assertNotNull($update->finished_at);

        $this->get(route('settings.updates'))->assertSee('0.1.0 sürümüne otomatik dönüldü');
    }

    public function test_the_progress_route_follows_the_run_and_survives_the_agent_restarting(): void
    {
        $update = PanelUpdate::create(['kind' => 'upgrade', 'from_version' => '0.1.0', 'to_version' => '0.2.0', 'run' => 'upd-cccccccccccccccccccc', 'status' => 'backing_up']);
        $url = route('settings.updates.progress', $update->run);
        $this->getJson($url)->assertOk()->assertJson(['done' => false, 'status' => 'backing_up', 'data' => '']);

        $update->update(['status' => 'running', 'started_at' => now()]);
        $this->status['running'] = $update->run;
        $this->getJson($url)->assertOk()->assertJson(['data' => "▸ Serfex 0.2.0 indiriliyor\n", 'offset' => 31, 'done' => false, 'agent' => true]);

        $this->agent->answers['update.run.read'] = new AgentUnavailableException('restarting');
        $this->getJson($url.'?offset=31')->assertOk()->assertJson(['agent' => false, 'done' => false, 'offset' => 31]);

        unset($this->status['running']);
        $this->status['last'] = ['run' => $update->run, 'status' => 'succeeded'];
        $this->agent->answers['update.run.read'] = ['data' => "✓ Serfex 0.2.0 çalışıyor.\n", 'offset' => 60, 'size' => 60, 'done' => true];
        $this->getJson($url.'?offset=31')->assertOk()->assertJson(['done' => true, 'status' => 'succeeded']);
        $this->assertSame('succeeded', $update->refresh()->status);
    }

    public function test_the_progress_route_answers_during_maintenance(): void
    {
        $update = PanelUpdate::create(['kind' => 'upgrade', 'from_version' => '0.1.0', 'to_version' => '0.2.0', 'run' => 'upd-dddddddddddddddddddd', 'status' => 'backing_up']);
        $this->app->maintenanceMode()->activate([]);
        try {
            $this->get(route('settings.updates'))->assertStatus(503);
            $this->getJson(route('settings.updates.progress', $update->run))->assertOk();
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_rolling_back_returns_to_the_previous_release(): void
    {
        $this->status['previous'] = '0.0.9';
        $this->status['releases'] = [];
        Livewire::test(UpdatesPage::class)->call('ask', 'rollback')->call('confirm')->assertSet('refused', null);

        $update = PanelUpdate::sole();
        $this->assertSame(['rollback', '0.1.0', '0.0.9'], [$update->kind, $update->from_version, $update->to_version]);
        (new RunPanelUpdate($update))->handle(app(Updates::class));
        $this->assertSame([['run' => $update->run]], $this->agent->called('update.rollback'));
    }

    public function test_the_menu_and_dashboard_point_at_a_ready_or_failed_update_without_asking_the_agent(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSee('Güncellemeler')->assertDontSee('0.2.0 hazır');

        app(Updates::class)->check();
        $this->agent->calls = [];
        $this->get(route('dashboard'))->assertOk()->assertSee('Serfex 0.2.0 hazır')->assertSee('güncelleme bildirimi');
        // Genel Bakış reads the live numbers; the update notice itself asks the agent nothing.
        $this->assertSame([], array_values(array_diff($this->agent->methods(), ['monitor.live'])));
        $this->agent->calls = [];

        PanelUpdate::create(['kind' => 'upgrade', 'from_version' => '0.1.0', 'to_version' => '0.2.0', 'run' => 'upd-eeeeeeeeeeeeeeeeeeee', 'status' => 'failed', 'error' => 'x', 'finished_at' => now()]);
        $notices = app(Updates::class)->notices();
        $this->assertSame([['level' => 'warn', 'text' => 'Serfex 0.2.0 güncellemesi başarısız oldu']], $notices);
        $this->assertSame([], $this->agent->methods());
    }

    public function test_customers_and_application_members_cannot_open_it(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $this->actingAs($customer->user)->get(route('settings.updates'))->assertForbidden();
        $this->actingAs(User::factory()->member()->create())->get(route('settings.updates'))->assertForbidden();
        $this->get(route('settings.updates.progress', 'upd-ffffffffffffffffffff'))->assertForbidden();
    }

    public function test_the_daily_check_and_the_command_line(): void
    {
        $this->artisan('serfex:update-check')->expectsOutput('Serfex 0.2.0 hazır.')->assertSuccessful();
        $this->assertNotNull(Cache::get(Updates::KNOWN_KEY));

        $this->artisan('serfex:update --check')->expectsOutput('Serfex 0.2.0 hazır.')->assertSuccessful();
        $this->assertSame(0, PanelUpdate::count());
    }
}
