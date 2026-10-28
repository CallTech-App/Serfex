<?php

namespace Tests\Feature;

use App\Enums\MailDomainStatus;
use App\Jobs\DeleteMailDomain;
use App\Jobs\PurgeMailbox;
use App\Jobs\SetupMailServer;
use App\Jobs\SyncMailDomain;
use App\Livewire\Mail\MailDomainShow;
use App\Livewire\Mail\MailIndex;
use App\Livewire\Mail\MailSettingsPage;
use App\Models\DnsZone;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailDns;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailPassword;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MailPagesTest extends TestCase
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

    private function domain(string $name = 'kahve.com.tr'): MailDomain
    {
        return MailDomain::factory()->create(['name' => $name]);
    }

    private function show(MailDomain $domain)
    {
        return Livewire::test(MailDomainShow::class, ['domain' => $domain->id]);
    }

    public function test_list_shows_domains_with_their_mailboxes(): void
    {
        $domain = $this->domain();
        Mailbox::factory()->for($domain, 'domain')->count(2)->create(['quota_mb' => 1024, 'usage_bytes' => 3 * 1048576]);

        $this->get('/mail')
            ->assertOk()
            ->assertSee('kahve.com.tr')
            ->assertSee('6 MB / 2 GB')
            ->assertSee('DNS başka yerde')
            ->assertSee('Posta açık')
            ->assertDontSee('Posta sunucusu kurulu değil');
    }

    public function test_list_offers_setup_when_the_server_is_missing(): void
    {
        Setting::put('mail.server', []);
        $this->agent->shouldReceive('call')->with('mail.status')->andReturn(['installed' => false, 'configured' => false, 'running' => false]);

        Livewire::test(MailIndex::class)
            ->assertSet('server.state', 'missing')
            ->assertSee('Posta sunucusunu kur')
            ->call('setupServer')
            ->assertSet('server.state', 'installing');

        Queue::assertPushed(SetupMailServer::class);
    }

    public function test_setup_needs_a_server_name_first(): void
    {
        Setting::put('mail.hostname', null);

        Livewire::test(MailIndex::class)
            ->assertSet('server.state', 'nohostname')
            ->call('setupServer')
            ->assertRedirect(route('mail.settings'));

        Queue::assertNotPushed(SetupMailServer::class);
    }

    public function test_turns_mail_on_for_a_domain(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);

        Livewire::test(MailIndex::class)
            ->call('openEnable')
            ->set('domain', 'Kahve.com.tr')
            ->call('enable')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $domain = MailDomain::where('name', 'kahve.com.tr')->first();
        $this->assertSame($site->id, $domain->site_id);
        $this->assertSame(MailDomainStatus::Syncing, $domain->status);
        Queue::assertPushed(SyncMailDomain::class);

        Livewire::test(MailIndex::class)->set('domain', 'kahve.com.tr')->call('enable')->assertHasErrors('domain');
        Livewire::test(MailIndex::class)->set('domain', 'mail.example.net')->call('enable')->assertHasErrors('domain');
        Livewire::test(MailIndex::class)->set('domain', 'olmaz')->call('enable')->assertHasErrors('domain');
    }

    public function test_site_page_link_opens_the_form_prefilled(): void
    {
        $this->get('/mail?ac=kahve.com.tr')->assertOk();
        Livewire::withQueryParams(['enable' => 'kahve.com.tr'])->test(MailIndex::class)
            ->assertSet('enabling', true)
            ->assertSet('domain', 'kahve.com.tr');
    }

    public function test_detail_page_lists_mailboxes(): void
    {
        $domain = $this->domain();
        Mailbox::factory()->for($domain, 'domain')->create(['name' => 'siparis', 'forward_to' => ['patron@gmail.com'],
            'autoreply' => ['enabled' => true, 'subject' => 'x', 'body' => 'y', 'until' => '']]);

        $this->get('/mail/'.$domain->id)
            ->assertOk()
            ->assertSee('siparis')
            ->assertSee('patron@gmail.com')
            ->assertSee('Açık')
            ->assertSee(route('mail.webmail', Mailbox::first()));
    }

    public function test_creates_a_mailbox(): void
    {
        $domain = $this->domain();

        $component = $this->show($domain)->call('openCreate');
        $this->assertNull(MailPassword::weakness($component->get('mbPassword')));
        $component->assertSet('mbQuota', 1024)
            ->set('mbName', 'Siparis')
            ->set('mbPassword', 'Kahve-fincan-7')
            ->set('mbQuota', 5120)
            ->call('saveMailbox')
            ->assertHasNoErrors()
            ->assertSet('editingMailbox', false)
            ->assertDispatched('toast');

        $mailbox = $domain->mailboxes()->first();
        $this->assertSame('siparis', $mailbox->name);
        $this->assertSame(5120, $mailbox->quota_mb);
        $this->assertTrue(MailPassword::verify('Kahve-fincan-7', $mailbox->password_hash));
        $this->assertStringNotContainsString('Kahve-fincan-7', json_encode(Mailbox::first()->getAttributes()));
        Queue::assertPushed(SyncMailDomain::class);
    }

    public function test_rejects_bad_mailboxes(): void
    {
        $domain = $this->domain();
        Mailbox::factory()->for($domain, 'domain')->create(['name' => 'ali']);
        Mailbox::factory()->for($domain, 'domain')->create(['name' => 'eski'])->delete();
        $domain->forwarders()->create(['name' => 'satis', 'destinations' => ['ali@kahve.com.tr']]);

        foreach ([
            ['ali', 'Kahve-fincan-7', 'zaten bir posta kutusu'],
            ['eski', 'Kahve-fincan-7', 'az önce silindi'],
            ['satis', 'Kahve-fincan-7', 'yönlendirici'],
            ['-ali', 'Kahve-fincan-7', 'küçük harf'],
            ['ayse', 'kisa1', 'en az 8'],
            ['ayse', 'Ayse-2026', 'adresini içermemeli'],
        ] as [$name, $password, $message]) {
            $c = $this->show($domain)->call('openCreate')->set('mbName', $name)->set('mbPassword', $password)->call('saveMailbox');
            $errors = $c->errors()->all();
            $this->assertNotEmpty($errors, "{$name} / {$password}");
            $this->assertStringContainsString($message, implode(' ', $errors));
        }
        $this->assertSame(1, $domain->mailboxes()->count());
    }

    public function test_edits_a_mailbox_and_undoes_it(): void
    {
        $domain = $this->domain();
        $mailbox = Mailbox::factory()->for($domain, 'domain')->create(['name' => 'ali']);
        $hash = $mailbox->password_hash;

        $component = $this->show($domain)
            ->call('openEdit', $mailbox->id)
            ->assertSet('mbName', 'ali')
            ->set('mbPassword', '')
            ->set('mbQuota', 0)
            ->set('mbForward', "ali@gmail.com, ALI@hotmail.com\n")
            ->set('mbKeepCopy', false)
            ->set('mbAutoreply', true)
            ->set('mbSubject', 'İzindeyim')
            ->set('mbBody', 'Pazartesi dönüyorum.')
            ->set('mbUntil', now()->addDays(3)->toDateString())
            ->call('saveMailbox')
            ->assertHasNoErrors();

        $mailbox->refresh();
        $this->assertSame($hash, $mailbox->password_hash, 'an empty password keeps the old one');
        $this->assertSame(0, $mailbox->quota_mb);
        $this->assertSame(['ali@gmail.com', 'ali@hotmail.com'], $mailbox->forward_to);
        $this->assertFalse($mailbox->keep_copy);
        $this->assertTrue($mailbox->autoreplyActive());

        $token = collect($component->effects['dispatches'])->firstWhere('name', 'toast')['params']['undo']['args'][0];
        $component->call('undoEdit', $token);
        $mailbox->refresh();
        $this->assertSame(1024, $mailbox->quota_mb);
        $this->assertSame([], $mailbox->forward_to);
        $this->assertFalse($mailbox->autoreplyActive());

        $this->show($domain)->call('openEdit', $mailbox->id)->set('mbForward', 'ali@kahve.com.tr')->call('saveMailbox')->assertHasErrors('mbForward');
        $this->show($domain)->call('openEdit', $mailbox->id)->set('mbAutoreply', true)->set('mbBody', '')->call('saveMailbox')->assertHasErrors('mbBody');
        $this->show($domain)->call('openEdit', $mailbox->id)->set('mbPassword', 'Yeni-parola-9')->call('saveMailbox')->assertHasNoErrors();
        $this->assertTrue(MailPassword::verify('Yeni-parola-9', $mailbox->fresh()->password_hash));
    }

    public function test_deletes_mailboxes_with_undo(): void
    {
        $domain = $this->domain();
        $ali = Mailbox::factory()->for($domain, 'domain')->create(['name' => 'ali']);
        $veli = Mailbox::factory()->for($domain, 'domain')->create(['name' => 'veli']);

        $component = $this->show($domain)
            ->set('selected', [$ali->id, $veli->id])
            ->call('deleteSelected')
            ->assertSet('selected', []);

        $this->assertSame(0, $domain->mailboxes()->count());
        Queue::assertPushed(PurgeMailbox::class, 2);
        $ids = collect($component->effects['dispatches'])->firstWhere('name', 'toast')['params']['undo']['args'][0];

        $component->call('undoDelete', $ids);
        $this->assertSame(2, $domain->mailboxes()->count());
    }

    public function test_forwarders_and_catch_all(): void
    {
        $domain = $this->domain();
        Mailbox::factory()->for($domain, 'domain')->create(['name' => 'ali']);

        $this->show($domain)->call('openForwarder')->set('fwName', 'satis')->set('fwTo', "ali@kahve.com.tr\nmuhasebe@gmail.com")->call('saveForwarder')->assertHasNoErrors();
        $this->show($domain)->call('openForwarder')->set('fwCatchAll', true)->set('fwTo', 'ali@kahve.com.tr')->call('saveForwarder')->assertHasNoErrors();
        $this->show($domain)->call('openForwarder')->set('fwName', 'ali')->set('fwTo', 'x@y.com')->call('saveForwarder')->assertHasErrors('fwName');
        $this->show($domain)->call('openForwarder')->set('fwName', 'bos')->set('fwTo', '')->call('saveForwarder')->assertHasErrors('fwTo');
        $this->show($domain)->call('openForwarder')->set('fwName', 'kotu')->set('fwTo', 'adres-degil')->call('saveForwarder')->assertHasErrors('fwTo');

        $spec = $domain->fresh()->agentSpec();
        $this->assertSame([['name' => 'satis', 'to' => ['ali@kahve.com.tr', 'muhasebe@gmail.com']]], $spec['aliases']);
        $this->assertSame(['ali@kahve.com.tr'], $spec['catch_all']);

        $satis = $domain->forwarders()->where('name', 'satis')->first();
        $component = $this->show($domain)->call('deleteForwarder', $satis->id);
        $this->assertFalse($domain->forwarders()->where('name', 'satis')->exists());
        $args = collect($component->effects['dispatches'])->firstWhere('name', 'toast')['params']['undo']['args'];
        $component->call('undoForwarder', ...$args);
        $this->assertSame(['ali@kahve.com.tr', 'muhasebe@gmail.com'], $domain->forwarders()->where('name', 'satis')->first()->destinations);

        $this->get('/mail/'.$domain->id.'?tab=forwarders')->assertOk()->assertSee('bilinmeyen tüm adresler')->assertSee('muhasebe@gmail.com');
    }

    public function test_settings_tab_shows_dns_records_and_writes_them(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = $this->domain();

        $this->get('/mail/'.$domain->id.'?tab=settings')
            ->assertOk()
            ->assertSee('v=spf1 a mx ip4:203.0.113.10 ~all')
            ->assertSee('Eksik')
            ->assertSee('Önerilen kayıtları yaz')
            ->assertSee('mail.example.net · 993 · SSL/TLS');

        $this->show($domain)->set('tab', 'settings')->call('fixDns')->assertDispatched('toast');
        $this->assertTrue($zone->records()->where('type', 'MX')->exists());
        $this->get('/mail/'.$domain->id.'?tab=settings')->assertSee('Yazıldı')->assertDontSee('Önerilen kayıtları yaz');

        $this->show($domain)->set('tab', 'settings')->set('spamFilter', false)->call('saveSettings');
        $this->assertFalse($domain->fresh()->spam_filter);
    }

    public function test_turns_mail_off_with_undo(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = $this->domain();
        app(MailDns::class)->apply($domain);

        $component = $this->show($domain)->set('tab', 'settings')->call('disable');

        $this->assertSame(MailDomainStatus::Deleting, $domain->fresh()->status);
        $this->assertFalse($zone->records()->where('type', 'MX')->exists());
        Queue::assertPushed(DeleteMailDomain::class, fn ($job) => ! $job->purge);
        $this->get('/mail/'.$domain->id)->assertSee('e-posta kapatıldı');

        $component->call('undoDisable');
        $this->assertSame(MailDomainStatus::Syncing, $domain->fresh()->status);
        Queue::assertPushed(SyncMailDomain::class);
    }

    public function test_webmail_opens_with_a_one_time_link(): void
    {
        $mailbox = Mailbox::factory()->for($this->domain(), 'domain')->create(['name' => 'ali']);
        $this->agent->shouldReceive('call')->once()->with('mail.webmail.login', ['address' => 'ali@kahve.com.tr'], 120.0)
            ->andReturn(['url' => 'https://mail.example.net/?_task=login&_action=serfex-sso&_token=abc', 'expires' => now()->addMinute()->toIso8601String()]);

        $this->get(route('mail.webmail', $mailbox))->assertRedirect('https://mail.example.net/?_task=login&_action=serfex-sso&_token=abc');
    }

    public function test_webmail_failure_returns_to_the_domain(): void
    {
        $mailbox = Mailbox::factory()->for($this->domain(), 'domain')->create(['name' => 'ali']);
        $this->agent->shouldReceive('call')->with('mail.webmail.login', Mockery::any(), Mockery::any())
            ->andThrow(AgentException::rpcError('mail.webmail.login', -32002, 'the mail server is not set up yet'));

        $this->get(route('mail.webmail', $mailbox))
            ->assertRedirect(route('mail.show', $mailbox->mail_domain_id))
            ->assertSessionHas('toast');

        Setting::put('mail.webmail', false);
        $this->get(route('mail.webmail', $mailbox))->assertSessionHas('toast', 'Web posta kapalı. E-posta ayarlarından açabilirsiniz.');
    }

    public function test_settings_page_saves_and_reconfigures_the_server(): void
    {
        $this->get('/mail/settings')->assertOk()->assertSee('mail.example.net')->assertSee('kendinden imzalı');

        Livewire::test(MailSettingsPage::class)
            ->set('hostname', 'posta.example.net')
            ->set('hourlyLimit', 100)
            ->set('defaultQuota', 5120)
            ->set('auto', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $settings = app(MailSettings::class);
        $this->assertSame('posta.example.net', $settings->hostname());
        $this->assertSame(100, $settings->hourlyLimit());
        $this->assertSame(5120, $settings->defaultQuotaMb());
        $this->assertFalse($settings->autoEnable());
        Queue::assertPushed(SetupMailServer::class);
    }

    public function test_settings_page_chooses_the_webmail(): void
    {
        $this->get('/mail/settings')->assertOk()->assertSee('Roundcube')->assertSee('SnappyMail');
        Livewire::test(MailSettingsPage::class)->assertSet('webmail', 'roundcube')
            ->set('webmail', 'snappymail')
            ->call('save')
            ->assertHasNoErrors();
        $settings = app(MailSettings::class);
        $this->assertTrue($settings->webmail());
        $this->assertSame('snappymail', $settings->webmailApp());
        Queue::assertPushed(SetupMailServer::class, 1);

        // Turned off, the choice is kept for turning it on again.
        $settings->saveServer(['state' => 'ready', 'webmail_app' => 'snappymail']);
        Livewire::test(MailSettingsPage::class)->assertSet('webmail', 'snappymail')
            ->set('webmail', 'off')
            ->call('save');
        $this->assertFalse($settings->webmail());
        $this->assertSame('snappymail', $settings->webmailApp());
        Queue::assertPushed(SetupMailServer::class, 2);
        Livewire::test(MailSettingsPage::class)->assertSet('webmail', 'off');

        // Saving the same choice again does not set the server up again.
        Livewire::test(MailSettingsPage::class)->call('save');
        Queue::assertPushed(SetupMailServer::class, 2);

        Livewire::test(MailSettingsPage::class)->set('webmail', 'horde')->call('save')->assertHasErrors('webmail');
    }

    public function test_settings_page_rejects_names_in_use(): void
    {
        Setting::put('panel.hostname', 'panel.example.net');
        Site::factory()->create(['domain' => 'kahve.com.tr']);

        Livewire::test(MailSettingsPage::class)->set('hostname', 'panel.example.net')->call('save')->assertHasErrors('hostname');
        Livewire::test(MailSettingsPage::class)->set('hostname', 'kahve.com.tr')->call('save')->assertHasErrors('hostname');
        Livewire::test(MailSettingsPage::class)->set('hostname', 'serfex')->call('save')->assertHasErrors('hostname');
        Livewire::test(MailSettingsPage::class)->set('hourlyLimit', -1)->call('save')->assertHasErrors('hourlyLimit');
        Queue::assertNotPushed(SetupMailServer::class);
    }

    public function test_site_page_links_to_its_mail(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        $this->get('/sites/'.$site->id)->assertOk()->assertSee('E-posta kapalı')->assertSee(route('mail.index', ['enable' => 'kahve.com.tr']), false);

        $domain = $this->domain();
        Mailbox::factory()->for($domain, 'domain')->create();
        $this->get('/sites/'.$site->id)->assertOk()->assertSee('1 kutu')->assertSee(route('mail.show', $domain))->assertSee('https://mail.example.net/');
    }

    public function test_new_sites_get_mail_once_the_server_is_set_up(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        app(MailDomains::class)->forSite($site);
        $this->assertTrue(MailDomain::where('name', 'kahve.com.tr')->where('site_id', $site->id)->exists());

        Setting::put('mail.auto', false);
        $other = Site::factory()->create(['domain' => 'cay.com.tr']);
        app(MailDomains::class)->forSite($other);
        $this->assertFalse(MailDomain::where('name', 'cay.com.tr')->exists());
    }
}
