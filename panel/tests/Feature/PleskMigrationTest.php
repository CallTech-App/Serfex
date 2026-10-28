<?php

namespace Tests\Feature;

use App\Jobs\PullSiteMigration;
use App\Livewire\Sites\SiteMigrate;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteMigration;
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
 * Başka sunucudan taşı → Plesk sunucusu: the panel signs in to the old
 * server over SSH, lists its subscriptions, copies one here and moves it
 * like a cPanel account. The agent's answers come from
 * tests/Fixtures/plesk-migration.json, which the agent's tests wrote from
 * their made-up Plesk server (subscription kafe.com.tr with the
 * additional domain kafemenu.net, the alias kafe.net and a subdomain).
 */
class PleskMigrationTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, mixed> */
    private array $fixture;

    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC2Hc7l0hX9F2yTqkQH1z3ZbV1t0Zr2l1o2vYw0ZsB3K serfex-backup@yeni';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/plesk-migration.json')), true);
        $this->agent = new FakeAgent([
            'backup.status' => ['public_key' => self::KEY],
            'migrate.plesk.connect' => $this->fixture['connect'],
            'migrate.plesk.pull' => fn ($p) => ['source' => $p['source'], 'size' => 40960],
            'migrate.inspect' => [...$this->fixture['inspect'], 'free_backups' => 1 << 36, 'free_home' => 1 << 36],
            'migrate.convert' => $this->fixture['convert'],
            'migrate.discard' => ['discarded' => true],
            'backup.member.read' => function ($p) {
                $json = json_encode($this->fixture['meta'][$p['name']]);

                return ['data' => base64_encode($json), 'offset' => strlen($json), 'size' => strlen($json)];
            },
            'backup.restore' => fn ($p) => ['restored' => $p['parts'] ?? [], 'skipped' => []],
            'backup.delete' => ['deleted' => true],
            'backup.run.read' => ['data' => "▸ plesk.eskisunucu.com sunucusuna bağlanılıyor\n", 'offset' => 48, 'size' => 48],
            'site.apply' => fn ($p) => ['uid' => 1001, 'home' => '/home/kafeweb', 'document_root' => '/home/kafeweb/web/'.($p['domain'] ?? '').'/public_html'],
            'php.versions' => ['7.4', '8.2', '8.4'],
            'mail.domain.apply' => ['dkim' => ['name' => 'serfex._domainkey', 'record' => 'v=DKIM1; k=rsa; p=abc']],
            'dns.zone.apply' => [],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        Setting::put('databases.products', ['mysql' => 'mysql']);
        app(MailSettings::class)->saveServer(['state' => 'ready', 'setup_at' => now()->toIso8601String()]);
        $this->actingAs(User::factory()->create());
    }

    /**
     * Connects with a password and starts copying kafe.com.tr.
     */
    private function pull(): SiteMigration
    {
        Livewire::test(SiteMigrate::class)
            ->set('from', 'plesk')
            ->set('pleskHost', 'Plesk.EskiSunucu.com')
            ->set('pleskAuth', 'password')
            ->set('pleskPassword', 'Eski-Root-Parola')
            ->call('connectPlesk')->assertHasNoErrors()->assertSet('error', null)
            ->set('pleskSubscription', 1)
            ->call('pullPlesk')->assertSet('error', null)->assertSet('pleskPassword', '');

        return SiteMigration::sole();
    }

    public function test_the_plesk_tab_shows_serfex_s_key_and_lists_the_subscriptions(): void
    {
        Livewire::test(SiteMigrate::class)
            ->set('from', 'plesk')
            ->assertSee('Plesk sunucusu')
            ->assertSee("echo '".self::KEY."' >> ~/.ssh/authorized_keys")
            ->set('pleskHost', '192.0.2.10')
            ->call('connectPlesk')->assertHasNoErrors()
            ->assertSee('kafe.com.tr')->assertSee('atolye.net')->assertSee('kafeweb')
            ->assertSee($this->fixture['connect']['fingerprint'])
            ->assertSee('Plesk 18.0.63 · Ubuntu 22.04');

        // Serfex's own key: no password goes to the agent.
        $this->assertSame([['host' => '192.0.2.10', 'port' => 22, 'user' => 'root']], $this->agent->called('migrate.plesk.connect'));
    }

    public function test_the_server_s_address_is_checked_before_connecting(): void
    {
        Livewire::test(SiteMigrate::class)
            ->set('from', 'plesk')
            ->set('pleskHost', 'eski sunucu')
            ->set('pleskAuth', 'password')
            ->call('connectPlesk')
            ->assertHasErrors(['pleskHost', 'pleskPassword']);
        $this->assertSame([], $this->agent->called('migrate.plesk.connect'));
    }

    public function test_sign_in_problems_are_told_in_words(): void
    {
        foreach ([
            'the server refused the user name or password' => 'Sunucu kullanıcı adını ya da parolayı kabul etmedi.',
            "the server refused Serfex's key: add it to the user's ~/.ssh/authorized_keys" => 'Sunucu Serfex\'in anahtarını kabul etmedi.',
            'could not reach 192.0.2.10:22 over SSH: i/o timeout' => 'Sunucuya SSH ile ulaşılamadı.',
            'the server is not a Plesk server: /usr/local/psa/version is missing' => 'Bu sunucuda Plesk bulunamadı',
        ] as $agent => $words) {
            $this->agent->answers['migrate.plesk.connect'] = AgentException::rpcError('migrate.plesk.connect', -32000, $agent);
            $page = Livewire::test(SiteMigrate::class)->set('from', 'plesk')->set('pleskHost', '192.0.2.10')->call('connectPlesk');
            $this->assertStringStartsWith($words, (string) $page->get('error'));
        }
    }

    public function test_the_subscription_is_copied_then_read_and_the_password_forgotten(): void
    {
        $m = $this->pull();
        Queue::assertPushed(PullSiteMigration::class);
        $this->assertSame('pulling', $m->status);
        $this->assertSame('plesk', $m->source);
        $this->assertSame('Eski-Root-Parola', $m->secrets['ssh_password']);
        $this->assertSame(['host' => 'plesk.eskisunucu.com', 'user' => 'root', 'password' => 'Eski-Root-Parola'], array_intersect_key($this->agent->called('migrate.plesk.connect')[0], ['host' => 1, 'user' => 1, 'password' => 1]));

        // The page follows the copy.
        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->assertSee("Plesk'ten alınıyor")->assertSee('Abonelik Plesk sunucusundan alınıyor')
            ->assertDontSee('Arşiv Serfex yedeklerine dönüştürülüyor');

        (new PullSiteMigration($m))->handle(app(SiteMigrations::class));
        $m->refresh();

        $pull = $this->agent->called('migrate.plesk.pull')[0];
        $this->assertSame(1, $pull['subscription']);
        $this->assertSame('Eski-Root-Parola', $pull['password']);
        $this->assertSame($this->fixture['connect']['host_key'], $pull['host_key']);
        $this->assertSame($m->upload, $pull['source']);
        $this->assertSame([['source' => $m->upload]], $this->agent->called('migrate.inspect'));
        $this->assertSame(SiteMigration::READY, $m->status);
        $this->assertNull($m->secrets, 'the password is gone once the copy is here');
        $this->assertSame('kafeweb', $m->plan['user']);

        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->assertSet('sites', ['kafe.com.tr', 'kafemenu.net'])
            ->assertSet('customerEmail', 'mehmet@kafe.com.tr')
            ->assertSee('Abonelikte ne var')
            ->assertSee('kafe.net')->assertSee('blog.kafe.com.tr')
            ->assertSee('eskikafe.com alan adının Plesk\'te web barındırması yok')
            ->assertSee('kafe_rapor veritabanı başka bir veritabanı sunucusunda')
            ->assertSee('httpdocs/wp-config.php dosyasında eski sunucunun /var/www/vhosts/ yolu geçiyor')
            ->assertSee('kafe.net takma adının postası taşınmaz');
    }

    public function test_a_failed_copy_is_reported_and_the_password_forgotten(): void
    {
        $m = $this->pull();
        $this->agent->answers['migrate.plesk.pull'] = AgentException::rpcError('migrate.plesk.pull', -32000, 'there is not enough free disk space to copy the subscription: 1.3 GB needed');

        app(SiteMigrations::class)->pull($m);
        $m->refresh();

        $this->assertSame('failed', $m->status);
        $this->assertNull($m->secrets);
        $this->assertSame('Aboneliği bu sunucuya almak için diskte yeterli boş yer yok.', $m->error);
        $this->assertSame([], $this->agent->called('migrate.inspect'));
        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->assertSee('Aboneliği bu sunucuya almak için diskte yeterli boş yer yok.');
    }

    public function test_the_subscription_moves_with_its_sites_databases_mail_and_dns(): void
    {
        $m = $this->pull();
        app(SiteMigrations::class)->pull($m);
        $m->refresh();

        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->set('customerName', 'Mehmet Demir')
            ->call('confirmStart')->assertHasNoErrors()
            ->call('start')->assertHasNoErrors()->assertSet('error', null);
        $m->refresh();
        $this->assertSame('queued', $m->status);
        $this->assertSame(1, $m->options['plesk']['subscription'], 'the copy is still known after the choices');

        app(SiteMigrations::class)->run($m);
        $m->refresh();

        $this->assertSame('succeeded', $m->status, json_encode($m->result));
        $this->assertSame('kafeweb', $this->agent->called('migrate.convert')[0]['account']);
        $this->assertStringStartsWith('pl-', $this->agent->called('migrate.convert')[0]['source']);

        $main = Site::where('domain', 'kafe.com.tr')->sole();
        $this->assertSame('8.2', $main->php_version);
        $this->assertSame(['www.kafe.com.tr', 'kafe.net', 'www.kafe.net'], $main->aliases);
        $menu = Site::where('domain', 'kafemenu.net')->sole();
        $this->assertSame(['www.kafemenu.net', 'kafemenu.com', 'www.kafemenu.com'], $menu->aliases);
        $this->assertSame(['kafe_wp'], Database::where('site_id', $main->id)->pluck('name')->all());
        $this->assertSame(['kafemenu_db'], Database::where('site_id', $menu->id)->pluck('name')->all());
        $this->assertSame(['info'], MailDomain::where('name', 'kafe.com.tr')->sole()->mailboxes()->pluck('name')->all());

        $records = DnsZone::where('name', 'kafe.com.tr')->sole()->records()->get()->map(fn ($r) => $r->name.' '.$r->type.' '.$r->content)->all();
        $this->assertContains('siparis CNAME kafe.myshopify.com.', $records);
        $this->assertContains('@ CAA 0 issue "letsencrypt.org"', $records);
        $this->assertNotNull(DnsZone::where('name', 'kafe.net')->where('site_id', $main->id)->first(), 'the alias keeps its zone');
        $this->assertSame('kafe_wpu', $m->secrets['databases'][0]['name']);
        $this->assertSame([['source' => $this->agent->called('migrate.convert')[0]['source']]], $this->agent->called('migrate.discard'), 'the copy is removed once everything came');

        Livewire::withQueryParams(['migration' => $m->id])->test(SiteMigrate::class)
            ->assertSee('Taşıma raporu')
            ->assertSee('Eski sunucudaki aboneliğe dokunulmadı')
            ->assertSee('kafe_tasarim');
    }
}
