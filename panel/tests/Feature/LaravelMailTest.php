<?php

namespace Tests\Feature;

use App\Jobs\ApplySitePhp;
use App\Jobs\DeployLaravelApp;
use App\Livewire\Laravel\Sections\Environment;
use App\Livewire\Laravel\Sections\Logs;
use App\Livewire\Laravel\Sections\Overview;
use App\Models\Customer;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Laravel\Backups;
use App\Services\Laravel\EnvFile;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\LogParser;
use App\Services\Laravel\MailEnv;
use App\Services\Laravel\Processes;
use App\Services\Mail\MailSettings;
use App\Services\Php\PhpVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Laravel applications send mail from this server: sendmail as the site's
 * account, with a sender address of the site's own domains.
 */
class LaravelMailTest extends TestCase
{
    use RefreshDatabase;

    /** What Laravel 13's .env.example gives a new application. */
    private const EXAMPLE = "APP_NAME=Laravel\nMAIL_MAILER=log\nMAIL_SCHEME=null\nMAIL_HOST=127.0.0.1\nMAIL_PORT=2525\nMAIL_USERNAME=null\nMAIL_PASSWORD=null\nMAIL_FROM_ADDRESS=\"hello@example.com\"\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n";

    private MockInterface $agent;

    private string $env = self::EXAMPLE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'Aylin']));
        Queue::fake();
        app(MailSettings::class)->saveServer(['setup_at' => now()->toIso8601String()]);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturnUsing(fn (string $method, array $params = []) => $this->answer($method, $params))->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function answer(string $method, array $params): mixed
    {
        return match ($method) {
            'laravel.env.get' => ['env' => $this->env, 'example' => self::EXAMPLE],
            'laravel.env.set' => (function () use ($params) {
                $this->env = $params['content'];

                return ['cached' => true];
            })(),
            'laravel.health' => ['ok' => true, 'status' => 200, 'ms' => 30],
            'laravel.status' => ['exists' => true, 'current' => '1', 'releases' => [], 'workers' => [], 'schedule' => ['enabled' => false]],
            'laravel.probe' => ['laravel' => '13.6.0', 'environment' => 'production', 'debug' => false, 'queue' => 'database', 'cache' => 'database', 'database' => 'mysql', 'session' => 'database',
                'mail' => ['mailer' => 'log', 'from' => 'hello@example.com', 'host' => '', 'port' => '', 'username' => '']],
            'laravel.logs' => ['files' => [['name' => 'laravel.log', 'size' => 300]], 'file' => 'laravel.log', 'truncated' => false,
                'content' => "[2026-10-29 15:20:11] production.ERROR: Expected response code \"250\" but got code \"451\", with message \"451 4.3.0 Error: internal error 512\". {\"exception\":\"[object] (Symfony\\\\Component\\\\Mailer\\\\Exception\\\\UnexpectedResponseException\"}\n#0 trace\n[2026-10-29 15:21:00] production.INFO: Sipariş alındı\n"],
            default => throw new \RuntimeException("unexpected agent call {$method}"),
        };
    }

    private function laravelApp(?Customer $customer = null): LaravelApp
    {
        $user = SystemUser::factory()->state(['name' => 'roifex', 'customer_id' => $customer?->id]);
        $site = Site::factory()->for($user)->create(['domain' => 'roifex.com', 'aliases' => ['www.roifex.com'], 'web_root' => 'laravel/current/public']);

        return LaravelApp::factory()->for($site)->create(['current_release' => '1']);
    }

    /**
     * @return list<string>
     */
    private function problems(Site $site, string $env): array
    {
        return app(MailEnv::class)->problems($site, MailEnv::fromEnv(EnvFile::values($env)));
    }

    public function test_laravel_defaults_are_found_and_fixed(): void
    {
        $site = $this->laravelApp()->site;

        $this->assertCount(2, $this->problems($site, self::EXAMPLE));
        $this->assertSame(['MAIL_MAILER' => 'sendmail', 'MAIL_FROM_ADDRESS' => 'noreply@roifex.com'], app(MailEnv::class)->fix($site, EnvFile::values(self::EXAMPLE)));

        $fixed = MailEnv::write(self::EXAMPLE, app(MailEnv::class)->fix($site, EnvFile::values(self::EXAMPLE)));
        $this->assertStringContainsString("MAIL_MAILER=sendmail\n", $fixed);
        $this->assertStringContainsString("MAIL_FROM_ADDRESS=noreply@roifex.com\n", $fixed);
        // Every other line stays as it was, ${APP_NAME} included.
        $this->assertStringContainsString("MAIL_FROM_NAME=\"\${APP_NAME}\"\nAPP_NAME", "MAIL_FROM_NAME=\"\${APP_NAME}\"\n".$fixed);
        $this->assertSame([], $this->problems($site, $fixed));
    }

    public function test_each_kind_of_setting_is_judged(): void
    {
        $site = $this->laravelApp()->site;
        $mail = app(MailEnv::class);

        // SMTP to this server without a login.
        $local = "MAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=25\nMAIL_FROM_ADDRESS=info@roifex.com\n";
        $this->assertStringContainsString('127.0.0.1:25', $this->problems($site, $local)[0]);
        $this->assertSame(['MAIL_MAILER' => 'sendmail'], $mail->fix($site, EnvFile::values($local)));

        // sendmail as another customer's domain, or a www name of its own.
        $this->assertStringContainsString('başka.com', $this->problems($site, "MAIL_MAILER=sendmail\nMAIL_FROM_ADDRESS=satis@başka.com\n")[0]);
        $this->assertSame([], $this->problems($site, "MAIL_MAILER=sendmail\nMAIL_FROM_ADDRESS=info@www.roifex.com\n"));

        // A real SMTP server or a mail API is the application's own business.
        $smtp = "MAIL_MAILER=smtp\nMAIL_HOST=smtp.postmarkapp.com\nMAIL_PORT=587\nMAIL_USERNAME=key\nMAIL_FROM_ADDRESS=info@roifex.net\n";
        $this->assertSame([], $this->problems($site, $smtp));
        $this->assertSame([], $mail->fix($site, EnvFile::values($smtp)));
        $this->assertSame([], $this->problems($site, "MAIL_MAILER=ses\nMAIL_FROM_ADDRESS=info@roifex.net\n"));
        // …except an example sender.
        $this->assertSame(['MAIL_FROM_ADDRESS' => 'noreply@roifex.com'], $mail->fix($site, EnvFile::values("MAIL_MAILER=ses\nMAIL_FROM_ADDRESS=hello@example.com\n")));

        // Laravel 6 reads MAIL_DRIVER.
        $this->assertSame(['MAIL_DRIVER' => 'sendmail'], $mail->fix($site, EnvFile::values("MAIL_DRIVER=log\nMAIL_FROM_ADDRESS=info@roifex.com\n")));

        // Without a mail server nothing could send: no warning.
        app(MailSettings::class)->saveServer(['setup_at' => null]);
        $this->assertSame([], $this->problems($site, self::EXAMPLE));
        $this->assertSame([], $mail->fix($site, EnvFile::values(self::EXAMPLE)));
    }

    public function test_the_first_deploy_writes_the_mail_settings(): void
    {
        $site = Site::factory()->for(SystemUser::factory()->state(['name' => 'roifex']))->create(['domain' => 'roifex.com']);
        $app = app(LaravelApps::class)->install($site, ['source' => 'create', 'kit' => '', 'database' => 'sqlite', 'frontend' => false, 'worker' => false, 'scheduler' => false]);
        $this->agent->shouldReceive('call')->with('laravel.deploy', Mockery::any(), Mockery::any())->andReturn(['release' => '1', 'current' => '1', 'rolled_back' => false, 'releases' => []]);
        $this->agent->shouldReceive('call')->with('site.apply', Mockery::any(), Mockery::any())->andReturn([]);
        $this->agent->shouldReceive('call')->with(Mockery::pattern('/^laravel\.(queue|schedule|process)\.apply$/'), Mockery::any(), Mockery::any())->andReturn([]);

        (new DeployLaravelApp($app->deployments()->sole()))->handle($this->agent, new Processes($this->agent), new Backups($this->agent));

        $this->assertStringContainsString("MAIL_MAILER=sendmail\n", $this->env);
        $this->assertStringContainsString("MAIL_FROM_ADDRESS=noreply@roifex.com\n", $this->env);
        $version = $app->envVersions()->sole();
        $this->assertSame('Serfex', $version->user_name);
        $this->assertSame(self::EXAMPLE, $version->content, 'the history keeps the file as the install left it');
    }

    public function test_environment_page_warns_and_fixes_with_undo(): void
    {
        $app = $this->laravelApp(Customer::factory()->create());

        $page = Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])
            ->assertSee('Uygulama bu sunucudan e-posta gönderemiyor')
            ->assertSee('Gönderen adresi hello@example.com örnek bir adres.')
            ->call('fixMail')
            ->assertDispatched('toast', message: 'E-posta ayarı düzeltildi: MAIL_MAILER=sendmail, MAIL_FROM_ADDRESS=noreply@roifex.com')
            ->assertDontSee('Uygulama bu sunucudan e-posta gönderemiyor');

        $this->assertStringContainsString("MAIL_MAILER=sendmail\n", $this->env);
        // The site's PHP pool is written again so that it keeps proc_open.
        Queue::assertPushed(ApplySitePhp::class, fn ($job) => $job->siteIds === [$app->site_id]);

        $page->call('restore', $app->envVersions()->sole()->id);
        $this->assertSame(self::EXAMPLE, $this->env);
    }

    public function test_overview_and_logs_explain_the_failure(): void
    {
        $app = $this->laravelApp();

        Livewire::withoutLazyLoading()->test(Overview::class, ['appId' => $app->id])
            ->assertSee('hello@example.com')
            ->assertSee('Bu sunucudan gönderemiyor');

        Livewire::withoutLazyLoading()->test(Logs::class, ['appId' => $app->id])
            ->assertSee('E-posta reddedildi: gönderen adresi bu sitenin alan adlarından biri değil.')
            ->assertSee('E-posta ayarını düzelt');

        $this->assertNull(LogParser::mailHint('Sipariş alındı'));
        $this->assertStringContainsString('25 numaralı', (string) LogParser::mailHint('Expected response code "250" but got code "554", with message "554 5.7.1 <noreply@roifex.com>: Sender address rejected: Mail from this server may use roifex.com as sender only through sendmail or a mailbox login on port 587".'));
        $this->assertStringContainsString('SMTP', (string) LogParser::mailHint('Connection could not be established with host "127.0.0.1:2525": stream_socket_client(): Unable to connect'));
        $this->assertStringContainsString('proc_open', (string) LogParser::mailHint('Call to undefined function Symfony\Component\Mailer\Transport\Smtp\Stream\proc_open()'));
    }

    public function test_laravel_sites_of_customers_keep_proc_open_only(): void
    {
        $customer = Customer::factory()->create();
        $app = $this->laravelApp($customer);
        $php = app(PhpVersions::class);

        $disabled = $php->siteSpec($app->site)['disable_functions'];
        $this->assertNotContains('proc_open', $disabled);
        $this->assertContains('exec', $disabled);
        $this->assertContains('popen', $disabled);

        $other = Site::factory()->for(SystemUser::factory()->state(['customer_id' => $customer->id]))->create();
        $this->assertContains('proc_open', $php->siteSpec($other)['disable_functions']);
    }
}
