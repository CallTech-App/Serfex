<?php

namespace Tests\Feature;

use App\Jobs\ApplySitePhp;
use App\Jobs\RunComponentOperation;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Services\PhpVersionShow;
use App\Livewire\Services\ServiceIndex;
use App\Livewire\Services\ServiceShow;
use App\Livewire\Sites\SitePhp;
use App\Models\AiConversation;
use App\Models\ComponentOperation;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Ai\Tools;
use App\Services\Customers\Customers;
use App\Services\Php\PhpVersions;
use App\Services\System\SystemServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Hizmetler: system services with their state, actions, logs and
 * settings; PHP versions with their php.ini defaults and extensions; a
 * site's own PHP settings and PHP-FPM pool within its package.
 */
class ServicesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, array<string, mixed>> service.list by unit */
    private array $services = [];

    /** @var array<string, string> php.info values of 8.4 */
    private array $ini = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $since = now()->subDays(3)->subHours(4)->toIso8601String();
        $row = fn (string $unit, string $name, string $group, array $more = []) => [
            'unit' => $unit, 'name' => $name, 'group' => $group, 'state' => 'active', 'sub_state' => 'running', 'enabled' => 'enabled',
            'since' => $since, 'pid' => 812, 'restarts' => 0, 'can_reload' => true, 'cpu' => 1.5, 'memory' => 48 * 1024 * 1024, ...$more,
        ];
        $this->services = [
            'nginx' => $row('nginx', 'Nginx', 'hosting', ['settings' => 'nginx']),
            'php8.4-fpm' => $row('php8.4-fpm', 'PHP 8.4 FPM', 'hosting'),
            'mysql' => $row('mysql', 'MySQL', 'hosting', ['can_reload' => false, 'settings' => 'database']),
            'memcached' => $row('memcached', 'Memcached', 'hosting', ['state' => 'inactive', 'sub_state' => 'dead', 'enabled' => 'disabled', 'cpu' => 0, 'memory' => 0]),
            'fail2ban' => $row('fail2ban', 'Fail2ban', 'system', ['state' => 'failed', 'sub_state' => 'failed', 'cpu' => 0, 'memory' => 0]),
            'ssh' => $row('ssh', 'SSH', 'system', ['protected' => 'x']),
            'servik-agent' => $row('servik-agent', 'Servik Agent', 'servik', ['protected' => 'x']),
        ];
        $this->ini = [
            'memory_limit' => '256M', 'upload_max_filesize' => '64M', 'post_max_size' => '64M', 'max_execution_time' => '60',
            'max_input_time' => '60', 'max_input_vars' => '3000', 'max_file_uploads' => '20', 'display_errors' => 'Off',
            'allow_url_fopen' => 'On', 'date.timezone' => 'Europe/Istanbul', 'session.gc_maxlifetime' => '1440', 'opcache.enable' => '1',
            'opcache.memory_consumption' => '128', 'opcache.interned_strings_buffer' => '16', 'opcache.max_accelerated_files' => '20000',
            'opcache.validate_timestamps' => '1', 'opcache.revalidate_freq' => '2',
        ];
        $info = fn () => [
            'version' => '8.4', 'values' => $this->ini, 'managed' => true, 'defaults' => [...$this->ini, 'memory_limit' => '256M'],
            'extensions' => [
                ['name' => 'apcu', 'enabled' => false, 'optional' => true, 'installed' => false],
                ['name' => 'intl', 'enabled' => true, 'installed' => true],
                ['name' => 'redis', 'enabled' => true, 'optional' => true, 'installed' => true],
            ],
        ];
        $this->agent = new FakeAgent([
            'service.list' => fn () => ['services' => array_values($this->services)],
            'service.action' => fn ($p) => $this->act($p['unit'], $p['action']),
            'service.logs' => ['lines' => ['2026-10-19T10:00:01+0300 web nginx[812]: started', '2026-10-19T10:00:02+0300 web nginx[812]: ready']],
            'service.settings' => ['unit' => 'nginx', 'nginx' => ['worker_processes' => 'auto', 'worker_connections' => 768, 'client_max_body_mb' => 64, 'keepalive_timeout' => 65]],
            'service.settings.apply' => fn ($p) => ['unit' => $p['unit'], 'nginx' => $p['nginx'] ?? null],
            'component.list' => ['components' => [
                ['id' => 'nginx', 'installed' => true, 'version' => '1.24.0', 'running' => true, 'units' => ['nginx'], 'required' => 'x'],
                ['id' => 'php8.5', 'installed' => false, 'running' => false],
                ['id' => 'php8.4', 'installed' => true, 'version' => '8.4.13', 'running' => true, 'units' => ['php8.4-fpm']],
                ['id' => 'php8.3', 'installed' => true, 'version' => '8.3.6', 'running' => true, 'units' => ['php8.3-fpm']],
            ], 'checked_at' => now()->toIso8601String()],
            'php.versions' => ['8.3', '8.4'],
            'php.info' => $info,
            'php.settings.apply' => function ($p) use ($info) {
                $this->ini = $p['values'];

                return $info();
            },
            'php.extension.set' => $info,
            'php.restart' => ['stopped' => 3],
            'site.apply' => ['document_root' => '/home/kahve/web/kahveatolyesi.com.tr/public_html'],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function act(string $unit, string $action): array
    {
        $this->services[$unit]['state'] = $action === 'stop' ? 'inactive' : 'active';
        if (in_array($action, ['enable', 'disable'], true)) {
            $this->services[$unit]['state'] = 'active';
            $this->services[$unit]['enabled'] = $action.'d';
        }

        return $this->services[$unit];
    }

    private function site(string $domain = 'kahveatolyesi.com.tr', ?Customer $customer = null): Site
    {
        $attributes = ['domain' => $domain, 'php_version' => '8.4', 'status' => 'active'];
        if ($customer !== null) {
            return Site::factory()->for(app(Customers::class)->account($customer))->create($attributes);
        }

        return Site::factory()->create($attributes);
    }

    public function test_lists_services_by_group_and_remembers_problems(): void
    {
        $this->site();

        $this->get('/hizmetler')->assertOk()
            ->assertSee('Hizmetler')
            ->assertSee('Barındırma hizmetleri')
            ->assertSee('Servik&#039;in kendi hizmetleri', false)
            ->assertSee('Nginx')
            ->assertSee('php8.4-fpm')
            ->assertSee('Çalışıyor')
            ->assertSee('Hata verdi')
            ->assertSee('Kapalı')
            ->assertSee('3 gün 4 saat')
            ->assertSee('48 MB');

        // A failed service counts; one switched off on purpose does not.
        $this->assertSame(['fail2ban'], array_column(SystemServices::problems(), 'unit'));
        $this->get('/')->assertOk()->assertSee('Fail2ban çalışmıyor');
    }

    public function test_stopping_asks_first_and_can_be_undone(): void
    {
        $this->site();

        Livewire::test(ServiceIndex::class)
            ->call('act', 'nginx', 'stop')
            ->assertSet('confirming', ['unit' => 'nginx', 'action' => 'stop'])
            ->assertSee('Nginx durdurulsun mu?')
            ->assertSee('Tüm siteler (1 site) yanıt vermez.')
            ->call('confirm')
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === 'Nginx durduruldu.' && $p['undo']['args'] === ['nginx', 'start'])
            ->call('undo', 'nginx', 'start');

        $this->assertSame([['unit' => 'nginx', 'action' => 'stop'], ['unit' => 'nginx', 'action' => 'start']], $this->agent->called('service.action'));

        // Restarting something no customer uses runs at once.
        Livewire::test(ServiceIndex::class)->call('act', 'fail2ban', 'restart')->assertSet('confirming', null);
        $this->assertSame('restart', $this->agent->called('service.action')[2]['action']);
    }

    public function test_the_agents_refusal_is_shown(): void
    {
        $this->agent->answers['service.action'] = AgentException::rpcError('service.action', -32602, 'configuration test failed, Fail2ban was left as it is: nginx: [emerg] unknown directive');

        Livewire::test(ServiceIndex::class)
            ->call('act', 'fail2ban', 'restart')
            ->assertSee('Ayar dosyası denetimden geçmedi; hizmete dokunulmadı. Hata: nginx: [emerg] unknown directive');
    }

    public function test_service_page_shows_logs_toggles_boot_and_changes_settings(): void
    {
        Livewire::test(ServiceShow::class, ['unit' => 'nginx'])
            ->assertSee('nginx.service')
            ->assertSee('nginx[812]: ready')
            ->assertSee('Yeniden yükle')
            ->call('act', 'disable')
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['args'] === ['enable'])
            ->set('tab', 'ayarlar')
            ->assertSee('En büyük istek gövdesi')
            ->set('settings.client_max_body_mb', '0')
            ->call('saveSettings')
            ->assertHasErrors('settings.client_max_body_mb')
            ->set('settings.client_max_body_mb', '256')
            ->set('settings.worker_processes', 'çok')
            ->call('saveSettings')
            ->assertHasErrors('settings.worker_processes')
            ->set('settings.worker_processes', '4')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['method'] === 'undoSettings' && $p['undo']['args'][0]['client_max_body_mb'] === 64)
            ->call('undoSettings', ['worker_processes' => 'auto', 'worker_connections' => 768, 'client_max_body_mb' => 64, 'keepalive_timeout' => 65]);

        $applied = $this->agent->called('service.settings.apply');
        $this->assertSame(['worker_processes' => '4', 'worker_connections' => 768, 'client_max_body_mb' => 256, 'keepalive_timeout' => 65], $applied[0]['nginx']);
        $this->assertSame(64, $applied[1]['nginx']['client_max_body_mb']);

        $this->get('/hizmetler/yok')->assertNotFound();
    }

    public function test_apache_and_powerdns_settings(): void
    {
        $this->services['apache2'] = [...$this->services['nginx'], 'unit' => 'apache2', 'name' => 'Apache', 'settings' => 'apache'];
        $this->services['pdns@servik'] = [...$this->services['nginx'], 'unit' => 'pdns@servik', 'name' => 'PowerDNS (DNS sunucusu)', 'can_reload' => false, 'settings' => 'powerdns'];
        $settings = [
            'apache2' => ['max_request_workers' => 150, 'timeout' => 300],
            'pdns@servik' => ['cache_ttl' => 20, 'negquery_cache_ttl' => 60, 'log_queries' => false, 'axfr_allow' => []],
        ];
        $this->agent->answers['service.settings'] = fn ($p) => ['unit' => $p['unit'], $p['unit'] === 'apache2' ? 'apache' : 'powerdns' => $settings[$p['unit']]];
        $this->agent->answers['service.settings.apply'] = fn ($p) => ['unit' => $p['unit'], ...array_diff_key($p, ['unit' => 1])];

        Livewire::test(ServiceShow::class, ['unit' => 'apache2', 'tab' => 'ayarlar'])
            ->assertSee('Eşzamanlı istek sınırı')
            ->assertSet('settings.max_request_workers', '150')
            ->set('settings.timeout', '600')
            ->call('saveSettings')
            ->assertHasErrors('settings.timeout')
            ->set('settings.timeout', '120')
            ->set('settings.max_request_workers', '400')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['args'][0] === ['max_request_workers' => 150, 'timeout' => 300]);
        $this->assertSame(['max_request_workers' => 400, 'timeout' => 120], $this->agent->called('service.settings.apply')[0]['apache']);

        Livewire::test(ServiceShow::class, ['unit' => 'pdns@servik', 'tab' => 'ayarlar'])
            ->assertSee('Bölge aktarımı izni')
            ->assertSet('settings.log_queries', false)
            ->assertSet('settings.axfr_allow', '')
            ->set('settings.axfr_allow', "192.0.2.10\nns2.example.net")
            ->call('saveSettings')
            ->assertHasErrors('settings.axfr_allow')
            ->set('settings.axfr_allow', "192.0.2.10\n 2001:db8::/48 ,198.51.100.0/24\n")
            ->set('settings.log_queries', true)
            ->set('settings.cache_ttl', '0')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('settings.axfr_allow', "192.0.2.10\n2001:db8::/48\n198.51.100.0/24")
            ->call('undoSettings', $settings['pdns@servik']);
        $applied = $this->agent->called('service.settings.apply');
        $this->assertSame(['cache_ttl' => 0, 'negquery_cache_ttl' => 60, 'log_queries' => true, 'axfr_allow' => ['192.0.2.10', '2001:db8::/48', '198.51.100.0/24']], $applied[1]['powerdns']);
        $this->assertSame($settings['pdns@servik'], $applied[2]['powerdns']);

        // Without Servik's Apache configuration there is no settings tab.
        $this->services['apache2']['settings'] = null;
        Livewire::test(ServiceShow::class, ['unit' => 'apache2', 'tab' => 'ayarlar'])
            ->assertSet('tab', 'gunluk')
            ->assertDontSee('Eşzamanlı istek sınırı');
    }

    public function test_protected_services_cannot_be_stopped_from_the_page(): void
    {
        $this->get('/hizmetler/servik-agent')->assertOk()
            ->assertSee('Panel sunucuyu onunla yönetir; durdurulamaz.')
            ->assertDontSee('wire:click="act(&#039;stop&#039;)"', false);
    }

    public function test_php_versions_tab_sets_the_default_and_disabled_functions(): void
    {
        $this->site();

        Livewire::test(ServiceIndex::class)
            ->set('tab', 'php')
            ->assertSee('PHP 8.4')
            ->assertSee('Kullanılıyor: kahveatolyesi.com.tr')
            ->assertSee('Kurulu değil')
            ->assertSee('yeni sitelerde')
            ->assertSet('defaultVersion', '8.4')
            ->set('defaultVersion', '8.3')
            ->set('disabledFunctions', 'exec, system, shell_exec')
            ->call('saveDefaults')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['method'] === 'undoDefaults')
            ->set('disabledFunctions', 'exec; rm -rf')
            ->call('saveDefaults')
            ->assertHasErrors('disabledFunctions');

        $this->assertSame('8.3', Setting::get('php.default_version'));
        $this->assertSame(['exec', 'system', 'shell_exec'], app(PhpVersions::class)->disabledFunctions());
        Queue::assertPushed(ApplySitePhp::class, fn ($job) => $job->siteIds === null);

        // Installing a version is a component operation with progress.
        Livewire::test(ServiceIndex::class)
            ->set('tab', 'php')
            ->call('askPhp', 'php8.5', 'install')
            ->assertSee('PHP 8.5 kurulsun mu?')
            ->call('confirm');
        $this->assertSame(['php8.5', 'install'], [ComponentOperation::sole()->component, ComponentOperation::sole()->action]);
        Queue::assertPushed(RunComponentOperation::class);
    }

    public function test_php_updates_are_checked_and_wait_for_the_running_operation(): void
    {
        $list = $this->agent->answers['component.list'];
        $this->agent->answers['component.check'] = function () use ($list) {
            $list['components'][2]['latest'] = '8.4.15';
            $this->agent->answers['component.list'] = $list;

            return $list;
        };

        Livewire::test(ServiceIndex::class)
            ->set('tab', 'php')
            ->assertSee('Son denetim')
            ->assertDontSee('→ 8.4.15')
            ->call('check')
            ->assertDispatched('toast')
            ->assertSee('→ 8.4.15')
            ->assertSee('Güncelleme var')
            ->assertSeeHtml("askPhp('php8.4', 'update')");

        // Another component's install holds every button and says why.
        $op = ComponentOperation::create(['component' => 'postgresql', 'action' => 'install', 'run' => 'cpgsql', 'status' => 'running']);
        Livewire::test(ServiceIndex::class)
            ->set('tab', 'php')
            ->assertSee('PostgreSQL kuruluyor.')
            ->assertSee('Bitene kadar başka kurulum, güncelleme veya kaldırma başlatılamaz.')
            ->assertSeeHtml('wire:click="show('.$op->id.')"')
            ->call('show', $op->id)
            ->assertSet('operationId', $op->id);

        $op->update(['status' => 'failed', 'finished_at' => now()]);
        Livewire::test(ServiceIndex::class)
            ->set('tab', 'php')
            ->assertDontSee('PostgreSQL kuruluyor.')
            ->call('askPhp', 'php8.4', 'update')
            ->assertSee('PHP 8.4 güncellensin mi?')
            ->call('confirm');
        $this->assertSame(['php8.4', 'update'], [ComponentOperation::latest('id')->first()->component, ComponentOperation::latest('id')->first()->action]);
    }

    public function test_php_version_defaults_are_validated_applied_and_undone(): void
    {
        Livewire::test(PhpVersionShow::class, ['version' => '8.4'])
            ->assertSee('php.ini varsayılanları')
            ->assertSee('Komut satırı PHP')
            ->assertSeeHtml('yalnızca web</span>')
            ->assertSet('values.memory_limit', '256M')
            ->set('values.upload_max_filesize', '128M')
            ->call('save')
            ->assertHasErrors('values.post_max_size')
            ->set('values.post_max_size', '128m')
            ->set('values.memory_limit', 'çok')
            ->call('save')
            ->assertHasErrors('values.memory_limit')
            ->set('values.memory_limit', '512M')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['args'][0]['memory_limit'] === '256M')
            ->assertSee('Nginx en fazla 64 MB');

        $applied = $this->agent->called('php.settings.apply')[0];
        $this->assertSame('8.4', $applied['version']);
        $this->assertSame(['512M', '128M', '128M'], [$applied['values']['memory_limit'], $applied['values']['upload_max_filesize'], $applied['values']['post_max_size']]);
        $this->assertSame('512M', Setting::get('php.memory.8.4'));

        $this->get('/hizmetler/php/7.4')->assertNotFound();
    }

    public function test_php_version_settings_with_a_dot_in_their_name_are_editable(): void
    {
        // Livewire reads dots in wire:model as nested arrays.
        Livewire::test(PhpVersionShow::class, ['version' => '8.4'])
            ->assertSeeHtml('wire:model="values.date_timezone"')
            ->set('values.date_timezone', 'Europe/Istanbul')
            ->set('values.opcache_memory_consumption', 'çok')
            ->call('save')
            ->assertHasErrors('values.opcache_memory_consumption')
            ->set('values.opcache_memory_consumption', '256')
            ->call('save')
            ->assertHasNoErrors();

        $applied = $this->agent->called('php.settings.apply')[0]['values'];
        $this->assertSame(['Europe/Istanbul', '256'], [$applied['date.timezone'], $applied['opcache.memory_consumption']]);
        $this->assertArrayNotHasKey('date_timezone', $applied);
    }

    public function test_php_extensions_are_switched_and_installed(): void
    {
        $this->site();

        Livewire::test(PhpVersionShow::class, ['version' => '8.4'])
            ->set('tab', 'eklentiler')
            ->assertSee('intl')
            ->call('toggle', 'intl')
            ->assertSee('intl kapatılsın mı?')
            ->call('confirm')
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['args'] === ['intl', true])
            ->call('askExtension', 'apcu', 'install')
            ->call('confirm');

        $this->assertSame(['version' => '8.4', 'name' => 'intl', 'enabled' => false], $this->agent->called('php.extension.set')[0]);
        $op = ComponentOperation::sole();
        $this->assertSame(['php8.4', 'ext-install', 'apcu'], [$op->component, $op->action, $op->detail]);
    }

    public function test_site_php_settings_go_to_its_pool(): void
    {
        $site = $this->site();

        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->assertSee('PHP-FPM havuzu')
            ->set('ini.memory_limit', '1G')
            ->set('ini.display_errors', 'On')
            ->set('custom', "session.cookie_secure = 1\nopen_basedir = /")
            ->call('save')
            ->assertHasErrors('custom')
            ->set('custom', 'session.cookie_secure = 1')
            ->set('pm', 'dynamic')
            ->set('maxChildren', '30')
            ->set('requestTimeout', '120')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => $p['undo']['args'] === ['8.4', 'nginx', []]);

        $site->refresh();
        $this->assertSame(['custom' => 'session.cookie_secure = 1', 'ini' => ['memory_limit' => '1G', 'display_errors' => 'On'], 'max_children' => 30, 'pm' => 'dynamic', 'request_timeout' => 120], $site->php_settings);
        $php = $this->agent->called('site.apply')[0]['php'];
        $this->assertSame(['memory_limit' => '1G', 'display_errors' => 'On', 'session.cookie_secure' => '1'], $php['values']);
        $this->assertSame(['dynamic', 30, 120], [$php['pm'], $php['max_children'], $php['request_timeout']]);
        // The administrator's own sites keep every function.
        $this->assertArrayNotHasKey('disable_functions', $php);

        Livewire::test(SitePhp::class, ['site' => $site->id])->call('restartFpm')->assertDispatched('toast');
        $this->assertSame([['domain' => 'kahveatolyesi.com.tr']], $this->agent->called('php.restart'));
    }

    public function test_customers_stay_within_their_package(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $customer->package->update(['max_php_memory_mb' => 512]);
        $site = $this->site('adapansiyon.com', $customer);
        $this->actingAs($customer->user);

        // Without settings the site still gets the cap and the disabled functions.
        $spec = $site->fresh()->agentSpec()['php'];
        $this->assertSame(['memory_limit' => '256M'], $spec['admin_values']);
        $this->assertSame(PhpVersions::DANGEROUS, $spec['disable_functions']);

        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->assertDontSee('exec, shell_exec, proc_open')
            ->set('ini.memory_limit', '1G')
            ->set('maxChildren', '50')
            ->set('custom', 'auto_prepend_file = /tmp/x.php')
            ->call('save')
            ->assertHasErrors(['ini.memory_limit', 'maxChildren', 'custom'])
            ->set('ini.memory_limit', '384M')
            ->set('maxChildren', '20')
            ->set('custom', '')
            ->set('allowFunctions', true)
            ->call('save')
            ->assertHasNoErrors();

        $php = $this->agent->called('site.apply')[0]['php'];
        $this->assertSame(['memory_limit' => '384M'], $php['admin_values']);
        $this->assertArrayNotHasKey('values', $php);
        // Only an administrator gives a site the functions.
        $this->assertSame(PhpVersions::DANGEROUS, $php['disable_functions']);

        // A package without PHP settings leaves the form read-only.
        $customer->package->update(['allow_php_settings' => false]);
        Livewire::test(SitePhp::class, ['site' => $site->id])
            ->assertSee('Paketiniz PHP ayarlarını değiştirmeye izin vermiyor')
            ->set('ini.memory_limit', '128M')
            ->call('save');
        $this->assertSame('384M', $site->fresh()->php_settings['ini']['memory_limit']);
    }

    public function test_package_php_limits_are_saved_and_applied_to_its_sites(): void
    {
        $customer = Customer::factory()->create();
        $this->site('adapansiyon.com', $customer);
        $package = $customer->package;

        Livewire::test(CustomerIndex::class)
            ->call('openPackage', $package->id)
            ->assertSet('pkgPhpMemory', '512')
            ->set('pkgPhpMemory', '10')
            ->call('savePackage')
            ->assertHasErrors('pkgPhpMemory')
            ->set('pkgPhpMemory', '1024')
            ->call('savePackage')
            ->assertHasNoErrors();

        $this->assertSame(1024, $package->fresh()->max_php_memory_mb);
        Queue::assertPushed(ApplySitePhp::class, fn ($job) => $job->siteIds === [Site::sole()->id]);
    }

    public function test_apply_job_rewrites_customer_pools_only(): void
    {
        $this->site('yonetici.com');
        $this->site('adapansiyon.com', Customer::factory()->create());

        (new ApplySitePhp)->handle($this->agent);

        $this->assertSame(['adapansiyon.com'], array_column($this->agent->called('site.apply'), 'domain'));
    }

    public function test_check_command_records_problems_and_syncs_pools_once(): void
    {
        $this->artisan('servik:services-check')->assertSuccessful();
        $this->artisan('servik:services-check')->assertSuccessful();

        $this->assertSame(['fail2ban'], array_column(SystemServices::problems(), 'unit'));
        Queue::assertPushed(ApplySitePhp::class, 1);
        $this->get('/hizmetler')->assertSee('1 durmuş hizmet');
    }

    public function test_ai_reads_services_and_a_log(): void
    {
        $this->site();
        $conversation = AiConversation::create(['user_id' => auth()->id(), 'title' => 'Hizmetler']);

        [$json, $error] = app(Tools::class)->run('get_services', ['unit' => 'nginx'], 't1', $conversation);
        $this->assertFalse($error);
        $data = json_decode($json, true);
        $this->assertSame('failed (failed)', collect($data['services'])->firstWhere('unit', 'fail2ban')['state']);
        $this->assertTrue(collect($data['services'])->firstWhere('unit', 'fail2ban')['needs_attention']);
        $this->assertSame('8.4', $data['php']['default_for_new_sites']);
        $this->assertSame(['8.4' => 1], $data['php']['sites_per_version']);
        $this->assertCount(2, $data['log']);

        [, $error] = app(Tools::class)->run('get_services', ['unit' => 'yok'], 't2', $conversation);
        $this->assertTrue($error);
    }

    public function test_customers_cannot_open_services(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $this->actingAs($customer->user);

        $this->get('/hizmetler')->assertForbidden();
        $this->get('/hizmetler/nginx')->assertForbidden();
        $this->get('/hizmetler/php/8.4')->assertForbidden();
    }
}
