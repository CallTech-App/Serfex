<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Jobs\DeleteCertificate;
use App\Jobs\IssueCertificate;
use App\Jobs\ProvisionSite;
use App\Livewire\Ssl\SiteSsl;
use App\Livewire\Ssl\SslIndex;
use App\Livewire\Ssl\SslSettingsPage;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SslPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['email' => 'admin@kahve.com.tr']));
        Queue::fake();
    }

    public function test_list_shows_every_site_with_its_certificate_state(): void
    {
        $valid = Site::factory()->create(['domain' => 'gecerli.com.tr']);
        Certificate::factory()->inUseBy($valid)->create();
        $expiring = Site::factory()->create(['domain' => 'bitiyor.com.tr']);
        Certificate::factory()->inUseBy($expiring)->expiring(5)->create();
        Site::factory()->create(['domain' => 'sertifikasiz.com.tr']);

        $this->get(route('ssl.index'))
            ->assertOk()
            ->assertSee('gecerli.com.tr')
            ->assertSee('Geçerli')
            ->assertSee('5 gün kaldı')
            ->assertSee('Sertifika yok')
            ->assertSee('1 sertifika ilgilenmenizi bekliyor');

        Livewire::test(SslIndex::class)
            ->set('filter', 'problem')
            ->assertSee('bitiyor.com.tr')
            ->assertSee('sertifikasiz.com.tr')
            ->assertDontSee('gecerli.com.tr');
    }

    public function test_menu_and_dashboard_show_certificate_problems(): void
    {
        $site = Site::factory()->create();
        Certificate::factory()->inUseBy($site)->create(['renew_error' => 'timeout']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 sertifika ilgilenmenizi bekliyor')
            ->assertSee('İlgilenmeniz gereken 1 sertifika', false);
    }

    public function test_list_requests_certificates_for_selected_sites(): void
    {
        $a = Site::factory()->create();
        $b = Site::factory()->create();
        $uploaded = Site::factory()->create();
        Certificate::factory()->inUseBy($uploaded)->create(['source' => Certificate::CUSTOM]);

        Livewire::test(SslIndex::class)
            ->set('selected', [$a->id, $b->id, $uploaded->id])
            ->call('issueSelected')
            ->assertDispatched('toast', message: "2 site için Let's Encrypt sertifikası alınıyor.");

        Queue::assertPushed(IssueCertificate::class, 2);
        $this->assertSame(CertificateStatus::Issuing, $a->certificates()->sole()->status);
    }

    public function test_site_page_requests_a_certificate(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr']]);

        $this->get(route('sites.ssl', $site))->assertOk()->assertSee('Bu sitenin sertifikası yok');

        Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->call('openRequest')
            ->set('includeAliases', false)
            ->call('requestCertificate')
            ->assertSet('requesting', false)
            ->assertSee("Let's Encrypt sertifikası alınıyor");

        $this->assertSame(['kahve.com.tr'], $site->certificates()->sole()->domains);
        Queue::assertPushed(IssueCertificate::class);
    }

    public function test_wildcard_is_offered_only_with_dns_on_this_server(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);

        Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->set('wildcard', true)
            ->call('requestCertificate');
        $this->assertSame('http-01', $site->certificates()->sole()->challenge);

        $site->certificates()->delete();
        DnsZone::factory()->create(['name' => 'kahve.com.tr', 'site_id' => $site->id]);
        Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->set('wildcard', true)
            ->call('requestCertificate');
        $this->assertSame(['kahve.com.tr', '*.kahve.com.tr'], $site->certificates()->sole()->domains);
    }

    public function test_site_page_shows_the_installed_certificate(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        Certificate::factory()->inUseBy($site)->create(['issuer' => "Let's Encrypt R11"]);

        $this->get(route('sites.ssl', $site))
            ->assertOk()
            ->assertSee('Geçerli')
            ->assertSee("Let's Encrypt")
            ->assertSee('Şimdi yenile');

        $this->get(route('sites.show', $site))
            ->assertOk()
            ->assertSee('https://kahve.com.tr', false);
    }

    public function test_upload_installs_the_certificate_with_undo(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        $old = Certificate::factory()->inUseBy($site)->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()
            ->with('ssl.install', Mockery::on(fn ($p) => $p['name'] === 'kahve.com.tr-2' && $p['domain'] === 'kahve.com.tr' && $p['certificate'] === 'CERT'), Mockery::any())
            ->andReturn(['name' => 'kahve.com.tr-2', 'names' => ['kahve.com.tr'], 'issuer' => 'Sectigo', 'not_before' => now()->toIso8601String(), 'not_after' => now()->addYear()->toIso8601String()]);
        $this->app->instance(AgentClient::class, $agent);

        $page = Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->call('openUpload')
            ->set('certificatePem', 'CERT')
            ->set('privateKeyPem', 'KEY')
            ->call('upload')
            ->assertHasNoErrors()
            ->assertDispatched('toast', undo: ['method' => 'undoSwap', 'args' => [$old->id]]);

        $uploaded = $site->fresh()->certificate;
        $this->assertSame(Certificate::CUSTOM, $uploaded->source);
        $this->assertSame('Sectigo', $uploaded->issuerLabel());
        $this->assertFalse($uploaded->auto_renew);
        Queue::assertPushed(DeleteCertificate::class, fn ($job) => $job->certificate->is($old));

        $page->call('undoSwap', $old->id);
        $this->assertSame($old->id, $site->fresh()->certificate_id);
    }

    public function test_upload_errors_are_readable(): void
    {
        $site = Site::factory()->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('ssl.install', -32602, 'the private key does not belong to the certificate'));
        $this->app->instance(AgentClient::class, $agent);

        Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->set('certificatePem', 'CERT')
            ->set('privateKeyPem', 'KEY')
            ->call('upload')
            ->assertHasErrors('certificatePem')
            ->assertSee('Özel anahtar bu sertifikaya ait değil.');

        $this->assertSame(0, Certificate::count());
    }

    public function test_redirect_toggle_has_undo(): void
    {
        $site = Site::factory()->create();
        Certificate::factory()->inUseBy($site)->create();

        Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->call('setRedirect', false)
            ->assertDispatched('toast', undo: ['method' => 'setRedirect', 'args' => [true]]);

        $site->refresh();
        $this->assertFalse($site->https_redirect);
        $this->assertFalse($site->agentSpec()['tls']['redirect']);
        Queue::assertPushed(ProvisionSite::class);
    }

    public function test_remove_goes_back_to_http_with_undo(): void
    {
        $site = Site::factory()->create();
        $certificate = Certificate::factory()->inUseBy($site)->create();

        $page = Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->call('remove')
            ->assertDispatched('toast', undo: ['method' => 'undoSwap', 'args' => [$certificate->id]]);
        $this->assertNull($site->fresh()->certificate_id);
        $this->assertArrayNotHasKey('tls', $site->fresh()->agentSpec());

        $page->call('undoSwap', $certificate->id);
        $this->assertSame($certificate->id, $site->fresh()->certificate_id);
    }

    public function test_auto_renew_toggle_has_undo(): void
    {
        $site = Site::factory()->create();
        $certificate = Certificate::factory()->inUseBy($site)->create();

        Livewire::test(SiteSsl::class, ['site' => $site->id])
            ->call('setAutoRenew', false)
            ->assertDispatched('toast', undo: ['method' => 'setAutoRenew', 'args' => [true]]);

        $this->assertFalse($certificate->fresh()->auto_renew);
    }

    public function test_settings_save_with_undo_and_point_to_the_panel_settings(): void
    {
        $this->get(route('ssl.settings'))->assertOk()->assertSee('Araçlar ve Ayarlar → Genel');

        $page = Livewire::test(SslSettingsPage::class)
            ->set('email', 'ssl@kahve.com.tr')
            ->set('auto', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'SSL ayarları kaydedildi.');

        $this->assertSame('ssl@kahve.com.tr', Setting::get('ssl.email'));
        $this->assertFalse(Setting::get('ssl.auto'));

        $page->call('undoSave', ['ssl.email' => null, 'ssl.auto' => true])->assertSet('email', '');
        $this->assertNull(Setting::get('ssl.email'));
        $this->assertTrue(Setting::get('ssl.auto'));
    }
}
