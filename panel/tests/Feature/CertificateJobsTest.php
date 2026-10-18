<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\SiteStatus;
use App\Jobs\DeleteCertificate;
use App\Jobs\DeleteSite;
use App\Jobs\IssueCertificate;
use App\Jobs\IssuePanelCertificate;
use App\Jobs\ProvisionSite;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\Zones;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CertificateJobsTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUED = [
        'name' => 'kahve.com.tr',
        'names' => ['kahve.com.tr', 'www.kahve.com.tr'],
        'issuer' => "Let's Encrypt R11",
        'serial' => '04ab',
        'fingerprint' => 'aa:bb',
        'not_before' => '2026-10-18T10:00:00Z',
        'not_after' => '2027-01-16T10:00:00Z',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['email' => 'admin@kahve.com.tr']);
    }

    /**
     * @param  array<string, bool>  $pointing  name => resolves here
     */
    private function dns(array $pointing): DnsCheck
    {
        return Mockery::mock(DnsCheck::class, function (MockInterface $m) use ($pointing) {
            $m->shouldReceive('check')->andReturnUsing(fn ($name) => ['ok' => $pointing[$name] ?? false, 'found' => ($pointing[$name] ?? false) ? ['192.0.2.10'] : ['198.51.100.7']]);
            $m->shouldReceive('serverAddresses')->andReturn(['192.0.2.10']);
        });
    }

    private function site(): Site
    {
        return Site::factory()->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr']]);
    }

    public function test_issue_installs_the_certificate_and_switches_the_site(): void
    {
        Queue::fake();
        $site = $this->site();
        $certificate = app(Certificates::class)->letsEncrypt($site);
        $this->assertNull($site->fresh()->certificate_id, 'the site switches only once the certificate exists');

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('ssl.issue', ['name' => 'kahve.com.tr', 'names' => ['kahve.com.tr', 'www.kahve.com.tr'], 'challenge' => 'http-01', 'email' => 'admin@kahve.com.tr'], 300.0)
            ->andReturn(self::ISSUED);

        (new IssueCertificate($certificate))->handle($agent, $this->dns(['kahve.com.tr' => true, 'www.kahve.com.tr' => true]), app(Certificates::class));

        $certificate->refresh();
        $this->assertSame(CertificateStatus::Active, $certificate->status);
        $this->assertSame("Let's Encrypt", $certificate->issuerLabel());
        $this->assertSame('2027-01-16', $certificate->not_after->format('Y-m-d'));
        $site->refresh();
        $this->assertSame($certificate->id, $site->certificate_id);
        $this->assertSame(SiteStatus::Provisioning, $site->status);
        $this->assertSame(['certificate' => 'kahve.com.tr', 'redirect' => true], $site->agentSpec()['tls']);
        Queue::assertPushed(ProvisionSite::class);
    }

    public function test_names_that_do_not_point_here_are_left_out(): void
    {
        Queue::fake();
        $site = $this->site();
        $certificate = app(Certificates::class)->letsEncrypt($site);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('ssl.issue', Mockery::on(fn ($p) => $p['names'] === ['kahve.com.tr']), Mockery::any())
            ->andReturn([...self::ISSUED, 'names' => ['kahve.com.tr']]);

        (new IssueCertificate($certificate))->handle($agent, $this->dns(['kahve.com.tr' => true]), app(Certificates::class));

        $this->assertSame(['kahve.com.tr'], $certificate->fresh()->domains);
    }

    public function test_a_domain_that_does_not_point_here_waits_without_asking_lets_encrypt(): void
    {
        Queue::fake();
        $site = $this->site();
        $certificate = app(Certificates::class)->letsEncrypt($site, auto: true);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldNotReceive('call');

        (new IssueCertificate($certificate, auto: true))->handle($agent, $this->dns([]), app(Certificates::class));

        $certificate->refresh();
        $this->assertSame(CertificateStatus::WaitingDns, $certificate->status);
        $this->assertStringContainsString('DNS henüz bu sunucuya işaret etmiyor', $certificate->last_error);
        $this->assertStringContainsString('198.51.100.7', $certificate->last_error);
        $this->assertSame('DNS bekleniyor', $site->fresh()->sslState()->label());
    }

    public function test_a_failed_renewal_keeps_the_installed_certificate(): void
    {
        $site = $this->site();
        $certificate = Certificate::factory()->inUseBy($site)->expiring(20)->create(['status' => CertificateStatus::Issuing]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('ssl.issue', -32000, 'kahve.com.tr: the CA could not fetch the challenge'));

        (new IssueCertificate($certificate, auto: true))->handle($agent, $this->dns(['kahve.com.tr' => true, 'www.kahve.com.tr' => true]), app(Certificates::class));

        $certificate->refresh();
        $this->assertSame(CertificateStatus::Active, $certificate->status);
        $this->assertStringContainsString('could not fetch', $certificate->renew_error);
        $this->assertNull($certificate->last_error);
        $this->assertSame('Yenilenemedi', $certificate->state()->label());
        $this->assertSame(1, Certificate::attentionCount());
    }

    public function test_a_failed_first_request_is_shown_as_failed(): void
    {
        Queue::fake();
        $site = $this->site();
        $certificate = app(Certificates::class)->letsEncrypt($site);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('ssl.issue', -32000, 'rate limited'));

        (new IssueCertificate($certificate))->handle($agent, $this->dns(['kahve.com.tr' => true]), app(Certificates::class));

        $this->assertSame(CertificateStatus::Failed, $certificate->fresh()->status);
        $this->assertNull($site->fresh()->certificate_id);
        $this->assertSame('Alınamadı', $site->fresh()->sslState()->label());
    }

    public function test_wildcards_use_the_zone_on_this_server(): void
    {
        Queue::fake();
        $site = $this->site();
        DnsZone::factory()->create(['name' => 'kahve.com.tr', 'site_id' => $site->id]);

        $certificate = app(Certificates::class)->letsEncrypt($site, wildcard: true);

        $this->assertSame(['kahve.com.tr', '*.kahve.com.tr'], $certificate->domains);
        $this->assertSame('dns-01', $certificate->challenge);
        Queue::assertPushed(IssueCertificate::class, fn ($job) => $job->zone === 'kahve.com.tr');

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('ssl.issue', Mockery::on(fn ($p) => $p['challenge'] === 'dns-01' && $p['dns_zone'] === 'kahve.com.tr' && $p['names'] === ['kahve.com.tr', '*.kahve.com.tr']), Mockery::any())
            ->andReturn([...self::ISSUED, 'names' => ['kahve.com.tr', '*.kahve.com.tr']]);
        $dns = Mockery::mock(DnsCheck::class);
        $dns->shouldNotReceive('check');

        (new IssueCertificate($certificate, zone: 'kahve.com.tr'))->handle($agent, $dns, app(Certificates::class));
        $this->assertTrue($certificate->fresh()->isWildcard());
    }

    public function test_wildcards_need_dns_on_this_server(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(Certificates::class)->letsEncrypt($this->site(), wildcard: true);
    }

    public function test_a_new_request_reuses_the_waiting_one(): void
    {
        Queue::fake();
        $site = $this->site();
        $first = app(Certificates::class)->letsEncrypt($site);
        $first->update(['status' => CertificateStatus::WaitingDns]);

        $second = app(Certificates::class)->letsEncrypt($site->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Certificate::count());
    }

    public function test_swapping_certificates_removes_the_old_one_later(): void
    {
        Queue::fake();
        $site = $this->site();
        $old = Certificate::factory()->inUseBy($site)->create();
        $new = Certificate::factory()->create(['site_id' => $site->id, 'source' => Certificate::CUSTOM]);

        app(Certificates::class)->use($site->fresh(), $new);

        $this->assertSame($new->id, $site->fresh()->certificate_id);
        Queue::assertPushed(DeleteCertificate::class, fn ($job) => $job->certificate->is($old) && $job->delay !== null);
    }

    public function test_delete_certificate_skips_one_that_is_in_use_again(): void
    {
        $site = $this->site();
        $certificate = Certificate::factory()->inUseBy($site)->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldNotReceive('call');

        (new DeleteCertificate($certificate))->handle($agent);

        $this->assertTrue($certificate->exists());
    }

    public function test_delete_certificate_removes_an_unused_one(): void
    {
        $certificate = Certificate::factory()->create(['name' => 'eski.com.tr']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('ssl.delete', ['name' => 'eski.com.tr'], Mockery::any())->andReturn(['deleted' => true]);

        (new DeleteCertificate($certificate))->handle($agent);

        $this->assertModelMissing($certificate);
    }

    public function test_deleting_a_site_removes_its_certificates_from_the_server(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Deleting]);
        Certificate::factory()->inUseBy($site)->create(['name' => 'kahve.com.tr']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('site.delete', Mockery::any(), Mockery::any())->andReturn(['user_removed' => true]);
        $agent->shouldReceive('call')->once()->with('ssl.delete', ['name' => 'kahve.com.tr'], Mockery::any())->andReturn(['deleted' => true]);

        (new DeleteSite($site->fresh()))->handle($agent, app(Zones::class));

        $this->assertSame(0, Certificate::count());
    }

    public function test_renew_command_picks_due_certificates(): void
    {
        Queue::fake();
        $due = Certificate::factory()->inUseBy(Site::factory()->create())->expiring(20)->create();
        $fresh = Certificate::factory()->inUseBy(Site::factory()->create())->expiring(60)->create();
        $manual = Certificate::factory()->inUseBy(Site::factory()->create())->expiring(5)->create(['auto_renew' => false]);
        $custom = Certificate::factory()->inUseBy(Site::factory()->create())->expiring(5)->create(['source' => Certificate::CUSTOM]);
        $waiting = Certificate::factory()->create(['status' => CertificateStatus::WaitingDns, 'not_after' => null, 'attempted_at' => now()->subHour()]);
        $justTried = Certificate::factory()->create(['status' => CertificateStatus::WaitingDns, 'not_after' => null, 'attempted_at' => now()->subMinutes(10)]);
        $panel = Certificate::factory()->expiring(10)->create(['kind' => 'panel', 'name' => 'panel', 'site_id' => null]);

        $this->artisan('servik:ssl-renew')->assertSuccessful();

        Queue::assertPushed(IssueCertificate::class, 2);
        Queue::assertPushed(IssueCertificate::class, fn ($job) => $job->certificate->is($due) && $job->auto);
        Queue::assertPushed(IssueCertificate::class, fn ($job) => $job->certificate->is($waiting));
        Queue::assertPushed(IssuePanelCertificate::class, fn ($job) => $job->certificate->is($panel));
        foreach ([$fresh, $manual, $custom, $justTried] as $skipped) {
            Queue::assertNotPushed(IssueCertificate::class, fn ($job) => $job->certificate->is($skipped));
        }
    }

    public function test_panel_certificate_is_issued_for_the_panel_address(): void
    {
        Queue::fake();
        Setting::put('panel.hostname', 'panel.kahve.com.tr');
        $certificate = app(Certificates::class)->issuePanel('panel.kahve.com.tr');
        Queue::assertPushed(IssuePanelCertificate::class);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('ssl.panel.issue', ['hostname' => 'panel.kahve.com.tr', 'email' => 'admin@kahve.com.tr'], 300.0)
            ->andReturn([...self::ISSUED, 'name' => 'panel', 'names' => ['panel.kahve.com.tr']]);

        (new IssuePanelCertificate($certificate))->handle($agent, $this->dns(['panel.kahve.com.tr' => true]));

        $certificate->refresh();
        $this->assertSame('panel', $certificate->kind);
        $this->assertSame(['panel.kahve.com.tr'], $certificate->domains);
        $this->assertSame(CertificateStatus::Active, $certificate->status);
    }

    public function test_new_sites_get_no_certificate_when_automatic_ssl_is_off(): void
    {
        Queue::fake();
        Setting::put('ssl.auto', false);
        $site = $this->site();

        app(Certificates::class)->autoIssue($site);

        $this->assertSame(0, Certificate::count());
        Queue::assertNotPushed(IssueCertificate::class);
    }
}
