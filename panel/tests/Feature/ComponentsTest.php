<?php

namespace Tests\Feature;

use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Jobs\RemoveLaravelApp;
use App\Jobs\RunComponentOperation;
use App\Livewire\Components\ComponentIndex;
use App\Livewire\Laravel\Sections\Environment;
use App\Livewire\Sites\SiteIndex;
use App\Livewire\Sites\SitePhp;
use App\Models\ComponentOperation;
use App\Models\Customer;
use App\Models\Database;
use App\Models\LaravelApp;
use App\Models\LaravelProcess;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use App\Services\Customers\Customers;
use App\Services\Dns\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Yazılım Yönetimi: listing, installing with live progress, refusing to
 * remove what is in use, database servers, ModSecurity settings; a site's
 * PHP version and web server (Nginx or Nginx + Apache); Redis for Laravel.
 */
class ComponentsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, array<string, mixed>> component.list by id */
    private array $components = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->components = [
            'nginx' => ['id' => 'nginx', 'installed' => true, 'version' => '1.24.0-2ubuntu7', 'latest' => '1.24.0-2ubuntu7.1', 'running' => true, 'units' => ['nginx'], 'required' => 'Servik serves every site with Nginx'],
            'apache' => ['id' => 'apache', 'installed' => false, 'running' => false],
            'php8.4' => ['id' => 'php8.4', 'installed' => true, 'version' => '8.4.13', 'running' => true, 'units' => ['php8.4-fpm'], 'used_by' => ['webmail (Roundcube)']],
            'php8.3' => ['id' => 'php8.3', 'installed' => true, 'version' => '8.3.6', 'running' => true, 'units' => ['php8.3-fpm']],
            'mysql' => ['id' => 'mysql', 'installed' => true, 'version' => '8.0.43', 'running' => true, 'units' => ['mysql']],
            'mariadb' => ['id' => 'mariadb', 'installed' => false, 'running' => false],
            'postgresql' => ['id' => 'postgresql', 'installed' => false, 'running' => false],
            'redis' => ['id' => 'redis', 'installed' => true, 'version' => '7.0.15', 'running' => true],
            'memcached' => ['id' => 'memcached', 'installed' => false, 'running' => false],
            'node24' => ['id' => 'node24', 'installed' => true, 'version' => '24.10.0', 'running' => true, 'default' => true],
            'composer' => ['id' => 'composer', 'installed' => true, 'version' => '2.8.12', 'running' => true],
            'modsecurity' => ['id' => 'modsecurity', 'installed' => true, 'version' => '3.3.5-2', 'running' => true, 'settings' => ['mode' => 'detect', 'paranoia' => 1]],
        ];
        $this->agent = new FakeAgent([
            'component.list' => fn () => ['components' => array_values($this->components), 'checked_at' => now()->subHours(3)->toIso8601String()],
            'component.install' => ['action' => 'install', 'status' => []],
            'component.run.read' => ['data' => "▸ Paketler kuruluyor: apache2\n$ apt-get install apache2\n✓ Kuruldu\n", 'offset' => 60, 'size' => 60, 'done' => true],
            'component.check' => ['components' => []],
            'component.configure' => [],
            'php.versions' => ['8.3', '8.4'],
            'site.apply' => ['document_root' => '/home/kahve/web/kahveatolyesi.com.tr/public_html'],
            'db.status' => ['servers' => ['mysql' => ['installed' => true, 'product' => 'mysql', 'running' => true]], 'manager' => ['configured' => true]],
            'redis.apply' => ['unit' => 'servik-redis-kahveatolyesi.com.tr.service'],
            'redis.remove' => ['removed' => true],
            'laravel.env.get' => ['env' => "APP_NAME=Kahve\nCACHE_STORE=database\n", 'example' => null],
            'laravel.env.set' => ['cached' => true],
            'laravel.queue.apply' => [],
            'laravel.schedule.apply' => [],
            'laravel.process.apply' => [],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    private function site(array $attributes = []): Site
    {
        $site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr', 'php_version' => '8.4', 'status' => 'active', ...$attributes]);
        $site->systemUser->update(['name' => 'kahve']);

        return $site;
    }

    public function test_lists_components_by_tab_with_status_and_usage(): void
    {
        $this->site();

        $this->get('/yazilim')->assertOk()
            ->assertSee('Yazılım Yönetimi')
            ->assertSee('Nginx')
            ->assertSee('Güncelleme var')
            ->assertSee('1.24.0-2ubuntu7.1')
            ->assertSee('Gerekli. Servik tüm sitelerde Nginx kullanır.')
            ->assertDontSee('PHP 8.4')
            ->assertSee('artık Hizmetler sayfasında')
            ->assertSee('Kurulu değil')
            ->assertSee('Yeni siteler için varsayılan')
            ->assertSee('Nginx + Apache + PHP-FPM (Apache kurulu değil)');

        Livewire::test(ComponentIndex::class)->set('tab', 'diger')
            ->assertSee('Redis')
            ->assertSee('Node.js 24 LTS')
            ->assertSee('Derlemelerde kullanılıyor')
            ->assertSee('Yalnızca izleme · seviye 1')
            ->assertSee('SnappyMail')
            ->assertSee('Sonraki sürümde.')
            ->assertSee('E-posta sunucusu kurulunca gelir.')
            // Not known to this agent version.
            ->assertSee('Ajan bu bileşeni tanımıyor');

        Livewire::test(ComponentIndex::class)->set('tab', 'veritabani')
            ->assertSee('MySQL')
            ->assertSee('MySQL kurulu; MariaDB onunla aynı anda kurulamaz.')
            ->assertSee('phpMyAdmin ve Adminer');
    }

    public function test_install_runs_in_the_background_with_its_log(): void
    {
        $page = Livewire::test(ComponentIndex::class)
            ->call('ask', 'apache', 'install')
            ->assertSee('Apache kurulsun mu?')
            ->call('confirm');

        $op = ComponentOperation::sole();
        $this->assertSame(['apache', 'install', 'queued'], [$op->component, $op->action, $op->status]);
        $page->assertSet('operationId', $op->id)->assertSee('Apache kuruluyor');
        Queue::assertPushed(RunComponentOperation::class);

        // A second operation waits for the first.
        $this->assertSame('Başka bir kurulum sürüyor. Bitince yeniden deneyin.', app(Components::class)->start('memcached', 'install', null));

        (new RunComponentOperation($op))->handle($this->agent, app(Components::class));
        $this->assertSame([['id' => 'apache', 'run' => $op->run]], $this->agent->called('component.install'));
        $this->assertSame('succeeded', $op->fresh()->status);

        $chunk = $page->instance()->readRun($op->run, 0);
        $this->assertTrue($chunk['done']);
        $this->assertSame('succeeded', $chunk['status']);
        $this->assertStringContainsString('▸ Paketler kuruluyor', $chunk['data']);
        $this->assertSame([['run' => $op->run, 'offset' => 0]], $this->agent->called('component.run.read'));
        // Another run is not read through this page.
        $this->assertNull($page->instance()->readRun('cnosuchrun', 0));
    }

    public function test_failed_install_reports_the_rollback(): void
    {
        $this->agent->answers['component.install'] = AgentException::rpcError('component.install', -32000, 'apache2ctl configtest: Syntax error');
        $op = app(Components::class)->start('apache', 'install', null);
        (new RunComponentOperation($op))->handle($this->agent, app(Components::class));

        $op->refresh();
        $this->assertSame('failed', $op->status);
        $this->assertSame('apache2ctl configtest: Syntax error', $op->error);
        $chunk = Livewire::test(ComponentIndex::class)->instance()->readRun($op->run, 0);
        $this->assertSame('apache2ctl configtest: Syntax error', $chunk['error']);
        $this->assertTrue($chunk['done']);
    }

    public function test_operation_whose_worker_died_is_closed_from_its_run_log(): void
    {
        // The install finished on the server, but a package upgrade of
        // that very install restarted the worker that waited for it.
        $this->components['postgresql'] = ['id' => 'postgresql', 'installed' => true, 'version' => '18.1', 'running' => true, 'units' => ['postgresql@18-main']];
        $this->agent->answers['db.status'] = ['servers' => ['mysql' => ['installed' => true, 'product' => 'mysql', 'running' => true], 'postgresql' => ['installed' => true, 'product' => 'postgresql', 'running' => true]], 'manager' => ['configured' => true]];
        $this->agent->answers['component.run.read'] = ['data' => "▸ PostgreSQL kuruluyor ve ayarlanıyor\n▸ Web yöneticisi (phpMyAdmin, Adminer) hazırlanıyor\n✓ Kuruldu\n", 'offset' => 90, 'size' => 90, 'done' => true];
        $op = ComponentOperation::create(['component' => 'postgresql', 'action' => 'install', 'run' => 'cstuckpostgres', 'status' => 'running']);
        $this->travel(5)->minutes();

        $this->assertInstanceOf(ComponentOperation::class, app(Components::class)->start('memcached', 'install', null));
        $op->refresh();
        $this->assertSame(['succeeded', null], [$op->status, $op->error]);
        $this->assertNotNull($op->finished_at);
        $this->assertSame('ready', Setting::get('databases.setup')['state']);
        $this->assertSame([['run' => 'cstuckpostgres', 'offset' => 0]], $this->agent->called('component.run.read'));
    }

    public function test_operation_is_closed_only_once_the_agent_is_done_with_it(): void
    {
        $this->agent->answers['component.run.read'] = ['data' => "▸ Paketler kuruluyor: apache2\n", 'offset' => 30, 'size' => 30, 'done' => false];
        $running = ComponentOperation::create(['component' => 'apache', 'action' => 'install', 'run' => 'capache', 'status' => 'running']);
        $queued = ComponentOperation::create(['component' => 'memcached', 'action' => 'install', 'run' => 'cmemcached']);

        // Just started: the job is about to call the agent.
        app(Components::class)->statuses();
        $this->assertSame('running', $running->fresh()->status);

        // The agent still works on it.
        $this->travel(10)->minutes();
        $this->components['apache']['busy'] = 'install';
        app(Components::class)->statuses();
        $this->assertSame('running', $running->fresh()->status);
        $this->assertSame([], $this->agent->called('component.run.read'));

        // The agent stopped without finishing the log; the queued job
        // never started.
        unset($this->components['apache']['busy']);
        $this->travel(1)->hours();
        app(Components::class)->statuses();
        $this->assertSame(['failed', 'İşlem yarıda kaldı.'], [$running->fresh()->status, $running->fresh()->error]);
        $this->assertSame('failed', $queued->fresh()->status);
    }

    public function test_job_that_dies_after_the_agent_finished_records_the_outcome(): void
    {
        $op = app(Components::class)->start('apache', 'install', null);
        $op->update(['status' => 'running']);
        (new RunComponentOperation($op))->failed(new \RuntimeException('has been attempted too many times.'));
        $this->assertSame('succeeded', $op->fresh()->status);

        $op = ComponentOperation::create(['component' => 'redis', 'action' => 'update', 'run' => 'credis', 'status' => 'running']);
        $this->agent->answers['component.run.read'] = ['data' => "▸ Yeni sürüm kuruluyor\n✗ apt-get install failed: exit status 100\n", 'offset' => 60, 'size' => 60, 'done' => true];
        (new RunComponentOperation($op))->failed(new \RuntimeException('has been attempted too many times.'));
        $this->assertSame(['failed', 'apt-get install failed: exit status 100'], [$op->fresh()->status, $op->fresh()->error]);
    }

    public function test_component_in_use_cannot_be_removed(): void
    {
        $this->components['apache'] = ['id' => 'apache', 'installed' => true, 'version' => '2.4.58', 'running' => true, 'units' => ['apache2']];
        $this->site(['web_server' => 'apache']);

        Livewire::test(ComponentIndex::class)
            ->assertSee('Kullanılıyor: kahveatolyesi.com.tr. Önce bu siteleri PHP ve Web Sunucusu sayfasından Nginx + PHP-FPM\'e alın.')
            ->call('ask', 'apache', 'remove')
            ->call('confirm')
            ->assertSet('refused', 'Kullanılıyor: kahveatolyesi.com.tr. Önce bu siteleri PHP ve Web Sunucusu sayfasından Nginx + PHP-FPM\'e alın.');
        $this->assertSame(0, ComponentOperation::count());

        // PHP versions: sites and what the agent reports.
        $this->assertStringStartsWith('Kullanılıyor: kahveatolyesi.com.tr, webmail (Roundcube).', app(Components::class)->removalRefusal('php8.4'));
        $this->assertNull(app(Components::class)->removalRefusal('php8.3'));
        $this->assertSame('Gerekli. Servik tüm sitelerde Nginx kullanır.', app(Components::class)->removalRefusal('nginx'));

        // Database servers: their databases.
        Setting::put('databases.products', ['mysql' => 'mysql']);
        Database::factory()->create(['server' => 'mysql', 'name' => 'kahve_wp']);
        $this->assertStringStartsWith('Kullanılıyor: kahve_wp. Önce veritabanlarını', app(Components::class)->removalRefusal('mysql'));

        // An unused one goes.
        $this->assertInstanceOf(ComponentOperation::class, app(Components::class)->start('php8.3', 'remove', null));
    }

    public function test_database_server_install_sets_up_the_web_manager_port(): void
    {
        $this->components['mysql']['installed'] = false;
        Setting::put('databases.manager_port', 8444);
        $op = app(Components::class)->start('postgresql', 'install', null);
        $this->agent->answers['db.status'] = ['servers' => ['mysql' => ['installed' => false], 'postgresql' => ['installed' => true, 'product' => 'postgresql', 'running' => true]], 'manager' => ['configured' => true]];

        (new RunComponentOperation($op))->handle($this->agent, app(Components::class));

        $this->assertSame([['id' => 'postgresql', 'run' => $op->run, 'port' => 8444]], $this->agent->called('component.install'));
        $this->assertSame(['postgresql' => 'postgresql'], Setting::get('databases.products'));
        $this->assertSame('ready', Setting::get('databases.setup')['state']);
    }

    public function test_checks_for_updates_and_configures_modsecurity(): void
    {
        Livewire::test(ComponentIndex::class)
            ->call('check')
            ->assertDispatched('toast')
            ->set('tab', 'diger')
            ->call('openSettings')
            ->assertSet('modsecMode', 'detect')
            ->set('modsecMode', 'block')
            ->set('modsecParanoia', 2)
            ->call('saveSettings')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo']['args'] ?? null) === ['detect', 1]);

        $this->assertSame(1, count($this->agent->called('component.check')));
        $this->assertSame([['id' => 'modsecurity', 'settings' => ['modsecurity_mode' => 'block', 'modsecurity_paranoia' => 2]]], $this->agent->called('component.configure'));
    }

    public function test_default_stack_for_new_sites(): void
    {
        Livewire::test(ComponentIndex::class)
            ->set('defaultStack', 'apache')
            ->call('saveDefault')
            ->assertHasErrors('defaultStack');

        $this->components['apache'] = ['id' => 'apache', 'installed' => true, 'version' => '2.4.58', 'running' => true];
        Livewire::test(ComponentIndex::class)
            ->set('defaultStack', 'apache')
            ->call('saveDefault')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === 'Yeni siteler Nginx + Apache + PHP-FPM ile açılacak.');

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'kahveatolyesi.com.tr')
            ->call('create');
        $site = Site::sole();
        $this->assertSame('apache', $site->web_server);
        $this->assertSame('apache', $site->agentSpec()['web_server']);
        Queue::assertPushed(ProvisionSite::class);
    }

    public function test_site_switches_php_version_and_web_server(): void
    {
        $site = $this->site();
        $this->assertArrayNotHasKey('web_server', $site->agentSpec());

        // Apache is offered once installed.
        $this->get('/sites/'.$site->id.'/php')->assertOk()->assertSee('PHP ve Web Sunucusu')->assertSee('Sunucuda Apache kurulu değil.');
        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->set('webServer', 'apache')
            ->call('save')
            ->assertHasErrors('webServer');

        $this->components['apache'] = ['id' => 'apache', 'installed' => true, 'version' => '2.4.58', 'running' => true];
        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->assertSet('versions', ['8.4', '8.3'])
            ->set('phpVersion', '8.3')
            ->set('webServer', 'apache')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['method'] === 'undo' && $p['undo']['args'] === ['8.4', 'nginx', []])
            ->call('undo', '8.4', 'nginx', []);

        $applied = $this->agent->called('site.apply');
        $this->assertSame(['8.3', 'apache'], [$applied[0]['php_version'], $applied[0]['web_server']]);
        $this->assertSame('8.4', $applied[1]['php_version']);
        $this->assertArrayNotHasKey('web_server', $applied[1]);
        $this->assertSame(['8.4', 'nginx'], [$site->fresh()->php_version, $site->fresh()->web_server]);

        // A change the server refuses leaves the site as it was.
        $this->agent->answers['site.apply'] = AgentException::rpcError('site.apply', -32000, 'apache2ctl configtest failed');
        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->set('webServer', 'apache')
            ->call('save')
            ->assertSet('webServer', 'nginx')
            ->assertSee('Değişiklik uygulanamadı, site önceki ayarlarıyla çalışıyor: apache2ctl configtest failed');
        $this->assertSame('nginx', $site->fresh()->web_server);
    }

    public function test_php_change_follows_the_laravel_application(): void
    {
        $site = $this->site();
        LaravelApp::factory()->for($site)->create(['php_requirement' => '^8.4']);

        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->set('phpVersion', '8.3')
            ->call('save')
            ->assertHasErrors('phpVersion');
        $this->assertSame([], $this->agent->called('site.apply'));

        LaravelApp::query()->update(['php_requirement' => '^8.3']);
        Livewire::test(SitePhp::class, ['site' => $site->id])->set('phpVersion', '8.3')->call('save')->assertHasNoErrors();
        // Workers and the scheduler run the new PHP.
        $this->assertSame('8.3', $this->agent->called('laravel.queue.apply')[0]['php_version']);
    }

    public function test_customer_gets_the_package_php_versions(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'kahve']);
        $customer->package->update(['php_versions' => ['8.4']]);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['php_version' => '8.4', 'status' => 'active']);
        $other = Site::factory()->create();
        $this->actingAs($customer->user);

        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->assertSet('versions', ['8.4'])
            ->set('phpVersion', '8.3')
            ->call('save')
            ->assertHasErrors('phpVersion');
        $this->get('/sites/'.$other->id.'/php')->assertNotFound();
        $this->get('/sites/'.$site->id)->assertOk()->assertSee('/sites/'.$site->id.'/php');
    }

    public function test_laravel_application_gets_its_own_redis(): void
    {
        $site = $this->site();
        $app = LaravelApp::factory()->for($site)->create();
        LaravelProcess::create(['laravel_app_id' => $app->id, 'kind' => 'octane', 'name' => 'Octane', 'port' => 20000, 'enabled' => true]);
        app(Components::class)->statuses();
        $this->agent->answers['laravel.env.get'] = fn () => ['env' => end($this->agent->answers['_env']) ?: "APP_NAME=Kahve\nCACHE_STORE=database\n", 'example' => null];
        $this->agent->answers['_env'] = [];
        $this->agent->answers['laravel.env.set'] = function ($p) {
            $this->agent->answers['_env'][] = $p['content'];

            return ['cached' => true];
        };

        Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])
            ->assertSee('Redis bağla')
            ->call('connectRedis')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo']['method'] ?? null) === 'undoConnectRedis');

        $app->refresh();
        $this->assertSame(20001, $app->redis_port);
        $applied = $this->agent->called('redis.apply')[0];
        $this->assertSame(['kahveatolyesi.com.tr', 'kahve', 20001], [$applied['domain'], $applied['user'], $applied['port']]);
        $this->assertSame($app->redis_password, $applied['password']);
        $env = $this->agent->called('laravel.env.set')[0]['content'];
        $this->assertStringContainsString("CACHE_STORE=redis\n", $env);
        $this->assertStringContainsString("QUEUE_CONNECTION=redis\n", $env);
        $this->assertStringContainsString("REDIS_PORT=20001\n", $env);
        $this->assertStringContainsString('REDIS_PASSWORD='.$app->redis_password."\n", $env);
        // The next process gets another port.
        $this->assertSame(20002, LaravelProcess::freePort());

        Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])
            ->assertSee('127.0.0.1:20001')
            ->call('undoConnectRedis');
        $this->assertNull($app->fresh()->redis_port);
        $this->assertSame([['domain' => 'kahveatolyesi.com.tr']], $this->agent->called('redis.remove'));
        $this->assertStringContainsString("CACHE_STORE=database\n", $this->agent->called('laravel.env.set')[1]['content']);
    }

    public function test_redis_goes_with_the_application_and_the_site(): void
    {
        $site = $this->site();
        $app = LaravelApp::factory()->for($site)->create(['redis_port' => 20005, 'redis_password' => str_repeat('a', 40)]);

        (new RemoveLaravelApp($app))->handle($this->agent);
        $this->assertSame([['domain' => 'kahveatolyesi.com.tr']], $this->agent->called('redis.remove'));

        $other = Site::factory()->create(['domain' => 'klinikmavi.com']);
        LaravelApp::factory()->for($other)->create(['redis_port' => 20006, 'redis_password' => str_repeat('b', 40)]);
        $this->agent->answers['site.delete'] = ['user_removed' => false];
        (new DeleteSite($other))->handle($this->agent, app(Zones::class));
        $this->assertSame('klinikmavi.com', $this->agent->called('redis.remove')[1]['domain']);
        // Before the site's account may go.
        $methods = $this->agent->methods();
        $this->assertLessThan(array_search('site.delete', $methods, true), array_keys($methods, 'redis.remove', true)[1]);
    }
}
