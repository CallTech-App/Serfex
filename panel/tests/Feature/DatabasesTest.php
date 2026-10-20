<?php

namespace Tests\Feature;

use App\Jobs\DeleteSite;
use App\Jobs\PurgeDatabase;
use App\Jobs\PurgeDatabaseUser;
use App\Jobs\SetupDatabaseServers;
use App\Jobs\SyncDatabaseUsers;
use App\Livewire\Databases\DatabaseIndex;
use App\Livewire\Databases\DatabaseSettingsPage;
use App\Livewire\Databases\DatabaseShow;
use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\Sections\Environment;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\LaravelApp;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use App\Services\Dns\Zones;
use App\Support\DatabaseHosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Customer databases: setting the servers up, databases and users with
 * their access, remote hosts, deleting with Undo, one-click phpMyAdmin,
 * quotas, customers' view and Laravel.
 */
class DatabasesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'db.status' => self::dbStatus(),
            'db.database.create' => ['created' => true],
            'db.user.apply' => ['applied' => true],
            'db.database.delete' => fn ($p) => ['deleted' => true, 'backup' => $p['backup'] ? '/var/lib/servik/backups/databases/'.$p['name'].'.sql.gz' : null],
            'db.manager.login' => ['path' => '/phpmyadmin/servik-sso.php?token=abc', 'port' => 8443, 'tool' => 'phpmyadmin'],
            'php.versions' => ['8.4'],
            'laravel.env.get' => ['env' => "APP_NAME=x\nDB_CONNECTION=sqlite\n", 'example' => null],
            'laravel.env.set' => ['cached' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        $this->site->systemUser->update(['name' => 'kahve']);
        Setting::put('databases.products', ['mysql' => 'mysql']);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private static function dbStatus(bool $mysql = true, bool $postgres = false, bool $manager = true): array
    {
        return [
            'servers' => [
                'mysql' => $mysql ? ['installed' => true, 'product' => 'mysql', 'running' => true, 'version' => '8.0.46-0ubuntu0.24.04.4', 'remote' => false] : ['installed' => false],
                'postgresql' => $postgres ? ['installed' => true, 'product' => 'postgresql', 'running' => true, 'version' => '16.10', 'remote' => false] : ['installed' => false],
            ],
            'manager' => ['configured' => $manager, 'port' => 8443, 'phpmyadmin' => $manager, 'adminer' => false],
        ];
    }

    private function database(string $suffix = 'shop'): Database
    {
        return Database::factory()->forSite($this->site, $suffix)->create();
    }

    public function test_first_visit_offers_the_setup_and_installs_the_chosen_servers(): void
    {
        $this->agent->answers['db.status'] = self::dbStatus(mysql: false, manager: false);

        Livewire::test(DatabaseIndex::class)
            ->assertSee('Veritabanı sunucusunu kurun')
            ->set('setupProduct', 'mariadb')
            ->set('setupPostgres', true)
            ->call('setupServer')
            ->assertSee('MariaDB kuruluyor');
        Queue::assertPushed(SetupDatabaseServers::class, fn ($job) => $job->products === ['mariadb', 'postgresql']);

        // The job installs each server, then the web manager.
        $this->agent->answers['db.status'] = self::dbStatus(postgres: true);
        (new SetupDatabaseServers(['mariadb', 'postgresql']))->handle($this->agent, app(DatabaseServers::class));
        $this->assertSame([['product' => 'mariadb'], ['product' => 'postgresql']], $this->agent->called('db.server.install'));
        $this->assertSame([['port' => 8443]], $this->agent->called('db.manager.setup'));
        $this->assertSame('ready', app(DatabaseServers::class)->setup()['state']);
    }

    public function test_a_failed_setup_shows_the_error_and_the_choice_again(): void
    {
        $this->agent->answers['db.status'] = self::dbStatus(mysql: false, manager: false);
        $this->agent->answers['db.server.install'] = AgentException::rpcError('db.server.install', -32000, 'apt-get install mysql-server: exit status 100');

        (new SetupDatabaseServers(['mysql']))->handle($this->agent, app(DatabaseServers::class));

        $this->assertSame('failed', Setting::get('databases.setup')['state']);
        $this->assertSame([], $this->agent->called('db.manager.setup'));
        Livewire::test(DatabaseIndex::class)
            ->assertSee('Önceki kurulum tamamlanamadı')
            ->assertSee('exit status 100');
    }

    public function test_creates_a_database_with_its_user_and_shows_the_password_once(): void
    {
        Livewire::test(DatabaseIndex::class)
            ->call('openCreate')
            ->assertSet('formSite', $this->site->id)
            ->assertSee('kahve_')
            ->set('dbSuffix', 'shop')
            ->set('userSuffix', 'app')
            ->set('password', 'Cok-gizli-parola-42')
            ->set('access', 'readwrite')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('creating', false)
            ->assertSee('Cok-gizli-parola-42')
            ->assertSee('DB_DATABASE=kahve_shop');

        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_shop', 'site' => 'kahveatolyesi.com.tr']], $this->agent->called('db.database.create'));
        $this->assertSame([[
            'server' => 'mysql', 'name' => 'kahve_app', 'password' => 'Cok-gizli-parola-42', 'hosts' => [],
            'grants' => [['database' => 'kahve_shop', 'access' => 'readwrite']],
        ]], $this->agent->called('db.user.apply'));
        $database = Database::where('name', 'kahve_shop')->firstOrFail();
        $this->assertSame($this->site->id, $database->site_id);
        $this->assertSame(['kahve_app'], $database->users->pluck('name')->all());
        $this->assertNotContains('Cok-gizli-parola-42', array_map('strval', $database->users->first()->getAttributes()));
    }

    public function test_validates_names_and_refuses_taken_ones(): void
    {
        $this->database('shop');

        Livewire::test(DatabaseIndex::class)
            ->call('openCreate')
            ->set('dbSuffix', 'Shop-1')
            ->set('userSuffix', str_repeat('a', 30))
            ->set('password', 'kisa')
            ->call('create')
            ->assertHasErrors(['dbSuffix' => 'regex', 'userSuffix' => 'max', 'password' => 'min'])
            ->set('dbSuffix', 'shop')
            ->set('userSuffix', 'app')
            ->set('password', 'Yeterince-uzun-parola')
            ->call('create')
            ->assertHasErrors('dbSuffix');
        $this->assertSame([], $this->agent->called('db.database.create'));
    }

    public function test_a_server_failure_keeps_nothing(): void
    {
        $this->agent->answers['db.user.apply'] = AgentException::rpcError('db.user.apply', -32000, 'ERROR 1396');

        Livewire::test(DatabaseIndex::class)
            ->call('openCreate')
            ->set('dbSuffix', 'shop')
            ->set('userSuffix', 'app')
            ->call('create')
            ->assertHasErrors('dbSuffix');

        $this->assertSame(0, Database::withTrashed()->count());
        $this->assertSame(0, DatabaseUser::withTrashed()->count());
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_shop', 'backup' => false]], $this->agent->called('db.database.delete'));
    }

    public function test_edits_a_users_password_databases_and_remote_hosts(): void
    {
        $shop = $this->database('shop');
        $blog = $this->database('blog');
        $user = app(Databases::class)->createUser($this->site->systemUser, 'mysql', 'kahve_app', 'Ilk-parola-uzun-1', [], [$shop->id => 'all']);
        $this->agent->calls = [];

        $page = Livewire::test(DatabaseIndex::class, ['tab' => 'kullanicilar'])
            ->assertSee('kahve_app')
            ->call('openUser', $user->id)
            ->assertSet('editGrants', [$blog->id => '', $shop->id => 'all'])
            ->set('remote', true)
            ->set('hostsText', "78.186.44.12\n10.8.0.%\nexample.com")
            ->call('saveUser')
            ->assertHasErrors('hostsText');
        $this->assertSame([], $this->agent->called('db.user.apply'));

        $page->set('hostsText', "78.186.44.12, 10.8.0.%\n88.247.120.0/28")
            ->set("editGrants.{$blog->id}", 'readonly')
            ->set('changePassword', true)
            ->set('editPassword', 'Yeni-parola-uzun-2')
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSet('userDialog', false)
            ->assertSee('Yeni-parola-uzun-2');

        $call = $this->agent->called('db.user.apply')[0];
        $this->assertSame('Yeni-parola-uzun-2', $call['password']);
        $this->assertSame(['78.186.44.12', '10.8.0.%', '88.247.120.0/28'], $call['hosts']);
        $this->assertEqualsCanonicalizing([['database' => 'kahve_blog', 'access' => 'readonly'], ['database' => 'kahve_shop', 'access' => 'all']], $call['grants']);
        $this->assertSame(['78.186.44.12', '10.8.0.%', '88.247.120.0/28'], $user->fresh()->hosts);
    }

    public function test_a_refused_change_leaves_the_panel_as_it_was(): void
    {
        $shop = $this->database('shop');
        $user = app(Databases::class)->createUser($this->site->systemUser, 'mysql', 'kahve_app', 'Ilk-parola-uzun-1', [], [$shop->id => 'all']);
        $this->agent->answers['db.user.apply'] = AgentException::rpcError('db.user.apply', -32000, 'mysql restart failed');

        Livewire::test(DatabaseIndex::class)
            ->call('openUser', $user->id)
            ->set('remote', true)
            ->set('hostsText', '%')
            ->call('saveUser')
            ->assertHasErrors('hostsText');

        $this->assertSame([], $user->fresh()->hosts);
        $this->assertSame('all', $user->fresh()->databases->first()->pivot->access);
    }

    public function test_deleting_a_database_takes_access_away_at_once_and_can_be_undone(): void
    {
        $shop = $this->database('shop');
        $blog = $this->database('blog');
        app(Databases::class)->createUser($this->site->systemUser, 'mysql', 'kahve_app', 'Ilk-parola-uzun-1', [], [$shop->id => 'all', $blog->id => 'all']);
        $this->agent->calls = [];

        $page = Livewire::test(DatabaseShow::class, ['database' => $shop->id])
            ->call('openDelete')
            ->set('confirmName', 'yanlis')
            ->call('delete')
            ->assertHasErrors('confirmName')
            ->set('confirmName', 'kahve_shop')
            ->call('delete')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo']['method'] ?? null) === 'undoDelete')
            ->assertSee('Bu veritabanı silindi');

        $this->assertSoftDeleted($shop);
        $this->assertSame([['database' => 'kahve_blog', 'access' => 'all']], $this->agent->called('db.user.apply')[0]['grants']);
        $this->assertSame([], $this->agent->called('db.database.delete'), 'the data stays during the undo window');
        Queue::assertPushed(PurgeDatabase::class, fn ($job) => $job->databaseId === $shop->id && $job->delay !== null);

        $this->agent->calls = [];
        $page->call('undoDelete');
        $this->assertNotSoftDeleted($shop);
        $this->assertCount(2, $this->agent->called('db.user.apply')[0]['grants']);

        // After the window, the server drops it with a backup.
        app(Databases::class)->delete($shop->fresh(), backup: true);
        (new PurgeDatabase($shop->id))->handle(app(Databases::class));
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_shop', 'backup' => true]], $this->agent->called('db.database.delete'));
        $this->assertSame(0, Database::withTrashed()->whereKey($shop->id)->count());
    }

    public function test_users_get_added_changed_and_removed_on_the_database_page(): void
    {
        $shop = $this->database('shop');
        $other = app(Databases::class)->createUser($this->site->systemUser, 'mysql', 'kahve_rapor', 'Ilk-parola-uzun-1');
        $this->agent->calls = [];

        $page = Livewire::test(DatabaseShow::class, ['database' => $shop->id])
            ->call('openAdd')
            ->set('newUserSuffix', 'app')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('adding', false)
            ->call('openAdd')
            ->set('addMode', 'existing')
            ->set('existingUser', $other->id)
            ->set('addAccess', 'readonly')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSee('kahve_rapor');
        $this->assertSame('readonly', $shop->users()->whereKey($other->id)->first()->pivot->access);

        $page->call('setAccess', $other->id, 'readwrite');
        $this->assertSame('readwrite', $shop->users()->whereKey($other->id)->first()->pivot->access);
        $page->call('revoke', $other->id)
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo']['method'] ?? null) === 'regrant');
        $this->assertNull($shop->users()->whereKey($other->id)->first());
        $page->call('regrant', $other->id, 'readwrite');
        $this->assertSame('readwrite', $shop->users()->whereKey($other->id)->first()->pivot->access);
    }

    public function test_deleting_a_user_closes_it_and_purges_after_undo(): void
    {
        $shop = $this->database('shop');
        $user = app(Databases::class)->createUser($this->site->systemUser, 'mysql', 'kahve_app', 'Ilk-parola-uzun-1', ['10.8.0.%'], [$shop->id => 'all']);
        $this->agent->calls = [];

        $page = Livewire::test(DatabaseIndex::class, ['tab' => 'kullanicilar'])
            ->call('deleteUser', $user->id)
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo']['method'] ?? null) === 'undoDeleteUser');
        $this->assertSame(['hosts' => [], 'grants' => []], array_intersect_key($this->agent->called('db.user.apply')[0], ['hosts' => 1, 'grants' => 1]));
        Queue::assertPushed(PurgeDatabaseUser::class);

        $page->call('undoDeleteUser', $user->id);
        $this->assertNotSoftDeleted($user);
        $this->assertSame(['10.8.0.%'], $this->agent->called('db.user.apply')[1]['hosts']);

        $user->delete();
        (new PurgeDatabaseUser($user->id))->handle(app(Databases::class));
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_app']], $this->agent->called('db.user.delete'));
        $this->assertSame(0, DatabaseUser::withTrashed()->count());
    }

    public function test_opens_phpmyadmin_with_a_one_time_link_on_the_managers_port(): void
    {
        $shop = $this->database('shop');

        $this->get('http://panel.servik.test/databases/'.$shop->id.'/yonetici')
            ->assertRedirect('https://panel.servik.test:8443/phpmyadmin/servik-sso.php?token=abc');
        $this->assertSame([['server' => 'mysql', 'database' => 'kahve_shop']], $this->agent->called('db.manager.login'));

        $this->agent->answers['db.manager.login'] = AgentException::rpcError('db.manager.login', -32602, 'the database manager is not set up');
        $this->get('/databases/'.$shop->id.'/yonetici')->assertRedirect(route('databases.show', $shop))->assertSessionHas('toast');
    }

    public function test_customers_see_and_manage_only_their_own_databases_within_the_package(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'klinik']);
        $customer->package->update(['max_databases' => 1]);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'klinikmavi.com']);
        $theirs = $this->database('shop');
        $this->actingAs($customer->user);

        $this->get('/databases')->assertOk()->assertDontSee('kahve_shop')->assertDontSee('Veritabanı sunucuları');
        $this->get('/databases/'.$theirs->id)->assertNotFound();
        $this->get('/databases/'.$theirs->id.'/yonetici')->assertNotFound();
        $this->get('/databases/settings')->assertForbidden();
        Livewire::test(DatabaseIndex::class)->call('setupServer')->assertForbidden();

        $page = Livewire::test(DatabaseIndex::class)
            ->call('openCreate')
            ->assertSet('formSite', $mine->id)
            ->set('formSite', $this->site->id)
            ->set('dbSuffix', 'x')
            ->set('userSuffix', 'x')
            ->call('create')
            ->assertHasErrors('formSite');
        $page->set('formSite', $mine->id)->set('dbSuffix', 'randevu')->set('userSuffix', 'app')->call('create')->assertHasNoErrors();
        $this->assertSame('klinik_randevu', Database::where('site_id', $mine->id)->value('name'));

        // One database is the package's limit.
        $page->call('openCreate')->set('dbSuffix', 'ikinci')->set('userSuffix', 'iki')->call('create')
            ->assertHasErrors('dbSuffix')->assertSee('veritabanı sınırına ulaşıldı');
        $this->assertSame('1 / 1', app(Quotas::class)->report($customer->fresh())['databases']['text']);
    }

    public function test_sizes_are_measured_and_count_as_disk(): void
    {
        $customer = Customer::factory()->create(['username' => 'klinik']);
        $account = app(Customers::class)->account($customer);
        $account->update(['disk_bytes' => 100]);
        $site = Site::factory()->for($account)->create();
        $db = Database::factory()->forSite($site, 'app')->create();
        Setting::put('databases.products', ['mysql' => 'mysql']);
        $this->agent->answers['db.list'] = ['databases' => [['name' => $db->name, 'size' => 5000, 'tables' => 12]], 'users' => []];

        $this->artisan('servik:disk-usage')->assertSuccessful();

        $this->assertSame(5000, $db->fresh()->size_bytes);
        $this->assertSame(12, $db->fresh()->tables);
        $this->assertSame(5100, app(Quotas::class)->usage($customer)['disk']);
    }

    public function test_a_suspended_customers_users_may_use_nothing_until_they_come_back(): void
    {
        $customer = Customer::factory()->create(['username' => 'klinik']);
        $account = app(Customers::class)->account($customer);
        $site = Site::factory()->for($account)->create();
        $db = Database::factory()->forSite($site, 'app')->create();
        app(Databases::class)->createUser($account, 'mysql', 'klinik_app', 'Ilk-parola-uzun-1', ['%'], [$db->id => 'all']);
        $this->agent->calls = [];

        app(Customers::class)->suspend($customer);
        Queue::assertPushed(SyncDatabaseUsers::class, fn ($job) => $job->systemUserId === $account->id);
        (new SyncDatabaseUsers($account->id))->handle(app(Databases::class));
        $this->assertSame(['hosts' => [], 'grants' => []], array_intersect_key($this->agent->called('db.user.apply')[0], ['hosts' => 1, 'grants' => 1]));

        app(Customers::class)->unsuspend($customer->fresh());
        (new SyncDatabaseUsers($account->id))->handle(app(Databases::class));
        $this->assertSame(['%'], $this->agent->called('db.user.apply')[1]['hosts']);
    }

    public function test_deleting_a_site_can_take_its_databases_with_it(): void
    {
        $shop = $this->database('shop');
        app(Databases::class)->createUser($this->site->systemUser, 'mysql', 'kahve_app', 'Ilk-parola-uzun-1', [], [$shop->id => 'all']);
        $this->agent->answers['site.delete'] = ['files_removed' => true, 'user_removed' => false];

        (new DeleteSite($this->site, removeDatabases: true))->handle($this->agent, app(Zones::class));

        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_shop', 'backup' => true]], $this->agent->called('db.database.delete'));
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_app']], $this->agent->called('db.user.delete'));
        $this->assertSame(0, Database::withTrashed()->count());
    }

    public function test_the_laravel_wizard_creates_a_database_for_the_application(): void
    {
        Livewire::test(LaravelIndex::class)
            ->call('openInstall')
            ->assertSet('database', 'new')
            ->assertSee('Yeni veritabanı oluştur')
            ->set('siteId', $this->site->id)
            ->call('install')
            ->assertHasNoErrors();

        $app = LaravelApp::where('site_id', $this->site->id)->firstOrFail();
        $database = Database::where('name', 'kahve_laravel')->firstOrFail();
        $this->assertSame($database->id, $app->database_id);
        $env = $app->install_env;
        $this->assertSame(['mysql', '127.0.0.1', '3306', 'kahve_laravel', 'kahve_laravel'], [$env['DB_CONNECTION'], $env['DB_HOST'], $env['DB_PORT'], $env['DB_DATABASE'], $env['DB_USERNAME']]);
        $this->assertSame($env['DB_PASSWORD'], $this->agent->called('db.user.apply')[0]['password']);
        $this->assertSame(24, strlen($env['DB_PASSWORD']));
    }

    public function test_the_environment_section_creates_and_connects_a_database(): void
    {
        $app = LaravelApp::factory()->for($this->site)->create();

        $section = Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])
            ->assertSee('Veritabanı oluştur ve bağla')
            ->call('createDatabase')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo']['method'] ?? null) === 'undoCreateDatabase');

        $content = $this->agent->called('laravel.env.set')[0]['content'];
        $this->assertStringContainsString("DB_CONNECTION=mysql\n", $content);
        $this->assertStringContainsString("DB_DATABASE=kahve_laravel\n", $content);
        $this->assertSame(Database::where('name', 'kahve_laravel')->value('id'), $app->fresh()->database_id);

        $section->call('undoCreateDatabase', $app->fresh()->database_id);
        $this->assertNull($app->fresh()->database_id);
        $this->assertSame(1, Database::onlyTrashed()->count());
    }

    public function test_settings_add_postgresql_and_show_the_panels_port(): void
    {
        Setting::put('databases.products', ['mysql' => 'mysql']);
        Setting::put('databases.manager_port', 9443);

        Livewire::test(DatabaseSettingsPage::class)
            ->assertSee('PostgreSQL\'i kur', false)
            ->assertSee('9443')
            ->assertSee('Araçlar ve Ayarlar → Genel')
            ->call('addPostgres');
        Queue::assertPushed(SetupDatabaseServers::class, fn ($job) => $job->products === ['mysql', 'postgresql']);
    }

    public function test_remote_hosts_are_checked_like_the_agent_does(): void
    {
        foreach (['%', '78.186.44.12', '10.8.0.%', '10.%', '88.247.120.0/28', '2001:db8::1'] as $ok) {
            $this->assertTrue(DatabaseHosts::valid($ok), $ok);
        }
        foreach (['example.com', '10.8.0.1/24', '10.8.0.0/33', '300.1.1.%', '1.2.3.4.%', 'fe80::1%eth0', ''] as $bad) {
            $this->assertFalse(DatabaseHosts::valid($bad), $bad);
        }
        $this->assertSame(['a', 'b', 'c'], DatabaseHosts::parse(" a, b\nc;a "));
    }
}
