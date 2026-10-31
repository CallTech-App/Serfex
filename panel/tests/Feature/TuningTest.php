<?php

namespace Tests\Feature;

use App\Livewire\Services\DatabaseTuning;
use App\Livewire\Services\OptimizationOverview;
use App\Livewire\Services\ServiceShow;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TuningChange;
use App\Models\TuningSample;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ClaudeClient;
use App\Services\Customers\Customers;
use App\Services\Settings\Notifications;
use App\Services\Tuning\MemoryBudget;
use App\Services\Tuning\MysqlAdvisor;
use App\Services\Tuning\Optimization;
use App\Services\Tuning\Samples;
use App\Services\Tuning\Tuner;
use App\Services\Tuning\TuningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Service optimization: suggestions for the database server from its
 * measurements, the shared memory budget, applying (now or in the
 * maintenance window), watching and rolling back by itself, undo, locks,
 * slow statements for administrators and customers and Serfex AI's notes.
 */
class TuningTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, mixed> the agent's database settings */
    private array $db = [
        'max_connections' => 151, 'innodb_buffer_pool_mb' => 128, 'max_allowed_packet_mb' => 64, 'tmp_table_mb' => 0,
        'table_open_cache' => 0, 'innodb_io_capacity' => 0, 'slow_query_log' => false, 'long_query_time' => 0,
    ];

    /** @var array<string, mixed> what service.measure answers */
    private array $measure;

    private string $state = 'active';

    protected function setUp(): void
    {
        parent::setUp();
        $this->measure = $this->sample(hours: 0);
        $this->agent = new FakeAgent([
            'service.list' => fn () => ['services' => [
                ['unit' => 'mysql', 'name' => 'MySQL', 'group' => 'hosting', 'state' => $this->state, 'sub_state' => 'running', 'enabled' => 'enabled',
                    'since' => now()->subDays(12)->toIso8601String(), 'restarts' => 0, 'can_reload' => false, 'cpu' => 3.4, 'memory' => 612 << 20, 'settings' => 'database'],
            ]],
            'service.settings' => fn () => ['unit' => 'mysql', 'database' => $this->db],
            'service.settings.apply' => function ($p) {
                $this->db = $p['database'];

                return ['unit' => 'mysql', 'database' => $this->db];
            },
            'service.measure' => fn () => $this->measure,
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    /**
     * A measurement of a 32 GB machine whose MySQL keeps the package's
     * settings, $hours into the week.
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function sample(float $hours, array $more = []): array
    {
        $grow = fn (float $base, float $perHour) => (string) (int) ($base + $perHour * $hours);

        return array_replace_recursive([
            'unit' => 'mysql', 'version' => '8.4.7', 'cpus' => 8, 'memory_mb' => 32096, 'available_mb' => 27400, 'swap_mb' => 4096, 'swap_used_mb' => 0, 'disk' => 'nvme',
            'variables' => [
                'innodb_buffer_pool_size' => (string) ($this->db['innodb_buffer_pool_mb'] << 20), 'max_connections' => (string) $this->db['max_connections'],
                'tmp_table_size' => '16777216', 'max_heap_table_size' => '16777216', 'table_open_cache' => '4000', 'innodb_io_capacity' => '200',
                'slow_query_log' => 'OFF', 'long_query_time' => '10.000000', 'innodb_flush_log_at_trx_commit' => '1', 'performance_schema' => 'ON',
                'sort_buffer_size' => '262144', 'join_buffer_size' => '262144', 'read_buffer_size' => '131072', 'read_rnd_buffer_size' => '262144', 'thread_stack' => '1048576', 'binlog_cache_size' => '32768',
            ],
            'status' => [
                'Uptime' => $grow(1000000, 3600), 'Max_used_connections' => '38', 'Connection_errors_max_connections' => '0',
                'Created_tmp_disk_tables' => $grow(400000, 74), 'Created_tmp_tables' => $grow(1000000, 194),
                'Innodb_buffer_pool_reads' => $grow(9000000, 72000), 'Innodb_buffer_pool_read_requests' => $grow(100000000, 837000), 'Opened_tables' => $grow(9000, 12),
            ],
            'data_bytes' => 3328599654, 'tables' => 412,
            'digests' => [
                ['schema' => 'roifex_laravel', 'digest' => 'aaa', 'text' => 'SELECT * FROM `orders` WHERE `status` = ? ORDER BY `created_at` DESC LIMIT ?',
                    'count' => (int) (1000 + 1717 * $hours), 'time_ms' => 190000 + 326000 * $hours, 'rows_examined' => (int) (180000000 + 309000000 * $hours), 'rows_sent' => (int) (20000 + 34340 * $hours)],
                ['schema' => 'other_shop', 'digest' => 'bbb', 'text' => 'SELECT * FROM `wp_options` WHERE `autoload` = ?',
                    'count' => (int) (500 + 400 * $hours), 'time_ms' => 210000 + 168000 * $hours, 'rows_examined' => (int) (500 + 400 * $hours), 'rows_sent' => (int) (500 + 400 * $hours)],
                ['schema' => 'roifex_laravel', 'digest' => 'ccc', 'text' => 'SELECT * FROM `products` WHERE `id` = ?',
                    'count' => (int) (9000 + 12000 * $hours), 'time_ms' => 5400 + 7200 * $hours, 'rows_examined' => (int) (9000 + 12000 * $hours), 'rows_sent' => (int) (9000 + 12000 * $hours)],
            ],
            'processes_mb' => ['mysql' => 612, 'php-fpm' => 1180, 'redis' => 310, 'memcached' => 0, 'postgresql' => 240],
        ], $more);
    }

    /** Stores a week of measurements: one 7 days ago and one now. */
    private function week(array $more = []): void
    {
        TuningSample::create(['unit' => 'mysql', 'data' => $this->sample(0, $more), 'created_at' => now()->subDays(7)]);
        $this->measure = $this->sample(168, $more);
        TuningSample::create(['unit' => 'mysql', 'data' => $this->measure, 'created_at' => now()]);
    }

    private function report(): array
    {
        return app(Optimization::class)->report('mysql');
    }

    public function test_suggests_from_a_week_of_measurements(): void
    {
        $this->week();
        $report = $this->report();
        $keys = array_column($report['cards'], 'key');
        $this->assertSame(['buffer_pool', 'tmp_table', 'io_capacity', 'slow_log'], $keys);
        $pool = $report['cards'][0];
        // 3,1 GB of data × 1,3, in 128 MB steps.
        $this->assertSame(['innodb_buffer_pool_mb' => 4224], $pool['values']);
        $this->assertSame(4224 - 128, $pool['memory_mb']);
        $this->assertNull($pool['blocked']);
        $this->assertSame(['tmp_table_mb' => 64], $report['cards'][1]['values']);
        $this->assertSame(['innodb_io_capacity' => 2000], $report['cards'][2]['values']);
        $this->assertSame(4, $report['count']);
        // Connections, the table cache and durability are left alone.
        $fine = array_column($report['fine'], 1);
        $this->assertContains('max_connections', $fine);
        $this->assertContains('table_open_cache', $fine);
        $this->assertContains('innodb_flush_log_at_trx_commit', $fine);
        // 91,4 % of reads from memory over the week.
        $this->assertSame('warn', $report['cells'][0][3]);
        $this->assertStringContainsString('91,4', $report['cells'][0][1]);
        // The budget: 80 % of 32 GB, PHP at its busiest.
        $this->assertSame(25676, $report['budget']['limit']);
        $this->assertSame($report['budget']['now']['mysql'] + 4096 + 96, $report['budget']['after']['mysql']);
    }

    public function test_a_suggestion_that_does_not_fit_the_memory_budget_is_blocked(): void
    {
        $budget = new MemoryBudget(8192, 80, ['system' => 2000, 'mysql' => 600, 'php-fpm' => 3500, 'cache' => 200]);
        $this->assertSame(553, $budget->reserve(806));
        $this->assertNull($budget->reserve(200));
        $this->assertSame(53, $budget->roomMB());
        $this->assertSame(200, $budget->summary()['after']['mysql'] - 600);

        $this->week(['memory_mb' => 6144, 'available_mb' => 1500, 'processes_mb' => ['php-fpm' => 2500]]);
        $pool = $this->report()['cards'][0];
        $this->assertSame('buffer_pool', $pool['key']);
        $this->assertNotNull($pool['blocked']);
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->assertSee('Bellek bütçesi yetmiyor');
    }

    public function test_applies_a_suggestion_watches_it_and_can_undo(): void
    {
        $this->week();
        $page = Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])
            ->assertSee('InnoDB önbelleğini büyüt')
            ->assertSee('Geçici tablolar için daha fazla bellek ayır')
            ->call('confirm', 'buffer_pool')
            ->assertSee('Kesinti')
            ->call('apply');
        $applied = $this->agent->called('service.settings.apply');
        $this->assertCount(1, $applied);
        // Only the suggested setting changes; the others stay.
        $this->assertSame(4224, $applied[0]['database']['innodb_buffer_pool_mb']);
        $this->assertSame(151, $applied[0]['database']['max_connections']);
        $change = TuningChange::sole();
        $this->assertSame('watching', $change->status);
        $this->assertSame(['innodb_buffer_pool_mb' => 128], $change->from_values);
        $this->assertSame(0, $change->baseline['swap_used_mb']);
        $page->assertSee('Uygulandı, gözleniyor');
        // Stored so that every reader sees it in their own language.
        $this->assertSame(['InnoDB önbelleğini büyüt', 'mb:128', 'mb:4224'], [$change->title, $change->getRawOriginal('from_label'), $change->getRawOriginal('to_label')]);
        $this->assertSame('4,1 GB', $change->to_label);
        app()->setLocale('en');
        $this->assertSame('4.1 GB', $change->to_label);
        app()->setLocale('tr');

        // Calm for half an hour: it stays.
        $this->travel(31)->minutes();
        app(Tuner::class)->tick();
        $this->assertSame('applied', $change->fresh()->status);

        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->assertSee('Son 7 günde uygulananlar')->call('undo', $change->id);
        $this->assertSame('undone', $change->fresh()->status);
        $this->assertSame(128, $this->db['innodb_buffer_pool_mb']);
    }

    public function test_rolls_back_by_itself_when_the_server_starts_swapping(): void
    {
        $this->week();
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->call('confirm', 'buffer_pool')->call('apply');
        $this->assertSame(4224, $this->db['innodb_buffer_pool_mb']);

        $this->measure['swap_used_mb'] = 1200;
        $this->travel(5)->minutes();
        app(Tuner::class)->tick();
        $change = TuningChange::sole();
        $this->assertSame('rolled_back', $change->status);
        $this->assertStringContainsString('takas', $change->note);
        $this->assertSame(128, $this->db['innodb_buffer_pool_mb']);
        // The administrators hear about it; the suggestion rests a week.
        $this->assertArrayHasKey('tuning:'.$change->id, app(Notifications::class)->problems());
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->assertSee('Kendiliğinden geri alındı');
        $this->assertNotContains('buffer_pool', array_column(array_filter($this->report()['cards'], fn ($c) => $c['state'] === 'new'), 'key'));

        // A stopped server rolls back too.
        $this->db['innodb_buffer_pool_mb'] = 128;
        $tmp = app(Tuner::class)->apply('mysql', app(Optimization::class)->suggestion('mysql', 'tmp_table'), true, null);
        $this->state = 'failed';
        app(Tuner::class)->tick();
        $this->assertSame('rolled_back', $tmp->fresh()->status);
        $this->assertSame(0, $this->db['tmp_table_mb']);
    }

    public function test_waits_for_the_maintenance_window(): void
    {
        $this->travelTo(now(config('serfex.timezone'))->setTime(14, 0));
        $this->week();
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->call('applyTonight', 'io_capacity')->assertSee('uygulanacak');
        $change = TuningChange::sole();
        $this->assertSame('scheduled', $change->status);
        $this->assertSame('03:00', $change->scheduled_for->setTimezone(config('serfex.timezone'))->format('H:i'));
        app(Tuner::class)->tick();
        $this->assertSame([], $this->agent->called('service.settings.apply'));

        $this->travelTo($change->scheduled_for->copy()->addMinutes(2));
        app(Tuner::class)->tick();
        $this->assertSame('watching', $change->fresh()->status);
        $this->assertSame(2000, $this->db['innodb_io_capacity']);

        // At the window level Apply always waits.
        app(TuningSettings::class)->save(TuningSettings::WINDOW, '03:00', '05:00', 80);
        $this->travelTo(now()->setTime(10, 0));
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->call('confirm', 'slow_log')->assertSee('Bakım penceresinde uygulanacak')->call('apply');
        $this->assertSame('scheduled', TuningChange::where('key', 'slow_log')->sole()->status);

        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->call('cancel', TuningChange::where('key', 'slow_log')->sole()->id);
        $this->assertSame('cancelled', TuningChange::where('key', 'slow_log')->sole()->status);
    }

    public function test_dismissed_and_locked_suggestions_are_not_offered(): void
    {
        $this->week();
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->call('dismiss', 'io_capacity');
        $this->assertNotContains('io_capacity', array_column($this->report()['cards'], 'key'));

        // Changing a value by hand locks it.
        Livewire::withQueryParams(['tab' => 'settings'])->test(ServiceShow::class, ['unit' => 'mysql'])
            ->set('settings.innodb_buffer_pool_mb', '2048')
            ->call('saveSettings')
            ->assertSee('Kilitli');
        $this->assertTrue(app(TuningSettings::class)->isLocked('mysql', 'buffer_pool'));
        // The settings the page does not show are kept.
        $this->assertSame(0, $this->db['tmp_table_mb']);
        $report = $this->report();
        $this->assertNotContains('buffer_pool', array_column($report['cards'], 'key'));
        $this->assertContains('innodb_buffer_pool_size', array_column($report['fine'], 1));

        Livewire::withQueryParams(['tab' => 'settings'])->test(ServiceShow::class, ['unit' => 'mysql'])->call('unlockTuning', 'buffer_pool');
        $this->assertFalse(app(TuningSettings::class)->isLocked('mysql', 'buffer_pool'));
    }

    public function test_database_server_page_opens_on_suggestions_and_the_overview_saves_settings(): void
    {
        $this->week();
        Livewire::test(ServiceShow::class, ['unit' => 'mysql'])->assertSet('tab', 'recs')->assertSee('Öneriler');
        $this->get('/services?tab=optimization')->assertOk()->assertSee('Bellek bütçesi')->assertSee('4 öneri');

        Livewire::test(OptimizationOverview::class)
            ->set('level', TuningSettings::OFF)->set('windowStart', '02:00')->set('windowEnd', '04:30')->set('memoryPercent', '95')
            ->call('save')->assertHasErrors('memoryPercent')
            ->set('memoryPercent', '70')->call('save')->assertHasNoErrors();
        $this->assertSame(TuningSettings::OFF, app(TuningSettings::class)->level());
        $this->assertSame(['02:00', '04:30'], app(TuningSettings::class)->window());
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])->assertSee('Optimizasyon kapalı');
        $this->assertSame([], app(Optimization::class)->measureAll());
    }

    public function test_measures_every_hour_and_prunes(): void
    {
        TuningSample::create(['unit' => 'mysql', 'data' => $this->sample(0), 'created_at' => now()->subDays(9)]);
        $this->artisan('serfex:tuning-measure')->assertSuccessful();
        $this->assertSame(1, TuningSample::count());
        $this->assertTrue(TuningSample::sole()->created_at->isToday());
    }

    public function test_slow_statements_are_tied_to_sites_and_shown_to_their_customer(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'roifex.com', 'status' => 'active']);
        Database::factory()->forSite($site)->create(['name' => 'roifex_laravel']);
        $this->week();
        TuningSample::create(['unit' => 'mysql', 'data' => $this->sample(144), 'created_at' => now()->subDay()]);

        $rows = app(Optimization::class)->slowRows('mysql')['rows'];
        // Most time first; the quick lookup by id is not listed.
        $this->assertSame(['aaa', 'bbb'], array_column($rows, 'digest'));
        $this->assertSame('roifex.com', $rows[0]['site']);
        $this->assertNull($rows[1]['site']);
        $this->assertSame(1717 * 24, $rows[0]['per_day']);
        $this->assertSame(179965, $rows[0]['examined']);

        $this->actingAs($customer->user);
        $this->get("/sites/{$site->id}/databases")->assertOk()
            ->assertSee('Yavaş sorgular')
            ->assertSee('`orders`', false)
            ->assertDontSee('wp_options');
    }

    public function test_serfex_ai_explains_and_may_pick_a_value_in_the_safe_range(): void
    {
        $this->week();
        app(AiSettings::class)->setApiKey('sk-ant-test');
        $answer = json_encode([
            'suggestions' => [
                'buffer_pool' => ['value' => 5120, 'text' => 'Veri hızla büyüyor; 5 GB önerilir.'],
                'tmp_table' => ['value' => 100000, 'text' => 'Raporlar diske yazıyor.'],
                'unknown' => ['value' => 1, 'text' => 'x'],
            ],
            'queries' => ['aaa' => ['text' => 'status sütununda index yok.', 'code' => "Schema::table('orders', fn (\$t) => \$t->index(['status', 'created_at']));"], 'zzz' => ['text' => 'x']],
        ]);
        $this->app->instance(ClaudeClient::class, new class(app(AiSettings::class), $answer) extends ClaudeClient
        {
            public int $calls = 0;

            public function __construct(AiSettings $settings, private readonly string $answer)
            {
                parent::__construct($settings);
            }

            public function send(string $model, array $system, array $tools, array $messages): array
            {
                $this->calls++;
                TuningTest::assertStringContainsString('safe_range', $messages[0]['content'][0]['text']);
                TuningTest::assertStringContainsString('`orders`', $messages[0]['content'][0]['text']);

                return ['content' => [['type' => 'text', 'text' => "Here:\n".$this->answer]], 'stop_reason' => 'end_turn', 'model' => $model,
                    'usage' => ['input' => 1200, 'output' => 300, 'cache_read' => 0, 'cache_write' => 0]];
            }
        });
        $this->assertTrue(app(Optimization::class)->refreshAi('mysql'));
        $this->assertSame(1, AiUsage::count());
        // Nothing changed: it is not asked again.
        $this->assertFalse(app(Optimization::class)->refreshAi('mysql'));
        $this->assertSame(1, app(ClaudeClient::class)->calls);

        $cards = collect($this->report()['cards'])->keyBy('key');
        $this->assertSame(['innodb_buffer_pool_mb' => 5120], $cards['buffer_pool']['values']);
        $this->assertSame('Veri hızla büyüyor; 5 GB önerilir.', $cards['buffer_pool']['why_ai']);
        // A value outside the safe range is ignored.
        $this->assertSame(['tmp_table_mb' => 64], $cards['tmp_table']['values']);
        Livewire::test(DatabaseTuning::class, ['unit' => 'mysql'])
            ->assertSee('Veri hızla büyüyor')
            ->assertSee('status sütununda index yok.')
            ->assertSee('Migration örneğini göster');
        $this->assertArrayNotHasKey('zzz', Setting::get('tuning.ai.mysql')['queries']);
    }

    public function test_advisor_suggests_more_connections_when_they_ran_out(): void
    {
        $this->week(['status' => ['Max_used_connections' => '151']]);
        TuningSample::query()->oldest('id')->first()->update(['data' => $this->sample(0, ['status' => ['Connection_errors_max_connections' => '0']])]);
        $latest = app(Samples::class)->latest('mysql');
        $report = app(MysqlAdvisor::class)->analyze($latest, app(Samples::class)->since($latest), new MemoryBudget(32096, 80, ['system' => 2000, 'mysql' => 600, 'php-fpm' => 1200, 'cache' => 300]));
        $conn = collect($report['suggestions'])->firstWhere('key', 'max_connections');
        $this->assertSame(['max_connections' => 250], $conn['values']);
        $this->assertGreaterThan(0, $conn['memory_mb']);
    }
}
