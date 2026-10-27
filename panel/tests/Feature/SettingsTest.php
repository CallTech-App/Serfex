<?php

namespace Tests\Feature;

use App\Jobs\IssuePanelCertificate;
use App\Jobs\PurgeAdministrator;
use App\Livewire\Settings\AdminIndex;
use App\Livewire\Settings\GeneralSettings;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Settings\ServerSettings;
use App\Mail\PanelMail;
use App\Models\Certificate;
use App\Models\NotificationEvent;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Settings\Notifications;
use App\Services\Settings\PanelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Araçlar ve Ayarlar: the panel's address, port, time zone and
 * certificate, the server's name, administrators and their roles, and
 * notifications by e-mail and webhook.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // The container's own disk is no business of these tests.
        config(['serfex.disk_paths' => []]);
        $this->agent = new FakeAgent([
            'system.info' => ['hostname' => 'srv-ist-01', 'os' => 'Ubuntu 24.04.3 LTS', 'kernel' => '6.8.0-60-generic', 'cpus' => 8, 'memory_bytes' => 16 * 1024 ** 3,
                'timezone' => 'Europe/Istanbul', 'uptime_seconds' => 86400 * 12, 'addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => ['2001:db8::10']]],
            'system.timezones' => ['Europe/Berlin', 'Europe/Istanbul', 'UTC'],
            'system.timezone.set' => fn ($p) => ['timezone' => $p['timezone']],
            'system.hostname.set' => fn ($p) => ['hostname' => $p['hostname']],
            'panel.web.ports' => fn ($p) => ['ports' => $p['ports'], 'port' => $p['ports'][0]],
            'ssl.panel.install' => ['domains' => ['panel.kahve.com.tr'], 'not_before' => '2026-10-01T00:00:00Z', 'not_after' => '2027-10-01T00:00:00Z', 'changed' => true, 'reloaded' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->admin = User::factory()->create(['name' => 'Sercan', 'role' => 'full']);
        $this->actingAs($this->admin);
    }

    public function test_the_sidebar_opens_araclar_ve_ayarlar_and_every_page_loads(): void
    {
        $this->get('/')->assertOk()->assertSee(route('settings.general'));
        foreach (['settings.general', 'settings.server', 'settings.notifications', 'settings.admins', 'settings.api', 'settings.audit', 'settings.updates'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('Araçlar ve Ayarlar')->assertSee('İşlem günlüğü');
        }
    }

    public function test_general_settings_save_the_address_time_zone_and_session_with_undo(): void
    {
        $this->get(route('settings.general'))->assertOk()->assertSee('Panel adresi')->assertSee('Europe/Berlin');

        $page = Livewire::test(GeneralSettings::class)
            ->set('hostname', 'Panel.Kahve.com.tr')
            ->set('timezone', 'Europe/Berlin')
            ->set('sessionMinutes', '480')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Genel ayarlar kaydedildi. Panel sertifikası alınıyor.');

        $this->assertSame([['timezone' => 'Europe/Berlin']], $this->agent->called('system.timezone.set'));
        $this->assertSame('Europe/Berlin', Setting::get('panel.timezone'));
        $this->assertSame(480, Setting::get('panel.session_minutes'));
        $this->assertSame('panel.kahve.com.tr', Setting::get('panel.hostname'));
        $this->assertSame(['panel.kahve.com.tr'], Certificate::where('kind', 'panel')->sole()->domains);
        Queue::assertPushed(IssuePanelCertificate::class);

        PanelSettings::apply();
        $this->assertSame('Europe/Berlin', config('serfex.timezone'));
        $this->assertSame(480, config('session.lifetime'));
        $this->assertSame('Europe/Berlin', config('app.schedule_timezone'));

        $page->call('undoSave', ['hostname' => null, 'timezone' => 'UTC', 'session' => 120])
            ->assertSet('hostname', '')
            ->assertSet('timezone', 'UTC');
        $this->assertSame('UTC', $this->agent->called('system.timezone.set')[1]['timezone']);
        $this->assertNull(Setting::get('panel.hostname'));
        $this->assertSame(120, Setting::get('panel.session_minutes'));
    }

    public function test_the_panel_address_cannot_be_a_site_or_the_mail_server(): void
    {
        Site::factory()->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr']]);
        Setting::put('mail.hostname', 'mail.kahve.com.tr');

        foreach (['www.kahve.com.tr', 'mail.kahve.com.tr', 'panel'] as $bad) {
            Livewire::test(GeneralSettings::class)->set('hostname', $bad)->call('save')->assertHasErrors('hostname');
        }
        Livewire::test(GeneralSettings::class)->set('timezone', 'Mars/Olympus')->call('save')->assertHasErrors('timezone');
        Livewire::test(GeneralSettings::class)->set('sessionMinutes', '5')->call('save')->assertHasErrors('sessionMinutes');
        $this->assertNull(Setting::get('panel.hostname'));
        $this->assertSame([], $this->agent->called('system.timezone.set'));
    }

    public function test_a_port_change_listens_on_both_ports_until_confirmed_from_the_new_one(): void
    {
        $page = Livewire::test(GeneralSettings::class)
            ->call('openPort')
            ->set('port', '443')
            ->call('savePort')
            ->assertHasErrors('port')
            ->set('port', '8443')
            ->call('savePort')
            ->assertHasErrors('port')
            ->set('port', '9443')
            ->call('savePort')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Panel 9443 portunda da açıldı. Yeni adresten açıp onaylayın.');

        $this->assertSame([['ports' => [9443, 8443]]], $this->agent->called('panel.web.ports'));
        $settings = app(PanelSettings::class);
        $this->assertSame([9443, 8443], $settings->ports());
        $this->assertSame(8443, $settings->port());
        $this->assertSame([], $settings->managerSetupParams(), 'database installs keep both ports meanwhile');

        // Confirming from the old port does nothing.
        $page->call('acceptPort')->assertDispatched('toast', message: 'Onayı yeni porttan verin; böylece yeni portun size açık olduğu görülür.');
        $this->assertNotNull($settings->portChange());

        $this->get(route('settings.general'))->assertSee('9443');
    }

    public function test_confirming_from_the_new_port_keeps_it_and_undo_goes_back_with_a_new_window(): void
    {
        $settings = app(PanelSettings::class);
        $settings->changePort(9443);

        $this->app['request']->server->set('SERVER_PORT', 9443);
        $this->app['request']->headers->set('HOST', 'panel.kahve.com.tr:9443');
        Livewire::test(GeneralSettings::class)
            ->call('acceptPort')
            ->assertDispatched('toast', message: 'Panel artık yalnızca 9443 portunda.', undo: ['method' => 'portBack', 'args' => [8443]]);
        $this->assertSame(['ports' => [9443]], $this->agent->called('panel.web.ports')[1]);
        $this->assertSame(9443, $settings->port());
        $this->assertNull($settings->portChange());
        $this->assertSame(['port' => 9443], $settings->managerSetupParams());

        Livewire::test(GeneralSettings::class)->call('portBack', 8443);
        $this->assertSame(['ports' => [8443, 9443]], $this->agent->called('panel.web.ports')[2]);
    }

    public function test_an_unconfirmed_port_change_goes_back_by_itself(): void
    {
        $settings = app(PanelSettings::class);
        $settings->changePort(9443);
        $this->travel(PanelSettings::CONFIRM_MINUTES + 1)->minutes();

        $this->artisan('serfex:settings-check')->assertSuccessful();

        $this->assertSame(['ports' => [8443]], $this->agent->called('panel.web.ports')[1]);
        $this->assertNull($settings->portChange());
        $this->assertSame(8443, $settings->port());
    }

    public function test_an_own_certificate_replaces_lets_encrypt_with_the_chain_after_it(): void
    {
        Setting::put('panel.hostname', 'panel.kahve.com.tr');
        $cert = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
        $chain = "-----BEGIN CERTIFICATE-----\nMIIC\n-----END CERTIFICATE-----";

        Livewire::test(GeneralSettings::class)
            ->call('openUpload')
            ->set('certificate', 'merhaba')
            ->set('privateKey', 'dunya')
            ->call('uploadCertificate')
            ->assertHasErrors(['certificate', 'privateKey'])
            ->set('certificate', $cert)
            ->set('privateKey', "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----")
            ->set('chain', $chain)
            ->call('uploadCertificate')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Panelin kendi sertifikası kuruldu.', undo: ['method' => 'issuePanel', 'args' => []]);

        $sent = $this->agent->called('ssl.panel.install')[0];
        $this->assertSame($cert."\n".$chain."\n", $sent['certificate']);
        $this->assertArrayNotHasKey('chain', $sent);
        $panel = Certificate::where('kind', 'panel')->sole();
        $this->assertSame(Certificate::CUSTOM, $panel->source);
        $this->assertFalse($panel->auto_renew);
    }

    public function test_the_server_page_shows_the_server_and_renames_it_with_undo(): void
    {
        $this->get(route('settings.server'))->assertOk()
            ->assertSee('srv-ist-01')->assertSee('203.0.113.10')->assertSee('2001:db8::10')->assertSee('Ubuntu 24.04.3 LTS');

        $page = Livewire::test(ServerSettings::class)
            ->set('hostname', 'bad_name!')
            ->call('save')
            ->assertHasErrors('hostname')
            ->set('hostname', 'SRV-IST-02.kahve.com.tr')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', undo: ['method' => 'undoHostname', 'args' => ['srv-ist-01']]);
        $this->assertSame([['hostname' => 'srv-ist-02.kahve.com.tr']], $this->agent->called('system.hostname.set'));
        $this->assertSame('srv-ist-02.kahve.com.tr', Setting::get('server.hostname'));

        $page->call('undoHostname', 'srv-ist-01');
        $this->assertSame('srv-ist-01', $this->agent->called('system.hostname.set')[1]['hostname']);
    }

    public function test_the_server_page_says_when_the_agent_is_unreachable(): void
    {
        $this->agent->answers['system.info'] = new AgentException('connection refused');
        $this->get(route('settings.server'))->assertOk()->assertSee('Sunucu ajanına ulaşılamadı');
    }

    // --- Yöneticiler ---

    public function test_inviting_an_administrator_mails_a_link_and_undo_cancels_it(): void
    {
        Mail::fake();
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.kahve.com.tr', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => 'serfex@kahve.com.tr', 'from_name' => 'Serfex']);

        $page = Livewire::test(AdminIndex::class)
            ->call('openInvite')
            ->set('email', $this->admin->email)
            ->call('invite')
            ->assertHasErrors('email')
            ->set('email', 'Emre@Kahve.com.tr')
            ->set('role', 'support')
            ->call('invite')
            ->assertHasNoErrors();

        $emre = User::where('email', 'emre@kahve.com.tr')->sole();
        $this->assertTrue($emre->is_admin);
        $this->assertSame('support', $emre->role);
        $this->assertTrue($emre->isInvited());
        $this->assertTrue($page->get('invitation')['mailed']);
        $this->assertStringContainsString('/davet/', $page->get('invitation')['link']);
        Mail::assertSent(PanelMail::class, fn ($m) => $m->hasTo('emre@kahve.com.tr'));

        // The link works once.
        auth()->logout();
        $this->get(parse_url($page->get('invitation')['link'], PHP_URL_PATH))->assertOk();
        $this->actingAs($this->admin);

        $page->call('cancelInvite', $emre->id);
        $this->assertNull(User::find($emre->id));
    }

    public function test_roles_change_with_undo_and_the_last_full_administrator_stays(): void
    {
        $other = User::factory()->create(['name' => 'Emre', 'role' => 'full']);

        Livewire::test(AdminIndex::class)
            ->call('openEdit', $this->admin->id)
            ->set('editRole', 'readonly')
            ->call('saveRole')
            ->assertHasErrors('editRole');

        $page = Livewire::test(AdminIndex::class)
            ->call('openEdit', $other->id)
            ->set('editRole', 'readonly')
            ->call('saveRole')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Emre artık Salt okunur.', undo: ['method' => 'undoRole', 'args' => [$other->id, 'full']]);
        $this->assertTrue($other->fresh()->isReadOnly());

        $page->call('undoRole', $other->id, 'full');
        $this->assertTrue($other->fresh()->isFullAdmin());
    }

    public function test_removing_an_administrator_signs_them_out_and_undo_brings_them_back(): void
    {
        $other = User::factory()->create(['name' => 'Emre', 'role' => 'support']);

        $page = Livewire::test(AdminIndex::class)
            ->call('confirmRemove', $other->id)
            ->call('remove')
            ->assertDispatched('toast', message: 'Emre kaldırıldı; oturumları kapandı.', undo: ['method' => 'restore', 'args' => [$other->id]]);
        $this->assertNotNull($other->fresh()->removed_at);
        Queue::assertPushed(PurgeAdministrator::class);

        // A removed administrator is signed out and cannot sign in.
        $this->actingAs($other->fresh())->get('/')->assertRedirect(route('login'));
        auth()->logout();
        $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertSessionHasErrors();

        $this->actingAs($this->admin);
        $page->call('restore', $other->id);
        $this->assertNull($other->fresh()->removed_at);

        // The purge after the undo window deletes only accounts still removed.
        (new PurgeAdministrator($other->id))->handle();
        $this->assertNotNull(User::find($other->id));
    }

    public function test_support_administrators_cannot_open_araclar_ve_ayarlar(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $this->actingAs($support);

        $this->get('/')->assertOk()->assertDontSee('href="'.route('settings.general').'"', false)->assertDontSee('href="'.route('settings.updates').'"', false)->assertSee('Destek');
        foreach (['settings.general', 'settings.admins', 'settings.api', 'settings.audit', 'settings.updates', 'ai.settings'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('sites.index'))->assertOk();
        $this->get(route('ssl.settings'))->assertOk()->assertDontSee('href="'.route('settings.general').'"', false);
    }

    // --- Bildirimler ---

    public function test_notification_settings_save_with_undo_and_check_addresses(): void
    {
        $page = Livewire::test(NotificationSettings::class)
            ->set('emails', 'ops@kahve.com.tr, yanlis')
            ->call('save')
            ->assertHasErrors('emails')
            ->set('emails', 'OPS@kahve.com.tr; sercan@kahve.com.tr')
            ->set('webhookUrl', 'https://hooks.kahve.com.tr/serfex')
            ->set('events.update', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('emails', 'ops@kahve.com.tr, sercan@kahve.com.tr');

        $saved = app(Notifications::class)->settings();
        $this->assertSame(['ops@kahve.com.tr', 'sercan@kahve.com.tr'], $saved['emails']);
        $this->assertFalse($saved['events']['update']);
        $this->assertTrue($saved['events']['disk']);

        $page->call('undoSave', ['emails' => [], 'events' => ['update' => true], 'webhook_url' => '']);
        $this->assertSame([], app(Notifications::class)->settings()['emails']);
        $this->assertSame([$this->admin->email], app(Notifications::class)->recipients());
    }

    public function test_a_problem_is_told_once_by_mail_and_signed_webhook_and_again_when_it_is_over(): void
    {
        Mail::fake();
        Http::fake(['hooks.kahve.com.tr/*' => Http::response('', 204)]);
        $notifications = app(Notifications::class);
        $notifications->save(['emails' => ['ops@kahve.com.tr'], 'webhook_url' => 'https://hooks.kahve.com.tr/serfex']);
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.kahve.com.tr', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        $certificate = Certificate::factory()->create(['site_id' => null, 'kind' => 'panel', 'name' => 'panel', 'not_after' => now()->addDays(3), 'renew_error' => 'DNS bu sunucuyu göstermiyor']);

        $this->assertSame(['opened' => 1, 'resolved' => 0], $notifications->check());
        $this->assertSame(['opened' => 0, 'resolved' => 0], $notifications->check(), 'told once');
        Mail::assertSent(PanelMail::class, 1);
        Http::assertSent(function ($request) use ($notifications) {
            $payload = json_decode($request->body(), true);

            return $request->hasHeader('X-Serfex-Signature', 'sha256='.hash_hmac('sha256', $request->body(), $notifications->secret()))
                && $payload['event'] === 'serfex.problems' && $payload['problems'][0]['kind'] === 'certificate';
        });
        $event = NotificationEvent::sole();
        $this->assertSame('open', $event->state);
        $this->assertSame(['email' => true, 'webhook' => true], $event->sent);

        $certificate->update(['not_after' => now()->addDays(80), 'renew_error' => null]);
        $this->assertSame(['opened' => 0, 'resolved' => 1], $notifications->check());
        Mail::assertSent(PanelMail::class, 2);
        $this->assertSame('resolved', $event->fresh()->state);

        $this->get(route('settings.notifications'))->assertOk()->assertSee('Düzeldi');
    }

    public function test_a_failing_webhook_is_recorded_and_the_test_button_reports_each_channel(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('nope', 500)]);
        app(Notifications::class)->save(['emails' => ['ops@kahve.com.tr'], 'webhook_url' => 'https://hooks.kahve.com.tr/serfex']);
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.kahve.com.tr', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);

        Livewire::test(NotificationSettings::class)
            ->call('test')
            ->assertSet('tested', ['email' => null, 'webhook' => 'adres HTTP 500 yanıtı verdi'])
            ->assertSee('Webhook: adres HTTP 500 yanıtı verdi');
        Mail::assertSent(PanelMail::class, fn ($m) => $m->title === 'Serfex deneme bildirimi');
    }

    public function test_switched_off_kinds_are_not_told(): void
    {
        Mail::fake();
        app(Notifications::class)->save(['emails' => ['ops@kahve.com.tr'], 'events' => ['certificate' => false]]);
        Certificate::factory()->create(['site_id' => null, 'kind' => 'panel', 'name' => 'panel', 'not_after' => now()->addDays(3), 'renew_error' => 'hata']);

        $this->artisan('serfex:notify')->assertSuccessful();
        $this->assertSame(0, NotificationEvent::count());
        Mail::assertNothingSent();
    }

    public function test_smtp_settings_keep_the_password_encrypted_and_out_of_undo(): void
    {
        $page = Livewire::test(NotificationSettings::class)
            ->set('mode', 'smtp')
            ->set('host', '')
            ->call('saveMail')
            ->assertHasErrors('host')
            ->set('host', 'smtp.kahve.com.tr')
            ->set('port', '465')
            ->set('encryption', 'tls')
            ->set('username', 'serfex@kahve.com.tr')
            ->set('password', 'gizli-parola')
            ->call('saveMail')
            ->assertHasNoErrors()
            ->assertSet('password', '');

        $saved = Setting::get('panel.mail');
        $this->assertNotSame('gizli-parola', $saved['password']);
        $this->assertSame('gizli-parola', decrypt($saved['password'], false));
        $page->assertDispatched('toast', fn ($name, $params) => ! str_contains(json_encode($params['undo']), 'password'));

        // Saving again without typing the password keeps it.
        $page->set('port', '587')->set('encryption', 'starttls')->call('saveMail');
        $this->assertSame('gizli-parola', decrypt(Setting::get('panel.mail')['password'], false));
        $this->assertSame([587, 'starttls'], [Setting::get('panel.mail')['port'], Setting::get('panel.mail')['encryption']]);
    }
}
