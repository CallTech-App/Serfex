<?php

namespace Tests\Feature;

use App\Enums\MailDomainStatus;
use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Jobs\PurgeCustomer;
use App\Jobs\SyncMailDomain;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Sites\SiteIndex;
use App\Models\Customer;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Customers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Müşteriler ve Paketler as the administrator sees it.
 */
class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $this->agent = new FakeAgent(['php.versions' => ['8.3', '8.4'], 'system.addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []]]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function site(Customer $customer, string $domain): Site
    {
        $account = app(Customers::class)->account($customer);

        return Site::factory()->for($account)->create(['domain' => $domain]);
    }

    public function test_lists_customers_with_usage_and_presets(): void
    {
        $customer = Customer::factory()->withLogin()->create(['company' => 'Kahve Atölyesi', 'username' => 'kahve']);
        $this->site($customer, 'kahveatolyesi.com.tr');

        $this->get('/musteriler')->assertOk()
            ->assertSee('Kahve Atölyesi')
            ->assertSee('Profesyonel')
            ->assertSee('1 / 5')
            ->assertSee('Etkin');
        $this->get('/musteriler?sekme=paketler')->assertOk()
            ->assertSee('Başlangıç')
            ->assertSee('Laravel Pro')
            ->assertSee('Kurumsal')
            ->assertSee('Varsayılan');
    }

    public function test_adds_a_customer_with_an_invitation_link(): void
    {
        Livewire::test(CustomerIndex::class)
            ->call('openCreate')
            ->set('company', 'Hızlı Kargo A.Ş.')
            ->assertSet('username', 'hizlikargo')
            ->set('name', 'Ayşe Kaya')
            ->set('email', 'Ayse@HizliKargo.com.tr')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('creating', false)
            ->assertSee('/davet/');

        $customer = Customer::sole();
        $this->assertSame('hizlikargo', $customer->username);
        $this->assertSame(Package::default()->id, $customer->package_id);
        $this->assertSame('ayse@hizlikargo.com.tr', $customer->user->email);
        $this->assertFalse($customer->user->is_admin);
        $this->assertTrue($customer->user->isInvited());
        $this->assertSame($customer->id, SystemUser::where('name', 'hizlikargo')->value('customer_id'));
    }

    public function test_refuses_an_account_that_belongs_to_another_customer_and_a_taken_email(): void
    {
        $other = Customer::factory()->withLogin()->create(['username' => 'kahve', 'email' => 'ozan@kahve.com.tr']);
        app(Customers::class)->account($other);

        Livewire::test(CustomerIndex::class)
            ->call('openCreate')
            ->set('name', 'Ozan')
            ->set('email', 'ozan@kahve.com.tr')
            ->set('username', 'kahve')
            ->call('create')
            ->assertHasErrors(['email', 'username']);
        Livewire::test(CustomerIndex::class)
            ->call('openCreate')
            ->set('name', 'Root')
            ->set('email', 'root@example.org')
            ->set('username', 'root')
            ->call('create')
            ->assertHasErrors('username');
        $this->assertSame(1, Customer::count());
    }

    public function test_packages_can_be_added_changed_and_deleted_when_unused(): void
    {
        Livewire::test(CustomerIndex::class)
            ->call('openPackage')
            ->set('pkgName', 'Mini')
            ->set('limits.sites', '2')
            ->set('limits.disk', '1,5')
            ->set('limits.traffic', '')
            ->set('features', ['mail', 'shell'])
            ->set('allPhp', false)
            ->set('pkgPhp', ['8.4'])
            ->call('savePackage')
            ->assertHasNoErrors();

        $mini = Package::where('name', 'Mini')->sole();
        $this->assertSame(2, $mini->max_sites);
        $this->assertSame(1536, $mini->max_disk_mb);
        $this->assertNull($mini->max_traffic_gb);
        $this->assertTrue($mini->allow_mail);
        $this->assertFalse($mini->allow_laravel);
        $this->assertFalse($mini->allow_shell, 'commands need the Laravel toolkit');
        $this->assertSame(['8.4'], $mini->php_versions);

        // Making it the default takes the mark from the old default.
        Livewire::test(CustomerIndex::class)->call('openPackage', $mini->id)
            ->assertSet('limits.disk', '1.5')
            ->set('pkgDefault', true)->call('savePackage');
        $this->assertSame('Mini', Package::default()->name);
        $this->assertSame(1, Package::where('is_default', true)->count());

        $used = Package::where('name', 'Başlangıç')->sole();
        Customer::factory()->create(['package_id' => $used->id]);
        Livewire::test(CustomerIndex::class)->call('deletePackage', $used->id)->call('deletePackage', $mini->id);
        $this->assertTrue($used->exists());
        $this->assertNotNull($used->fresh());
        $this->assertNotNull($mini->fresh(), 'the default package stays');

        $free = Package::where('name', 'Kurumsal')->sole();
        Livewire::test(CustomerIndex::class)->call('deletePackage', $free->id)
            ->call('undoDeletePackage', $free->only($free->getFillable()));
        $this->assertSame(100 * 1024, Package::where('name', 'Kurumsal')->sole()->max_disk_mb);
    }

    public function test_site_from_the_customer_page_goes_under_their_account_within_their_package(): void
    {
        $package = Package::factory()->create(['max_sites' => 1, 'php_versions' => ['8.4'], 'allow_mail' => false]);
        $customer = Customer::factory()->create(['username' => 'klinik', 'package_id' => $package->id]);

        Livewire::withQueryParams(['musteri' => (string) $customer->id])->test(SiteIndex::class)
            ->assertSet('creating', true)
            ->assertSet('systemUser', 'klinik')
            ->assertSet('phpVersions', ['8.4'])
            ->set('domain', 'klinikmavi.com')
            ->call('create')
            ->assertHasNoErrors();

        $site = Site::where('domain', 'klinikmavi.com')->sole();
        $this->assertSame($customer->id, $site->systemUser->customer_id);
        $this->assertSame(0, MailDomain::count(), 'the package has no mail');

        Livewire::withQueryParams(['musteri' => (string) $customer->id])->test(SiteIndex::class)
            ->assertSee('site sınırına ulaşıldı');
        // Typing the customer's account by hand counts against them too.
        Livewire::test(SiteIndex::class)->call('openCreate')
            ->set('domain', 'ikinci.com')->set('systemUser', 'klinik')
            ->call('create');
        $this->assertFalse(Site::where('domain', 'ikinci.com')->exists());
    }

    public function test_attaches_and_detaches_an_existing_account(): void
    {
        $customer = Customer::factory()->create();
        $account = SystemUser::factory()->create(['name' => 'eski']);
        Site::factory()->for($account)->create(['domain' => 'eski.com.tr']);

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->set('tab', 'siteler')
            ->call('openAttach')
            ->assertSet('accountId', $account->id)
            ->assertSee('eski.com.tr')
            ->call('attach');
        $this->assertSame($customer->id, $account->fresh()->customer_id);

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->set('tab', 'siteler')
            ->assertSee('eski.com.tr')
            ->call('detach', $account->id);
        $this->assertNull($account->fresh()->customer_id);
        $this->assertTrue(Site::where('domain', 'eski.com.tr')->exists());
    }

    public function test_suspension_stops_sites_mail_and_login_and_comes_back(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $site = $this->site($customer, 'adapansiyon.com');
        $mail = MailDomain::factory()->create(['name' => 'adapansiyon.com', 'site_id' => $site->id]);

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->call('openSuspend')
            ->set('reason', 'Ödeme gecikti')
            ->call('suspend');

        $customer->refresh();
        $this->assertNotNull($customer->suspended_at);
        $this->assertSame('Ödeme gecikti', $customer->suspend_reason);
        Queue::assertPushed(ProvisionSite::class, fn ($job) => $job->site->is($site));
        Queue::assertPushed(SyncMailDomain::class);
        $this->assertSame(SiteStatus::Provisioning, $site->fresh()->status);
        $this->assertSame(MailDomainStatus::Syncing, $mail->fresh()->status);
        $this->assertTrue($site->fresh()->agentSpec()['suspended']);
        $this->assertTrue($mail->fresh()->agentSpec()['suspended']);

        $this->get('/musteriler/'.$customer->id)->assertSee('askıya alındı')->assertSee('Ödeme gecikti');

        // The customer cannot sign in meanwhile.
        auth()->logout();
        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs(User::where('is_admin', true)->first());
        Livewire::test(CustomerShow::class, ['customer' => $customer->id])->call('unsuspend');
        $this->assertNull($customer->fresh()->suspended_at);
        $this->assertArrayNotHasKey('suspended', $site->fresh()->agentSpec());
        $this->assertFalse($mail->fresh()->agentSpec()['suspended']);
    }

    public function test_bulk_suspend_with_undo(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();

        Livewire::test(CustomerIndex::class)
            ->set('selected', [$a->id, $b->id])
            ->call('suspendSelected')
            ->assertDispatched('toast')
            ->call('undoSuspend', [$a->id]);

        $this->assertNull($a->fresh()->suspended_at);
        $this->assertNotNull($b->fresh()->suspended_at);
    }

    public function test_delete_needs_the_account_name_and_can_be_undone(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'pixel']);
        $site = $this->site($customer, 'pixelajans.com');

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->call('openDelete')
            ->set('confirmName', 'yanlis')
            ->call('delete')
            ->assertHasErrors('confirmName')
            ->set('confirmName', 'pixel')
            ->set('deleteSites', true)
            ->call('delete')
            ->assertRedirect(route('customers.index'));

        $this->assertSoftDeleted($customer);
        $this->assertTrue($site->fresh()->isSuspended(), 'sites show the suspension page meanwhile');
        Queue::assertPushed(PurgeCustomer::class);
        $this->get('/musteriler')->assertSee('sularında silinecek')->assertSee('Geri al');

        Livewire::test(CustomerIndex::class)->call('restore', $customer->id);
        $this->assertNotSoftDeleted($customer);
        $this->assertNull($customer->fresh()->delete_sites);
        $this->assertFalse($site->fresh()->isSuspended());
    }

    public function test_purge_deletes_the_customer_and_optionally_their_sites(): void
    {
        $keep = Customer::factory()->withLogin()->create(['username' => 'kalan']);
        $kept = $this->site($keep, 'kalan.com.tr');
        $drop = Customer::factory()->withLogin()->create(['username' => 'giden']);
        $dropped = $this->site($drop, 'giden.com.tr');
        $customers = app(Customers::class);
        $customers->delete($keep, false);
        $customers->delete($drop, true);
        Customer::withTrashed()->update(['deleted_at' => now()->subSeconds(Customers::UNDO_SECONDS)]);
        Queue::fake();

        (new PurgeCustomer($keep->id))->handle($customers);
        (new PurgeCustomer($drop->id))->handle($customers);

        $this->assertSame(0, Customer::withTrashed()->count());
        $this->assertSame(0, User::where('is_admin', false)->count());
        $this->assertNull($kept->systemUser->fresh()->customer_id);
        $this->assertFalse($kept->fresh()->isSuspended());
        Queue::assertPushed(DeleteSite::class, fn ($job) => $job->site->is($dropped));
        Queue::assertNotPushed(DeleteSite::class, fn ($job) => $job->site->is($kept));
    }

    public function test_purge_waits_when_the_customer_was_deleted_again(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        (new PurgeCustomer($customer->id))->handle(app(Customers::class));

        $this->assertSoftDeleted($customer);
    }

    public function test_log_in_as_the_customer_and_return(): void
    {
        $admin = auth()->user();
        $customer = Customer::factory()->withLogin()->create(['company' => 'Mağaza Online']);

        $this->post('/musteriler/'.$customer->id.'/giris')->assertRedirect(route('dashboard'));
        $this->assertTrue(auth()->user()->is($customer->user));
        $this->get('/')->assertOk()->assertSee('Mağaza Online')->assertSee('olarak bakıyorsunuz')->assertSee('Profesyonel paketi');
        $this->get('/monitor')->assertForbidden();

        $this->post('/yoneticiye-don')->assertRedirect(route('customers.show', $customer));
        $this->assertTrue(auth()->user()->is($admin));
        $this->post('/yoneticiye-don')->assertForbidden();
    }

    public function test_admins_still_see_everything_and_customers_count_on_the_dashboard(): void
    {
        Site::factory()->create(['domain' => 'sahipsiz.com.tr']);
        $package = Package::factory()->create(['max_sites' => 1]);
        $customer = Customer::factory()->create(['package_id' => $package->id]);
        $this->site($customer, 'dolu.com.tr');

        $this->get('/sites')->assertOk()->assertSee('sahipsiz.com.tr')->assertSee('dolu.com.tr');
        $this->get('/')->assertOk()->assertSee('Müşteriler')->assertSee('1 müşteri paket sınırına yaklaştı ya da aştı');
    }
}
