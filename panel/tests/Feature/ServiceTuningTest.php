<?php

namespace Tests\Feature;

use App\Livewire\Services\DatabaseTuning;
use App\Livewire\Services\OptimizationOverview;
use App\Livewire\Services\RedisTuningPage;
use App\Livewire\Services\ServiceShow;
use App\Models\LaravelApp;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TuningChange;
use App\Models\TuningSample;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Tuning\Optimization;
use App\Services\Tuning\Tuner;
use App\Services\Tuning\TuningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Optimization of Nginx, the customers' PostgreSQL, Memcached and the
 * sites' own Redis instances: suggestions from their measurements,
 * applying through the service settings (or the application's Redis),
 * undo, locks for values set by hand and the automatic level, which never
 * restarts a service.
 */
class ServiceTuningTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, array<string, mixed>> service.settings by section */
    private array $settings = [
        'nginx' => ['worker_processes' => 'auto', 'worker_connections' => 768, 'client_max_body_mb' => 64, 'keepalive_timeout' => 65, 'worker_rlimit_nofile' => 0],
        'postgresql' => ['shared_buffers_mb' => 128, 'effective_cache_size_mb' => 4096, 'work_mem_mb' => 4, 'maintenance_work_mem_mb' => 64, 'random_page_cost' => '4', 'effective_io_concurrency' => 1],
        'memcached' => ['memory_mb' => 64, 'max_connections' => 1024],
    ];

    private LaravelApp $laravel;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::factory()->create(['domain' => 'roifex.com', 'provisioned_at' => now()]);
        $this->laravel = LaravelApp::factory()->create(['site_id' => $site->id, 'redis_port' => 6380, 'redis_password' => str_repeat('a', 40)]);
        $service = fn (string $unit, string $name, string $settings) => ['unit' => $unit, 'name' => $name, 'group' => 'hosting', 'state' => 'active', 'sub_state' => 'running',
            'enabled' => 'enabled', 'since' => now()->subDays(12)->toIso8601String(), 'restarts' => 0, 'can_reload' => true, 'cpu' => 1.0, 'memory' => 48 << 20, 'settings' => $settings];
        $this->agent = new FakeAgent([
            'service.list' => fn () => ['services' => [
                $service('nginx', 'Nginx', 'nginx'), $service('postgresql@17-main', 'PostgreSQL 17', 'postgresql'), $service('memcached', 'Memcached', 'memcached'),
            ]],
            'service.measure' => fn ($p) => $this->sample($p['unit'], 168),
            'service.settings' => function ($p) {
                $section = Optimization::kind($p['unit']);

                return ['unit' => $p['unit'], $section => $this->settings[$section]];
            },
            'service.settings.apply' => function ($p) {
                $section = Optimization::kind($p['unit']);
                $this->settings[$section] = $p[$section];

                return ['unit' => $p['unit'], $section => $this->settings[$section]];
            },
            'redis.apply' => fn ($p) => ['unit' => 'serfex-redis-roifex.com.service', 'port' => $p['port'], 'changed' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    /**
     * A measurement $hours into the week.
     *
     * @return array<string, mixed>
     */
    private function sample(string $unit, float $hours): array
    {
        $machine = ['cpus' => 8, 'memory_mb' => 32096, 'available_mb' => 27400, 'swap_mb' => 4096, 'swap_used_mb' => 0,
            'processes_mb' => ['mysql' => 612, 'php-fpm' => 1180, 'redis' => 310, 'memcached' => 64, 'postgresql' => 240]];
        $start = now()->subDays(12)->timestamp;

        return $machine + match (Optimization::kind($unit)) {
            'nginx' => ['unit' => 'nginx', 'kind' => 'nginx', 'worker_processes' => $this->settings['nginx']['worker_processes'], 'workers' => 8,
                'worker_connections' => $this->settings['nginx']['worker_connections'], 'worker_rlimit_nofile' => $this->settings['nginx']['worker_rlimit_nofile'],
                'file_limit' => 1024, 'sockets' => 212, 'not_enough' => 37, 'not_enough_day' => 4, 'too_many_files' => 0, 'too_many_files_day' => 0],
            'postgresql' => ['unit' => $unit, 'kind' => 'postgresql', 'version' => '17.1', 'disk' => 'nvme', 'start_time' => $start,
                'settings' => array_map('strval', $this->settings['postgresql'] + ['max_connections' => 100]),
                'blocks_hit' => (int) (4e9 + 2.6e6 * $hours), 'blocks_read' => (int) (2e8 + 110000 * $hours), 'temp_files' => (int) (9000 + 14 * $hours),
                'temp_bytes' => (int) (9e10 + 1.4e8 * $hours), 'transactions' => 0, 'backends' => 14, 'data_bytes' => 6_442_450_944],
            'memcached' => ['unit' => 'memcached', 'kind' => 'memcached', 'version' => '1.6.24', 'memcached_mb' => $this->settings['memcached']['memory_mb'],
                'max_connections' => $this->settings['memcached']['max_connections'], 'start_time' => $start,
                'stats' => ['limit_maxbytes' => 64 << 20, 'bytes' => 63 << 20, 'evictions' => (int) (80000 + 310 * $hours), 'listen_disabled_num' => 0,
                    'get_hits' => (int) (9e7 + 61000 * $hours), 'get_misses' => (int) (1.2e7 + 9500 * $hours), 'curr_connections' => 12]],
            'redis' => ['unit' => Optimization::REDIS, 'kind' => 'redis', 'instances' => [
                ['domain' => 'roifex.com', 'port' => 6380, 'max_memory_mb' => $this->laravel->fresh()->redis_memory_mb ?? 128, 'read' => true,
                    'used_mb' => 109.4, 'peak_mb' => 121.7, 'keys' => 48211, 'evicted' => 0, 'oom_errors' => 0, 'start_time' => $start],
            ]],
        };
    }

    private function week(string $unit): void
    {
        TuningSample::create(['unit' => $unit, 'data' => $this->sample($unit, 0), 'created_at' => now()->subDays(7)]);
        TuningSample::create(['unit' => $unit, 'data' => $this->sample($unit, 168), 'created_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function card(string $unit, string $key): array
    {
        return collect(app(Optimization::class)->report($unit)['cards'])->firstWhere('key', $key) ?? $this->fail("no card $key");
    }

    /** @return list<string> */
    private function keys(string $unit): array
    {
        return array_column(app(Optimization::class)->report($unit)['cards'], 'key');
    }

    public function test_nginx_gets_more_connections_and_a_file_limit_when_its_log_says_they_ran_out(): void
    {
        $this->week('nginx');
        $c = $this->card('nginx', 'nginx_connections');
        $this->assertSame(['worker_connections' => 2048, 'worker_rlimit_nofile' => 8192], $c['values']);
        $this->assertSame('reload', $c['disruption']);
        $this->assertStringContainsString('37', $c['measure']);

        $change = app(Tuner::class)->apply('nginx', $c, true, null);
        $this->assertInstanceOf(TuningChange::class, $change);
        $sent = $this->agent->called('service.settings.apply')[0]['nginx'];
        $this->assertSame(2048, $sent['worker_connections']);
        $this->assertSame(8192, $sent['worker_rlimit_nofile']);
        // The settings the suggestion does not change stay as they were.
        $this->assertSame(64, $sent['client_max_body_mb']);

        $this->assertNull(app(Tuner::class)->undo($change->fresh()));
        $this->assertSame(768, $this->settings['nginx']['worker_connections']);
        $this->assertSame(0, $this->settings['nginx']['worker_rlimit_nofile']);
    }

    public function test_postgresql_suggestions_and_the_buffer_restart_waits_for_the_window(): void
    {
        $this->week('postgresql@17-main');
        $this->assertSame(['pg_shared_buffers', 'pg_work_mem', 'pg_effective_cache', 'pg_io'], $this->keys('postgresql@17-main'));
        $buffers = $this->card('postgresql@17-main', 'pg_shared_buffers');
        // 6 GB of data: × 1,25 is 7,5 GB, under a quarter of 32 GB.
        $this->assertSame(['shared_buffers_mb' => 6144], $buffers['values']);
        $this->assertSame('restart', $buffers['disruption']);
        $this->assertSame(6144 - 128, $buffers['memory_mb']);
        $work = $this->card('postgresql@17-main', 'pg_work_mem');
        $this->assertSame(['work_mem_mb' => 8], $work['values']);
        $this->assertSame(4 * 14, $work['memory_mb']);
        $this->assertSame(['random_page_cost' => '1.1', 'effective_io_concurrency' => 200], $this->card('postgresql@17-main', 'pg_io')['values']);

        // The Apply dialog of a restart starts on the maintenance window.
        Livewire::test(DatabaseTuning::class, ['unit' => 'postgresql@17-main'])
            ->call('confirm', 'pg_shared_buffers')
            ->assertSet('when', 'window')
            ->assertSee('PostgreSQL yeniden başlar');

        $change = app(Tuner::class)->apply('postgresql@17-main', $work, true, null);
        $this->assertInstanceOf(TuningChange::class, $change);
        $sent = $this->agent->called('service.settings.apply')[0]['postgresql'];
        $this->assertSame(8, $sent['work_mem_mb']);
        $this->assertSame(128, $sent['shared_buffers_mb']);
    }

    public function test_memcached_grows_when_it_evicts_and_saving_by_hand_locks_it(): void
    {
        $this->week('memcached');
        $c = $this->card('memcached', 'memcached_memory');
        $this->assertSame(['memory_mb' => 128], $c['values']);
        $this->assertSame('restart', $c['disruption']);

        Livewire::test(ServiceShow::class, ['unit' => 'memcached'])
            ->set('tab', 'settings')
            ->set('settings.memory_mb', '96')
            ->call('saveSettings')
            ->assertHasNoErrors();
        $this->assertSame(96, $this->settings['memcached']['memory_mb']);
        $this->assertArrayHasKey('memcached_memory', app(TuningSettings::class)->locked('memcached'));
        $this->assertNotContains('memcached_memory', $this->keys('memcached'));
    }

    public function test_a_sites_redis_grows_on_the_running_instance_and_undoes(): void
    {
        $this->week(Optimization::REDIS);
        $key = 'redis_memory:'.$this->laravel->id;
        $c = $this->card(Optimization::REDIS, $key);
        // Peak 121,7 MB × 1,5 → 192 MB.
        $this->assertSame(['app' => $this->laravel->id, 'max_memory_mb' => 192], $c['values']);
        $this->assertSame('none', $c['disruption']);
        $this->assertTrue(Optimization::inBand($c));

        $change = app(Tuner::class)->apply(Optimization::REDIS, $c, true, null);
        $this->assertInstanceOf(TuningChange::class, $change);
        $this->assertSame(192, $this->agent->called('redis.apply')[0]['max_memory_mb']);
        $this->assertSame(192, $this->laravel->fresh()->redis_memory_mb);

        $this->assertNull(app(Tuner::class)->undo($change->fresh()));
        $this->assertNull($this->laravel->fresh()->redis_memory_mb);
        $this->assertSame(0, $this->agent->called('redis.apply')[1]['max_memory_mb']);
    }

    public function test_the_automatic_level_applies_small_changes_but_never_restarts(): void
    {
        Setting::put('tuning.level', TuningSettings::AUTO);
        $this->week(Optimization::REDIS);
        $this->week('memcached');
        $optimization = app(Optimization::class);

        $redis = $optimization->autoApply(Optimization::REDIS);
        $this->assertSame('watching', $redis?->status);
        $this->assertNull($redis->user_id);
        // 64 → 128 MB is out of the band and restarts Memcached anyway.
        $this->assertNull($optimization->autoApply('memcached'));
    }

    public function test_overview_lists_every_service_and_the_redis_page(): void
    {
        $units = array_column(app(Optimization::class)->servers(), 'unit');
        $this->assertSame(['postgresql@17-main', 'nginx', 'memcached', Optimization::REDIS], $units);
        $this->week(Optimization::REDIS);

        Livewire::test(OptimizationOverview::class)
            ->assertSee('PostgreSQL 17')
            ->assertSee('Redis (site önbellekleri)')
            ->assertSee(route('services.redis'));
        Livewire::test(RedisTuningPage::class)
            ->assertSee('roifex.com')
            ->assertSee('Redis belleğini büyüt');
        $this->get(route('services.redis'))->assertOk();
    }

    public function test_measure_command_measures_the_new_services(): void
    {
        $this->artisan('serfex:tuning-measure')->assertSuccessful();
        foreach (['nginx', 'postgresql@17-main', 'memcached', Optimization::REDIS] as $unit) {
            $this->assertTrue(TuningSample::where('unit', $unit)->exists(), $unit);
        }
    }
}
