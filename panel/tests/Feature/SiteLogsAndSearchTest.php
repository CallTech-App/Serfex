<?php

namespace Tests\Feature;

use App\Livewire\QuickSearch;
use App\Livewire\Sites\SiteLogs as SiteLogsPage;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Customers;
use App\Services\Sites\SiteLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Günlükler shows the end of a site's logs, filtered and downloadable;
 * Ctrl+K finds what the account may open, and nothing else.
 */
class SiteLogsAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS = "203.0.113.5 - - [20/Oct/2026:10:00:00 +0300] \"GET / HTTP/2.0\" 200 512 \"-\" \"curl\"\n"
        ."203.0.113.6 - - [20/Oct/2026:10:00:01 +0300] \"GET /wp-login.php HTTP/2.0\" 404 162 \"-\" \"bot\"\n"
        ."203.0.113.7 - - [20/Oct/2026:10:00:02 +0300] \"POST /odeme HTTP/2.0\" 502 157 \"-\" \"Mozilla\"\n";

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new FakeAgent([
            'site.logs' => function ($p) {
                $content = self::ACCESS;
                $offset = $p['offset'] ?? null;
                if ($offset !== null) {
                    $part = substr($content, $offset, 100);

                    return ['kind' => $p['kind'], 'exists' => true, 'size' => strlen($content), 'content' => $part, 'truncated' => false, 'next' => $offset + strlen($part)];
                }

                return ['kind' => $p['kind'], 'exists' => true, 'size' => strlen($content), 'content' => $content, 'truncated' => false];
            },
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr', 'kahve.net']]);
    }

    public function test_the_log_viewer_filters_and_marks_failed_requests(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('sites.logs', $this->site))->assertOk()->assertSee('wp-login.php')->assertSee('Erişim');

        Livewire::test(SiteLogsPage::class, ['site' => $this->site->id])
            ->set('filter', 'ODEME')
            ->assertSee('/odeme')->assertDontSee('wp-login.php')
            ->set('filter', '')
            ->set('problems', true)
            ->assertSee('wp-login.php')->assertDontSee('GET / HTTP');

        $lines = SiteLogs::lines(self::ACCESS, false, 'access', '', false, 2);
        $this->assertCount(2, $lines);
        $this->assertSame([null, 'warn', 'crit'], array_column(SiteLogs::lines(self::ACCESS, false, 'access', '', false, 10), 'tone'));
        // A tail that starts mid-file drops its broken first line.
        $this->assertCount(2, SiteLogs::lines(self::ACCESS, true, 'access', '', false, 10));
    }

    public function test_a_log_downloads_whole_in_parts(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('sites.logs.download', ['site' => $this->site, 'tur' => 'access']));
        $response->assertOk()->assertDownload('kahve.com.tr-access.log');
        $this->assertSame(self::ACCESS, $response->streamedContent());
        $this->assertSame([0, 100, 200], array_column($this->agent->called('site.logs'), 'offset'));

        $this->get(route('sites.logs.download', ['site' => $this->site, 'tur' => 'apache']))->assertNotFound();
    }

    public function test_quick_search_finds_every_kind_of_thing_for_an_administrator(): void
    {
        $this->actingAs(User::factory()->create());
        Subdomain::create(['site_id' => $this->site->id, 'name' => 'blog.kahve.com.tr', 'web_root' => 'subdomains/blog']);
        SiteRedirect::create(['site_id' => $this->site->id, 'kind' => SiteRedirect::DOMAIN, 'source' => 'eskikahve.com', 'target' => 'https://kahve.com.tr', 'code' => 301, 'keep_path' => true]);
        Customer::factory()->create(['name' => 'Kahve Dünyası Ltd', 'company' => null, 'email' => 'muhasebe@kahvedunyasi.example']);
        Mailbox::factory()->for(MailDomain::factory()->create(['name' => 'kahve.com.tr', 'site_id' => $this->site->id]), 'domain')->create(['name' => 'siparis']);

        $groups = collect(Livewire::test(QuickSearch::class)->set('q', 'kahve')->instance()->results())->keyBy('group');
        $this->assertSame(['kahve.com.tr', 'blog.kahve.com.tr', 'eskikahve.com'], array_column($groups['Siteler ve alan adları']['items'], 'label'));
        $this->assertSame('Kahve Dünyası Ltd', $groups['Müşteriler']['items'][0]['label']);
        $this->assertSame('siparis@kahve.com.tr', $groups['Posta kutuları']['items'][0]['label']);

        // An additional domain finds its site.
        $item = Livewire::test(QuickSearch::class)->set('q', 'kahve.net')->instance()->results()[0]['items'][0];
        $this->assertSame(['kahve.com.tr', 'Ek alan adı: kahve.net'], [$item['label'], $item['detail']]);

        // Pages, without Turkish letters.
        $pages = collect(Livewire::test(QuickSearch::class)->set('q', 'islem')->instance()->results())->firstWhere('group', 'Sayfalar');
        $this->assertSame('İşlem günlüğü', $pages['items'][0]['label']);
        Livewire::test(QuickSearch::class)->set('q', 'guncel')->assertSee('Güncellemeler')->assertSee(route('settings.updates'));
    }

    public function test_a_customer_finds_only_their_own_things(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada', 'name' => 'Ada Pansiyon']);
        $own = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        Subdomain::create(['site_id' => $own->id, 'name' => 'rezervasyon.adapansiyon.com', 'web_root' => 'subdomains/rezervasyon']);
        Subdomain::create(['site_id' => $this->site->id, 'name' => 'rezervasyon.kahve.com.tr', 'web_root' => 'subdomains/rezervasyon']);
        $this->actingAs($customer->user);

        $groups = collect(Livewire::test(QuickSearch::class)->set('q', 'rezervasyon')->instance()->results())->keyBy('group');
        $this->assertSame(['rezervasyon.adapansiyon.com'], array_column($groups['Siteler ve alan adları']['items'], 'label'));

        $groups = collect(Livewire::test(QuickSearch::class)->set('q', 'a')->instance()->results())->keyBy('group');
        $labels = array_column($groups['Sayfalar']['items'], 'label');
        $this->assertNotContains('Müşteriler ve Paketler', $labels);
        $this->assertNotContains('Canlı İzleme', $labels);
        $this->assertCount(0, collect(Livewire::test(QuickSearch::class)->set('q', 'kahve')->instance()->results())->where('group', '!=', 'Sayfalar'));
        $this->assertCount(0, collect(Livewire::test(QuickSearch::class)->set('q', 'Ada')->instance()->results())->where('group', 'Müşteriler'));
    }
}
