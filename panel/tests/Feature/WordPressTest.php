<?php

namespace Tests\Feature;

use App\Enums\WordPressOperationStatus;
use App\Jobs\RunWordPressOperation;
use App\Livewire\Sites\SiteWordPress;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Database;
use App\Models\LaravelApp;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Models\WordPressInstall;
use App\Models\WordPressOperation;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Mail\MailSettings;
use App\Services\Sites\WordPressSites;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * WordPress araç seti: one WordPress per site, installed by Serfex into
 * the web root or found on the site, kept up to date with a restore point
 * before every update, and signed in to, put in maintenance and hardened
 * from the site page.
 */
class WordPressTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'db.database.create' => ['created' => true],
            'db.user.apply' => ['applied' => true],
            'db.database.delete' => ['deleted' => true, 'backup' => null],
            'db.user.delete' => ['deleted' => true],
            'wordpress.scan' => [],
            'wordpress.status' => self::wpStatus(),
            'wordpress.install' => ['version' => '6.8.3'],
            'wordpress.update' => ['point' => 'wp-update-x', 'changes' => [['kind' => 'plugin', 'name' => 'akismet', 'from' => '5.5', 'to' => '5.6']], 'rolled_back' => false, 'problem' => ''],
            'wordpress.restore' => ['restored' => true],
            'wordpress.login' => ['url' => 'https://kahveatolyesi.com.tr/wp-login.php?serfex_login='.str_repeat('a', 64)],
            'wordpress.maintenance' => ['maintenance' => true],
            'wordpress.item' => fn ($p) => ['name' => $p['name'], 'title' => ucfirst($p['name'])],
            'wordpress.autoupdate' => ['auto_update_core' => 'all'],
            'wordpress.harden' => fn ($p) => ['hardening' => $p['rules']],
            'wordpress.detach' => ['detached' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        $this->site->systemUser->update(['name' => 'kahve']);
        Setting::put('databases.products', ['mysql' => 'mysql']);
        $this->actingAs(User::factory()->create());
    }

    /**
     * A wordpress.status answer.
     *
     * @return array<string, mixed>
     */
    private static function wpStatus(array $overrides = []): array
    {
        return $overrides + [
            'version' => '6.8.3',
            'core_update' => '6.9',
            'site_url' => 'https://kahveatolyesi.com.tr',
            'home_url' => 'https://kahveatolyesi.com.tr',
            'plugins' => [
                ['name' => 'akismet', 'title' => 'Akismet Anti-spam', 'version' => '5.5', 'update' => '5.6', 'status' => 'inactive', 'auto_update' => false],
                ['name' => 'hello', 'title' => 'Hello Dolly', 'version' => '1.7.2', 'update' => '', 'status' => 'active', 'auto_update' => false],
            ],
            'themes' => [
                ['name' => 'twentytwentyfive', 'title' => 'Twenty Twenty-Five', 'version' => '1.3', 'update' => '', 'status' => 'active', 'auto_update' => false],
                ['name' => 'twentytwentyfour', 'title' => 'Twenty Twenty-Four', 'version' => '1.3', 'update' => '1.4', 'status' => 'inactive', 'auto_update' => false],
            ],
            'maintenance' => false,
            'admins' => ['kahve'],
            'hardening' => ['file_edit' => false, 'config_perms' => false, 'uploads_php' => false, 'sensitive_files' => false, 'xmlrpc' => false],
            'auto_update_core' => 'minor',
            'database' => 'kahve_wp',
            'problem' => '',
        ];
    }

    private function managed(): WordPressInstall
    {
        $install = app(WordPressSites::class)->adopt($this->site->fresh('systemUser'), 'public_html');

        return $install->fresh();
    }

    /** Runs the operation's queued job as the worker would. */
    private function work(WordPressOperation $operation, array $secrets = []): void
    {
        (new RunWordPressOperation($operation, $secrets))->handle($this->agent, app(WordPressSites::class));
    }

    public function test_the_site_page_links_the_toolkit(): void
    {
        $this->get(route('sites.show', $this->site))->assertOk()
            ->assertSee(route('sites.wordpress', $this->site))
            ->assertSee('Kurulu değil');
        $this->get(route('sites.wordpress', $this->site))->assertOk()->assertSee('WordPress');

        $this->managed();
        $this->get(route('sites.show', $this->site))->assertOk()->assertSee('3 güncelleme');
    }

    public function test_a_wordpress_found_on_the_site_is_managed_with_undo(): void
    {
        $this->agent->answers['wordpress.scan'] = [['path' => 'public_html/blog', 'version' => '6.7.1']];
        $page = Livewire::test(SiteWordPress::class, ['site' => $this->site->id])
            ->call('loadStatus')
            ->assertSee('public_html/blog')
            ->call('adopt', 0)
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'detachQuietly', 'args' => []]);
        $this->assertSame(['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => $this->site->php_version, 'root' => 'public_html'], $this->agent->called('wordpress.scan')[0]);
        $install = $this->site->wordpress()->first();
        $this->assertSame('public_html/blog', $install->path);
        $this->assertSame('6.8.3', $install->version);
        $this->assertSame(3, $install->update_count);
        $this->assertSame('/blog/', $install->setRelation('site', $this->site)->webPath());

        $page->call('detachQuietly');
        $this->assertNull($this->site->wordpress()->first());
        $this->assertCount(1, $this->agent->called('wordpress.detach'));
    }

    public function test_installing_creates_the_database_and_runs_in_the_background(): void
    {
        $page = Livewire::test(SiteWordPress::class, ['site' => $this->site->id])
            ->call('openInstall')
            ->assertSet('installing', true)
            ->assertSet('title', 'kahveatolyesi.com.tr')
            ->assertSet('adminUser', 'kahve')
            ->set('adminUser', 'admin')
            ->call('install')
            ->assertHasErrors(['adminUser' => 'not_in']);
        $this->assertSame([], $this->agent->called('db.database.create'));

        $password = $page->get('adminPassword');
        $page->set('adminUser', 'sercan')->call('install')->assertHasNoErrors()->assertSet('installing', false)->assertSet('adminPassword', '');
        $install = $this->site->wordpress()->first();
        $this->assertSame(WordPressInstall::INSTALLING, $install->status);
        $this->assertSame('kahve_wp', $install->database->name);
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_wp', 'site' => 'kahveatolyesi.com.tr']], $this->agent->called('db.database.create'));

        $job = null;
        Queue::assertPushed(RunWordPressOperation::class, function (RunWordPressOperation $j) use (&$job) {
            $job = $j;

            return true;
        });
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertSame('sercan', $job->secrets['admin_user']);
        $this->assertSame($password, $job->secrets['admin_password']);
        $this->assertSame(['name' => 'kahve_wp', 'user' => 'kahve_wp', 'host' => 'localhost'], array_diff_key($job->secrets['db'], ['password' => 1]));
        // Passwords never reach the database.
        $this->assertStringNotContainsString($password, json_encode(WordPressOperation::first()->request));

        $job->handle($this->agent, app(WordPressSites::class));
        $call = $this->agent->called('wordpress.install')[0];
        $this->assertSame('public_html', $call['path']);
        $this->assertSame('tr_TR', $call['locale']);
        $this->assertTrue($call['harden']);
        $install->refresh();
        $this->assertSame(WordPressInstall::ACTIVE, $install->status);
        $this->assertSame('6.8.3', $install->version);
        $this->assertSame(WordPressOperationStatus::Succeeded, WordPressOperation::first()->status);
    }

    public function test_a_failed_install_gives_the_database_back_and_can_be_tried_again(): void
    {
        $install = app(WordPressSites::class)->install($this->site->fresh('systemUser'), [
            'title' => 'Kahve', 'admin_user' => 'sercan', 'admin_email' => 'sercan@example.com',
            'admin_password' => 'Uzun-bir-parola-1', 'locale' => 'tr_TR', 'harden' => true,
        ], null);
        $this->agent->answers['wordpress.install'] = AgentException::rpcError('wordpress.install', -32000, 'wp core download: could not reach wordpress.org');
        $this->work(WordPressOperation::first());

        $install->refresh();
        $this->assertSame(WordPressInstall::FAILED, $install->status);
        $this->assertNull($install->database_id);
        $this->assertSame(WordPressOperationStatus::Failed, WordPressOperation::first()->status);
        $this->assertStringContainsString('wordpress.org', WordPressOperation::first()->error);
        $this->assertSame(0, Database::count());

        Livewire::test(SiteWordPress::class, ['site' => $this->site->id])
            ->assertSee('WordPress kurulamadı')
            ->call('openInstall')->assertSet('installing', true);
    }

    public function test_install_is_refused_on_a_laravel_site_and_without_mysql(): void
    {
        LaravelApp::factory()->create(['site_id' => $this->site->id]);
        Livewire::test(SiteWordPress::class, ['site' => $this->site->id])
            ->call('openInstall')
            ->assertSet('installing', false)
            ->assertDispatched('toast', message: 'Bu sitede bir Laravel uygulaması var; WordPress ayrı bir siteye kurulmalı.');

        $other = Site::factory()->create();
        Setting::put('databases.products', []);
        $this->assertSame('WordPress için MySQL ya da MariaDB gerekir; önce Veritabanları\'ndan kurun.', app(WordPressSites::class)->installRefusal($other));
    }

    public function test_updates_take_a_restore_point_and_can_be_undone(): void
    {
        $this->managed();
        $page = Livewire::test(SiteWordPress::class, ['site' => $this->site->id])
            ->call('confirmUpdate', 'all')
            ->assertSet('pending', ['core' => true, 'plugins' => ['akismet'], 'themes' => ['twentytwentyfour']])
            ->call('update');
        $update = WordPressOperation::first();
        $this->assertSame('update', $update->kind);
        $page->assertSet('logId', $update->id);

        $this->work($update);
        $call = $this->agent->called('wordpress.update')[0];
        $this->assertTrue($call['core']);
        $this->assertSame(['akismet'], $call['plugins']);
        $this->assertSame('/', $call['check']);
        $update->refresh();
        $this->assertSame(WordPressOperationStatus::Succeeded, $update->status);
        $this->assertSame('wp-update-x', $update->point);

        $page->call('confirmRestore', $update->id)->assertSet('restoreId', $update->id)->call('restore');
        $restore = WordPressOperation::where('kind', 'restore')->first();
        $this->assertSame('wp-update-x', $restore->point);
        $this->work($restore);
        $this->assertSame('wp-update-x', $this->agent->called('wordpress.restore')[0]['point']);
        $this->assertNotNull($update->fresh()->restored_at);
        $this->assertNull(app(WordPressSites::class)->restorable($this->site->wordpress()->first()->setRelation('site', $this->site)));
    }

    public function test_an_update_that_broke_the_site_is_rolled_back_by_the_agent(): void
    {
        $this->managed();
        $this->agent->answers['wordpress.update'] = ['point' => 'wp-update-y', 'changes' => [], 'rolled_back' => true, 'problem' => 'HTTP 500 after the update'];
        Livewire::test(SiteWordPress::class, ['site' => $this->site->id])->call('confirmUpdate', 'plugin', 'akismet')->call('update');
        $this->work($update = WordPressOperation::first());
        $this->assertSame(['akismet'], $this->agent->called('wordpress.update')[0]['plugins']);
        $this->assertFalse($this->agent->called('wordpress.update')[0]['core']);
        $this->assertSame(WordPressOperationStatus::RolledBack, $update->fresh()->status);
        $this->assertNull(app(WordPressSites::class)->restorable($this->site->wordpress()->first()->setRelation('site', $this->site)));
    }

    public function test_maintenance_plugins_auto_updates_and_hardening_change_with_undo(): void
    {
        $this->managed();
        $page = Livewire::test(SiteWordPress::class, ['site' => $this->site->id]);

        $page->call('setMaintenance', true)
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'setMaintenance', 'args' => [false]]);
        $this->assertTrue($this->site->wordpress()->first()->maintenance);

        $page->call('switchItem', 'plugin', 'akismet', 'activate')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'switchItem', 'args' => ['plugin', 'akismet', 'deactivate']]);
        $page->call('switchItem', 'theme', 'twentytwentyfour', 'activate')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'switchItem', 'args' => ['theme', 'twentytwentyfive', 'activate']]);
        $this->assertSame(['plugin', 'theme'], array_column($this->agent->called('wordpress.item'), 'kind'));

        $page->call('setAutoUpdate', 'all')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'setAutoUpdate', 'args' => ['minor']]);
        $this->assertSame('all', $this->agent->called('wordpress.autoupdate')[0]['core']);

        $page->call('hardenRecommended')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'applyRules', 'args' => [['file_edit' => false, 'config_perms' => false, 'uploads_php' => false, 'sensitive_files' => false]]]);
        $this->assertSame(['file_edit' => true, 'config_perms' => true, 'uploads_php' => true, 'sensitive_files' => true], $this->agent->called('wordpress.harden')[0]['rules']);
        $this->assertSame('/', $this->agent->called('wordpress.harden')[0]['web_path']);
        $this->assertTrue($this->site->wordpress()->first()->state['hardening']['file_edit']);
    }

    public function test_signing_in_uses_a_one_time_link_and_is_audited(): void
    {
        $this->managed();
        $this->post(route('sites.wordpress.login', $this->site))
            ->assertRedirect('https://kahveatolyesi.com.tr/wp-login.php?serfex_login='.str_repeat('a', 64));
        $this->assertSame('kahveatolyesi.com.tr WordPress yönetimine giriş yapıldı.', AuditEntry::latest('id')->first()->message);

        $this->actingAs(User::factory()->create(['role' => 'readonly']));
        $this->post(route('sites.wordpress.login', $this->site))->assertForbidden();
    }

    public function test_detaching_keeps_wordpress_and_can_be_undone(): void
    {
        $this->managed();
        Livewire::test(SiteWordPress::class, ['site' => $this->site->id])
            ->call('detach')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'reattach', 'args' => ['public_html']])
            ->call('reattach', 'public_html');
        $this->assertCount(1, $this->agent->called('wordpress.detach'));
        $this->assertSame('public_html', $this->site->wordpress()->first()->path);
    }

    public function test_customers_manage_only_their_own_sites(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->actingAs($customer->user);
        $this->get(route('sites.wordpress', $mine))->assertOk();
        $this->get(route('sites.wordpress', $this->site))->assertNotFound();
        $this->post(route('sites.wordpress.login', $this->site))->assertNotFound();
    }

    public function test_plugin_titles_are_shown_as_plain_text(): void
    {
        $status = self::wpStatus();
        $status['plugins'][0]['title'] = '<img src=x onerror=alert(1)>Kötü &amp; <b>Eklenti</b>';
        $this->agent->answers['wordpress.status'] = $status;
        $this->managed();
        $this->get(route('sites.wordpress', $this->site))->assertOk()
            ->assertDontSee('<img src=x', false)
            ->assertSee('Kötü &amp; Eklenti', false);
    }

    public function test_a_site_that_prefers_www_installs_and_checks_on_www(): void
    {
        $this->site->update(['aliases' => ['www.kahveatolyesi.com.tr'], 'preferred_host' => Site::PREFER_WWW]);
        app(WordPressSites::class)->install($this->site->fresh('systemUser'), [
            'title' => 'Kahve', 'admin_user' => 'sercan', 'admin_email' => 'sercan@example.com',
            'admin_password' => 'Uzun-bir-parola-1', 'locale' => 'tr_TR', 'harden' => true,
        ], null);
        Queue::assertPushed(RunWordPressOperation::class, fn (RunWordPressOperation $j) => str_ends_with($j->secrets['url'], '://www.kahveatolyesi.com.tr'));
        $this->work(WordPressOperation::first(), ['url' => 'http://www.kahveatolyesi.com.tr']);

        $install = $this->site->wordpress()->first();
        $this->work(app(WordPressSites::class)->update($install->setRelation('site', $this->site->fresh('systemUser')), false, ['akismet'], [], null));
        $this->assertSame('www.kahveatolyesi.com.tr', $this->agent->called('wordpress.update')[0]['host']);
    }

    public function test_the_daily_check_refreshes_managed_sites(): void
    {
        $install = $this->managed();
        $this->agent->answers['wordpress.status'] = self::wpStatus(['core_update' => '', 'plugins' => [], 'themes' => []]);
        $this->artisan('serfex:wordpress-check')->assertSuccessful();
        $this->assertSame(0, $install->fresh()->update_count);
    }

    public function test_the_page_says_wordpress_needs_an_smtp_plugin_to_send(): void
    {
        $install = $this->managed();
        // No mail server: nothing to say.
        $this->get(route('sites.wordpress', $this->site))->assertOk()->assertDontSee('PHP mail()');

        app(MailSettings::class)->saveServer(['setup_at' => now()->toIso8601String()]);
        Setting::put('mail.hostname', 'mail.kahveatolyesi.com.tr');
        $this->get(route('sites.wordpress', $this->site))->assertOk()
            ->assertSee('Gönderemiyor: WordPress PHP mail() kullanıyor, bu sunucuda kapalı.', false)
            ->assertSee('mail.kahveatolyesi.com.tr');

        $status = self::wpStatus();
        $status['plugins'][] = ['name' => 'wp-mail-smtp', 'title' => 'WP Mail SMTP', 'version' => '4.6', 'update' => '', 'status' => 'active', 'auto_update' => false];
        $this->agent->answers['wordpress.status'] = $status;
        app(WordPressSites::class)->refresh($install->setRelation('site', $this->site->fresh('systemUser')));
        $this->get(route('sites.wordpress', $this->site))->assertOk()
            ->assertSee('SMTP eklentisiyle: WP Mail SMTP')
            ->assertDontSee('PHP mail() kullanıyor', false);
    }
}
