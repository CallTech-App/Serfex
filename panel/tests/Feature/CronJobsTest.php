<?php

namespace Tests\Feature;

use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Jobs\SyncCronJobs;
use App\Livewire\Sites\SiteCron;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Sites\CronJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Zamanlanmış görevler: a site's cron tasks are kept by the panel and
 * written by the agent as the site's user; runs leave their output, and
 * customers stay within their package.
 */
class CronJobsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'cron.apply' => fn ($p) => ['tasks' => count($p['tasks']), 'enabled' => count(array_filter($p['tasks'], fn ($t) => $t['enabled']))],
            'cron.remove' => ['removed' => true],
            'cron.run' => ['started' => true],
            'cron.runs' => ['runs' => [
                ['id' => '1760900000000000000', 'started_at' => '2026-10-19T14:00:00Z', 'finished_at' => '2026-10-19T14:00:02Z', 'duration_ms' => 2100, 'exit_code' => 1,
                    'output' => "PHP Fatal error: Uncaught Error: Class \"Rapor\" not found\n", 'truncated' => false, 'timed_out' => false, 'skipped' => false, 'trigger' => 'schedule'],
                ['id' => '1760896400000000000', 'started_at' => '2026-10-19T13:00:00Z', 'finished_at' => '2026-10-19T13:00:01Z', 'duration_ms' => 900, 'exit_code' => 0,
                    'output' => "12 rapor gönderildi\n", 'truncated' => false, 'timed_out' => false, 'skipped' => false, 'trigger' => 'manual'],
            ], 'running' => false],
            'site.delete' => ['removed_user' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahve.com.tr', 'php_version' => '8.4']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create());
    }

    private function task(array $values = []): CronJob
    {
        return CronJob::create(['site_id' => $this->site->id, 'schedule' => '0 3 * * *', 'command' => 'php yedek.php', ...$values]);
    }

    public function test_the_site_page_links_scheduled_tasks(): void
    {
        $this->task();
        $this->get(route('sites.show', $this->site))->assertOk()->assertSee(route('sites.cron', $this->site))->assertSee('1 görev');
        $this->get(route('sites.cron', $this->site))->assertOk()
            ->assertSee('Zamanlanmış görevler')->assertSee('php yedek.php')->assertSee('Her gün 03:00')->assertSee('Henüz çalışmadı');
    }

    public function test_adding_a_task_writes_it_as_the_sites_user_with_its_php(): void
    {
        $page = Livewire::test(SiteCron::class, ['site' => $this->site->id])
            ->call('openCreate')
            ->set('preset', 'custom')
            ->set('schedule', '61 * * * *')
            ->set('command', "php a.php\nrm -rf /")
            ->call('save')
            ->assertHasErrors(['schedule', 'command'])
            ->set('schedule', '30  2 * * MON-FRI')
            ->set('command', 'php artisan rapor:gonder')
            ->set('timeout', 900)
            ->call('save')
            ->assertHasNoErrors();

        $task = CronJob::sole();
        $this->assertSame('30 2 * * mon-fri', $task->schedule);
        $this->assertSame([[
            'domain' => 'kahve.com.tr', 'user' => 'kahve', 'php' => '8.4',
            'tasks' => [['id' => $task->id, 'schedule' => '30 2 * * mon-fri', 'command' => 'php artisan rapor:gonder', 'enabled' => true, 'timeout' => 900]],
        ]], $this->agent->called('cron.apply'));
        $page->assertDispatched('toast', message: 'Zamanlanmış görev eklendi: 30 2 * * mon-fri.', undo: ['method' => 'undoCreate', 'args' => [$task->id]]);

        // Undo removes it, and with no tasks left the crontab goes.
        $page->call('undoCreate', $task->id);
        $this->assertSame(0, CronJob::withTrashed()->count());
        $this->assertSame([['domain' => 'kahve.com.tr']], $this->agent->called('cron.remove'));
    }

    public function test_a_refused_apply_keeps_the_panel_as_it_was(): void
    {
        $this->agent->answers['cron.apply'] = new AgentException('the site folder is missing', AgentException::INVALID_PARAMS);

        Livewire::test(SiteCron::class, ['site' => $this->site->id])
            ->call('openCreate')
            ->set('command', 'php a.php')
            ->call('save')
            ->assertHasErrors('command')
            ->assertSet('editing', true);
        $this->assertSame(0, CronJob::count());
    }

    public function test_edit_pause_and_delete_have_undo(): void
    {
        $task = $this->task();
        $page = Livewire::test(SiteCron::class, ['site' => $this->site->id])
            ->call('openEdit', $task->id)
            ->assertSet('preset', '0 3 * * *')
            ->set('preset', '*/15 * * * *')
            ->call('save')
            ->assertDispatched('toast', message: 'Zamanlanmış görev kaydedildi.');
        $this->assertSame('*/15 * * * *', $task->fresh()->schedule);
        $page->call('undoEdit', $task->id, ['schedule' => '0 3 * * *', 'command' => 'php yedek.php', 'note' => null, 'timeout' => 3600]);
        $this->assertSame('0 3 * * *', $task->fresh()->schedule);

        $page->call('pause', $task->id)->assertDispatched('toast', undo: ['method' => 'resume', 'args' => [$task->id]]);
        $this->assertFalse($task->fresh()->enabled);
        $this->assertFalse(last($this->agent->called('cron.apply'))['tasks'][0]['enabled']);
        $page->call('resume', $task->id);
        $this->assertTrue($task->fresh()->enabled);

        $page->call('confirmDelete', $task->id)->call('delete')
            ->assertDispatched('toast', message: 'Zamanlanmış görev silindi.', undo: ['method' => 'restore', 'args' => [$task->id]]);
        $this->assertTrue($task->fresh()->trashed());
        $this->assertSame([['domain' => 'kahve.com.tr']], $this->agent->called('cron.remove'));
        $page->call('restore', $task->id);
        $this->assertFalse($task->fresh()->trashed());
        $this->assertCount(1, last($this->agent->called('cron.apply'))['tasks']);
    }

    public function test_run_now_and_the_history_with_output(): void
    {
        $task = $this->task();
        Livewire::test(SiteCron::class, ['site' => $this->site->id])
            ->call('runNow', $task->id)
            ->assertDispatched('toast', message: 'Görev başlatıldı; çıktısı geçmişte görünecek.')
            ->call('openHistory', $task->id)
            ->assertSee('Class "Rapor" not found')
            ->assertSee('Hata verdi')
            ->assertSee('Zamanında çalıştı')
            ->call('showRun', '1760896400000000000')
            ->assertSee('12 rapor gönderildi')
            ->assertSee('Elle başlatıldı');
        $this->assertSame([['domain' => 'kahve.com.tr', 'id' => $task->id]], $this->agent->called('cron.run'));
        $this->assertSame('running', $task->fresh()->last_status);

        $this->artisan('servik:cron-history')->assertSuccessful();
        $this->assertSame('failed', $task->fresh()->last_status);
        $this->assertSame('2026-10-19 14:00:00', $task->fresh()->last_run_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_customers_stay_within_their_package_and_suspension_pauses_tasks(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $customer->package->update(['max_cron_jobs' => 1]);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->actingAs($customer->user);

        $this->get(route('sites.cron', $this->site))->assertNotFound();
        $page = Livewire::test(SiteCron::class, ['site' => $site->id])
            ->call('openCreate')
            ->set('command', 'php cron.php')
            ->call('save')
            ->assertHasNoErrors();
        $page->call('openCreate')->assertDispatched('toast')->assertSet('editing', false);
        $this->get(route('sites.cron', $site))->assertOk()->assertSee('1 / 1 zamanlanmış görev');

        $this->actingAs(User::factory()->create());
        app(Customers::class)->suspend($customer);
        Queue::assertPushed(ProvisionSite::class);
        (new SyncCronJobs($site->id))->handle(app(CronJobs::class));
        $this->assertFalse(last($this->agent->called('cron.apply'))['tasks'][0]['enabled']);
    }

    public function test_deleting_the_site_removes_its_crontab(): void
    {
        $this->task();
        app()->call([new DeleteSite($this->site), 'handle']);
        $this->assertContains('cron.remove', $this->agent->methods());
        $this->assertLessThan(array_search('site.delete', $this->agent->methods()), array_search('cron.remove', $this->agent->methods()));
    }

    public function test_provisioning_writes_the_tasks_again(): void
    {
        $this->task();
        app()->call([new ProvisionSite($this->site), 'handle']);
        Queue::assertPushed(SyncCronJobs::class, fn ($job) => $job->siteId === $this->site->id);
    }
}
