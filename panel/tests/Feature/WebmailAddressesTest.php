<?php

namespace Tests\Feature;

use App\Console\Commands\RenewCertificates;
use App\Enums\CertificateStatus;
use App\Enums\MailDomainStatus;
use App\Jobs\IssueWebmailCertificate;
use App\Jobs\SyncWebmailAddresses;
use App\Livewire\Mail\MailDomainShow;
use App\Livewire\Mail\MailSettingsPage;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Mail\MailDns;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailSettings;
use App\Services\Mail\WebmailAddresses;
use App\Services\Ssl\DnsCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WebmailAddressesTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Setting::put('dns.ipv4', '203.0.113.10');
        Setting::put('mail.hostname', 'mail.example.net');
        app(MailSettings::class)->saveServer(['state' => 'ready', 'setup_at' => now()->toIso8601String(), 'webmail_url' => 'https://mail.example.net/', 'certificate' => '']);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('mail.status')->andReturn(['installed' => true, 'configured' => true, 'running' => true, 'services' => ['postfix' => true]])->byDefault();
        $this->agent->shouldReceive('call')->with('mail.usage', Mockery::any())->andReturn(['mailboxes' => []])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function addresses(): WebmailAddresses
    {
        return app(WebmailAddresses::class);
    }

    private function installed(MailDomain $domain): Certificate
    {
        return Certificate::create([
            'kind' => 'webmail', 'name' => 'webmail-'.$domain->name, 'source' => Certificate::LETS_ENCRYPT, 'challenge' => 'http-01',
            'domains' => ['webmail.'.$domain->name], 'status' => CertificateStatus::Active,
            'not_before' => now()->subDay(), 'not_after' => now()->addDays(89),
        ]);
    }

    /** @return list<string> */
    private function records(DnsZone $zone): array
    {
        return $zone->records()->get()->map(fn ($r) => "{$r->name} {$r->type} {$r->content}")->all();
    }

    public function test_the_domains_zone_gets_its_webmail_address_and_loses_only_that(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        app(MailDns::class)->apply($domain);
        $this->assertContains('webmail A 203.0.113.10', $this->records($zone));
        $this->assertContains('mail A 203.0.113.10', $this->records($zone));

        app(MailDns::class)->removeWebmail($domain);
        $this->assertNotContains('webmail A 203.0.113.10', $this->records($zone));
        $this->assertContains('mail A 203.0.113.10', $this->records($zone));

        // Turned off, the record is not wanted.
        Setting::put('mail.domain_webmail', false);
        $this->assertNotContains('webmail-a', array_column(app(MailDns::class)->wanted($domain), 'key'));
    }

    public function test_an_old_record_pointing_elsewhere_is_left_alone(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $zone->records()->create(['name' => 'webmail', 'type' => 'A', 'content' => '198.51.100.7', 'ttl' => 3600]);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        app(MailDns::class)->apply($domain);

        $this->assertContains('webmail A 198.51.100.7', $this->records($zone));
        $this->assertNotContains('webmail A 203.0.113.10', $this->records($zone));
        $row = collect(app(MailDns::class)->check($domain, public: false))->firstWhere('key', 'webmail-a');
        $this->assertSame('different', $row['zone']);
    }

    public function test_sync_requests_certificates_and_serves_the_ready_addresses(): void
    {
        $ready = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        $waiting = MailDomain::factory()->create(['name' => 'cay.com.tr']);
        $this->installed($ready);

        $this->agent->shouldReceive('call')->once()->with('mail.webmail.hosts', ['hosts' => [['name' => 'webmail.kahve.com.tr', 'certificate' => 'webmail-kahve.com.tr']]], 120.0)->andReturn(['changed' => true]);
        $this->assertTrue($this->addresses()->sync($this->agent));
        // Nothing changed: the agent is not asked again.
        $this->assertFalse($this->addresses()->sync($this->agent));

        $requested = Certificate::where('name', 'webmail-cay.com.tr')->first();
        $this->assertSame(CertificateStatus::Issuing, $requested->status);
        $this->assertSame(['webmail.cay.com.tr'], $requested->domains);
        Queue::assertPushed(IssueWebmailCertificate::class, fn ($job) => $job->domain->is($waiting));

        $this->assertSame('ready', $this->addresses()->state($ready)['state']);
        $this->assertSame('cert', $this->addresses()->state($waiting)['state']);
        $this->assertSame('https://webmail.kahve.com.tr/', $this->addresses()->urlFor($ready));
        $this->assertSame('https://mail.example.net/', $this->addresses()->urlFor($waiting));

        // Mail turned off for the domain: no longer served.
        app(MailDomains::class)->disable($ready);
        Queue::assertPushed(SyncWebmailAddresses::class);
        $this->agent->shouldReceive('call')->once()->with('mail.webmail.hosts', ['hosts' => []], 120.0)->andReturn(['changed' => true]);
        $this->assertTrue($this->addresses()->sync($this->agent));
        $this->assertNull($this->addresses()->state($ready->fresh()));
    }

    public function test_nothing_is_served_or_requested_while_turned_off(): void
    {
        Setting::put('mail.domain_webmail', false);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        $this->agent->shouldNotReceive('call')->with('mail.webmail.hosts', Mockery::any(), Mockery::any());

        $this->assertFalse($this->addresses()->sync($this->agent));
        $this->assertSame(0, Certificate::count());
        $this->assertNull($this->addresses()->state($domain));
        $this->assertSame('https://mail.example.net/', $this->addresses()->urlFor($domain));
    }

    public function test_the_certificate_waits_for_dns_then_is_issued_and_served(): void
    {
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        $certificate = $this->addresses()->request($domain);
        $dns = Mockery::mock(DnsCheck::class);
        $dns->shouldReceive('check')->with('webmail.kahve.com.tr')->andReturn(['ok' => false, 'found' => ['198.51.100.7'], 'proxied' => false], ['ok' => true, 'found' => ['203.0.113.10'], 'proxied' => false]);

        (new IssueWebmailCertificate($certificate, $domain))->handle($this->agent, $dns, $this->addresses());
        $certificate->refresh();
        $this->assertSame(CertificateStatus::WaitingDns, $certificate->status);
        $this->assertStringContainsString('198.51.100.7', $certificate->last_error);
        $this->assertSame('dns', $this->addresses()->state($domain)['state']);

        // The hourly run asks again.
        $certificate->update(['attempted_at' => now()->subHour()]);
        Queue::fake();
        $this->artisan(RenewCertificates::class)->assertSuccessful();
        Queue::assertPushed(IssueWebmailCertificate::class);

        $this->agent->shouldReceive('call')->once()->with('ssl.issue', Mockery::on(fn ($p) => $p['name'] === 'webmail-kahve.com.tr' && $p['names'] === ['webmail.kahve.com.tr'] && $p['challenge'] === 'http-01'), Mockery::any())
            ->andReturn(['names' => ['webmail.kahve.com.tr'], 'not_before' => now()->toIso8601String(), 'not_after' => now()->addDays(90)->toIso8601String(), 'issuer' => 'R11']);
        (new IssueWebmailCertificate($certificate, $domain))->handle($this->agent, $dns, $this->addresses());
        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);
        Queue::assertPushed(SyncWebmailAddresses::class);
    }

    public function test_a_request_for_a_domain_whose_mail_went_is_dropped(): void
    {
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        $certificate = $this->addresses()->request($domain);
        $domain->update(['status' => MailDomainStatus::Deleting]);
        $this->agent->shouldNotReceive('call')->with('ssl.issue', Mockery::any(), Mockery::any());

        (new IssueWebmailCertificate($certificate, $domain))->handle($this->agent, Mockery::mock(DnsCheck::class), $this->addresses());
        $this->assertNull(Certificate::find($certificate->id));
    }

    public function test_updated_servers_keep_it_off_and_are_offered_it(): void
    {
        $migration = require database_path('migrations/2026_11_12_000010_keep_domain_webmail_off_on_existing_servers.php');
        $migration->up();
        $this->assertFalse(app(MailSettings::class)->domainWebmail());
        $this->assertTrue(app(MailSettings::class)->offerDomainWebmail());

        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        MailDomain::factory()->create(['name' => 'kahve.com.tr']);

        $page = Livewire::test(MailSettingsPage::class)->assertSee('Yeni: her alan adına kendi web posta adresi');
        $page->call('enableDomainWebmail')->assertDontSee('Yeni: her alan adına kendi web posta adresi');
        $this->assertTrue(app(MailSettings::class)->domainWebmail());
        $this->assertContains('webmail A 203.0.113.10', $this->records($zone));
        Queue::assertPushed(SyncWebmailAddresses::class);

        // Undo takes the records away again.
        $page->call('undoSave', ['hostname' => 'mail.example.net', 'default_quota_mb' => 1024, 'hourly_limit' => 500, 'webmail' => true, 'webmail_app' => 'roundcube', 'domain_webmail' => false, 'auto' => true]);
        $this->assertFalse(app(MailSettings::class)->domainWebmail());
        $this->assertNotContains('webmail A 203.0.113.10', $this->records($zone));
        $this->assertFalse(app(MailSettings::class)->offerDomainWebmail());
    }

    public function test_new_servers_have_it_on_without_an_offer(): void
    {
        Setting::put('mail.server', []);
        $migration = require database_path('migrations/2026_11_12_000010_keep_domain_webmail_off_on_existing_servers.php');
        $migration->up();
        $this->assertTrue(app(MailSettings::class)->domainWebmail());
        $this->assertFalse(app(MailSettings::class)->offerDomainWebmail());
    }

    public function test_the_domain_page_shows_the_address_to_give_users(): void
    {
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        Livewire::test(MailDomainShow::class, ['domain' => $domain->id])
            ->assertSee('https://webmail.kahve.com.tr/')
            ->assertSee('Sertifika alınıyor');

        $this->installed($domain);
        Setting::put('mail.webmail_hosts', [['name' => 'webmail.kahve.com.tr', 'certificate' => 'webmail-kahve.com.tr']]);
        Livewire::test(MailDomainShow::class, ['domain' => $domain->id])
            ->assertSee('Hazır')
            ->assertSee('panele girmeden');

        Setting::put('mail.domain_webmail', false);
        Livewire::test(MailDomainShow::class, ['domain' => $domain->id])->assertDontSee('https://webmail.kahve.com.tr/');
    }
}
