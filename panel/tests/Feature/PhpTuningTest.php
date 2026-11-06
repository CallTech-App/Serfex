<?php

namespace Tests\Feature;

use App\Livewire\Services\DatabaseTuning;
use App\Livewire\Services\OptimizationOverview;
use App\Livewire\Services\PhpVersionShow;
use App\Livewire\Services\ServiceShow;
use App\Livewire\Sites\SitePhp;
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
 * Optimization of PHP-FPM: OPcache (memory, interned strings, file table)
 * from the version's measurements, the sites' worker pools (out of
 * workers, idle workers), applying to php.ini defaults or the site's PHP
 * settings, undo, locks for values set by hand and the automatic level.
 */
class PhpTuningTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, string> php.info values of 8.4 */
    private array $ini = [
        'memory_limit' => '256M', 'upload_max_filesize' => '64M', 'post_max_size' => '64M', 'max_execution_time' => '60', 'max_input_time' => '60',
        'max_input_vars' => '3000', 'max_file_uploads' => '20', 'display_errors' => 'Off', 'allow_url_fopen' => 'On', 'date.timezone' => 'Europe/Istanbul',
        'session.gc_maxlifetime' => '1440', 'opcache.enable' => '1', 'opcache.memory_consumption' => '128', 'opcache.interned_strings_buffer' => '16',
        'opcache.max_accelerated_files' => '20000', 'opcache.validate_timestamps' => '1', 'opcache.revalidate_freq' => '2',
    ];

    private Site $shop;

    private Site $quiet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Site::factory()->create(['domain' => 'roifex.com', 'php_version' => '8.4', 'provisioned_at' => now()]);
        $this->quiet = Site::factory()->create(['domain' => 'serfex.ai', 'php_version' => '8.4', 'provisioned_at' => now(), 'php_settings' => ['pm' => 'dynamic', 'max_children' => 20]]);
        $this->agent = new FakeAgent([
            'service.list' => fn () => ['services' => [
                ['unit' => 'php8.4-fpm', 'name' => 'PHP 8.4 (PHP-FPM)', 'group' => 'hosting', 'state' => 'active', 'sub_state' => 'running', 'enabled' => 'enabled',
                    'since' => now()->subDays(12)->toIso8601String(), 'restarts' => 0, 'can_reload' => true, 'cpu' => 2.1, 'memory' => 1180 << 20],
            ]],
            'service.measure' => fn () => $this->sample(168),
            'php.info' => fn () => ['version' => '8.4', 'values' => $this->ini, 'managed' => true, 'defaults' => $this->ini, 'extensions' => []],
            'php.settings.apply' => function ($p) {
                $this->ini = $p['values'];

                return ['version' => '8.4', 'values' => $this->ini, 'managed' => true, 'defaults' => $this->ini, 'extensions' => []];
            },
            'php.versions' => ['8.4'],
            'site.apply' => fn () => [],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    /**
     * A measurement of PHP 8.4 $hours into the week: OPcache nearly full,
     * the shop out of workers every evening, the other site idling.
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function sample(float $hours, array $more = []): array
    {
        $start = now()->subDays(12)->timestamp;

        return array_replace_recursive([
            'unit' => 'php8.4-fpm', 'kind' => 'php', 'version' => '8.4', 'cpus' => 8, 'memory_mb' => 32096, 'available_mb' => 27400, 'swap_mb' => 4096, 'swap_used_mb' => 0,
            'processes_mb' => ['mysql' => 612, 'php-fpm' => 1180, 'redis' => 310, 'memcached' => 0, 'postgresql' => 240],
            'opcache' => [
                'loaded' => true, 'enabled' => (bool) (int) $this->ini['opcache.enable'], 'memory_mb' => (int) $this->ini['opcache.memory_consumption'], 'used_mb' => 121.4, 'free_mb' => 2.1, 'wasted_mb' => 4.5,
                'strings_mb' => 16, 'strings_used_mb' => 15.6, 'strings_free_mb' => 0.4, 'max_files' => 20000, 'cached_scripts' => 9840, 'cached_keys' => 12210, 'max_cached_keys' => 32531,
                'hits' => (int) (48000000 + 410000 * $hours), 'misses' => (int) (90000 + 1200 * $hours), 'oom_restarts' => 3 + (int) ($hours / 48), 'hash_restarts' => 0,
                'start_time' => $start, 'validate_timestamps' => true, 'revalidate_freq' => 2,
            ],
            'pools' => [
                ['domain' => 'roifex.com', 'pm' => 'ondemand', 'max_children' => 10, 'start_time' => $start, 'accepted' => (int) (900000 + 5200 * $hours),
                    'max_reached' => 14 + (int) ($hours / 12), 'max_active' => 10, 'max_queue' => 23, 'active' => 4, 'idle' => 2, 'workers' => 6, 'memory_mb' => 384, 'read' => true],
                ['domain' => 'serfex.ai', 'pm' => 'dynamic', 'max_children' => 20, 'start_time' => $start, 'accepted' => (int) (40000 + 210 * $hours),
                    'max_reached' => 0, 'max_active' => 3, 'max_queue' => 0, 'active' => 0, 'idle' => 10, 'workers' => 10, 'memory_mb' => 420, 'read' => true],
            ],
        ], $more);
    }

    /** Stores a week of measurements: one 7 days ago and one now. */
    private function week(array $more = []): void
    {
        TuningSample::create(['unit' => 'php8.4-fpm', 'data' => $this->sample(0, $more), 'created_at' => now()->subDays(7)]);
        TuningSample::create(['unit' => 'php8.4-fpm', 'data' => $this->sample(168, $more), 'created_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(Optimization::class)->report('php8.4-fpm');
    }

    /** @return array<string, mixed> */
    private function card(string $key): array
    {
        return collect($this->report()['cards'])->firstWhere('key', $key) ?? $this->fail("no card $key");
    }

    public function test_suggests_opcache_and_pool_changes_from_a_week_of_measurements(): void
    {
        $this->week();
        $report = $this->report();
        $this->assertSame('php', $report['kind']);
        $this->assertSame(['opcache_memory', 'opcache_strings', 'fpm_children:'.$this->shop->id, 'fpm_pm:'.$this->quiet->id], array_column($report['cards'], 'key'));

        // 125,9 MB taken × 1,3 → the next size: 192 MB.
        $memory = $report['cards'][0];
        $this->assertSame(['ini' => ['opcache.memory_consumption' => '192']], $memory['values']);
        $this->assertSame(64, $memory['memory_mb']);
        $this->assertSame('reload', $memory['disruption']);
        $this->assertSame(['ini' => ['opcache.interned_strings_buffer' => '24']], $report['cards'][1]['values']);

        // The shop ran out of 10 workers; 15, each about 64 MB.
        $children = $report['cards'][2];
        $this->assertSame(['site' => $this->shop->id, 'max_children' => 15], $children['values']);
        $this->assertSame(320, $children['memory_mb']);
        $this->assertSame('pm.max_children · roifex.com', $children['setting']);
        $this->assertStringContainsString('14 kez sınıra dayandı', $children['measure']);

        // The quiet site keeps 10 workers waiting for nothing.
        $pm = $report['cards'][3];
        $this->assertSame(['site' => $this->quiet->id, 'pm' => 'ondemand'], $pm['values']);
        $this->assertSame(-420, $pm['memory_mb']);

        $this->assertContains('opcache.validate_timestamps', array_column($report['fine'], 1));
        $this->assertContains('opcache.max_accelerated_files', array_column($report['fine'], 1));
        $this->assertSame('warn', $report['cells'][0][3]);
        $this->assertSame('1 / 2', $report['cells'][3][1]);
        // PHP's suggestions take from PHP-FPM's part of the budget.
        $this->assertSame($report['budget']['now']['php-fpm'] + 64 + 8 + 320, $report['budget']['after']['php-fpm']);
        $this->assertSame([], $report['slow']['rows']);
    }

    public function test_nothing_to_suggest_when_opcache_and_the_pools_are_fine(): void
    {
        $this->week(['opcache' => ['used_mb' => 60.0, 'oom_restarts' => 0, 'strings_free_mb' => 8.0], 'pools' => [['max_reached' => 0], ['pm' => 'ondemand']]]);
        $report = $this->report();
        $this->assertSame([], $report['cards']);
        $this->assertContains('Site havuzları', array_column($report['fine'], 0));
    }

    public function test_opcache_switched_off_is_suggested_on(): void
    {
        $this->ini['opcache.enable'] = '0';
        $this->week();
        $this->assertSame(['ini' => ['opcache.enable' => '1']], $this->card('opcache_enable')['values']);
        $this->assertNotContains('opcache_memory', array_column($this->report()['cards'], 'key'));
    }

    public function test_a_temporary_worker_limit_is_not_mistaken_for_the_sites_own(): void
    {
        $this->week(['pools' => [['max_children' => 3]]]);
        $this->assertNotContains('fpm_children:'.$this->shop->id, array_column($this->report()['cards'], 'key'));
    }

    public function test_the_probe_failing_is_shown(): void
    {
        $this->week(['opcache' => null, 'opcache_error' => 'PHP-FPM 8.4 rejected the probe pool']);
        Livewire::test(DatabaseTuning::class, ['unit' => 'php8.4-fpm'])->assertSee('rejected the probe pool');
    }

    public function test_applies_an_opcache_suggestion_to_the_versions_defaults_and_undoes_it(): void
    {
        $this->week();
        Livewire::test(DatabaseTuning::class, ['unit' => 'php8.4-fpm'])
            ->assertSee('OPcache belleğini büyüt')
            ->assertSee('PHP-FPM yeniden yüklenir')
            ->call('confirm', 'opcache_memory')
            ->assertSee('OPcache baştan dolar')
            ->call('apply');
        $applied = $this->agent->called('php.settings.apply');
        $this->assertCount(1, $applied);
        $this->assertSame('8.4', $applied[0]['version']);
        $this->assertSame('192', $applied[0]['values']['opcache.memory_consumption']);
        // The other defaults go back as they are.
        $this->assertSame('Europe/Istanbul', $applied[0]['values']['date.timezone']);
        $change = TuningChange::sole();
        $this->assertSame('watching', $change->status);
        $this->assertSame(['ini' => ['opcache.memory_consumption' => '128']], $change->from_values);

        $this->assertNull(app(Tuner::class)->undo($change));
        $this->assertSame('128', $this->ini['opcache.memory_consumption']);
        $this->assertSame('undone', $change->fresh()->status);
    }

    public function test_applies_more_workers_to_the_sites_pool_and_undoes_it(): void
    {
        $this->week();
        $key = 'fpm_children:'.$this->shop->id;
        Livewire::test(DatabaseTuning::class, ['unit' => 'php8.4-fpm'])
            ->assertSee('PHP işçi sayısını artır')
            ->call('confirm', $key)
            ->call('apply');
        $this->assertSame(15, $this->shop->fresh()->php_settings['max_children']);
        $this->assertCount(1, $this->agent->called('site.apply'));
        $change = TuningChange::sole();
        $this->assertSame(['site' => $this->shop->id, 'max_children' => null], $change->from_values);
        // The site's own page shows the new number.
        Livewire::test(SitePhp::class, ['site' => $this->shop->id])->assertSet('maxChildren', '15');

        $this->assertNull(app(Tuner::class)->undo($change));
        $this->assertNull($this->shop->fresh()->php_settings);
    }

    public function test_switching_an_idle_pool_to_ondemand_keeps_its_other_settings(): void
    {
        $this->week();
        $change = app(Tuner::class)->apply('php8.4-fpm', $this->card('fpm_pm:'.$this->quiet->id), true, auth()->user());
        $this->assertInstanceOf(TuningChange::class, $change);
        $this->assertSame(['max_children' => 20], $this->quiet->fresh()->php_settings);
        app(Tuner::class)->undo($change);
        $this->assertEquals(['pm' => 'dynamic', 'max_children' => 20], $this->quiet->fresh()->php_settings);
    }

    public function test_values_set_by_hand_are_locked(): void
    {
        $this->week();
        Livewire::test(PhpVersionShow::class, ['version' => '8.4'])
            ->set('values.opcache_memory_consumption', '256')
            ->call('save');
        $this->assertTrue(app(TuningSettings::class)->isLocked('php8.4-fpm', 'opcache_memory'));
        $this->assertFalse(app(TuningSettings::class)->isLocked('php8.4-fpm', 'opcache_files'));

        Livewire::test(SitePhp::class, ['site' => $this->shop->id])->set('maxChildren', '12')->call('save');
        $this->assertTrue(app(TuningSettings::class)->isLocked(Optimization::SITE_LOCKS, 'fpm_children:'.$this->shop->id));

        $this->ini['opcache.memory_consumption'] = '128';
        $keys = array_column($this->report()['cards'], 'key');
        $this->assertNotContains('opcache_memory', $keys);
        $this->assertNotContains('fpm_children:'.$this->shop->id, $keys);
        $this->assertContains('opcache_strings', $keys);
    }

    public function test_the_automatic_level_applies_one_small_change_a_day_in_the_window(): void
    {
        $this->week();
        Setting::put('tuning.level', TuningSettings::AUTO);
        $optimization = app(Optimization::class);

        // OPcache 128 → 192 MB is +50 %: in the band. PHP reloads, so it
        // waits for the maintenance window.
        $change = $optimization->autoApply('php8.4-fpm');
        $this->assertSame(['opcache_memory', 'scheduled', null], [$change->key, $change->status, $change->user_id]);
        $this->assertTrue($change->scheduled_for->gt(now()));
        // One a day.
        $this->assertNull($optimization->autoApply('php8.4-fpm'));
        $this->travel(25)->hours();
        TuningSample::create(['unit' => 'php8.4-fpm', 'data' => $this->sample(193), 'created_at' => now()]);
        $next = $optimization->autoApply('php8.4-fpm');
        $this->assertSame('opcache_strings', $next->key);

        // A change a person undid is left to people.
        $next->update(['status' => 'undone']);
        $this->travel(25)->hours();
        TuningSample::create(['unit' => 'php8.4-fpm', 'data' => $this->sample(218), 'created_at' => now()]);
        $this->assertSame('fpm_children:'.$this->shop->id, $optimization->autoApply('php8.4-fpm')?->key);

        // Big jumps are never automatic.
        $this->assertFalse(Optimization::inBand(['from_raw' => 'mb:128', 'to_raw' => 'mb:4224']));
        $this->assertTrue(Optimization::inBand(['from_raw' => 'num:10', 'to_raw' => 'num:15']));
        $this->assertFalse(Optimization::inBand(['from_raw' => 'off', 'to_raw' => 'on:1']));
        $this->assertFalse(Optimization::inBand(['from_raw' => 'text:dynamic', 'to_raw' => 'text:ondemand']));
    }

    public function test_the_automatic_level_does_nothing_at_other_levels(): void
    {
        $this->week();
        $this->assertNull(app(Optimization::class)->autoApply('php8.4-fpm'));
        $this->assertSame(0, TuningChange::count());
    }

    public function test_hourly_measurement_includes_php_and_the_pages_show_it(): void
    {
        $this->artisan('serfex:tuning-measure')->expectsOutputToContain('php8.4-fpm: measured')->assertSuccessful();
        $this->assertSame(1, TuningSample::where('unit', 'php8.4-fpm')->count());

        $this->week();
        Livewire::withQueryParams([])->test(ServiceShow::class, ['unit' => 'php8.4-fpm'])->assertSet('tab', 'recs')->assertSee('Öneriler');
        Livewire::test(OptimizationOverview::class)->assertSee('PHP 8.4 (PHP-FPM)')->assertSee('Küçük değişiklikleri kendiliğinden uygula');
        Livewire::test(PhpVersionShow::class, ['version' => '8.4'])->assertSee('Optimizasyon önerileri');
    }

    public function test_an_automatic_change_shows_who_made_it(): void
    {
        $this->week();
        $change = app(Tuner::class)->apply('php8.4-fpm', $this->card('opcache_memory'), true, null);
        $change->update(['status' => 'applied']);
        Livewire::test(DatabaseTuning::class, ['unit' => 'php8.4-fpm'])->assertSee('Serfex (otomatik)');
        app()->setLocale('en');
        $this->assertSame('Grow the OPcache memory', $change->fresh()->title);
    }
}
