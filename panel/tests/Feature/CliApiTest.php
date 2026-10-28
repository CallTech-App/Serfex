<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Livewire\Account\AccountSecurity;
use App\Livewire\Account\CliApprove;
use App\Livewire\Laravel\Sections\Access;
use App\Livewire\Settings\ApiKeyIndex;
use App\Models\ApiKey;
use App\Models\CliLogin;
use App\Models\Customer;
use App\Models\LaravelApiToken;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Cli\CliLogins;
use App\Services\Customers\Customers;
use App\Services\Databases\DatabaseServers;
use App\Services\Mail\MailSettings;
use App\Services\Settings\ApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * The serfex command line: signing in through the browser, its keys
 * acting as their person within that person's role, and the parts of
 * /api/v1 it uses for hosting and for Laravel applications.
 */
class CliApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    private Customer $customer;

    private Site $mine;

    private Site $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'php.versions' => ['8.4', '8.3'],
            'system.addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []],
            'mail.status' => ['installed' => true, 'configured' => true, 'running' => true, 'services' => []],
            'dns.status' => ['installed' => true, 'running' => true],
            'laravel.status' => ['exists' => true, 'processes' => [], 'workers' => [], 'releases' => [['name' => '1'], ['name' => '2']], 'info' => ['packages' => []]],
            'laravel.probe' => fn (array $p) => match ($p['kind']) {
                'queues' => ['sizes' => [['connection' => '', 'queue' => 'default', 'size' => 4]], 'failed' => 1],
                'failed' => [['id' => 'a1', 'connection' => 'database', 'queue' => 'default', 'job' => 'App\\Jobs\\SendInvoice', 'error' => 'boom', 'failed_at' => '2026-10-20 08:00:00']],
                default => [],
            },
            'laravel.env.get' => ['env' => "APP_NAME=Kargo\nAPP_DEBUG=false\n", 'example' => "APP_NAME=\nAPP_KEY=\n"],
            'laravel.env.set' => ['cached' => true, 'output' => '', 'restarted' => true],
            'laravel.logs' => ['files' => [['name' => 'laravel.log', 'size' => 120]], 'file' => 'laravel.log', 'truncated' => false,
                'content' => "[2026-10-20 08:00:00] production.ERROR: Ödeme alınamadı\n[2026-10-20 08:01:00] production.INFO: Tamam\n"],
            'laravel.maintenance' => ['exit_code' => 0, 'ms' => 120],
            'laravel.run' => ['exit_code' => 0, 'output' => ''],
            'service.list' => ['services' => [
                ['unit' => 'nginx', 'name' => 'Nginx', 'group' => 'hosting', 'state' => 'active', 'sub_state' => 'running', 'enabled' => 'enabled'],
                ['unit' => 'ssh', 'name' => 'SSH', 'group' => 'system', 'state' => 'active', 'sub_state' => 'running', 'enabled' => 'enabled', 'protected' => 'SSH durdurulamaz.'],
            ]],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        Setting::put('mail.hostname', 'mail.example.net');
        app(MailSettings::class)->saveServer(['state' => 'ready', 'setup_at' => now()->toIso8601String(), 'webmail_url' => 'https://mail.example.net/', 'certificate' => '']);

        $this->admin = User::factory()->create(['name' => 'Aylin', 'role' => 'full']);
        $this->customer = Customer::factory()->withLogin()->create(['company' => 'Kahve Atölyesi', 'username' => 'kahve']);
        $this->mine = Site::factory()->for(app(Customers::class)->account($this->customer))->create(['domain' => 'kahveatolyesi.com.tr']);
        $other = Customer::factory()->withLogin()->create(['username' => 'klinik']);
        $this->theirs = Site::factory()->for(app(Customers::class)->account($other))->create(['domain' => 'klinikmavi.com']);
    }

    private function cliKey(User $user, string $scope = 'write'): string
    {
        return app(ApiKeys::class)->create($user, 'Komut satırı: dizüstü', $scope, [], now()->addYear(), ApiKey::CLI, 'dizüstü')[1];
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $plain, string $lang = 'tr'): array
    {
        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json', 'Accept-Language' => $lang];
    }

    private function laravelApp(string $domain = 'api.hizlikargo.com.tr'): LaravelApp
    {
        $site = Site::factory()->for(SystemUser::factory()->state(['name' => 'hizlikargo']))->create(['domain' => $domain, 'web_root' => 'laravel/current/public']);

        return LaravelApp::factory()->for($site)->create();
    }

    public function test_signing_in_through_the_browser_gives_the_key_once(): void
    {
        $start = $this->postJson('/api/v1/cli/login', ['client' => 'aylin-mbp'])->assertCreated();
        $code = $start->json('code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
        $this->assertStringContainsString('/cli/approve?code='.$code, $start->json('url'));
        $device = $start->json('device');

        $this->postJson('/api/v1/cli/login/token', ['device' => $device])->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->postJson('/api/v1/cli/login/token', ['device' => 'yanlış'])->assertStatus(410);

        $this->actingAs($this->admin);
        $this->get('/cli/approve?code='.$code)->assertOk()->assertSee('aylin-mbp')->assertSee($code);
        Livewire::test(CliApprove::class)
            ->set('typed', strtolower(str_replace('-', '', $code)))
            ->call('lookup')
            ->assertSet('code', $code)
            ->set('scope', 'write')
            ->call('approve')
            ->assertSet('done', 'approved');

        $got = $this->postJson('/api/v1/cli/login/token', ['device' => $device])->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('email', $this->admin->email);
        $key = ApiKey::sole();
        $this->assertSame(ApiKey::CLI, $key->kind);
        $this->assertSame('aylin-mbp', $key->client);
        $this->assertSame(hash('sha256', $got->json('key')), $key->token_hash);
        $this->assertTrue($key->expires_at->between(now()->addDays(364), now()->addDays(366)));
        $this->assertNull(CliLogin::sole()->secret);

        // Only once.
        $this->postJson('/api/v1/cli/login/token', ['device' => $device])->assertStatus(410);

        $this->getJson('/api/v1/me', $this->bearer($got->json('key')))->assertOk()
            ->assertJsonPath('data.kind', 'key')
            ->assertJsonPath('data.account', 'admin')
            ->assertJsonPath('data.key.kind', 'cli')
            ->assertJsonPath('data.key.scope', 'write');
    }

    public function test_a_sign_in_can_be_refused_and_expires(): void
    {
        [$login, $device] = app(CliLogins::class)->start('bilinmeyen', '198.51.100.7');
        $this->actingAs($this->admin);
        Livewire::test(CliApprove::class, ['code' => $login->code])
            ->assertSee('198.51.100.7')
            ->call('deny')
            ->assertSet('done', 'denied');
        $this->postJson('/api/v1/cli/login/token', ['device' => $device])->assertForbidden()->assertJsonPath('status', 'denied');
        $this->assertSame(0, ApiKey::count());

        [$late, $device] = app(CliLogins::class)->start('geç', null);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/cli/login/token', ['device' => $device])->assertStatus(410);
        Livewire::test(CliApprove::class)->set('typed', $late->code)->call('lookup')->assertHasErrors('typed');
    }

    public function test_logout_closes_only_a_command_line_key(): void
    {
        $cli = $this->cliKey($this->admin);
        $panel = app(ApiKeys::class)->create($this->admin, 'Faturalama', 'write', [], null)[1];

        $this->deleteJson('/api/v1/me', [], $this->bearer($panel))->assertOk()->assertJsonPath('closed', false);
        $this->deleteJson('/api/v1/me', [], $this->bearer($cli))->assertNoContent();
        $this->getJson('/api/v1/me', $this->bearer($cli))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer($panel))->assertOk();
    }

    public function test_a_read_only_administrator_gets_a_read_only_key(): void
    {
        $viewer = User::factory()->create(['name' => 'Ayşe', 'role' => 'readonly']);
        [$login, $device] = app(CliLogins::class)->start('ayse-pc', null);
        $this->actingAs($viewer);
        Livewire::test(CliApprove::class, ['code' => $login->code])
            ->assertSet('scope', 'read')
            ->set('scope', 'write')
            ->call('approve')
            ->assertSet('done', 'approved');
        $plain = $this->postJson('/api/v1/cli/login/token', ['device' => $device])->assertOk()->json('key');
        $this->assertSame('read', ApiKey::sole()->scope);

        $this->getJson('/api/v1/sites', $this->bearer($plain))->assertOk();
        $this->getJson('/api/v1/services', $this->bearer($plain))->assertOk();
        $this->postJson('/api/v1/sites', ['domain' => 'yeni.com.tr'], $this->bearer($plain))->assertForbidden();
    }

    public function test_a_read_only_administrators_own_key_cannot_change_even_with_write_scope(): void
    {
        $viewer = User::factory()->create(['role' => 'readonly']);
        $plain = $this->cliKey($viewer, 'write');
        $this->getJson('/api/v1/me', $this->bearer($plain))->assertJsonPath('data.key.scope', 'read');
        $this->deleteJson('/api/v1/sites/kahveatolyesi.com.tr', [], $this->bearer($plain))->assertForbidden();
    }

    public function test_viewing_as_a_customer_cannot_approve_a_sign_in(): void
    {
        [$login] = app(CliLogins::class)->start('x', null);
        $this->actingAs($this->admin)->post(route('customers.impersonate', $this->customer))->assertRedirect();
        Livewire::test(CliApprove::class, ['code' => $login->code])
            ->call('approve')
            ->assertHasErrors('scope');
        $this->assertSame(0, ApiKey::count());
    }

    public function test_a_customers_key_sees_and_changes_only_their_own_hosting(): void
    {
        $plain = $this->cliKey($this->customer->user);
        $this->getJson('/api/v1/me', $this->bearer($plain))->assertOk()
            ->assertJsonPath('data.account', 'customer')
            ->assertJsonPath('data.customer', 'Kahve Atölyesi');

        $this->getJson('/api/v1/sites', $this->bearer($plain))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain', 'kahveatolyesi.com.tr');
        $this->getJson('/api/v1/sites/klinikmavi.com', $this->bearer($plain))->assertNotFound()
            ->assertJsonPath('message', 'klinikmavi.com adında bir site yok.');
        $this->getJson('/api/v1/sites/klinikmavi.com', $this->bearer($plain, 'en-US'))->assertNotFound()
            ->assertJsonPath('message', 'There is no site klinikmavi.com.');
        $this->getJson('/api/v1/sites/klinikmavi.com/ssl', $this->bearer($plain))->assertNotFound();
        $this->getJson('/api/v1/sites/klinikmavi.com/domains', $this->bearer($plain))->assertNotFound();
        $this->deleteJson('/api/v1/sites/klinikmavi.com', [], $this->bearer($plain))->assertNotFound();

        foreach (['/api/v1/server', '/api/v1/customers', '/api/v1/packages', '/api/v1/services'] as $uri) {
            $this->getJson($uri, $this->bearer($plain))->assertForbidden();
        }

        $this->postJson('/api/v1/sites', ['domain' => 'yenidukkan.com.tr', 'customer_id' => Customer::where('username', 'klinik')->value('id')], $this->bearer($plain))
            ->assertStatus(202);
        $this->assertSame($this->customer->id, Site::where('domain', 'yenidukkan.com.tr')->sole()->customer()?->id);
    }

    public function test_a_suspended_customers_key_is_refused(): void
    {
        $plain = $this->cliKey($this->customer->user);
        $this->customer->update(['suspended_at' => now()]);
        $this->getJson('/api/v1/sites', $this->bearer($plain))->assertForbidden();
        $this->getJson('/api/v1/me', $this->bearer($plain))->assertForbidden();
    }

    public function test_the_package_decides_dns_mail_and_backups(): void
    {
        $plain = $this->cliKey($this->customer->user);
        $this->customer->package->update(['allow_dns' => false, 'allow_mail' => false, 'allow_backups' => false]);
        $this->getJson('/api/v1/dns/zones', $this->bearer($plain))->assertForbidden()
            ->assertJsonPath('message', 'Paketiniz DNS düzenleme özelliğini içermiyor.');
        $this->getJson('/api/v1/mail/domains', $this->bearer($plain))->assertForbidden();
        $this->getJson('/api/v1/backups', $this->bearer($plain))->assertForbidden();
        $this->getJson('/api/v1/databases', $this->bearer($plain))->assertOk();
    }

    public function test_a_member_key_reaches_only_its_applications(): void
    {
        $app = $this->laravelApp();
        $member = User::factory()->member()->create();
        $app->members()->create(['user_id' => $member->id, 'role' => 'viewer']);
        $plain = $this->cliKey($member);

        $this->getJson('/api/v1/me', $this->bearer($plain))->assertJsonPath('data.account', 'member');
        $this->getJson('/api/v1/apps', $this->bearer($plain))->assertOk()->assertJsonPath('data.0.domain', 'api.hizlikargo.com.tr');
        $this->getJson('/api/v1/sites', $this->bearer($plain))->assertForbidden();
        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr', $this->bearer($plain))->assertOk();
        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/logs', $this->bearer($plain))->assertOk();
        // A viewer neither deploys nor reads the environment.
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/deploy', [], $this->bearer($plain))->assertForbidden();
        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/env', $this->bearer($plain))->assertForbidden();
        $this->getJson('/api/v1/apps/'.$this->mine->domain, $this->bearer($plain))->assertNotFound();

        $app->members()->where('user_id', $member->id)->update(['role' => 'developer']);
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/deploy', [], $this->bearer($plain))->assertStatus(202);
        $this->assertSame($member->name, LaravelDeployment::latest('id')->first()->triggered_by);
    }

    public function test_an_application_token_deploys_and_only_an_operate_token_does_more(): void
    {
        $app = $this->laravelApp();
        $deploy = LaravelApiToken::issue($app, 'GitHub Actions')[1];
        $operate = LaravelApiToken::issue($app, 'Geliştirici', null, 'operate')[1];

        $this->getJson('/api/v1/me', $this->bearer($deploy))->assertOk()
            ->assertJsonPath('data.kind', 'token')
            ->assertJsonPath('data.role', 'deploy')
            ->assertJsonPath('data.app', 'api.hizlikargo.com.tr');
        $this->getJson('/api/v1/apps/klinikmavi.com', $this->bearer($deploy))->assertForbidden();

        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/deploy', ['ref' => 'main'], $this->bearer($deploy))->assertStatus(202);
        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/deployments', $this->bearer($deploy))->assertOk()->assertJsonPath('data.0.triggered_by', 'API: GitHub Actions');
        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/env', $this->bearer($deploy))->assertForbidden()
            ->assertJsonPath('message', 'Bu belirteç yalnızca dağıtım içindir; Erişim ve API\'den Geliştirici yetkili bir belirteç oluşturun.');
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/maintenance', ['down' => true], $this->bearer($deploy))->assertForbidden();
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/queues/restart', [], $this->bearer($deploy))->assertForbidden();

        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/env', $this->bearer($operate))->assertOk()
            ->assertJsonPath('data.values.APP_NAME', 'Kargo')
            ->assertJsonPath('data.missing', ['APP_KEY']);
        $this->patchJson('/api/v1/apps/api.hizlikargo.com.tr/env', ['set' => ['APP_DEBUG' => 'true'], 'unset' => ['APP_NAME']], $this->bearer($operate))->assertOk();
        $written = $this->agent->called('laravel.env.set');
        $this->assertCount(1, $written);
        $this->assertStringContainsString('APP_DEBUG=true', $written[0]['content']);
        $this->assertStringNotContainsString('APP_NAME', $written[0]['content']);
        $this->patchJson('/api/v1/apps/api.hizlikargo.com.tr/env', ['set' => ['1BAD' => 'x']], $this->bearer($operate))->assertUnprocessable();
    }

    public function test_commands_need_the_shell_feature_for_a_customer_and_run_with_their_output(): void
    {
        $app = $this->laravelApp();
        $operate = LaravelApiToken::issue($app, 'Geliştirici', null, 'operate')[1];

        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/commands', ['tool' => 'artisan', 'command' => 'migrate:status'], $this->bearer($operate))
            ->assertStatus(202)
            ->assertJsonPath('data.command', 'migrate:status');
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/commands', ['tool' => 'bash', 'command' => 'ls'], $this->bearer($operate))->assertUnprocessable();
    }

    public function test_queues_maintenance_and_logs_for_an_application(): void
    {
        $app = $this->laravelApp();
        $operate = LaravelApiToken::issue($app, 'Geliştirici', null, 'operate')[1];

        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/queues', $this->bearer($operate))->assertOk();
        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/queues/failed', $this->bearer($operate))->assertOk()->assertJsonPath('data.0.id', 'a1');
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/queues/restart', [], $this->bearer($operate))->assertOk();
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/queues/retry', ['id' => 'a1'], $this->bearer($operate))->assertOk();

        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/maintenance', ['down' => true, 'secret' => 'gizli-yol'], $this->bearer($operate))->assertOk()
            ->assertJsonPath('data.down', true)
            ->assertJsonPath('data.bypass_url', 'http://api.hizlikargo.com.tr/gizli-yol');
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/maintenance', ['down' => true, 'mode' => 'app'], $this->bearer($operate))->assertOk();
        $this->assertNotEmpty($this->agent->called('laravel.maintenance'));

        $this->getJson('/api/v1/apps/api.hizlikargo.com.tr/logs?level=error', $this->bearer($operate))->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.message', 'Ödeme alınamadı');
    }

    public function test_rollback_goes_back_to_a_release_still_on_disk(): void
    {
        $app = $this->laravelApp();
        $app->update(['current_release' => '2']);
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 1, 'release' => '1', 'kind' => 'deploy', 'status' => DeploymentStatus::Succeeded, 'run' => 'deploy-1', 'triggered_by' => 'Aylin']);
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 2, 'release' => '2', 'kind' => 'deploy', 'status' => DeploymentStatus::Succeeded, 'run' => 'deploy-2', 'triggered_by' => 'Aylin']);
        $token = LaravelApiToken::issue($app, 'CI')[1];

        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/rollback', ['to' => 2], $this->bearer($token))->assertStatus(409);
        $this->postJson('/api/v1/apps/api.hizlikargo.com.tr/rollback', [], $this->bearer($token))->assertStatus(202);

        // A rollback that went back to release 1 is the one marked live, not deployment 1 too.
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 4, 'release' => '1', 'kind' => 'rollback', 'status' => DeploymentStatus::Succeeded, 'run' => 'rollback-4']);
        $app->update(['current_release' => '1']);
        $live = collect($this->getJson('/api/v1/apps/api.hizlikargo.com.tr/deployments', $this->bearer($token))->assertOk()->json('data'))
            ->where('live', true)->pluck('deployment')->all();
        $this->assertSame([4], $live);
    }

    public function test_domains_ssl_and_databases_of_a_site(): void
    {
        $plain = $this->cliKey($this->customer->user);
        $this->postJson('/api/v1/sites/kahveatolyesi.com.tr/subdomains', ['name' => 'blog'], $this->bearer($plain))->assertCreated()
            ->assertJsonPath('data.subdomains.0.name', 'blog.kahveatolyesi.com.tr')
            ->assertJsonPath('data.subdomains.0.root', 'subdomains/blog');
        $this->postJson('/api/v1/sites/kahveatolyesi.com.tr/subdomains', ['name' => 'kötü ad'], $this->bearer($plain))->assertUnprocessable();
        $this->postJson('/api/v1/sites/kahveatolyesi.com.tr/redirects', ['source' => '/eski', 'target' => 'https://kahveatolyesi.com.tr/yeni'], $this->bearer($plain))->assertCreated();
        $this->getJson('/api/v1/sites/kahveatolyesi.com.tr/domains', $this->bearer($plain))->assertOk()->assertJsonCount(1, 'data.redirects');
        $this->deleteJson('/api/v1/sites/kahveatolyesi.com.tr/domains?name=blog', [], $this->bearer($plain))->assertOk()->assertJsonCount(0, 'data.subdomains');

        $this->getJson('/api/v1/sites/kahveatolyesi.com.tr/ssl', $this->bearer($plain))->assertOk()->assertJsonPath('data.site', 'kahveatolyesi.com.tr');
        $this->getJson('/api/v1/certificates', $this->bearer($plain))->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/databases', ['site' => 'kahveatolyesi.com.tr', 'name' => 'dukkan'], $this->bearer($plain))->assertUnprocessable()
            ->assertJsonPath('message', 'Bu sunucuda veritabanı sunucusu kurulu değil.');
        Setting::put('databases.products', ['mysql' => 'mysql']);
        $this->app->forgetInstance(DatabaseServers::class);
        $created = $this->postJson('/api/v1/databases', ['site' => 'kahveatolyesi.com.tr', 'name' => 'dukkan'], $this->bearer($plain))->assertCreated()
            ->assertJsonPath('data.name', 'kahve_dukkan')
            ->assertJsonPath('data.users.0.name', 'kahve_dukkan');
        $this->assertNotEmpty($created->json('data.connection'));
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_dukkan', 'site' => 'kahveatolyesi.com.tr']], $this->agent->called('db.database.create'));
        $this->getJson('/api/v1/databases', $this->bearer($plain))->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/databases', ['site' => 'klinikmavi.com', 'name' => 'x'], $this->bearer($plain))->assertNotFound();
    }

    public function test_mail_mailboxes_get_a_password_made_once(): void
    {
        $plain = $this->cliKey($this->customer->user);
        $this->postJson('/api/v1/mail/domains', ['name' => 'klinikmavi.com'], $this->bearer($plain))->assertForbidden();
        $this->postJson('/api/v1/mail/domains', ['name' => 'kahveatolyesi.com.tr'], $this->bearer($plain))->assertStatus(202);

        $box = $this->postJson('/api/v1/mail/domains/kahveatolyesi.com.tr/mailboxes', ['name' => 'siparis'], $this->bearer($plain))->assertCreated()
            ->assertJsonPath('data.address', 'siparis@kahveatolyesi.com.tr');
        $password = $box->json('data.password');
        $this->assertGreaterThanOrEqual(12, strlen($password));
        $this->assertNotSame($password, Mailbox::sole()->password_hash ?? null);
        $this->postJson('/api/v1/mail/domains/kahveatolyesi.com.tr/mailboxes', ['name' => 'info', 'password' => '123'], $this->bearer($plain))->assertUnprocessable();

        $this->putJson('/api/v1/mail/domains/kahveatolyesi.com.tr/forwarders/*', ['to' => ['patron@gmail.com']], $this->bearer($plain))->assertCreated()
            ->assertJsonPath('data.address', '*@kahveatolyesi.com.tr');
        $this->getJson('/api/v1/mail/domains/kahveatolyesi.com.tr', $this->bearer($plain))->assertOk()
            ->assertJsonCount(1, 'data.mailboxes')
            ->assertJsonCount(1, 'data.forwarders');
        $this->deleteJson('/api/v1/mail/domains/kahveatolyesi.com.tr/mailboxes/siparis', [], $this->bearer($plain))->assertNoContent();

        MailDomain::factory()->create(['name' => 'klinikmavi.com', 'site_id' => $this->theirs->id]);
        $this->getJson('/api/v1/mail/domains/klinikmavi.com', $this->bearer($plain))->assertNotFound();
    }

    public function test_only_administrators_back_up_the_whole_server(): void
    {
        $plain = $this->cliKey($this->customer->user);
        $this->postJson('/api/v1/backups', [], $this->bearer($plain))->assertForbidden()
            ->assertJsonPath('message', 'Sunucunun tamamını yalnızca yöneticiler yedekleyebilir; bir site belirtin.');
        $this->postJson('/api/v1/backups', ['site' => 'klinikmavi.com'], $this->bearer($plain))->assertNotFound();
        $this->getJson('/api/v1/backups', $this->bearer($plain))->assertOk()->assertJsonCount(0, 'data');

        $this->mine->update(['provisioned_at' => now()]);
        $id = $this->postJson('/api/v1/backups', ['site' => 'kahveatolyesi.com.tr'], $this->bearer($plain))->assertAccepted()->json('data.id');
        $this->getJson('/api/v1/backups', $this->bearer($plain))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/backups?site=kahveatolyesi.com.tr', $this->bearer($plain))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/backups/{$id}", $this->bearer($plain))->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson("/api/v1/backups/{$id}", $this->bearer($this->cliKey(Customer::where('username', 'klinik')->first()->user)))->assertNotFound();
    }

    public function test_services_are_listed_and_protected_ones_are_not_stopped(): void
    {
        $plain = $this->cliKey($this->admin);
        $this->getJson('/api/v1/services', $this->bearer($plain))->assertOk();
        $this->getJson('/api/v1/services/yok.service', $this->bearer($plain))->assertNotFound();
        $this->postJson('/api/v1/services/ssh/stop', [], $this->bearer($plain))->assertStatus(409);
    }

    public function test_customers_are_shown_and_suspended_by_number(): void
    {
        $plain = $this->cliKey($this->admin);
        $this->getJson("/api/v1/customers/{$this->customer->id}", $this->bearer($plain))->assertOk()
            ->assertJsonPath('data.id', $this->customer->id);
        $this->getJson('/api/v1/customers/999', $this->bearer($plain, 'en'))->assertNotFound()
            ->assertJsonPath('message', 'There is no customer number 999.');
        $this->postJson("/api/v1/customers/{$this->customer->id}/suspend", ['reason' => 'Ödeme'], $this->bearer($plain))->assertOk();
        $this->assertNotNull($this->customer->fresh()->suspended_at);
        $this->postJson("/api/v1/customers/{$this->customer->id}/unsuspend", [], $this->bearer($plain))->assertOk();
        $this->assertNull($this->customer->fresh()->suspended_at);
    }

    public function test_me_names_the_role_and_key_in_the_readers_language(): void
    {
        $plain = $this->cliKey($this->admin);
        $this->getJson('/api/v1/me', $this->bearer($plain, 'en'))->assertOk()
            ->assertJsonPath('data.role_name', 'Full administrator')
            ->assertJsonPath('data.key.name', 'Command line: dizüstü');
    }

    public function test_command_line_keys_are_closed_from_account_security_with_undo(): void
    {
        $this->cliKey($this->admin);
        app(ApiKeys::class)->create($this->admin, 'Faturalama', 'write', [], null);
        $key = ApiKey::where('kind', ApiKey::CLI)->sole();

        $this->actingAs($this->admin);
        $this->get('/account/security')->assertOk()->assertSee('dizüstü')->assertSee('Komut satırı');
        Livewire::test(AccountSecurity::class)
            ->call('closeCli', $key->id)
            ->assertDispatched('toast', undo: ['method' => 'reopenCli', 'args' => [$key->id]]);
        $this->assertNotNull($key->fresh()->revoked_at);
        Livewire::test(AccountSecurity::class)->call('reopenCli', $key->id);
        $this->assertNull($key->fresh()->revoked_at);

        // API anahtarları lists only the panel's own keys.
        Livewire::test(ApiKeyIndex::class)->assertSee('Faturalama')->assertDontSee('dizüstü');
    }

    public function test_an_application_token_is_made_with_its_ability(): void
    {
        $app = $this->laravelApp();
        $this->actingAs($this->admin);
        Livewire::withoutLazyLoading()->test(Access::class, ['appId' => $app->id])
            ->call('openToken')
            ->set('tokenName', 'Geliştirici')
            ->set('tokenAbility', 'operate')
            ->call('createToken')
            ->assertHasNoErrors();
        $this->assertSame('operate', LaravelApiToken::sole()->ability);
    }

    public function test_the_server_serves_the_installer_and_the_binaries(): void
    {
        $dir = sys_get_temp_dir().'/serfex-cli-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/install-cli.sh', "#!/bin/sh\nSOURCE='https://github.com/x'\necho ok\n");
        file_put_contents($dir.'/serfex-cli-linux-amd64', 'ELF');
        config(['serfex.cli_downloads' => $dir]);

        $script = $this->get('/cli/install.sh')->assertOk();
        $this->assertStringContainsString("SOURCE='".url('/cli/download')."'", $script->getContent());
        $this->get('/cli/download/serfex-cli-linux-amd64')->assertOk();
        $this->get('/cli/download/serfex-cli-darwin-arm64')->assertNotFound();
        $this->get('/cli/download/..%2Fpanel.json')->assertNotFound();

        // A checkout from git serves the repository's script.
        config(['serfex.cli_downloads' => $dir.'/yok']);
        $script = $this->get('/cli/install.sh')->assertOk()->getContent();
        $this->assertStringContainsString("SOURCE='".url('/cli/download')."'", $script);
        $this->assertStringNotContainsString('github.com/CallTech-App/Serfex/releases', explode("\n", explode('SOURCE=', $script, 2)[1])[0]);
    }

    public function test_cli_key_signs_the_command_line_in_on_the_server(): void
    {
        $this->assertSame(0, Artisan::call('serfex:cli-key', ['--client' => 'sunucu (root)']));
        $out = json_decode(trim(Artisan::output()), true);
        $this->assertStringStartsWith(ApiKeys::PREFIX, $out['key']);
        $this->assertSame($this->admin->email, $out['email']);
        $this->assertSame('sunucu (root)', ApiKey::sole()->client);

        $this->assertSame(1, Artisan::call('serfex:cli-key', ['--email' => 'yok@example.com']));
    }
}
