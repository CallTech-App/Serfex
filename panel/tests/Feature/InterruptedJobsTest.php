<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Exceptions\JobInterrupted;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Sites\SiteShow;
use App\Models\Backup;
use App\Models\PanelUpdate;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Queue\RunningJobs;
use App\Services\Updates\Updates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Jobs whose queue worker was stopped half-way (a panel update restarts
 * the workers): they are failed at once, so a site no longer stays on
 * Siliniyor, and an update waits for a long job instead of cutting it off.
 */
class InterruptedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DB::connection('worker')->select('select pg_advisory_unlock_all()');
        DB::purge('worker');
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Another session, like a running worker's.
        config(['database.connections.worker' => config('database.connections.pgsql')]);
    }

    public function test_a_deletion_cut_off_by_a_worker_restart_shows_on_the_site(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $id = $this->queued(new DeleteSite($site), takenSecondsAgo: 600);

        $this->artisan('serfex:jobs-recover')->assertSuccessful();

        $site->refresh();
        $this->assertSame(SiteStatus::Failed, $site->status);
        $this->assertSame(Site::FAILED_DELETE, $site->failed_action);
        $this->assertStringContainsString('Silme yarıda kaldı', $site->last_error);
        $this->assertStringContainsString('durduruldu', $site->last_error);
        $this->assertFalse(DB::table('jobs')->where('id', $id)->exists());
        // Listed with php artisan queue:failed.
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_job_a_worker_is_running_is_left_alone(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $id = $this->queued(new DeleteSite($site), takenSecondsAgo: 600);
        DB::connection('worker')->select('select pg_advisory_lock(?)', [RunningJobs::key($id)]);

        $this->artisan('serfex:jobs-recover')->assertSuccessful();

        $this->assertSame(SiteStatus::Deleting, $site->fresh()->status);
        $this->assertTrue(DB::table('jobs')->where('id', $id)->exists());
        $this->assertTrue(app(RunningJobs::class)->busy('default'));
    }

    public function test_waiting_and_just_taken_jobs_are_left_alone(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $waiting = $this->queued(new DeleteSite($site));
        $justTaken = $this->queued(new ProvisionSite(Site::factory()->provisioning()->create()), takenSecondsAgo: 5);

        $this->artisan('serfex:jobs-recover')->assertSuccessful();

        $this->assertSame(2, DB::table('jobs')->whereIn('id', [$waiting, $justTaken])->count());
        $this->assertSame(SiteStatus::Deleting, $site->fresh()->status);
        // A job just taken counts as running until its lock shows.
        $this->assertTrue(app(RunningJobs::class)->busy('default'));
        $this->assertFalse(app(RunningJobs::class)->busy('backups'));
    }

    public function test_a_worker_holds_the_jobs_lock_while_it_runs_it(): void
    {
        $id = $this->queued(new ProvisionSite(Site::factory()->provisioning()->create()), takenSecondsAgo: 600);
        $row = DB::table('jobs')->find($id);
        $job = new DatabaseJob(app(), app(DatabaseQueue::class, ['database' => DB::connection(), 'table' => 'jobs', 'default' => 'default']), new DatabaseJobRecord($row), 'database', 'default');
        $free = fn () => DB::connection('worker')->selectOne('select pg_try_advisory_lock(?) as ok', [RunningJobs::key($id)])->ok
            && DB::connection('worker')->selectOne('select pg_advisory_unlock(?) as ok', [RunningJobs::key($id)])->ok;

        app(RunningJobs::class)->started($job);
        $this->assertFalse($free());
        $this->assertTrue(app(RunningJobs::class)->busy('default'));
        app(RunningJobs::class)->finished($job);
        $this->assertTrue($free());
        $this->assertFalse(app(RunningJobs::class)->busy('default'));
    }

    public function test_a_provisioning_cut_off_shows_tekrar_dene(): void
    {
        $site = Site::factory()->provisioning()->create();

        (new ProvisionSite($site))->failed(new JobInterrupted);

        $site->refresh();
        $this->assertSame(SiteStatus::Failed, $site->status);
        $this->assertNull($site->failed_action);
        $this->assertStringContainsString('durduruldu', $site->last_error);
    }

    public function test_the_backup_a_cut_off_deletion_was_taking_is_marked_failed(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $backup = Backup::create(['key' => 'd1', 'scope' => 'site', 'type' => 'full', 'trigger' => 'delete', 'status' => 'running', 'run' => 'bk-d1']);
        $backup->items()->create(['name' => $site->domain, 'site_id' => $site->id, 'status' => 'pending']);
        $other = Backup::create(['key' => 'm1', 'scope' => 'site', 'type' => 'full', 'trigger' => 'manual', 'status' => 'running', 'run' => 'bk-m1']);

        (new DeleteSite($site, backup: true))->failed(new JobInterrupted);

        $this->assertSame('failed', $backup->fresh()->status);
        $this->assertSame('failed', $backup->items()->sole()->status);
        $this->assertSame('running', $other->fresh()->status);
    }

    public function test_the_site_page_offers_to_delete_again(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::factory()->create(['status' => SiteStatus::Failed, 'failed_action' => Site::FAILED_DELETE, 'last_error' => 'Silme yarıda kaldı: kesildi']);

        Livewire::test(SiteShow::class, ['site' => $site->id])
            ->assertSee('Site silinemedi')
            ->assertSee('Silmeyi yeniden dene')
            ->assertDontSee('Önceki ayarlar geri yüklendi')
            // Tekrar dene would build the site again rather than delete it.
            ->call('retry')
            ->call('openDelete')
            ->assertSet('deleting', true);
        $this->assertSame(SiteStatus::Failed, $site->fresh()->status);

        Queue::fake();
        Livewire::test(SiteShow::class, ['site' => $site->id])
            ->call('openDelete')
            ->set('confirmDomain', $site->domain)
            ->call('delete');
        $site->refresh();
        $this->assertSame(SiteStatus::Deleting, $site->status);
        $this->assertNull($site->failed_action);
        Queue::assertPushed(DeleteSite::class);
    }

    public function test_an_update_refuses_and_waits_while_a_long_job_runs(): void
    {
        $agent = new FakeAgent([
            'update.status' => ['installed' => true, 'version' => '0.1.0', 'releases' => [['version' => '0.2.0']]],
            'update.start' => fn ($p) => ['run' => $p['run']],
        ]);
        $this->app->instance(AgentClient::class, $agent);
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        $id = $this->queued((new DeleteSite($site, backup: true)), takenSecondsAgo: 600, queue: 'backups');
        DB::connection('worker')->select('select pg_advisory_lock(?)', [RunningJobs::key($id)]);

        $this->assertStringContainsString('Arka planda uzun bir iş sürüyor', app(Updates::class)->start(null));

        // Asked before the job started: the update waits for it, then gives up.
        Sleep::fake();
        $update = PanelUpdate::create(['kind' => 'upgrade', 'from_version' => '0.1.0', 'to_version' => '0.2.0', 'run' => 'upd-aaaaaaaaaaaaaaaaaaaa', 'status' => 'queued']);
        app(Updates::class)->begin($update);

        $update->refresh();
        $this->assertSame('failed', $update->status);
        $this->assertStringContainsString('15 dakikada bitmedi', $update->error);
        $this->assertSame([], $agent->called('update.start'));
        $this->assertSame(0, Backup::count());
        Sleep::assertSleptTimes(Updates::DRAIN_SECONDS / 5);
    }

    /**
     * Puts $job on the database queue as a worker would have; taken that
     * many seconds ago, or still waiting. Returns its id.
     */
    private function queued(object $job, ?int $takenSecondsAgo = null, string $queue = 'default'): int
    {
        $id = Queue::connection('database')->pushOn($queue, $job);
        if ($takenSecondsAgo !== null) {
            DB::table('jobs')->where('id', $id)->update(['reserved_at' => now()->getTimestamp() - $takenSecondsAgo, 'attempts' => 1]);
        }

        return (int) $id;
    }
}
