<?php

namespace Tests\Feature;

use App\Livewire\Laravel\Sections\Environment;
use App\Livewire\Laravel\Sections\Logs;
use App\Livewire\Laravel\Sections\Overview;
use App\Models\Customer;
use App\Models\LaravelApp;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Laravel\EnvFile;
use App\Services\Laravel\LogParser;
use App\Services\Laravel\MailEnv;
use App\Services\Mail\MailPassword;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Laravel applications send mail from this server only with a mailbox
 * login on port 587: sendmail and unauthenticated SMTP are refused to
 * sites, so the panel says so and writes a mailbox's login into the .env.
 */
class LaravelMailTest extends TestCase
{
    use RefreshDatabase;

    /** What Laravel 13's .env.example gives a new application. */
    private const EXAMPLE = "APP_NAME=Laravel\nMAIL_MAILER=log\nMAIL_SCHEME=null\nMAIL_HOST=127.0.0.1\nMAIL_PORT=2525\nMAIL_USERNAME=null\nMAIL_PASSWORD=null\nMAIL_FROM_ADDRESS=\"hello@example.com\"\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n";

    private string $env = self::EXAMPLE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'Aylin']));
        app(MailSettings::class)->saveServer(['setup_at' => now()->toIso8601String()]);
        Setting::put('mail.hostname', 'mail.roifex.com');
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturnUsing(fn (string $method, array $params = []) => $this->answer($method, $params));
        $this->app->instance(AgentClient::class, $agent);
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
            'mail.senders.apply' => [],
            'laravel.health' => ['ok' => true, 'status' => 200, 'ms' => 30],
            'laravel.status' => ['exists' => true, 'current' => '1', 'releases' => [], 'workers' => [], 'schedule' => ['enabled' => false]],
            'laravel.probe' => ['laravel' => '13.6.0', 'environment' => 'production', 'debug' => false, 'queue' => 'database', 'cache' => 'database', 'database' => 'mysql', 'session' => 'database',
                'mail' => ['mailer' => 'sendmail', 'from' => 'noreply@roifex.com', 'host' => '', 'port' => '', 'username' => '']],
            'laravel.logs' => ['files' => [['name' => 'laravel.log', 'size' => 300]], 'file' => 'laravel.log', 'truncated' => false,
                'content' => "[2026-10-29 15:20:11] production.ERROR: Expected response code \"250\" but got code \"451\", with message \"451 4.3.0 Error: internal error 512\". {\"exception\":\"[object] (Symfony\\\\Component\\\\Mailer\\\\Exception\\\\UnexpectedResponseException\"}\n#0 trace\n[2026-10-29 15:21:00] production.INFO: Sipariş alındı\n"],
            default => throw new \RuntimeException("unexpected agent call {$method}"),
        };
    }

    private function laravelApp(?Customer $customer = null): LaravelApp
    {
        $user = SystemUser::factory()->state(['name' => 'roifex', 'customer_id' => $customer?->id]);
        $site = Site::factory()->for($user)->create(['domain' => 'roifex.com', 'web_root' => 'laravel/current/public']);

        return LaravelApp::factory()->for($site)->create(['current_release' => '1']);
    }

    private function mailbox(Site $site, string $name = 'noreply'): Mailbox
    {
        $domain = $site->mailDomain()->first() ?? MailDomain::factory()->create(['name' => $site->domain, 'site_id' => $site->id]);

        return Mailbox::factory()->for($domain, 'domain')->create(['name' => $name, 'password_hash' => MailPassword::hash('Posta-parola-42')]);
    }

    /**
     * @return list<string>
     */
    private function problems(string $env): array
    {
        return app(MailEnv::class)->problems(MailEnv::fromEnv(EnvFile::values($env)));
    }

    public function test_each_kind_of_setting_is_judged(): void
    {
        // Laravel's defaults: the log mailer and an example sender.
        $this->assertCount(2, $this->problems(self::EXAMPLE));
        $this->assertStringContainsString('MAIL_MAILER=log', $this->problems(self::EXAMPLE)[0]);

        // sendmail and unauthenticated SMTP to this server are refused.
        $this->assertSame(['Bu sunucuda sendmail (PHP mail()) ile gönderim kapalı.'], $this->problems("MAIL_MAILER=sendmail\nMAIL_FROM_ADDRESS=noreply@roifex.com\n"));
        $this->assertStringContainsString('127.0.0.1:25', $this->problems("MAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=25\nMAIL_FROM_ADDRESS=info@roifex.com\n")[0]);
        $this->assertStringContainsString('mail.roifex.com:587', $this->problems("MAIL_MAILER=smtp\nMAIL_HOST=mail.roifex.com\nMAIL_PORT=587\nMAIL_FROM_ADDRESS=info@roifex.com\n")[0]);

        // A mailbox login works; the sender must be the mailbox.
        $login = "MAIL_MAILER=smtp\nMAIL_HOST=mail.roifex.com\nMAIL_PORT=587\nMAIL_USERNAME=noreply@roifex.com\nMAIL_PASSWORD=x\n";
        $this->assertSame([], $this->problems($login."MAIL_FROM_ADDRESS=noreply@roifex.com\n"));
        $this->assertStringContainsString('ile aynı değil', $this->problems($login."MAIL_FROM_ADDRESS=satis@roifex.com\n")[0]);

        // A real SMTP server or a mail API is the application's own business…
        $this->assertSame([], $this->problems("MAIL_MAILER=smtp\nMAIL_HOST=smtp.postmarkapp.com\nMAIL_PORT=587\nMAIL_USERNAME=key\nMAIL_FROM_ADDRESS=info@roifex.net\n"));
        $this->assertSame([], $this->problems("MAIL_MAILER=ses\nMAIL_FROM_ADDRESS=info@roifex.net\n"));
        // …except an example sender.
        $this->assertSame(['Gönderen adresi hello@example.com örnek bir adres.'], $this->problems("MAIL_MAILER=ses\nMAIL_FROM_ADDRESS=hello@example.com\n"));

        // Laravel 6 reads MAIL_DRIVER.
        $this->assertCount(1, $this->problems("MAIL_DRIVER=sendmail\nMAIL_FROM_ADDRESS=info@roifex.com\n"));

        // Without a mail server nothing could send: no warning.
        app(MailSettings::class)->saveServer(['setup_at' => null]);
        $this->assertSame([], $this->problems(self::EXAMPLE));
    }

    public function test_a_mailbox_login_is_written_over_the_defaults(): void
    {
        $box = $this->mailbox($this->laravelApp()->site);
        $mail = app(MailEnv::class);

        $values = $mail->values($box, 'Posta-parola-42', EnvFile::values(self::EXAMPLE));
        $this->assertSame([
            'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'mail.roifex.com', 'MAIL_PORT' => '587', 'MAIL_USERNAME' => 'noreply@roifex.com',
            'MAIL_PASSWORD' => 'Posta-parola-42', 'MAIL_FROM_ADDRESS' => 'noreply@roifex.com', 'MAIL_SCHEME' => 'smtp',
        ], $values);

        $written = MailEnv::write(self::EXAMPLE, $values);
        $this->assertSame([], $this->problems($written));
        // Every other line stays as it was, ${APP_NAME} included.
        $this->assertStringContainsString('MAIL_FROM_NAME="${APP_NAME}"', $written);
        $this->assertStringStartsWith("APP_NAME=Laravel\n", $written);

        // Laravel 6 reads MAIL_DRIVER; older apps set MAIL_ENCRYPTION.
        $old = $mail->values($box, 'p', ['MAIL_DRIVER' => 'mail', 'MAIL_ENCRYPTION' => 'null']);
        $this->assertSame('smtp', $old['MAIL_DRIVER']);
        $this->assertArrayNotHasKey('MAIL_MAILER', $old);
        $this->assertSame('tls', $old['MAIL_ENCRYPTION']);
    }

    public function test_environment_page_connects_a_mailbox_with_undo(): void
    {
        $app = $this->laravelApp(Customer::factory()->create());
        $this->mailbox($app->site, 'info');
        $this->mailbox($app->site);

        $page = Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])
            ->assertSee('Uygulama bu sunucudan e-posta gönderemiyor')
            ->assertSee('Gönderen adresi hello@example.com örnek bir adres.')
            ->call('openMail')
            // The modal sits inside the page's form: a form of its own would be dropped by the browser.
            ->assertSeeHtml('wire:click="saveMail"')
            ->assertSet('mailboxId', Mailbox::where('name', 'noreply')->value('id'))
            ->set('mailPassword', 'yanlış')
            ->call('saveMail')
            ->assertHasErrors('mailPassword');
        $this->assertSame(self::EXAMPLE, $this->env);

        $page->set('mailPassword', 'Posta-parola-42')
            ->call('saveMail')
            ->assertHasNoErrors()
            ->assertSet('mailPassword', '')
            ->assertDispatched('toast', message: 'noreply@roifex.com posta kutusuyla gönderecek (mail.roifex.com, port 587).')
            ->assertDontSee('Uygulama bu sunucudan e-posta gönderemiyor');

        $this->assertStringContainsString("MAIL_MAILER=smtp\n", $this->env);
        $this->assertStringContainsString("MAIL_USERNAME=noreply@roifex.com\n", $this->env);
        $this->assertStringContainsString("MAIL_PASSWORD=Posta-parola-42\n", $this->env);

        $page->call('restore', $app->envVersions()->first()->id);
        $this->assertSame(self::EXAMPLE, $this->env);
    }

    public function test_without_a_mailbox_the_page_links_to_open_one(): void
    {
        $app = $this->laravelApp();
        $domain = MailDomain::factory()->create(['name' => 'roifex.com', 'site_id' => $app->site_id]);

        Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])
            ->call('openMail')
            ->assertSee('için henüz posta kutusu yok.')
            ->assertSee('noreply@roifex.com')
            ->assertSee(route('mail.show', $domain))
            ->call('saveMail')
            ->assertHasErrors('mailboxId');
    }

    public function test_overview_and_logs_explain_the_failure(): void
    {
        $app = $this->laravelApp();

        Livewire::withoutLazyLoading()->test(Overview::class, ['appId' => $app->id])
            ->assertSee('noreply@roifex.com')
            ->assertSee('Bu sunucudan gönderemiyor');

        Livewire::withoutLazyLoading()->test(Logs::class, ['appId' => $app->id])
            ->assertSee('E-posta reddedildi: bu sunucuda sendmail (PHP mail()) ile gönderim kapalı.')
            ->assertSee('E-posta ayarını düzelt');

        $this->assertNull(LogParser::mailHint('Sipariş alındı'));
        $this->assertStringContainsString('sendmail', (string) LogParser::mailHint('sendmail: fatal: roifex(1001): not authorized to use sender=noreply@roifex.com'));
        $this->assertStringContainsString('giriş yapmadan', (string) LogParser::mailHint('Expected response code "250" but got code "454", with message "454 4.7.1 <x@gmail.com>: Relay access denied".'));
        $this->assertStringContainsString('giriş yapmadan', (string) LogParser::mailHint('554 5.7.1 <noreply@roifex.com>: Sender address rejected: Mail from this server may use roifex.com as sender only with a mailbox login on port 587'));
        $this->assertStringContainsString('parolası yanlış', (string) LogParser::mailHint('Failed to authenticate on SMTP server with username "noreply@roifex.com" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed: authentication failure".'));
        $this->assertStringContainsString('ait değil', (string) LogParser::mailHint('553 5.7.1 <satis@roifex.com>: Sender address rejected: not owned by user noreply@roifex.com'));
        $this->assertStringContainsString('587', (string) LogParser::mailHint('Connection could not be established with host "127.0.0.1:2525": stream_socket_client(): Unable to connect'));
    }

    public function test_wordpress_smtp_plugins_are_recognised(): void
    {
        $this->assertNull(MailEnv::wordpressSmtpPlugin([['name' => 'akismet', 'status' => 'active', 'title' => 'Akismet']]));
        $this->assertNull(MailEnv::wordpressSmtpPlugin([['name' => 'wp-mail-smtp', 'status' => 'inactive', 'title' => 'WP Mail SMTP']]));
        $this->assertSame('WP Mail SMTP', MailEnv::wordpressSmtpPlugin([['name' => 'wp-mail-smtp', 'status' => 'active', 'title' => 'WP Mail SMTP']]));
        $this->assertSame('my-smtp-relay', MailEnv::wordpressSmtpPlugin([['name' => 'my-smtp-relay', 'status' => 'must-use', 'title' => '']]));
    }
}
