<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Jobs\DeleteSite;
use App\Jobs\IssueCertificate;
use App\Livewire\Sites\SiteDirectives;
use App\Livewire\Sites\SiteDomainList;
use App\Livewire\Sites\SiteErrorPages;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\DnsZone;
use App\Models\Package;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Services\Dns\DnsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Alan adları: subdomains, additional domains and redirects are written
 * to the server at once, get DNS records when their zone is here and join
 * the site's certificate; error pages and directives are tested by the
 * web server first. Every change can be undone; customers stay within
 * their package.
 */
class SiteDomainsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    private DnsZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['servik.ssl.check_dns' => false]);
        app(DnsSettings::class)->save(['ipv4' => '203.0.113.10']);
        $this->agent = new FakeAgent([
            'site.apply' => fn ($p) => ['document_root' => '/home/kahve/web/'.$p['domain'].'/public_html', 'changed' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr'], 'php_version' => '8.4', 'document_root' => '/home/kahve/web/kahve.com.tr/public_html']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr', 'site_id' => $this->site->id]);
        Certificate::factory()->inUseBy($this->site)->create();
        $this->actingAs(User::factory()->create());
    }

    private function lastSpec(): array
    {
        return collect($this->agent->called('site.apply'))->last();
    }

    public function test_the_site_page_opens_the_domain_tools(): void
    {
        Subdomain::create(['site_id' => $this->site->id, 'name' => 'blog.kahve.com.tr', 'web_root' => 'subdomains/blog']);
        $this->get(route('sites.show', $this->site))->assertOk()
            ->assertSee(route('sites.domains', $this->site))
            ->assertSee('1 alt alan adı')
            ->assertSee(route('sites.logs', $this->site))
            ->assertSee(route('sites.errors', $this->site))
            ->assertSee(route('sites.directives', $this->site));
        $this->get(route('sites.domains', $this->site))->assertOk()
            ->assertSee('Alt alan adları')->assertSee('blog.kahve.com.tr')->assertSee('subdomains/blog');
        $this->get(route('sites.domains', ['site' => $this->site, 'sekme' => 'ek']))->assertOk()->assertSee('www.kahve.com.tr')->assertSee('HTTPS');
    }

    public function test_a_subdomain_goes_live_with_dns_and_joins_the_certificate(): void
    {
        $page = Livewire::test(SiteDomainList::class, ['site' => $this->site->id])
            ->call('openSubdomain')
            ->set('label', 'blog/../x')
            ->call('saveSubdomain')
            ->assertHasErrors('label')
            ->set('label', 'www')
            ->call('saveSubdomain')
            ->assertHasErrors('label')
            ->assertSee('ek alan adı')
            ->set('label', 'Blog')
            ->call('saveSubdomain')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'blog.kahve.com.tr eklendi.');

        $sub = Subdomain::where('name', 'blog.kahve.com.tr')->sole();
        $this->assertSame('subdomains/blog', $sub->web_root);
        $this->assertSame([['name' => 'blog.kahve.com.tr', 'web_root' => 'subdomains/blog']], $this->lastSpec()['subdomains']);
        // Its address record in the site's zone, and a certificate request
        // that covers it.
        $this->assertTrue($this->zone->records()->where('name', 'blog')->where('type', 'A')->where('content', '203.0.113.10')->exists());
        Queue::assertPushed(IssueCertificate::class, fn ($job) => $job->auto && $job->certificate->is($this->site->certificate));
        $this->assertContains('blog.kahve.com.tr', $this->site->fresh()->hostnames());

        // Inside the main site, or a folder of its own choosing.
        $page->call('openSubdomain')->set('label', 'magaza')->set('rootMode', 'inside')->call('saveSubdomain')->assertHasNoErrors();
        $this->assertSame('public_html/magaza', Subdomain::where('name', 'magaza.kahve.com.tr')->value('web_root'));
        $page->call('openSubdomain')->set('label', 'api')->set('rootMode', 'custom')->set('customRoot', '../etc')->call('saveSubdomain')->assertHasErrors('customRoot')
            ->set('customRoot', 'logs')->call('saveSubdomain')->assertHasErrors('customRoot')
            ->set('customRoot', 'laravel/current/public')->call('saveSubdomain')->assertHasNoErrors();

        // Undo takes it away again, with its DNS record.
        $page->call('undoAdd', 'subdomain', $sub->id)->assertDispatched('toast', message: 'Eklenen ad geri alındı.');
        $this->assertNull(Subdomain::find($sub->id));
        $this->assertFalse($this->zone->records()->where('name', 'blog')->exists());
        $this->assertNotContains('blog.kahve.com.tr', array_column($this->lastSpec()['subdomains'], 'name'));
    }

    public function test_a_refused_change_is_rolled_back_with_the_servers_words(): void
    {
        $this->agent->answers['site.apply'] = AgentException::rpcError('site.apply', AgentException::CONFIG_REJECTED, 'nginx rejected the configuration: nginx: [emerg] conflicting parameter');
        Livewire::test(SiteDomainList::class, ['site' => $this->site->id])
            ->call('openSubdomain')->set('label', 'blog')->call('saveSubdomain')
            ->assertHasErrors('label')
            ->assertSee('Nginx yapılandırmayı kabul etmedi, hiçbir şey değişmedi: nginx: [emerg] conflicting parameter');
        $this->assertSame(0, Subdomain::count());
        $this->assertFalse($this->zone->records()->where('name', 'blog')->exists());
    }

    public function test_an_additional_domain_gets_a_zone_of_its_own_and_comes_back_after_undo(): void
    {
        $page = Livewire::test(SiteDomainList::class, ['site' => $this->site->id, 'sekme' => 'ek'])
            ->call('openAlias')
            ->set('aliasName', 'Kahve.NET')
            ->assertSee('Bu alan adı için DNS bölgesi oluştur')
            ->call('saveAlias')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'kahve.net artık kahve.com.tr sitesini açıyor.');

        $this->assertSame(['www.kahve.com.tr', 'kahve.net'], $this->site->fresh()->aliases);
        $this->assertSame(['www.kahve.com.tr', 'kahve.net'], $this->lastSpec()['aliases']);
        $zone = DnsZone::where('name', 'kahve.net')->sole();
        $this->assertSame($this->site->id, $zone->site_id);

        // An alias inside a zone here gets records there instead.
        $page->call('openAlias')->set('aliasName', 'shop.kahve.com.tr')->call('saveAlias')->assertHasNoErrors();
        $this->assertTrue($this->zone->records()->where('name', 'shop')->where('type', 'A')->exists());
        $this->assertSame(0, DnsZone::where('name', 'shop.kahve.com.tr')->count());

        // Taken names are refused, whoever has them.
        $other = Site::factory()->create(['domain' => 'cay.com.tr']);
        $page->call('openAlias')->set('aliasName', 'cay.com.tr')->call('saveAlias')->assertHasErrors('aliasName')->assertSee('zaten bir site');

        // Removing keeps the zone (it may hold mail) and can be undone.
        $page->call('confirmRemove', 'alias', 'kahve.net')->call('remove');
        $this->assertSame(['www.kahve.com.tr', 'shop.kahve.com.tr'], $this->site->fresh()->aliases);
        $this->assertTrue(DnsZone::where('name', 'kahve.net')->exists());
        $token = $this->undoToken($page);
        $page->call('undoRemove', $token)->assertDispatched('toast', message: 'Geri alındı.');
        $this->assertContains('kahve.net', $this->site->fresh()->aliases);

        // Removing a name in the site's zone takes its records too.
        $page->call('confirmRemove', 'alias', 'shop.kahve.com.tr')->call('remove');
        $this->assertFalse($this->zone->records()->where('name', 'shop')->exists());
        $this->assertNotNull($other);
    }

    public function test_redirects_send_a_domain_or_a_path_elsewhere(): void
    {
        $page = Livewire::test(SiteDomainList::class, ['site' => $this->site->id, 'sekme' => 'yonlendirmeler'])
            ->call('openRedirect', 'domain')
            ->assertSet('target', 'https://kahve.com.tr')
            ->set('source', 'www.eskikahve.com')
            ->call('saveRedirect')
            ->assertHasNoErrors();
        $redirect = SiteRedirect::sole();
        $this->assertSame(['eskikahve.com', true, 301, true], [$redirect->source, $redirect->with_www, $redirect->code, $redirect->keep_path]);
        $this->assertSame([['hosts' => ['eskikahve.com', 'www.eskikahve.com'], 'target' => 'https://kahve.com.tr', 'code' => 301, 'keep_path' => true]], $this->lastSpec()['redirects']);
        $this->assertTrue(DnsZone::where('name', 'eskikahve.com')->exists());

        // Paths: not the whole site, not Let's Encrypt's, safe targets.
        $page->call('openRedirect', 'path')
            ->set('source', '/')->set('target', '/yeni')->call('saveRedirect')->assertHasErrors('source')
            ->set('source', '/.well-known/acme-challenge')->call('saveRedirect')->assertHasErrors('source')
            ->set('source', '/eski-menu')->set('target', 'https://kahve.com.tr/$host')->call('saveRedirect')->assertHasErrors('target')
            ->set('target', 'javascript:alert(1)')->call('saveRedirect')->assertHasErrors('target')
            ->set('target', '/menu?x=1')->call('saveRedirect')->assertHasErrors('target')
            ->set('keepPath', false)->set('code', 302)->call('saveRedirect')->assertHasNoErrors();
        $path = SiteRedirect::where('kind', SiteRedirect::PATH)->sole();
        $this->assertSame(['path' => '/eski-menu', 'target' => '/menu?x=1', 'code' => 302, 'keep_path' => false], $this->lastSpec()['redirects'][1]);

        // A domain redirect needs a full address, and not to itself.
        $page->call('openRedirect', 'domain')->set('source', 'kampanya.com')->set('target', '/indirim')->call('saveRedirect')->assertHasErrors('target')
            ->set('target', 'https://kampanya.com/x')->call('saveRedirect')->assertHasErrors('target');

        // Editing, with undo.
        $page->call('openEditRedirect', $path->id)->set('target', '/yeni-menu')->call('saveRedirect')->assertHasNoErrors();
        $this->assertSame('/yeni-menu', $path->fresh()->target);
        $page->call('undoEditRedirect', $path->id, ['target' => '/menu?x=1', 'code' => 302, 'keep_path' => false]);
        $this->assertSame('/menu?x=1', $path->fresh()->target);
        // An undo carrying an unsafe target is ignored.
        $page->call('undoEditRedirect', $path->id, ['target' => 'https://a.com/;evil', 'code' => 302, 'keep_path' => false]);
        $this->assertSame('/menu?x=1', $path->fresh()->target);
    }

    public function test_customers_stay_within_their_package_and_their_own_zones(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $customer->package->update(['max_subdomains' => 1, 'max_aliases' => 1]);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com', 'aliases' => ['www.adapansiyon.com']]);
        $this->actingAs($customer->user);

        $page = Livewire::test(SiteDomainList::class, ['site' => $site->id])
            ->call('openSubdomain')->set('label', 'rezervasyon')->call('saveSubdomain')->assertHasNoErrors();
        $page->call('openSubdomain')->assertDispatched('toast', message: 'Paketin alt alan adı sınırına ulaşıldı (1 / 1). Paketi yükseltin ya da kullanılmayanları silin.')
            ->assertSet('adding', null);

        // www does not count; a redirected domain does.
        $page->call('openRedirect', 'domain')->set('source', 'adapansiyon.net')->set('withWww', false)->set('target', 'https://adapansiyon.com')->call('saveRedirect')->assertHasNoErrors();
        $this->assertSame(1, app(Quotas::class)->usage($customer->fresh())['aliases']);
        $page->call('openAlias')->assertDispatched('toast', message: 'Paketin ek alan adı sınırına ulaşıldı (1 / 1). Paketi yükseltin ya da kullanılmayanları silin.');

        // Not inside another account's zone, nor another customer's site.
        $customer->package->update(['max_aliases' => 5]);
        $page->call('openAlias')->set('aliasName', 'menu.kahve.com.tr')->call('saveAlias')->assertHasErrors('aliasName')->assertSee('başka bir hesaba ait');
        Livewire::test(SiteDomainList::class, ['site' => $this->site->id])->assertStatus(404);

        // Directives are for administrators.
        $this->get(route('sites.directives', $site))->assertForbidden();
        $this->get(route('sites.show', $site))->assertOk()->assertDontSee('Web sunucusu yönergeleri');
        $this->get(route('sites.domains', $site))->assertOk()->assertSee('1 / 1 alt alan adı (paket)');
    }

    public function test_error_pages_are_written_and_can_go_back(): void
    {
        $page = Livewire::test(SiteErrorPages::class, ['site' => $this->site->id])
            ->call('openEdit', '404')
            ->assertSet('editing', '404')
            ->assertSee('Sayfa bulunamadı')
            ->set('html', '')
            ->call('save')
            ->assertHasErrors('html')
            ->set('html', '<h1>Kahve bulunamadı</h1>')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(['404' => '<h1>Kahve bulunamadı</h1>'], $this->site->fresh()->error_pages);
        $this->assertSame(['404' => '<h1>Kahve bulunamadı</h1>'], $this->lastSpec()['error_pages']);
        // The starting page is Servik's own, in the site's name.
        $this->assertStringContainsString('kahve.com.tr ana sayfasına dönün', SiteErrorPages::template('50x', 'kahve.com.tr'));

        $page->call('confirmReset', '404')->call('restoreDefault');
        $this->assertNull($this->site->fresh()->error_pages);
        $this->assertArrayNotHasKey('error_pages', $this->lastSpec());
        $token = $this->undoToken($page);
        $page->call('undo', $token);
        $this->assertSame(['404' => '<h1>Kahve bulunamadı</h1>'], $this->site->fresh()->error_pages);
    }

    public function test_directives_are_tested_by_the_web_server_before_they_stay(): void
    {
        $page = Livewire::test(SiteDirectives::class, ['site' => $this->site->id])
            ->assertSee('Site yalnızca Nginx ile çalışıyor')
            ->set('nginx', "add_header X-Kahve evet always;\r\n")
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Yönergeler denendi ve uygulandı.');
        $this->assertSame('add_header X-Kahve evet always;', $this->site->fresh()->nginx_directives);
        $this->assertSame('add_header X-Kahve evet always;', $this->lastSpec()['nginx_directives']);

        $this->agent->answers['site.apply'] = AgentException::rpcError('site.apply', AgentException::CONFIG_REJECTED, 'nginx rejected the configuration: nginx: [emerg] unknown directive "kahve" in /etc/nginx/sites-enabled/kahve.com.tr.conf:40');
        $page->set('nginx', 'kahve on;')->call('save')->assertHasErrors('nginx')->assertSee('unknown directive');
        $this->assertSame('add_header X-Kahve evet always;', $this->site->fresh()->nginx_directives);

        // Apache lines go only to a site behind Apache.
        $this->agent->answers['site.apply'] = ['document_root' => '/x'];
        $this->site->update(['web_server' => Site::APACHE, 'apache_directives' => 'Header set X-A b']);
        $this->assertSame('Header set X-A b', $this->site->fresh()->load('systemUser')->agentSpec()['apache_directives']);
        $this->site->update(['web_server' => Site::NGINX]);
        $this->assertArrayNotHasKey('apache_directives', $this->site->fresh()->load('systemUser')->agentSpec());
    }

    public function test_a_suspended_site_drops_redirects_and_directives(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com', 'nginx_directives' => 'add_header X-A b;']);
        Subdomain::create(['site_id' => $site->id, 'name' => 'blog.adapansiyon.com', 'web_root' => 'subdomains/blog']);
        SiteRedirect::create(['site_id' => $site->id, 'kind' => SiteRedirect::PATH, 'source' => '/a', 'target' => '/b']);
        $customer->update(['suspended_at' => now()]);
        $spec = $site->fresh()->agentSpec();
        $this->assertTrue($spec['suspended']);
        $this->assertCount(1, $spec['subdomains']);
        $this->assertArrayNotHasKey('redirects', $spec);
        $this->assertArrayNotHasKey('nginx_directives', $spec);
    }

    public function test_hourly_renewal_adds_names_whose_dns_points_here(): void
    {
        Subdomain::create(['site_id' => $this->site->id, 'name' => 'blog.kahve.com.tr', 'web_root' => 'subdomains/blog']);
        $this->artisan('servik:ssl-renew')->assertSuccessful();
        Queue::assertPushed(IssueCertificate::class, 1);
        $this->assertSame(CertificateStatus::Issuing, $this->site->certificate->fresh()->status);

        // Nothing to add: nothing asked.
        Queue::fake();
        $this->site->certificate->update(['status' => CertificateStatus::Active, 'domains' => ['kahve.com.tr', 'www.kahve.com.tr', 'blog.kahve.com.tr']]);
        $this->artisan('servik:ssl-renew')->assertSuccessful();
        Queue::assertNotPushed(IssueCertificate::class);
    }

    public function test_deleting_the_site_can_take_the_zones_of_its_additional_domains(): void
    {
        DnsZone::factory()->create(['name' => 'kahve.net', 'site_id' => $this->site->id]);
        $this->agent->answers['site.delete'] = ['user_removed' => false];
        // The queue is faked here, so the job runs by hand.
        app()->call([new DeleteSite($this->site, removeZone: true), 'handle']);
        $this->assertSame(2, DnsZone::where('status', 'deleting')->count());
    }

    public function test_packages_have_subdomain_and_alias_limits(): void
    {
        $limits = Package::pluck('max_subdomains', 'name');
        $this->assertSame(Package::LIMITS['subdomains'][0], 'max_subdomains');
        $this->assertArrayHasKey('aliases', Package::LIMITS);
        $this->assertNotNull($limits);
    }

    private function undoToken($page): string
    {
        $toast = collect($page->effects['dispatches'] ?? [])->last(fn ($d) => $d['name'] === 'toast' && isset($d['params']['undo']));

        return $toast['params']['undo']['args'][0];
    }
}
