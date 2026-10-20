<?php

namespace Tests\Feature;

use App\Jobs\IssuePanelCertificate;
use App\Jobs\SetupDnsServer;
use App\Jobs\SyncDnsZone;
use App\Livewire\Setup\SetupWizard;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\BackupSettings;
use App\Services\Dns\DnsSettings;
use App\Services\Security\Firewall;
use App\Services\Settings\Notifications;
use App\Services\Setup\FirstRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * The first run: the one-time setup link install.sh prints, the first
 * administrator it creates, the setup wizard and Genel Bakış's list.
 */
class SetupTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['servik.disk_paths' => []]);
        $this->agent = new FakeAgent([
            'system.addresses' => ['ipv4' => ['203.0.113.10', '203.0.113.11'], 'ipv6' => ['2001:db8::10']],
            'system.info' => ['hostname' => 'srv-ist-01.servik.test', 'timezone' => 'UTC'],
            'system.timezones' => ['Europe/Berlin', 'Europe/Istanbul', 'UTC'],
            'system.timezone.set' => fn ($p) => ['timezone' => $p['timezone']],
            'system.hostname.set' => fn ($p) => ['hostname' => $p['hostname']],
            'ssh.status' => ['installed' => true, 'ports' => [2222], 'root_login' => 'prohibit-password', 'password_auth' => true],
            'firewall.rules.apply' => ['enabled' => true],
            'php.versions' => ['8.4', '8.3'],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function link(): string
    {
        $this->artisan('servik:setup-url', ['--json' => true])->assertSuccessful();

        // The command's own output is not captured by artisan(); make a
        // fresh token the same way.
        return app(FirstRun::class)->newToken()['token'];
    }

    public function test_the_command_prints_a_link_on_the_servers_address(): void
    {
        $this->artisan('servik:setup-url')
            ->expectsOutputToContain('https://203.0.113.10:8443/install/')
            ->assertSuccessful();
        $saved = Setting::get('setup.token');
        $this->assertSame(64, strlen($saved['hash']));
        $this->assertTrue(now()->addHours(23)->lt($saved['expires_at']));
    }

    public function test_the_link_creates_the_first_administrator_and_opens_the_wizard(): void
    {
        $token = $this->link();
        $this->get('/login')->assertOk()->assertSee('Bu panel henüz kurulmadı.')->assertSee('sudo servik setup-url');
        $this->get('/install/'.$token)->assertOk()->assertSee('Yönetici hesabını oluşturun');

        $this->post('/install/'.$token, [
            'name' => 'Sercan', 'email' => 'Sercan@Ornek.com.tr', 'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery', 'locale' => 'en',
        ])->assertRedirect('/install');

        $admin = User::sole();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame('sercan@ornek.com.tr', $admin->email);
        $this->assertTrue($admin->isFullAdmin());
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->password));
        $this->assertSame('en', Setting::get('panel.locale'));
        $this->assertNull(Setting::get('setup.token'));

        // The link works once.
        auth()->logout();
        $this->get('/install/'.$token)->assertStatus(410)->assertSee('Bu panel zaten kuruldu');
        $this->post('/install/'.$token, ['name' => 'X', 'email' => 'x@example.test', 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password', 'locale' => 'tr'])->assertStatus(410);
        $this->assertSame(1, User::count());
        $this->get('/login')->assertDontSee('sudo servik setup-url');
    }

    public function test_a_wrong_old_or_expired_link_says_how_to_get_a_new_one(): void
    {
        $old = $this->link();
        $new = app(FirstRun::class)->newToken()['token'];
        $this->get('/install/'.$old)->assertStatus(410)->assertSee('sudo servik setup-url');
        $this->get('/install/'.str_repeat('a', 48))->assertStatus(410);

        $this->travel(25)->hours();
        $this->get('/install/'.$new)->assertStatus(410)->assertSee('Kurulum bağlantısı geçersiz');
    }

    public function test_the_password_must_be_long_and_confirmed(): void
    {
        $token = $this->link();
        $this->from('/install/'.$token)->post('/install/'.$token, [
            'name' => 'Sercan', 'email' => 'sercan@example.test', 'password' => 'short', 'password_confirmation' => 'short', 'locale' => 'tr',
        ])->assertSessionHasErrors('password');
        $this->assertSame(0, User::count());
    }

    public function test_no_link_once_an_administrator_exists(): void
    {
        User::factory()->create(['role' => 'full']);
        $this->artisan('servik:setup-url')->assertFailed();
        $this->assertNull(Setting::get('setup.token'));
        $this->artisan('servik:setup-url', ['--json' => true])->expectsOutputToContain('"error"')->assertFailed();
    }

    public function test_a_customer_login_does_not_count_as_an_administrator(): void
    {
        User::factory()->create(['is_admin' => false]);
        $this->assertTrue(app(FirstRun::class)->needsAdministrator());
        $this->artisan('servik:setup-url')->assertSuccessful();
    }

    public function test_the_wizard_saves_each_step_through_the_settings_it_belongs_to(): void
    {
        $admin = User::factory()->create(['role' => 'full', 'email' => 'sercan@ornek.com.tr']);
        $this->actingAs($admin);

        $wizard = Livewire::test(SetupWizard::class)
            ->assertSet('step', 'sunucu')
            ->assertSet('ipv4', '203.0.113.10')
            ->assertSet('ns1', 'ns1.servik.test')
            ->set('hostname', 'srv1')
            ->call('save')
            ->assertHasErrors('hostname')
            ->set('hostname', 'srv1.ornek.com.tr')
            ->set('timezone', 'Europe/Istanbul')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('step', 'ad-sunuculari');
        $this->assertSame([['hostname' => 'srv1.ornek.com.tr']], $this->agent->called('system.hostname.set'));
        $this->assertSame([['timezone' => 'Europe/Istanbul']], $this->agent->called('system.timezone.set'));
        $this->assertSame('203.0.113.10', app(DnsSettings::class)->ipv4());

        // The suggestions follow the server's new name.
        $wizard->assertSet('domain', 'ornek.com.tr')
            ->assertSet('ns1', 'ns1.ornek.com.tr')
            ->assertSet('ns2', 'ns2.ornek.com.tr')
            ->assertSee('glue')
            ->set('ns2Ip', '203.0.113.11')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('step', 'panel');
        $this->assertSame(['ns1.ornek.com.tr', 'ns2.ornek.com.tr'], app(DnsSettings::class)->nameservers());
        Queue::assertPushed(SetupDnsServer::class);
        $zone = DnsZone::where('name', 'ornek.com.tr')->sole();
        $records = $zone->records()->get()->map(fn ($r) => $r->name.' '.$r->type.' '.$r->content)->all();
        $this->assertContains('ns1 A 203.0.113.10', $records);
        $this->assertContains('ns2 A 203.0.113.11', $records);
        $this->assertContains('srv1 A 203.0.113.10', $records);
        Queue::assertPushed(SyncDnsZone::class);

        // The panel opens on the server's own name unless another is chosen.
        $wizard->assertSet('panelMode', 'hostname')
            ->assertSee('https://srv1.ornek.com.tr:8443')
            ->call('save')
            ->assertSet('step', 'bildirimler');
        $this->assertSame('srv1.ornek.com.tr', Setting::get('panel.hostname'));
        Queue::assertPushed(IssuePanelCertificate::class);

        $wizard->assertSet('emails', 'sercan@ornek.com.tr')
            ->set('emails', 'sercan@ornek.com.tr, bad')
            ->call('save')
            ->assertHasErrors('emails')
            ->set('emails', 'sercan@ornek.com.tr, ops@ornek.com.tr')
            ->call('save')
            ->assertSet('step', 'guvenlik');
        $this->assertSame(['sercan@ornek.com.tr', 'ops@ornek.com.tr'], app(Notifications::class)->settings()['emails']);

        $wizard->call('save')->assertHasNoErrors()->assertSet('step', 'varsayilanlar');
        $this->assertTrue(app(Firewall::class)->enabled());
        $apply = $this->agent->called('firewall.rules.apply')[0];
        $this->assertTrue($apply['enabled']);
        $this->assertContains('2222', array_column($apply['rules'], 'ports'), 'SSH stays open on its real port');
        $this->assertArrayHasKey('protect', $apply);

        $wizard->set('channel', 'beta')->call('save')->assertSet('step', 'yedekler');
        $this->assertSame('beta', Setting::get('updates.channel'));

        $wizard->set('backupTime', '25:00')->call('save')->assertHasErrors('backupTime')
            ->set('backupTime', '02:30')->call('save')->assertSet('step', 'ozet')
            ->assertSee('Kurulumu bitir');
        $this->assertSame('02:30', app(BackupSettings::class)->schedule()['time']);

        $states = collect(app(FirstRun::class)->checklist())->pluck('state', 'step')->all();
        $this->assertSame(['sunucu' => 'done', 'ad-sunuculari' => 'done', 'panel' => 'done', 'bildirimler' => 'done', 'guvenlik' => 'done', 'varsayilanlar' => 'done', 'yedekler' => 'done'], $states);

        $this->get('/')->assertSee('İlk kurulum');
        $wizard->call('finish')->assertRedirect(route('dashboard'));
        $this->assertTrue(app(FirstRun::class)->completed());
        $this->get('/')->assertDontSee('Sihirbaza devam et');
    }

    public function test_steps_can_be_left_for_later_and_stay_on_genel_bakis(): void
    {
        $admin = User::factory()->create(['role' => 'full']);
        $this->actingAs($admin);

        Livewire::test(SetupWizard::class)
            ->call('skip')->assertSet('step', 'ad-sunuculari')
            ->call('skip')->assertSet('step', 'panel')
            ->call('back')->assertSet('step', 'ad-sunuculari')
            ->call('go', 'ozet')->assertSet('step', 'ozet')
            ->call('go', 'nowhere')->assertSet('step', 'ozet');

        $states = collect(app(FirstRun::class)->checklist())->pluck('state', 'step');
        $this->assertSame('skipped', $states['sunucu']);
        $this->assertSame('open', $states['panel']);
        $this->assertSame(7, app(FirstRun::class)->openCount() + 2);
        $this->get('/')->assertOk()->assertSee('İlk kurulum')->assertSee('Sihirbaza devam et')->assertSee('Sonraya bırakıldı');
    }

    public function test_a_server_set_up_before_the_wizard_counts_what_is_in_place(): void
    {
        $admin = User::factory()->create(['role' => 'full']);
        $this->actingAs($admin);
        app(DnsSettings::class)->save(['nameservers' => ['ns1.kahve.com.tr', 'ns2.kahve.com.tr']]);
        Setting::put('panel.hostname', 'panel.kahve.com.tr');
        Certificate::create(['kind' => 'panel', 'name' => 'panel', 'domains' => ['panel.kahve.com.tr'], 'source' => Certificate::LETS_ENCRYPT, 'status' => 'active', 'not_after' => now()->addMonths(2)]);

        $states = collect(app(FirstRun::class)->checklist())->keyBy('step');
        $this->assertSame('done', $states['ad-sunuculari']['state']);
        $this->assertSame('ns1.kahve.com.tr, ns2.kahve.com.tr', $states['ad-sunuculari']['detail']);
        $this->assertSame('done', $states['panel']['state']);
        $this->assertSame('open', $states['guvenlik']['state']);
    }

    public function test_the_panel_can_open_on_another_name_or_only_on_its_ip_address(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'full']));
        Site::factory()->create(['domain' => 'yonetim.ornek.com.tr']);
        Setting::put('mail.hostname', 'mail.ornek.com.tr');

        $wizard = Livewire::test(SetupWizard::class)->call('go', 'panel')
            ->set('panelMode', 'custom')
            ->call('save')->assertHasErrors('panelHostname')
            ->set('panelHostname', 'yonetim.ornek.com.tr')
            ->call('save')->assertHasErrors('panelHostname')
            ->set('panelHostname', 'mail.ornek.com.tr')
            ->call('save')->assertHasErrors('panelHostname')
            ->set('panelHostname', 'Servik.Ornek.com.tr')
            ->call('save')->assertHasNoErrors()->assertSet('step', 'bildirimler');
        $this->assertSame('servik.ornek.com.tr', Setting::get('panel.hostname'));
        Queue::assertPushed(IssuePanelCertificate::class);

        // Only https://<IP>:<port>: no name and no certificate request.
        Queue::fake();
        $wizard->call('go', 'panel')
            ->assertSet('panelMode', 'custom')
            ->set('panelMode', 'ip')
            ->assertSee('https://203.0.113.10:8443')
            ->call('save')->assertHasNoErrors()->assertSet('step', 'bildirimler');
        $this->assertNull(Setting::get('panel.hostname'));
        Queue::assertNotPushed(IssuePanelCertificate::class);
        Livewire::test(SetupWizard::class)->assertSet('panelMode', 'ip');
        $this->assertSame('IP adresi ve port', collect(app(FirstRun::class)->checklist())->keyBy('step')['panel']['detail']);
    }

    public function test_only_full_administrators_open_the_wizard(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']));
        $this->get('/install')->assertForbidden();
        $this->get('/')->assertDontSee('İlk kurulum');

        $this->actingAs(User::factory()->create(['role' => 'full']));
        $this->get('/install')->assertOk()->assertSee('Sunucunun adı, adresi ve saati');
        $this->get(route('settings.general'))->assertSee(route('setup'));
    }

    public function test_the_firewall_step_shows_the_agents_refusal(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'full']));
        $this->agent->answers['firewall.rules.apply'] = AgentException::rpcError('firewall.rules.apply', AgentException::INVALID_PARAMS, 'these rules would cut your own connection to the panel');

        Livewire::test(SetupWizard::class, ['step' => 'guvenlik'])
            ->call('save')
            ->assertHasErrors('firewall')
            ->assertSet('step', 'guvenlik');
        $this->assertFalse(app(Firewall::class)->enabled());
    }

    public function test_a_full_administrators_first_sign_in_opens_the_wizard_once(): void
    {
        User::factory()->create(['email' => 'sercan@example.test', 'password' => 'correct-horse-battery', 'role' => 'full']);
        $this->post('/login', ['email' => 'sercan@example.test', 'password' => 'correct-horse-battery'])->assertRedirect('/install');
        $this->post('/logout');
        $this->post('/login', ['email' => 'sercan@example.test', 'password' => 'correct-horse-battery'])->assertRedirect('/');
    }
}
