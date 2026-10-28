<?php

namespace Tests\Feature;

use App\Livewire\Sites\SiteConnection;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\FtpAccount;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Customers;
use App\Services\Files\SshAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Bağlantı bilgileri: the site's SFTP, database and SSH details on one
 * card, without any password, and only for those who see the site.
 */
class ConnectionDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(AgentClient::class, new FakeAgent([
            'ftp.status' => ['installed' => true, 'ready' => true, 'port' => 2222, 'accounts' => 1],
        ]));
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_site_tools_link_it(): void
    {
        $this->get(route('sites.show', $this->site))->assertOk()
            ->assertSee(route('sites.connection', $this->site))
            ->assertSee('SFTP, veritabanı, SSH');
    }

    public function test_it_shows_sftp_databases_and_ssh_without_passwords(): void
    {
        Setting::put('dns.ipv4', '203.0.113.20');
        FtpAccount::create(['site_id' => $this->site->id, 'system_user_id' => $this->site->system_user_id, 'name' => 'kahve_deploy', 'directory' => 'public_html', 'password_changed_at' => now()]);
        $db = Database::create(['server' => 'mysql', 'name' => 'kahve_shop', 'system_user_id' => $this->site->system_user_id, 'site_id' => $this->site->id]);
        $db->users()->attach(DatabaseUser::create(['server' => 'mysql', 'name' => 'kahve_app', 'system_user_id' => $this->site->system_user_id, 'hosts' => []]), ['access' => 'all']);

        $page = Livewire::test(SiteConnection::class, ['site' => $this->site->id])
            ->assertSee('203.0.113.20')
            ->assertSee('2222')
            ->assertSee('kahve_deploy')
            ->assertSee('/public_html')
            ->assertSee('kahve_shop')
            ->assertSee('127.0.0.1:3306')
            ->assertSee('kahve_app')
            ->assertSee(route('databases.manager', $db))
            ->assertSee('SSH erişimi kapalı.')
            ->assertDontSee('Parola:');

        $this->site->systemUser->update(['ssh_access' => SshAccess::SHELL]);
        $page->call('$refresh')
            ->assertSee('sftp -P 2222 kahve@kahveatolyesi.com.tr')
            ->assertSee('ssh -p 2222 kahve@kahveatolyesi.com.tr');
    }

    public function test_an_empty_site_points_to_where_things_are_added(): void
    {
        $this->get(route('sites.connection', $this->site))->assertOk()
            ->assertSee('Henüz FTP hesabı yok.')
            ->assertSee('Bu sitenin veritabanı yok.')
            ->assertSee(route('sites.ftp', $this->site))
            ->assertSee(route('databases.index', ['site' => $this->site->id]));
    }

    public function test_customers_see_only_their_own_sites_and_what_their_package_has(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $customer->package->update(['allow_ssh' => false]);
        $this->actingAs($customer->user);

        $this->get(route('sites.connection', $this->site))->assertNotFound();
        $this->get(route('sites.connection', $mine))->assertOk()
            ->assertSee('adapansiyon.com')
            ->assertSee('Paketinizde yok')
            ->assertDontSee(route('sites.ssh', $mine));
    }
}
