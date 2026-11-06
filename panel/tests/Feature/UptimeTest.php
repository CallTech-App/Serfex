<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Livewire\Sites\SiteUptime;
use App\Mail\PanelMail;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\NotificationEvent;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteOutage;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Laravel\BackgroundWatch;
use App\Services\Settings\Notifications;
use App\Services\Sites\CronJobs;
use App\Services\Sites\SiteProblems;
use App\Services\Sites\Uptime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class UptimeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agent = new FakeAgent;
        $this->app->instance(AgentClient::class, $this->agent);
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.kahve.com.tr', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        app(Notifications::class)->save(['emails' => ['ops@kahve.com.tr']]);
        $this->site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        Http::fake(['*' => fn () => is_int($this->status) ? Http::response('', $this->status) : throw new ConnectionException($this->status)]);
    }

    /** What the sites answer: an HTTP status, or a connection error. */
    private int|string $status = 200;

    private function answer(int|string $status): void
    {
        $this->status = $status;
    }

    public function test_a_site_that_answers_is_up_and_its_check_is_counted(): void
    {
        $this->answer(200);

        $this->assertSame(['checked' => 1, 'down' => 0, 'up' => 0], app(Uptime::class)->check());

        $site = $this->site->fresh();
        $this->assertSame('up', $site->uptime_state);
        $this->assertSame(0, $site->uptime_failures);
        Http::assertSent(fn ($r) => $r->url() === 'http://kahve.com.tr/' && str_starts_with($r->header('User-Agent')[0], 'Serfex-Uptime/'));
        $bucket = DB::table('site_uptime')->sole();
        $this->assertSame([1, 0], [$bucket->checks, $bucket->failures]);
    }

    public function test_two_failed_checks_open_an_outage_and_tell_the_administrators_once(): void
    {
        $this->site->forceFill(['uptime_state' => 'up'])->save();
        $this->answer(502);

        $this->assertSame(0, app(Uptime::class)->check()['down'], 'one failure is not an outage');
        $this->assertSame(0, SiteOutage::count());
        $this->assertSame(1, app(Uptime::class)->check()['down']);

        $site = $this->site->fresh();
        $this->assertTrue($site->isDown());
        $outage = SiteOutage::sole();
        $this->assertSame('HTTP 502', $outage->error);
        $this->assertStringContainsString('Sunucudan denendiğinde de hata: HTTP 502.', $outage->cause);
        $this->assertStringContainsString('PHP-FPM', $outage->cause);
        $event = NotificationEvent::where('kind', 'site')->sole();
        $this->assertSame(['site:'.$site->id, 'open'], [$event->key, $event->state]);
        $this->assertSame('kahve.com.tr yanıt vermiyor', $event->title);
        Mail::assertSent(PanelMail::class, 1);

        app(Uptime::class)->check();
        Mail::assertSent(PanelMail::class, 1);
        $this->assertSame(1, SiteOutage::count(), 'still the same outage');

        $this->answer(200);
        $this->assertSame(1, app(Uptime::class)->check()['up']);
        $this->assertNotNull($outage->fresh()->ended_at);
        $this->assertSame('resolved', $event->fresh()->state);
        Mail::assertSent(PanelMail::class, 2);
    }

    public function test_answers_below_500_are_up_and_cloudflare_errors_are_named(): void
    {
        $this->site->forceFill(['uptime_state' => 'up'])->save();
        $this->answer(404);
        app(Uptime::class)->check();
        $this->assertSame('up', $this->site->fresh()->uptime_state);

        $this->answer(521);
        app(Uptime::class)->check();
        app(Uptime::class)->check();
        $site = $this->site->fresh();
        $this->assertTrue($site->isDown());
        $this->assertSame('Cloudflare 521: sunucuya bağlanamıyor', $site->uptime_error);
    }

    public function test_a_timeout_is_down_with_a_readable_reason(): void
    {
        $this->site->forceFill(['uptime_state' => 'up', 'uptime_failures' => 1, 'uptime_checked_at' => now()->subMinute()])->save();
        $this->answer('cURL error 28: Operation timed out after 10001 milliseconds');

        app(Uptime::class)->check();

        $site = $this->site->fresh();
        $this->assertTrue($site->isDown());
        $this->assertSame('Zaman aşımı (10 sn içinde yanıt yok)', $site->uptime_error);
        $this->assertTrue(SiteOutage::sole()->started_at->lt(now()->subSeconds(30)), 'the outage began at the first failed check');
    }

    public function test_a_site_that_never_answered_is_not_an_outage(): void
    {
        $this->answer('cURL error 6: Could not resolve host: kahve.com.tr');

        app(Uptime::class)->check();
        app(Uptime::class)->check();

        $site = $this->site->fresh();
        $this->assertNull($site->uptime_state);
        $this->assertSame(2, $site->uptime_failures);
        $this->assertSame('Alan adı çözümlenemedi (DNS)', $site->uptime_error);
        $this->assertSame(0, SiteOutage::count());
        $this->assertSame(0, NotificationEvent::where('kind', 'site')->count());
    }

    public function test_suspended_switched_off_and_maintenance_sites_are_not_checked(): void
    {
        $this->answer(500);
        $suspended = Site::factory()->create(['domain' => 'askida.com.tr']);
        $suspended->systemUser->update(['customer_id' => Customer::factory()->create(['suspended_at' => now()])->id]);
        Site::factory()->create(['domain' => 'kapali.com.tr', 'uptime_enabled' => false]);
        LaravelApp::factory()->create(['site_id' => Site::factory()->create(['domain' => 'bakim.com.tr'])->id, 'maintenance_on' => true]);

        $this->assertSame(1, app(Uptime::class)->check()['checked']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'kahve.com.tr'));
    }

    public function test_the_laravel_health_path_is_checked_unless_the_site_names_another(): void
    {
        $this->answer(200);
        LaravelApp::factory()->create(['site_id' => $this->site->id, 'health_path' => '/up']);
        $uptime = app(Uptime::class);

        $this->assertSame('http://kahve.com.tr/up', $uptime->url($this->site->fresh()));
        $this->site->update(['uptime_path' => '/saglik']);
        $this->assertSame('http://kahve.com.tr/saglik', $uptime->url($this->site->fresh()));
    }

    public function test_the_site_page_shows_the_card_and_saves_its_settings(): void
    {
        $this->actingAs(User::factory()->create());
        $this->site->forceFill(['uptime_state' => 'down', 'uptime_since' => now()->subMinutes(6), 'uptime_error' => 'HTTP 502', 'uptime_cause' => 'Sunucudan denendiğinde de hata: HTTP 502.'])->save();
        $this->site->outages()->create(['started_at' => now()->subMinutes(6), 'error' => 'HTTP 502']);
        DB::table('site_uptime')->insert(['site_id' => $this->site->id, 'bucket_at' => Uptime::bucketStart(now()), 'checks' => 10, 'failures' => 5, 'ms_total' => 900]);

        $this->get(route('sites.show', $this->site))->assertOk()->assertSee('Erişilebilirlik')->assertSee('Yanıt vermiyor · 6 dakikadır')->assertSee('Kesintiler');

        Livewire::test(SiteUptime::class, ['siteId' => $this->site->id])
            ->assertSee('%50,00')
            ->assertSee('180 ms')
            ->call('openSettings')
            ->set('path', 'saglik')
            ->call('save')
            ->assertHasErrors('path')
            ->set('path', '/saglik')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('http://kahve.com.tr/saglik');

        $site = $this->site->fresh();
        $this->assertSame('/saglik', $site->uptime_path);
        $this->assertNull($site->uptime_state, 'a new address starts over');
        $this->assertNotNull(SiteOutage::sole()->ended_at);
    }

    public function test_the_site_list_and_dashboard_show_a_site_that_does_not_answer(): void
    {
        $this->actingAs(User::factory()->create());
        $this->site->forceFill(['uptime_state' => 'down', 'uptime_since' => now()->subMinutes(3), 'uptime_error' => 'HTTP 503'])->save();

        $this->get(route('sites.index'))->assertOk()->assertSee('Yanıt vermiyor');
        $this->get('/')->assertOk()->assertSee('kahve.com.tr yanıt vermiyor')->assertSee('Siteye git');
    }

    public function test_failed_and_rolled_back_deployments_are_problems_for_a_day(): void
    {
        $app = LaravelApp::factory()->create(['site_id' => $this->site->id]);
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 41, 'release' => '41', 'kind' => 'deploy', 'status' => DeploymentStatus::Succeeded, 'run' => 'deploy-41', 'finished_at' => now()->subHour()]);
        $d = LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 42, 'release' => '42', 'kind' => 'deploy', 'status' => DeploymentStatus::RolledBack, 'run' => 'deploy-42',
            'error' => '/up 500: SQLSTATE[42S22] Unknown column', 'finished_at' => now()->subMinutes(5)]);

        $problems = app(SiteProblems::class)->all();
        $this->assertSame('kahve.com.tr dağıtımı geri alındı', $problems['deploy:laravel:'.$d->id]['title']);
        $this->assertStringContainsString('Dağıtım #42 sağlık kontrolünü geçemedi', $problems['deploy:laravel:'.$d->id]['text']);

        $d->update(['finished_at' => now()->subHours(25)]);
        $this->assertSame([], app(SiteProblems::class)->all());

        $d->update(['finished_at' => now()->subMinutes(5), 'status' => DeploymentStatus::Failed, 'error' => 'composer install başarısız']);
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 43, 'release' => '43', 'kind' => 'deploy', 'status' => DeploymentStatus::Succeeded, 'run' => 'deploy-43', 'finished_at' => now()]);
        $this->assertSame([], app(SiteProblems::class)->all(), 'a newer deployment that worked ends it');
    }

    public function test_a_scheduled_task_that_fails_three_times_in_a_row_is_a_problem(): void
    {
        $run = fn (int $code) => ['id' => (string) random_int(1, PHP_INT_MAX), 'started_at' => now()->toIso8601String(), 'exit_code' => $code, 'timed_out' => false, 'skipped' => false];
        $this->agent->answers['cron.runs'] = ['runs' => [$run(1), $run(1), $run(0)], 'running' => false];
        $job = CronJob::create(['site_id' => $this->site->id, 'schedule' => '* * * * *', 'command' => 'php rapor.php']);

        app(CronJobs::class)->refreshHistory();
        $this->assertSame(2, $job->fresh()->failed_runs);
        $this->assertSame([], app(SiteProblems::class)->all());

        $this->agent->answers['cron.runs'] = ['runs' => [$run(1), $run(1), $run(1)], 'running' => false];
        app(CronJobs::class)->refreshHistory();
        $this->assertSame(3, $job->fresh()->failed_runs);
        $problem = app(SiteProblems::class)->all()['cron:'.$job->id];
        $this->assertSame('kahve.com.tr zamanlanmış görevi üst üste 3 kez başarısız', $problem['title']);
        $this->assertSame('php rapor.php', $problem['text']);
    }

    public function test_crashing_workers_and_failing_scheduler_tasks_are_problems(): void
    {
        $app = LaravelApp::factory()->create(['site_id' => $this->site->id, 'scheduler' => true]);
        $this->agent->answers['laravel.watch'] = [[
            'domain' => 'kahve.com.tr',
            'units' => [
                ['unit' => 'serfex-queue-kahve.com.tr_default@1.service', 'kind' => 'worker', 'name' => 'default', 'state' => 'activating', 'crashes' => 7],
                ['unit' => 'serfex-queue-kahve.com.tr_raporlar@1.service', 'kind' => 'worker', 'name' => 'raporlar', 'state' => 'active', 'crashes' => 1],
                ['unit' => 'serfex-proc-kahve.com.tr_horizon.service', 'kind' => 'process', 'name' => 'horizon', 'state' => 'failed', 'crashes' => 0],
            ],
            'scheduler' => true,
            'tasks' => [['command' => 'php artisan rapor:gonder', 'ok' => false, 'failures' => 3, 'at' => '2026-11-06 10:03:00'], ['command' => 'php artisan cache:prune', 'ok' => true, 'failures' => 0]],
        ]];

        $this->artisan('serfex:laravel-watch')->assertSuccessful();

        $this->assertSame(['laravel.watch', ['domains' => ['kahve.com.tr']]], $this->agent->calls[0]);
        $problems = app(SiteProblems::class)->all();
        $worker = $problems['queue:'.$app->id.':worker:default'];
        $this->assertSame('kahve.com.tr kuyruk işçisi sürekli çöküyor', $worker['title']);
        $this->assertStringContainsString('Son 10 dakikada 7 kez çöküp yeniden başladı', $worker['text']);
        $this->assertArrayNotHasKey('queue:'.$app->id.':worker:raporlar', $problems);
        $this->assertSame('kahve.com.tr horizon süreci durdu', $problems['queue:'.$app->id.':process:horizon']['title']);
        $tasks = array_values(array_filter($problems, fn ($p) => $p['kind'] === 'cron'));
        $this->assertCount(1, $tasks);
        $this->assertSame('php artisan rapor:gonder', $tasks[0]['text']);

        $this->assertSame(BackgroundWatch::CRASHES, 5);
        app(Notifications::class)->check();
        $this->assertEqualsCanonicalizing(['queue', 'queue', 'cron'], NotificationEvent::whereIn('kind', ['queue', 'cron'])->pluck('kind')->all());
    }

    public function test_prune_forgets_old_checks_and_outages(): void
    {
        DB::table('site_uptime')->insert([
            ['site_id' => $this->site->id, 'bucket_at' => now()->subDays(91), 'checks' => 15, 'failures' => 0, 'ms_total' => 0],
            ['site_id' => $this->site->id, 'bucket_at' => now()->subDays(2), 'checks' => 15, 'failures' => 0, 'ms_total' => 0],
        ]);
        $this->site->outages()->create(['started_at' => now()->subDays(95), 'ended_at' => now()->subDays(95), 'error' => 'HTTP 502']);

        $this->artisan('serfex:uptime --prune')->assertSuccessful();

        $this->assertSame(1, DB::table('site_uptime')->count());
        $this->assertSame(0, SiteOutage::count());
    }
}
