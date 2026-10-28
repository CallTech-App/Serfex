<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Jobs\InstallFromTemplate;
use App\Jobs\UpdateFromTemplate;
use App\Livewire\Settings\AppTemplateIndex;
use App\Livewire\Settings\AppTemplateShow;
use App\Models\AppTemplate;
use App\Models\Customer;
use App\Models\GitAccount;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Laravel\AppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Uygulama şablonları: an administrator describes their own Laravel
 * application once (a Git repository or a release package that updates
 * itself) and installs it on any site, then rolls new versions out.
 */
class AppTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new FakeAgent([
            'laravel.package.install' => ['version' => '1.2.0', 'files' => 4],
            'laravel.setup' => ['failed' => -1, 'exit_code' => 0, 'millis' => 900],
            'site.apply' => ['document_root' => '/home/magaza/web/kitapkosesi.com/app/public'],
            'laravel.health' => ['ok' => true, 'status' => 200, 'ms' => 40],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->admin = User::factory()->create(['name' => 'Aylin']);
        $this->actingAs($this->admin);
    }

    /**
     * A release package downloaded from a typed address.
     *
     * @param  array<string, mixed>  $values
     */
    private function packageTemplate(array $values = []): AppTemplate
    {
        return AppTemplate::create([
            'name' => 'Roifex',
            'source' => 'package',
            'provider' => 'other',
            'ref_type' => 'tag',
            'package_url' => 'https://indir.ornek.com/roifex-latest.zip',
            'database' => 'none',
            'package_manager' => 'npm',
            'worker' => true,
            'scheduler' => true,
            'fields' => [
                ['key' => 'STORE_NAME', 'label' => 'Mağaza adı', 'default' => '{customer.name}', 'required' => true, 'secret' => false, 'type' => 'text'],
                ['key' => 'API_KEY', 'label' => 'API anahtarı', 'default' => '', 'required' => true, 'secret' => true, 'type' => 'text'],
                ['key' => 'LICENSE', 'label' => 'Lisans', 'default' => '', 'required' => false, 'secret' => false, 'type' => 'file'],
            ],
            'setup_commands' => "# first install\nphp artisan odur:install --no-interaction --url={site.url} '--store={ask.STORE_NAME}' --key={ask.API_KEY} --license={file.LICENSE}",
            ...$values,
        ]);
    }

    public function test_setup_commands_must_start_with_a_known_tool(): void
    {
        $this->assertSame([
            ['tool' => 'artisan', 'args' => ['migrate', '--force']],
            ['tool' => 'composer', 'args' => ['run', 'post-install']],
        ], AppTemplates::parseCommands("php artisan migrate --force\n\n# comment\ncomposer run post-install"));

        try {
            AppTemplates::parseCommands("php artisan migrate\nrm -rf /");
            $this->fail('A shell command was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('2.', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        AppTemplates::parseCommands(str_repeat("php artisan about\n", 21));
    }

    public function test_env_template_lines_and_placeholders(): void
    {
        $this->assertSame(['MAIL_FROM_NAME' => '{customer.name}'], AppTemplates::parseEnv("# mail\nMAIL_FROM_NAME={customer.name}"));
        $this->assertSame('Kitap Köşesi https://kitapkosesi.com {ask.UNKNOWN}', AppTemplates::fill('{customer.name} {site.url} {ask.UNKNOWN}', [
            'customer.name' => 'Kitap Köşesi',
            'site.url' => 'https://kitapkosesi.com',
        ]));
        $this->assertSame(32, strlen(AppTemplates::fill('{random:32}', [])));
        // Kept between 8 and 64 letters.
        $this->assertSame(8, strlen(AppTemplates::fill('{random:2}', [])));

        $this->expectException(InvalidArgumentException::class);
        AppTemplates::parseEnv('NOT A LINE');
    }

    public function test_a_package_template_is_unpacked_set_up_and_served_with_secrets_hidden(): void
    {
        Queue::fake();
        $template = $this->packageTemplate();
        app(AppTemplates::class)->check($template, force: true);
        $customer = Customer::factory()->create(['company' => 'Atlas Yayıncılık', 'name' => 'Selin Kaya']);
        $site = Site::factory()->create(['domain' => 'kitapkosesi.com']);
        $site->systemUser->update(['name' => 'magaza', 'customer_id' => $customer->id]);

        $app = app(AppTemplates::class)->install($template->fresh(), $site->fresh(), ['STORE_NAME' => 'Atlas Yayıncılık', 'API_KEY' => 'cok-gizli-anahtar'], [
            'LICENSE' => ['name' => 'lisans.JSON', 'content' => '{"domain":"kitapkosesi.com"}'],
        ], null, $this->admin);

        $this->assertSame('app', $app->path);
        $this->assertSame('package', $app->source);
        $this->assertNull($app->repository);
        // The answers are kept encrypted until the setup has run.
        $this->assertStringNotContainsString('cok-gizli-anahtar', (string) $app->getRawOriginal('template_setup'));
        $deployment = $app->deployments()->sole();
        Queue::assertPushed(InstallFromTemplate::class, fn ($job) => $job->deployment->is($deployment));

        app()->call([new InstallFromTemplate($deployment), 'handle']);

        $install = $this->agent->called('laravel.package.install')[0];
        $this->assertSame('https://indir.ornek.com/roifex-latest.zip', $install['url']);
        $this->assertFalse($install['replace']);
        $this->assertSame([['path' => 'storage/app/serfex/license.json', 'content' => base64_encode('{"domain":"kitapkosesi.com"}')]], $install['files']);

        $setup = $this->agent->called('laravel.setup')[0];
        $this->assertSame('artisan', $setup['commands'][0]['tool']);
        $this->assertSame([
            'odur:install', '--no-interaction', '--url=http://kitapkosesi.com', '--store=Atlas Yayıncılık', '--key=cok-gizli-anahtar',
            '--license=/home/magaza/web/kitapkosesi.com/app/storage/app/serfex/license.json',
        ], $setup['commands'][0]['args']);
        $this->assertContains('cok-gizli-anahtar', $setup['redact']);

        $app->refresh();
        $this->assertSame(LaravelAppStatus::Active, $app->status);
        $this->assertSame('1.2.0', $app->template_version);
        $this->assertNull($app->template_setup);
        $this->assertSame('app/public', $site->fresh()->web_root);
        $this->assertSame(DeploymentStatus::Succeeded, $deployment->fresh()->status);
        $this->assertContains('laravel.queue.apply', $this->agent->methods());
    }

    public function test_a_failed_setup_command_keeps_the_answers_for_a_retry(): void
    {
        Queue::fake();
        $this->agent->answers['laravel.setup'] = ['failed' => 0, 'exit_code' => 1];
        $template = $this->packageTemplate(['fields' => [], 'setup_commands' => 'php artisan odur:install --url={site.url}']);
        $site = Site::factory()->create(['domain' => 'kitapkosesi.com']);
        $app = app(AppTemplates::class)->install($template, $site, [], [], null);

        app()->call([new InstallFromTemplate($app->deployments()->sole()), 'handle']);

        $app->refresh();
        $this->assertSame(LaravelAppStatus::Failed, $app->status);
        $this->assertStringContainsString('odur:install', (string) $app->last_error);
        $this->assertNotNull($app->template_setup);
        $this->assertSame([], $this->agent->called('site.apply'));
    }

    public function test_commands_using_the_administrator_need_the_administrator_turned_on(): void
    {
        $template = $this->packageTemplate(['fields' => [], 'setup_commands' => null]);

        Livewire::test(AppTemplateShow::class, ['template' => $template])
            ->set('setupCommands', 'php artisan odur:install --admin-email={admin.email}')
            ->set('createAdmin', false)
            ->call('saveSetup')
            ->assertHasErrors('setupCommands');
        $this->assertNull($template->fresh()->setup_commands);

        Livewire::test(AppTemplateShow::class, ['template' => $template])
            ->set('setupCommands', 'php artisan odur:install --admin-email={admin.email}')
            ->set('createAdmin', true)
            ->set('adminCommand', 'php artisan user:create {admin.email} {admin.password}')
            ->call('saveSetup')
            ->assertHasNoErrors();
        $this->assertTrue($template->fresh()->create_admin);
    }

    public function test_an_administrator_adds_a_package_template_by_its_address(): void
    {
        Livewire::test(AppTemplateIndex::class)
            ->call('openAdd')
            ->set('name', 'Roifex')
            ->set('source', 'package')
            ->set('provider', 'other')
            ->set('packageUrl', 'http://indir.ornek.com/roifex.zip')
            ->call('add')
            ->assertHasErrors('packageUrl')
            ->set('packageUrl', 'https://indir.ornek.com/roifex.zip')
            ->call('add')
            ->assertHasNoErrors();

        $template = AppTemplate::sole();
        $this->assertTrue($template->isPackage());
        $this->assertFalse($template->migrate);
        $this->assertFalse($template->customers_can_install);
        $this->assertSame('https://indir.ornek.com/roifex.zip', $template->latest_package['url']);
    }

    public function test_templates_are_for_full_administrators_and_customers_see_only_the_ones_shared(): void
    {
        $this->packageTemplate();
        $this->packageTemplate(['name' => 'Blog', 'customers_can_install' => true]);
        $customer = Customer::factory()->withLogin()->create();
        $user = User::where('customer_id', $customer->id)->sole();

        $this->get('/settings/app-templates')->assertOk()->assertSee('Roifex');
        $this->actingAs($user)->get('/settings/app-templates')->assertForbidden();
        $this->assertSame(['Blog'], AppTemplate::installableBy($user)->pluck('name')->all());
        $this->assertCount(2, AppTemplate::installableBy($this->admin)->get());
    }

    public function test_export_leaves_out_the_account_and_import_keeps_customers_out(): void
    {
        $account = GitAccount::create(['user_id' => $this->admin->id, 'provider' => 'github', 'remote_id' => '42', 'username' => 'aylin', 'access_token' => 'gho_token']);
        $template = $this->packageTemplate(['provider' => 'github', 'git_account_id' => $account->id, 'remote_repository' => 'aylin/roifex', 'package_url' => null, 'package_asset' => '*.zip', 'customers_can_install' => true]);
        $templates = app(AppTemplates::class);

        $data = $templates->export($template);
        $this->assertArrayNotHasKey('git_account_id', $data);
        $this->assertSame(AppTemplates::EXPORT_VERSION, $data['serfex_app_template']);

        $copy = $templates->import(json_decode(json_encode($data), true));
        $this->assertSame('Roifex (2)', $copy->name);
        $this->assertNull($copy->git_account_id);
        $this->assertFalse($copy->customers_can_install);
        $this->assertSame($template->questions(), $copy->questions());

        $this->expectException(InvalidArgumentException::class);
        $templates->import(['name' => 'Başka bir şey']);
    }

    public function test_the_newest_github_release_package_is_read_with_its_checksum(): void
    {
        Http::fake([
            'api.github.com/repos/aylin/roifex/releases/latest' => Http::response([
                'tag_name' => 'v1.0.44',
                'assets' => [
                    ['name' => 'roifex-1.0.44.zip', 'url' => 'https://api.github.com/repos/aylin/roifex/releases/assets/7', 'size' => 1024, 'digest' => 'sha256:'.str_repeat('a', 64)],
                    ['name' => 'manifest.sig', 'url' => 'https://api.github.com/repos/aylin/roifex/releases/assets/8', 'size' => 88],
                ],
            ]),
        ]);
        $template = $this->packageTemplate(['provider' => 'github', 'repository' => 'aylin/roifex', 'package_url' => null]);

        app(AppTemplates::class)->check($template, force: true);

        $template->refresh();
        $this->assertNull($template->check_error);
        $this->assertSame('v1.0.44', $template->latest_version);
        $this->assertSame('roifex-1.0.44.zip', $template->latest_package['name']);
        $this->assertSame(str_repeat('a', 64), $template->latest_package['sha256']);
    }

    public function test_bulk_update_queues_only_the_sites_behind_one_after_the_other(): void
    {
        Bus::fake();
        $template = AppTemplate::create([
            'name' => 'Mağaza', 'source' => 'git', 'provider' => 'github', 'repository' => 'ajans/magaza',
            'ref_type' => 'tag', 'ref' => 'v2.5.0', 'database' => 'mysql', 'package_manager' => 'npm',
        ]);
        LaravelApp::factory()->create(['app_template_id' => $template->id, 'template_version' => 'v2.4.0']);
        LaravelApp::factory()->create(['app_template_id' => $template->id, 'template_version' => 'v2.5.0']);

        $this->assertSame(1, app(AppTemplates::class)->updateAll($template, true, $this->admin));

        Bus::assertChained([UpdateFromTemplate::class]);
    }
}
