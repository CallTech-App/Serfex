<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\MailDomainStatus;
use App\Jobs\DeleteMailDomain;
use App\Jobs\IssueMailCertificate;
use App\Jobs\PurgeMailbox;
use App\Jobs\SetupMailServer;
use App\Jobs\SyncMailDomain;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailDns;
use App\Services\Mail\MailServerDns;
use App\Services\Mail\MailSettings;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MailJobsTest extends TestCase
{
    use RefreshDatabase;

    private const DKIM = 'v=DKIM1; k=rsa; p=MIIBIjANBgkq';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::put('dns.ipv4', '203.0.113.10');
        Setting::put('mail.hostname', 'mail.serfex.net.tr');
        config(['serfex.ssl.check_dns' => false]);
    }

    public function test_sync_sends_the_whole_domain_stores_dkim_and_writes_dns(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr', 'status' => MailDomainStatus::Syncing, 'spam_filter' => false]);
        $ali = Mailbox::factory()->for($domain, 'domain')->create(['name' => 'ali', 'quota_mb' => 5120, 'forward_to' => ['ali@gmail.com'], 'keep_copy' => false,
            'autoreply' => ['enabled' => true, 'subject' => 'İzindeyim', 'body' => 'Pazartesi dönüyorum.', 'until' => '2026-10-27']]);
        Mailbox::factory()->for($domain, 'domain')->create(['name' => 'veli', 'autoreply' => ['enabled' => false, 'subject' => 'x', 'body' => 'y', 'until' => '']]);
        Mailbox::factory()->for($domain, 'domain')->create(['name' => 'eski'])->delete();
        $domain->forwarders()->create(['name' => 'satis', 'destinations' => ['ali@kahve.com.tr', 'veli@kahve.com.tr']]);
        $domain->forwarders()->create(['name' => '*', 'destinations' => ['ali@kahve.com.tr']]);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('mail.domain.apply', Mockery::on(function ($spec) use ($ali) {
            $this->assertSame('kahve.com.tr', $spec['domain']);
            $this->assertFalse($spec['spam_filter']);
            $this->assertSame(['ali', 'veli'], array_column($spec['mailboxes'], 'name'), 'removed mailboxes are left out');
            $this->assertSame([
                'name' => 'ali',
                'password_hash' => $ali->password_hash,
                'quota_mb' => 5120,
                'forward_to' => ['ali@gmail.com'],
                'keep_copy' => false,
                'autoreply' => ['subject' => 'İzindeyim', 'body' => 'Pazartesi dönüyorum.', 'until' => '2026-10-27'],
            ], $spec['mailboxes'][0]);
            $this->assertNull($spec['mailboxes'][1]['autoreply']);
            $this->assertSame([['name' => 'satis', 'to' => ['ali@kahve.com.tr', 'veli@kahve.com.tr']]], $spec['aliases']);
            $this->assertSame(['ali@kahve.com.tr'], $spec['catch_all']);

            return true;
        }), 120.0)->andReturn(['domain' => 'kahve.com.tr', 'changed' => true, 'dkim' => ['selector' => 'serfex', 'name' => 'serfex._domainkey', 'record' => self::DKIM]]);

        (new SyncMailDomain($domain))->handle($agent, app(MailDns::class));

        $domain->refresh();
        $this->assertSame(MailDomainStatus::Active, $domain->status);
        $this->assertSame(self::DKIM, $domain->dkim_record);
        $this->assertNotNull($domain->synced_at);
        $this->assertTrue($zone->records()->where('name', 'serfex._domainkey')->where('content', self::DKIM)->exists());
        $this->assertTrue($zone->records()->where('type', 'MX')->exists());
    }

    public function test_sync_explains_a_missing_mail_server(): void
    {
        $domain = MailDomain::factory()->create(['status' => MailDomainStatus::Syncing]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('mail.domain.apply', -32002, 'the mail server is not set up yet'));

        (new SyncMailDomain($domain))->handle($agent, app(MailDns::class));

        $domain->refresh();
        $this->assertSame(MailDomainStatus::Failed, $domain->status);
        $this->assertStringContainsString('Posta sunucusu henüz kurulmadı', $domain->last_error);
    }

    public function test_sync_leaves_dns_alone_when_the_user_manages_it(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr', 'status' => MailDomainStatus::Syncing, 'manage_dns' => false]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn(['dkim' => ['name' => 'serfex._domainkey', 'record' => self::DKIM]]);

        (new SyncMailDomain($domain))->handle($agent, app(MailDns::class));

        $this->assertSame(4, $zone->records()->count());
    }

    public function test_turning_mail_off_keeps_mail_until_the_purge(): void
    {
        $domain = MailDomain::factory()->create(['status' => MailDomainStatus::Deleting]);
        Mailbox::factory()->for($domain, 'domain')->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('mail.domain.delete', ['domain' => $domain->name, 'remove_files' => false], 120.0)->andReturn(['deleted' => true]);

        (new DeleteMailDomain($domain))->handle($agent);

        $this->assertNotNull($domain->fresh());
        Queue::assertPushed(DeleteMailDomain::class, fn ($job) => $job->purge && $job->delay !== null);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('mail.domain.delete', ['domain' => $domain->name, 'remove_files' => true], 120.0)->andReturn(['deleted' => true, 'files_removed' => true]);
        (new DeleteMailDomain($domain, purge: true))->handle($agent);

        $this->assertNull($domain->fresh());
        $this->assertSame(0, Mailbox::withTrashed()->count());
    }

    public function test_purge_skips_a_domain_whose_mail_came_back(): void
    {
        $domain = MailDomain::factory()->create(['status' => MailDomainStatus::Active]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldNotReceive('call');

        (new DeleteMailDomain($domain, purge: true))->handle($agent);

        $this->assertNotNull($domain->fresh());
    }

    public function test_purge_mailbox_deletes_its_mail_unless_restored(): void
    {
        $mailbox = Mailbox::factory()->create(['name' => 'ali']);
        $mailbox->delete();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('mail.mailbox.purge', ['domain' => $mailbox->domain->name, 'name' => 'ali'], 120.0)->andReturn(['files_removed' => true]);

        (new PurgeMailbox($mailbox))->handle($agent);
        $this->assertSame(0, Mailbox::withTrashed()->count());

        $restored = Mailbox::factory()->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldNotReceive('call');
        (new PurgeMailbox($restored))->handle($agent);
        $this->assertNotNull($restored->fresh());
    }

    public function test_setup_records_the_server_resyncs_domains_and_asks_for_a_certificate(): void
    {
        Setting::put('mail.hourly_limit', 200);
        $failed = MailDomain::factory()->create(['status' => MailDomainStatus::Failed, 'last_error' => 'kurulmadı']);
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'serfex.net.tr']);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('mail.setup', [
            'hostname' => 'mail.serfex.net.tr',
            'certificate' => '',
            'hourly_limit' => 200,
            'webmail' => true,
        ], 840)->andReturn(['installed' => true, 'changed' => true, 'hostname' => 'mail.serfex.net.tr', 'webmail_url' => 'https://mail.serfex.net.tr/']);

        (new SetupMailServer)->handle($agent, app(MailSettings::class), app(Certificates::class), app(MailServerDns::class));

        $settings = app(MailSettings::class);
        $this->assertSame('ready', $settings->server()['state']);
        $this->assertTrue($settings->isSetUp());
        $this->assertSame('https://mail.serfex.net.tr/', $settings->webmailUrl());
        $this->assertSame(MailDomainStatus::Syncing, $failed->fresh()->status);
        Queue::assertPushed(SyncMailDomain::class, fn ($job) => $job->domain->is($failed));
        // The server's name points here in its zone.
        $this->assertTrue($zone->records()->where('name', 'mail')->where('type', 'A')->where('content', '203.0.113.10')->exists());
        $certificate = Certificate::where('kind', 'mail')->first();
        $this->assertSame(['mail.serfex.net.tr'], $certificate->domains);
        $this->assertSame(CertificateStatus::Issuing, $certificate->status);
        Queue::assertPushed(IssueMailCertificate::class);
    }

    public function test_setup_failure_is_kept(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('mail.setup', -32000, 'postfix check failed'));

        (new SetupMailServer)->handle($agent, app(MailSettings::class), app(Certificates::class), app(MailServerDns::class));

        $server = app(MailSettings::class)->server();
        $this->assertSame('failed', $server['state']);
        $this->assertSame('postfix check failed', $server['error']);
        Queue::assertNotPushed(IssueMailCertificate::class);
    }

    public function test_certificate_switches_the_mail_server_to_it(): void
    {
        app(MailSettings::class)->saveServer(['state' => 'ready', 'certificate' => '', 'setup_at' => now()->toIso8601String()]);
        $certificate = Certificate::create(['kind' => 'mail', 'name' => 'mail', 'source' => 'letsencrypt', 'domains' => ['mail.serfex.net.tr'], 'challenge' => 'http-01', 'status' => CertificateStatus::Issuing]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('ssl.issue', Mockery::on(fn ($p) => $p['name'] === 'mail' && $p['names'] === ['mail.serfex.net.tr'] && $p['challenge'] === 'http-01'), 300.0)
            ->andReturn(['names' => ['mail.serfex.net.tr'], 'issuer' => "Let's Encrypt R11", 'not_before' => now()->toIso8601String(), 'not_after' => now()->addDays(90)->toIso8601String()]);

        (new IssueMailCertificate($certificate))->handle($agent, app(DnsCheck::class), app(MailSettings::class));

        $this->assertSame(CertificateStatus::Active, $certificate->fresh()->status);
        $this->assertSame('mail', app(MailSettings::class)->certificateName());
        Queue::assertPushed(SetupMailServer::class);
    }

    public function test_certificate_waits_for_dns(): void
    {
        config(['serfex.ssl.check_dns' => true]);
        $check = Mockery::mock(DnsCheck::class);
        $check->shouldReceive('check')->andReturn(['ok' => false, 'found' => ['198.51.100.7']]);
        $certificate = Certificate::create(['kind' => 'mail', 'name' => 'mail', 'source' => 'letsencrypt', 'domains' => ['mail.serfex.net.tr'], 'status' => CertificateStatus::Issuing]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldNotReceive('call');

        (new IssueMailCertificate($certificate))->handle($agent, $check, app(MailSettings::class));

        $certificate->refresh();
        $this->assertSame(CertificateStatus::WaitingDns, $certificate->status);
        $this->assertStringContainsString('198.51.100.7', $certificate->last_error);
    }

    public function test_renewal_command_renews_the_mail_certificate(): void
    {
        $certificate = Certificate::create(['kind' => 'mail', 'name' => 'mail', 'source' => 'letsencrypt', 'domains' => ['mail.serfex.net.tr'],
            'status' => CertificateStatus::Active, 'not_after' => now()->addDays(10), 'auto_renew' => true]);

        $this->artisan('serfex:ssl-renew')->assertSuccessful();

        $this->assertSame(CertificateStatus::Issuing, $certificate->fresh()->status);
        Queue::assertPushed(IssueMailCertificate::class);
    }

    public function test_usage_command_records_mailbox_sizes(): void
    {
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        $ali = Mailbox::factory()->for($domain, 'domain')->create(['name' => 'ali']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('mail.usage', ['domain' => 'kahve.com.tr'])->andReturn(['domain' => 'kahve.com.tr', 'mailboxes' => ['ali' => 5242880]]);
        $this->app->instance(AgentClient::class, $agent);

        $this->artisan('serfex:mail-usage')->assertSuccessful();

        $this->assertSame(5242880, $ali->fresh()->usage_bytes);
    }
}
