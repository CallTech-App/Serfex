<?php

namespace Tests\Feature;

use App\Jobs\RunSiteMigration;
use App\Livewire\Sites\SiteMigrate;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteMigration;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailSettings;
use App\Services\Migrations\SiteMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Başka sunucudan taşı: a cPanel account archive is read, the
 * administrator picks the sites, parts and customer, and the converted
 * site backups are restored. The agent's answers come from
 * tests/Fixtures/cpanel-migration.json, which the agent's own converter
 * wrote from its test archive (account magaza: magaza.com.tr with the
 * parked magaza.net and two subdomains, the addon blogum.net).
 */
class SiteMigrationTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/cpanel-migration.json')), true);
        $inspect = [...$this->fixture['inspect'], 'free_backups' => 1 << 36, 'free_home' => 1 << 36];
        $this->agent = new FakeAgent([
            'migrate.scan' => [['file' => 'cpmove-magaza.tar.gz', 'size' => 24064, 'modified' => '2026-10-28T12:00:00Z']],
            'migrate.inspect' => fn ($p) => [...$inspect, 'source' => isset($p['upload']) ? 'migrate/'.$p['upload'].'.tar' : ''],
            'migrate.convert' => $this->fixture['convert'],
            'backup.member.read' => function ($p) {
                $json = json_encode($this->fixture['meta'][$p['name']]);

                return ['data' => base64_encode($json), 'offset' => strlen($json), 'size' => strlen($json)];
            },
            'backup.restore' => fn ($p) => ['restored' => $p['parts'] ?? [], 'skipped' => []],
            'backup.delete' => ['deleted' => true],
            'backup.run.read' => ['data' => "▸ Arşiv okunuyor\n", 'offset' => 20, 'size' => 20],
            'site.apply' => fn ($p) => ['uid' => 1001, 'home' => '/home/magaza', 'document_root' => '/home/magaza/web/'.($p['domain'] ?? '').'/public_html'],
            'php.versions' => ['8.1', '8.3', '8.4'],
            'mail.domain.apply' => ['dkim' => ['name' => 'serfex._domainkey', 'record' => 'v=DKIM1; k=rsa; p=abc']],
            'dns.zone.apply' => [],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        Setting::put('databases.products', ['mysql' => 'mysql']);
        app(MailSettings::class)->saveServer(['state' => 'ready', 'setup_at' => now()->toIso8601String()]);
        $this->actingAs(User::factory()->create());
    }

    private function read(): SiteMigration
    {
        Livewire::test(SiteMigrate::class)->set('from', 'folder')->call('inspectFile', 'cpmove-magaza.tar.gz')->assertSet('error', null);

        return SiteMigration::sole();
    }

    public function test_the_page_reads_an_archive_and_shows_what_will_move_and_what_will_not(): void
    {
        $this->get('/sites/migrate')->assertOk()->assertSee('Taşınacak hesabı seçin')->assertSee('Yedeği nereden alırım?');
        Livewire::test(SiteMigrate::class)->set('from', 'folder')->assertSee('cpmove-magaza.tar.gz');

        $m = $this->read();
        $this->assertSame('cpanel', $m->source);
        $this->assertSame('magaza', $m->plan['user']);
        $this->assertSame(['file' => 'cpmove-magaza.tar.gz'], $this->agent->called('migrate.inspect')[0]);

        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->assertSet('sites', ['magaza.com.tr', 'blogum.net'])
            ->assertSet('parts', ['files', 'databases', 'mail', 'dns', 'certificates'])
            ->assertSet('customerMode', 'new')
            ->assertSet('customerEmail', 'ayse@magaza.com.tr')
            ->assertSee('magaza.com.tr')->assertSee('blogum.net')->assertSee('magaza.net')
            ->assertSee('Arşivde ne var')
            ->assertSee('zamanlanmış görev taşınmaz')
            ->assertSee('FTP hesabı taşınmaz');
    }

    public function test_an_archive_that_is_not_from_cpanel_is_refused_in_words(): void
    {
        $this->agent->answers['migrate.inspect'] = AgentException::rpcError('migrate.inspect', -32000, 'the file is not a cPanel account backup (cpmove or full backup)');

        Livewire::test(SiteMigrate::class)->call('inspectFile', 'baska.tar.gz')
            ->assertSet('error', 'Bu dosya bir cPanel hesap yedeği değil. WHM\'deki aktarım aracının ya da cPanel\'deki tam yedeğin oluşturduğu .tar.gz dosyasını kullanın.');
        $this->assertSame(0, SiteMigration::count());
    }

    public function test_the_account_moves_with_its_sites_databases_mail_dns_and_a_new_customer(): void
    {
        $m = $this->read();
        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->set('customerName', 'Ayşe Yılmaz')
            ->call('confirmStart')->assertHasNoErrors()->assertSet('confirming', true)
            ->call('start')->assertHasNoErrors()->assertSet('error', null);
        Queue::assertPushed(RunSiteMigration::class);
        $m->refresh();
        $this->assertSame('queued', $m->status);
        $customer = Customer::where('username', 'magaza')->sole();
        $this->assertSame($customer->id, $m->customer_id);
        $this->assertStringContainsString('/invite/', $m->secrets['invite']);

        app(SiteMigrations::class)->run($m);
        $m->refresh();

        $this->assertSame('succeeded', $m->status, json_encode($m->result));
        $convert = $this->agent->called('migrate.convert')[0];
        $this->assertSame(['magaza.com.tr', 'blogum.net'], $convert['sites']);
        $this->assertSame('magaza', $convert['account']);
        $this->assertSame('cpmove-magaza.tar.gz', $convert['file']);

        $account = SystemUser::where('name', 'magaza')->sole();
        $this->assertSame($customer->id, $account->customer_id);
        $main = Site::where('domain', 'magaza.com.tr')->sole();
        $this->assertSame($account->id, $main->system_user_id);
        $this->assertSame('8.1', $main->php_version);
        $this->assertSame(['www.magaza.com.tr', 'magaza.net', 'www.magaza.net'], $main->aliases);
        $this->assertNotNull(Site::where('domain', 'blogum.net')->first());

        $this->assertEqualsCanonicalizing(['magaza_blog', 'magaza_eski', 'magaza_wp'], Database::where('site_id', $main->id)->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['magaza_blog', 'magaza_wpu'], DatabaseUser::where('system_user_id', $account->id)->pluck('name')->all());
        $restore = collect($this->agent->called('backup.restore'))->firstWhere('site.domain', 'magaza.com.tr');
        $this->assertSame(['domain' => 'magaza.com.tr', 'user' => 'magaza'], $restore['site']);
        $this->assertEqualsCanonicalizing(['magaza_blog', 'magaza_eski', 'magaza_wp'], array_column($restore['databases'], 'name'));

        $this->assertEqualsCanonicalizing(['info', 'satis'], MailDomain::where('name', 'magaza.com.tr')->sole()->mailboxes()->pluck('name')->all());

        $zone = DnsZone::where('name', 'magaza.com.tr')->sole();
        $records = $zone->records()->get()->map(fn ($r) => $r->name.' '.$r->type.' '.$r->content)->all();
        $this->assertContains('kampanya CNAME magaza.shopify.com.', $records);
        $this->assertContains('_sip._tcp SRV 5 5060 sip.magaza.com.tr.', $records);
        $this->assertContains('@ CAA 0 issue "letsencrypt.org"', $records);
        $this->assertSame(1, collect($records)->filter(fn ($r) => str_starts_with($r, 'www '))->count(), 'the template www and the old one do not both stay');
        $this->assertNotNull(DnsZone::where('name', 'magaza.net')->where('site_id', $main->id)->first(), 'the parked domain keeps its zone');

        $this->assertSame('magaza_wpu', $m->secrets['databases'][0]['name']);
        $this->assertSame(['yazar@blogum.net'], array_column($m->secrets['mailboxes'], 'name'), 'its old hash is in a form Dovecot cannot read');
        $this->assertSame(['succeeded', 'succeeded'], array_column($m->result['sites'], 'status'));
        $this->assertSame([], $this->agent->called('migrate.discard'), 'an archive in the import folder is left for the administrator');
        $this->assertCount(1, $this->agent->called('backup.delete'), 'the converted backups are removed');

        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->assertSee('Taşıma raporu')->assertSee('Yeni parolalar')->assertSee('magaza_wpu')
            ->assertSee($m->secrets['databases'][0]['password'])
            ->assertSee('Sırada ne var')
            ->call('forgetSecrets')
            ->assertDontSee($m->secrets['databases'][0]['password']);
        $this->assertNull($m->fresh()->secrets);
    }

    public function test_taken_domains_and_existing_databases_are_left_alone(): void
    {
        $other = Site::factory()->create(['domain' => 'blogum.net', 'provisioned_at' => now()]);
        $mine = SystemUser::factory()->create(['name' => 'magaza']);
        Database::factory()->create(['server' => 'mysql', 'name' => 'magaza_wp', 'system_user_id' => $mine->id, 'site_id' => null]);
        $m = $this->read();

        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->assertSet('sites', ['magaza.com.tr'])
            ->set('customerMode', 'none')
            ->call('start')->assertHasNoErrors();
        $m->refresh();
        $this->assertSame(['magaza.com.tr'], $m->options['sites']);

        $this->agent->answers['migrate.convert'] = [...$this->fixture['convert'], 'items' => [$this->fixture['convert']['items'][0]]];
        app(SiteMigrations::class)->run($m);
        $m->refresh();

        $this->assertSame('succeeded', $m->status, json_encode($m->result));
        $this->assertSame($other->id, Site::where('domain', 'blogum.net')->sole()->id);
        $this->assertNull(SystemUser::where('name', 'magaza')->sole()->customer_id);
        $this->assertContains(['code' => 'database-exists', 'subject' => 'magaza_wp'], $m->result['sites'][0]['notes']);
        $this->assertSame(1, Database::where('name', 'magaza_wp')->count());
        $this->assertNull(Database::where('name', 'magaza_wp')->value('site_id'), 'the existing database is not taken over');
    }

    public function test_a_mailbox_whose_old_password_cannot_be_used_gets_a_new_one(): void
    {
        $meta = $this->fixture['meta']['magaza.com.tr'];
        $meta['mail']['mailboxes'][1]['password_hash'] = '';
        $this->fixture['meta']['magaza.com.tr'] = $meta;
        $m = $this->read();
        $m->update(['status' => 'queued', 'options' => ['sites' => ['magaza.com.tr'], 'parts' => ['files', 'mail']], 'run' => 'migrate-abcdefabcdef']);
        $this->agent->answers['migrate.convert'] = [...$this->fixture['convert'], 'items' => [$this->fixture['convert']['items'][0]]];

        app(SiteMigrations::class)->run($m);
        $m->refresh();

        $this->assertSame('succeeded', $m->status, json_encode($m->result));
        $this->assertSame('satis@magaza.com.tr', $m->secrets['mailboxes'][0]['name']);
        $this->assertSame(16, strlen($m->secrets['mailboxes'][0]['password']));
        $this->assertFalse(DnsZone::where('name', 'magaza.com.tr')->sole()->records()->where('name', 'kampanya')->exists(), 'DNS was not chosen: the site has the starting zone only');
        $this->assertSame(0, Database::count(), 'databases were not chosen');
    }

    public function test_a_failed_conversion_is_reported_and_nothing_is_created(): void
    {
        $m = $this->read();
        $m->update(['status' => 'queued', 'options' => ['sites' => ['magaza.com.tr'], 'parts' => ['files']], 'run' => 'migrate-abcdefabcdef']);
        $this->agent->answers['migrate.convert'] = AgentException::rpcError('migrate.convert', -32000, 'not enough free disk space: need 3 GiB');

        app(SiteMigrations::class)->run($m);
        $m->refresh();

        $this->assertSame('failed', $m->status);
        $this->assertSame('Arşivi dönüştürmek için diskte yeterli boş yer yok.', $m->error);
        $this->assertSame(0, Site::count());
        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)->assertSee('Arşivi dönüştürmek için diskte yeterli boş yer yok.');
    }

    public function test_an_uploaded_archive_is_removed_when_it_is_discarded(): void
    {
        $page = Livewire::test(SiteMigrate::class);
        $upload = $page->instance()->newUpload();
        $page->call('inspectUpload', $upload)->assertSet('error', null);
        $m = SiteMigration::sole();
        $this->assertSame('migrate/'.$upload.'.tar', $m->upload);

        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)->call('discard')->assertSet('migrationId', null);
        $this->assertSame([['source' => 'migrate/'.$upload.'.tar']], $this->agent->called('migrate.discard'));
        $this->assertSame(0, SiteMigration::count());
    }

    public function test_customers_cannot_open_the_page(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => false, 'customer_id' => $customer->id]));

        $this->get('/sites/migrate')->assertForbidden();
    }

    public function test_the_sites_list_and_the_backup_import_point_to_the_page(): void
    {
        $this->get('/sites')->assertOk()->assertSee('Başka sunucudan taşı')->assertSee(route('sites.migrate'));
    }
}
