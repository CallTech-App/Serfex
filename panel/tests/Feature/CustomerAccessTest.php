<?php

namespace Tests\Feature;

use App\Jobs\ProvisionSite;
use App\Livewire\Dns\ZoneIndex;
use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\LaravelShow;
use App\Livewire\Laravel\Sections\Commands;
use App\Livewire\Mail\MailDomainShow;
use App\Livewire\Mail\MailIndex;
use App\Livewire\Sites\SiteIndex;
use App\Livewire\Sites\SiteShow;
use App\Models\Customer;
use App\Models\DnsZone;
use App\Models\LaravelApp;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Customers;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * A customer signed in to the panel: their own hosting, within their
 * package, and nothing of the server or of other customers.
 */
class CustomerAccessTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Site $mine;

    private Site $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(AgentClient::class, new FakeAgent([
            'php.versions' => ['8.3', '8.4'],
            'system.addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []],
            'mail.status' => ['installed' => true, 'configured' => true, 'running' => true, 'services' => []],
            'dns.status' => ['installed' => true, 'running' => true],
            'laravel.status' => ['exists' => true, 'processes' => [], 'info' => ['packages' => []]],
        ]));
        Setting::put('mail.hostname', 'mail.serfex.net.tr');
        app(MailSettings::class)->saveServer(['state' => 'ready', 'setup_at' => now()->toIso8601String(), 'webmail_url' => 'https://mail.serfex.net.tr/', 'certificate' => '']);

        $this->customer = Customer::factory()->withLogin()->create(['company' => 'Kahve Atölyesi', 'username' => 'kahve']);
        $this->mine = Site::factory()->for(app(Customers::class)->account($this->customer))->create(['domain' => 'kahveatolyesi.com.tr']);
        $other = Customer::factory()->withLogin()->create(['username' => 'klinik']);
        $this->theirs = Site::factory()->for(app(Customers::class)->account($other))->create(['domain' => 'klinikmavi.com']);
        Site::factory()->create(['domain' => 'sahipsiz.com.tr']);

        $this->actingAs($this->customer->user);
    }

    private function package(array $attributes): void
    {
        $this->customer->package->update($attributes);
        // The signed-in user object lives on between requests in tests.
        $this->customer->user->unsetRelation('customer');
    }

    public function test_sees_only_their_own_sites(): void
    {
        $this->get('/sites')->assertOk()
            ->assertSee('kahveatolyesi.com.tr')
            ->assertDontSee('klinikmavi.com')
            ->assertDontSee('sahipsiz.com.tr');
        $this->get('/sites/'.$this->mine->id)->assertOk();
        $this->get('/sites/'.$this->theirs->id)->assertNotFound();
        $this->get('/sites/'.$this->theirs->id.'/ssl')->assertNotFound();
    }

    public function test_server_pages_are_hidden_and_refused(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Profesyonel paketi')
            ->assertSee('Müşteri · Profesyonel')
            ->assertDontSee('Canlı İzleme')
            ->assertDontSee('Serfex AI')
            ->assertDontSee('Müşteriler ve Paketler')
            ->assertDontSee('Yazılım Yönetimi');
        foreach (['/monitor', '/ai', '/ai/settings', '/musteriler', '/musteriler/'.$this->customer->id, '/dns/settings', '/mail/settings', '/ssl/settings', '/yazilim'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/musteriler/'.$this->customer->id.'/giris')->assertForbidden();
    }

    public function test_creates_sites_under_their_own_account_within_the_package(): void
    {
        $this->package(['max_sites' => 2, 'php_versions' => ['8.3']]);

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->assertSet('systemUser', 'kahve')
            ->assertSet('phpVersions', ['8.3'])
            ->set('domain', 'blog.kahveatolyesi.com.tr')
            ->set('systemUser', 'root')
            ->call('create')
            ->assertHasNoErrors();
        $site = Site::where('domain', 'blog.kahveatolyesi.com.tr')->sole();
        $this->assertSame('kahve', $site->systemUser->name);
        Queue::assertPushed(ProvisionSite::class);

        Livewire::test(SiteIndex::class)->call('openCreate')->assertSee('site sınırına ulaşıldı');
    }

    public function test_cannot_create_a_site_inside_someone_elses_domain(): void
    {
        DnsZone::factory()->create(['name' => 'klinikmavi.com', 'site_id' => $this->theirs->id]);

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'shop.klinikmavi.com')
            ->call('create')
            ->assertHasErrors('domain');
        $this->assertFalse(Site::where('domain', 'shop.klinikmavi.com')->exists());
    }

    public function test_cannot_use_other_customers_or_server_wide_actions(): void
    {
        Livewire::test(SiteShow::class, ['site' => $this->mine->id])->assertOk();
        Livewire::test(SiteShow::class, ['site' => $this->theirs->id])->assertNotFound();
        Livewire::test(ZoneIndex::class)->call('openCreate')->assertForbidden();
        Livewire::test(MailIndex::class)->call('setupServer')->assertForbidden();
    }

    public function test_dns_and_mail_follow_the_package(): void
    {
        $zone = DnsZone::factory()->create(['name' => 'kahveatolyesi.com.tr', 'site_id' => $this->mine->id]);
        $foreign = DnsZone::factory()->create(['name' => 'klinikmavi.com', 'site_id' => $this->theirs->id]);
        $mail = MailDomain::factory()->create(['name' => 'kahveatolyesi.com.tr', 'site_id' => $this->mine->id]);
        $foreignMail = MailDomain::factory()->create(['name' => 'klinikmavi.com', 'site_id' => $this->theirs->id]);

        $this->get('/dns')->assertOk()->assertSee('kahveatolyesi.com.tr')->assertDontSee('klinikmavi.com');
        $this->get('/dns/'.$zone->id)->assertOk();
        $this->get('/dns/'.$foreign->id)->assertNotFound();
        $this->get('/mail')->assertOk()->assertSee('kahveatolyesi.com.tr')->assertDontSee('klinikmavi.com');
        $this->get('/mail/'.$mail->id)->assertOk();
        $this->get('/mail/'.$foreignMail->id)->assertNotFound();

        $this->package(['allow_dns' => false, 'allow_mail' => false]);
        $this->get('/')->assertDontSee('Alan Adları ve DNS');
        $this->get('/dns')->assertForbidden();
        $this->get('/mail')->assertForbidden();
        $this->get('/sites/'.$this->mine->id)->assertOk()->assertSee('Paketinizde yok');
    }

    public function test_mailboxes_are_refused_at_the_limit(): void
    {
        $this->package(['max_mailboxes' => 1]);
        $mail = MailDomain::factory()->create(['name' => 'kahveatolyesi.com.tr', 'site_id' => $this->mine->id]);
        Mailbox::factory()->create(['mail_domain_id' => $mail->id]);

        Livewire::test(MailDomainShow::class, ['domain' => $mail->id])
            ->call('openCreate')
            ->assertSet('creating', false)
            ->assertDispatched('toast');
    }

    public function test_laravel_apps_on_own_sites_with_commands_only_when_the_package_allows(): void
    {
        $app = LaravelApp::factory()->for($this->mine)->create();
        LaravelApp::factory()->for($this->theirs)->create();

        $this->get('/laravel')->assertOk()->assertSee('kahveatolyesi.com.tr')->assertDontSee('klinikmavi.com')->assertSee('Laravel uygulaması kur');
        $this->get('/laravel/'.$app->id)->assertOk()->assertDontSee('Komutlar');
        Livewire::test(Commands::class, ['appId' => $app->id])->assertForbidden();

        $this->package(['allow_shell' => true]);
        $this->get('/laravel/'.$app->id)->assertOk()->assertSee('Komutlar');
        Livewire::test(Commands::class, ['appId' => $app->id])->assertOk();

        $this->package(['max_laravel_apps' => 1]);
        Livewire::test(LaravelIndex::class)->call('openInstall')->assertSet('installing', false)->assertDispatched('toast');

        $this->package(['allow_laravel' => false]);
        $this->get('/laravel')->assertForbidden();
        Livewire::test(LaravelShow::class, ['app' => $app->id])->assertForbidden();
    }

    public function test_suspended_customer_is_signed_out(): void
    {
        app(Customers::class)->suspend($this->customer);

        $this->get('/sites')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_team_members_still_only_see_laravel(): void
    {
        $this->actingAs(User::factory()->member()->create());

        $this->get('/')->assertRedirect(route('laravel.index'));
        $this->get('/sites')->assertForbidden();
        $this->get('/musteriler')->assertForbidden();
        $this->assertFalse(SystemUser::where('name', 'x')->exists());
    }
}
