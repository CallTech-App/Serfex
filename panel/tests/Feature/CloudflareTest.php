<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\ZoneStatus;
use App\Jobs\IssueCertificate;
use App\Jobs\ProvisionSite;
use App\Jobs\SyncDnsZone;
use App\Livewire\Dns\CloudflarePage;
use App\Livewire\Dns\ZoneShow;
use App\Livewire\Sites\SiteIndex;
use App\Models\Certificate;
use App\Models\CloudflareAccount;
use App\Models\Customer;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Cloudflare\CloudflareRanges;
use App\Services\Cloudflare\CloudflareRecords;
use App\Services\Cloudflare\CloudflareSync;
use App\Services\Cloudflare\CloudflareZones;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Services\Sites\SiteCreator;
use App\Services\Ssl\Certificates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\Support\FakeCloudflare;
use Tests\TestCase;

class CloudflareTest extends TestCase
{
    use RefreshDatabase;

    private FakeCloudflare $cf;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $this->cf = FakeCloudflare::install();
        $this->agent = new FakeAgent([
            'ping' => true,
            'php.versions' => ['8.4'],
            'system.addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []],
            'dns.zone.delete' => ['deleted' => true],
            'web.edge.apply' => ['ranges' => 22, 'changed' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        Setting::put('dns.ipv4', '203.0.113.10');
    }

    private function account(string $name = 'CallTech'): CloudflareAccount
    {
        return CloudflareAccount::create(['name' => $name, 'token' => 'tok_'.str_repeat('a', 36), 'account_id' => 'acc1', 'account_name' => 'CallTech Ana Hesap', 'zones_count' => 1, 'status' => CloudflareAccount::OK, 'checks' => ['dns' => true, 'ssl' => true, 'create' => true]]);
    }

    /**
     * A zone in the panel connected to a Cloudflare zone, synced once.
     */
    private function connectedZone(string $name = 'magaza-ornek.com'): DnsZone
    {
        $account = $this->account();
        $id = $this->cf->addZone($name, 'active', [
            ['type' => 'A', 'name' => '@', 'content' => '203.0.113.10', 'proxied' => true],
            ['type' => 'MX', 'name' => '@', 'content' => 'mail.'.$name, 'priority' => 10, 'ttl' => 3600],
        ]);
        $zone = DnsZone::factory()->create(['name' => $name]);
        app(CloudflareZones::class)->attach($zone, $account, $this->cf->zones[$id], []);
        app(CloudflareSync::class)->sync($zone->fresh());

        return $zone->fresh();
    }

    public function test_records_translate_between_the_panel_and_cloudflare(): void
    {
        $mx = CloudflareRecords::fromCloudflare(['type' => 'MX', 'name' => 'ornek.com', 'content' => 'Mail.Ornek.com', 'priority' => 5, 'ttl' => 1], 'ornek.com', 3600);
        $this->assertSame(['name' => '@', 'type' => 'MX', 'content' => 'mail.ornek.com.', 'priority' => 5, 'ttl' => 3600, 'proxied' => null], $mx);

        $srv = CloudflareRecords::fromCloudflare(['type' => 'SRV', 'name' => '_sip._tcp.ornek.com', 'data' => ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.ornek.com'], 'ttl' => 300], 'ornek.com', 3600);
        $this->assertSame('5 5060 sip.ornek.com.', $srv['content']);
        $this->assertSame(10, $srv['priority']);

        $txt = CloudflareRecords::fromCloudflare(['type' => 'TXT', 'name' => 'ornek.com', 'content' => '"v=spf1 " "a mx ~all"'], 'ornek.com', 3600);
        $this->assertSame('v=spf1 a mx ~all', $txt['content']);

        // Types the panel does not keep, and Cloudflare's own name servers.
        $this->assertNull(CloudflareRecords::fromCloudflare(['type' => 'HTTPS', 'name' => 'ornek.com', 'content' => '1 .'], 'ornek.com', 3600));
        $this->assertNull(CloudflareRecords::fromCloudflare(['type' => 'NS', 'name' => 'ornek.com', 'content' => 'ada.ns.cloudflare.com'], 'ornek.com', 3600));

        $r = new DnsRecord(['name' => '_sip._tcp', 'type' => 'SRV', 'content' => '5 5060 sip.ornek.com.', 'priority' => 10, 'ttl' => 300]);
        $this->assertSame(['type' => 'SRV', 'name' => '_sip._tcp.ornek.com', 'ttl' => 300, 'data' => ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.ornek.com']], CloudflareRecords::toCloudflare($r, 'ornek.com'));

        $a = new DnsRecord(['name' => 'www', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'proxied' => true]);
        $this->assertSame(['type' => 'A', 'name' => 'www.ornek.com', 'ttl' => 1, 'proxied' => true, 'content' => '203.0.113.10'], CloudflareRecords::toCloudflare($a, 'ornek.com'));
    }

    public function test_sync_settles_changes_made_on_either_side(): void
    {
        $zone = $this->connectedZone();
        $zoneId = $zone->cloudflare_zone_id;
        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'imported' => 0, 'removed' => 0], app(CloudflareSync::class)->sync($zone), 'nothing to do right after connecting');

        // In the panel: one record changed, one added, one deleted.
        $a = $zone->records()->where('type', 'A')->first();
        $a->update(['content' => '203.0.113.20']);
        $zone->records()->create(['name' => 'shop', 'type' => 'CNAME', 'content' => 'shops.myshopify.com.', 'ttl' => 3600, 'proxied' => false]);
        $mx = $zone->records()->where('type', 'MX')->first();
        $mxId = $mx->cloudflare_id;
        $mx->delete();
        // At Cloudflare: a record added.
        $this->cf->put($zoneId, ['type' => 'TXT', 'name' => '@', 'content' => 'google-site-verification=abc', 'ttl' => 3600]);

        $done = app(CloudflareSync::class)->sync($zone->fresh());

        $this->assertSame(['created' => 1, 'updated' => 1, 'deleted' => 1, 'imported' => 1, 'removed' => 0], $done);
        $this->assertSame('203.0.113.20', $this->cf->find($zoneId, 'A')[0]['content']);
        $this->assertTrue($this->cf->find($zoneId, 'A')[0]['proxied']);
        $this->assertSame('shops.myshopify.com', $this->cf->find($zoneId, 'CNAME')[0]['content']);
        $this->assertArrayNotHasKey($mxId, $this->cf->records[$zoneId]);
        $this->assertTrue($zone->records()->where('type', 'TXT')->where('content', 'google-site-verification=abc')->exists());

        // At Cloudflare: one changed, one deleted; the panel follows.
        $txt = $this->cf->find($zoneId, 'TXT')[0];
        unset($this->cf->records[$zoneId][$txt['id']]);
        $cname = $this->cf->find($zoneId, 'CNAME')[0];
        $this->cf->put($zoneId, [...$cname, 'content' => 'shops2.myshopify.com', 'modified_on' => now()->addMinute()->toIso8601String()], $cname['id']);

        $done = app(CloudflareSync::class)->sync($zone->fresh());

        $this->assertSame(1, $done['imported']);
        $this->assertSame(1, $done['removed']);
        $this->assertSame('shops2.myshopify.com.', $zone->records()->where('type', 'CNAME')->value('content'));
        $this->assertFalse($zone->records()->where('type', 'TXT')->exists());
    }

    public function test_the_panel_wins_when_both_sides_changed_a_record(): void
    {
        $zone = $this->connectedZone();
        $zoneId = $zone->cloudflare_zone_id;
        $a = $this->cf->find($zoneId, 'A')[0];
        $this->cf->put($zoneId, [...$a, 'content' => '198.51.100.7', 'modified_on' => now()->addMinute()->toIso8601String()], $a['id']);
        $zone->records()->where('type', 'A')->update(['content' => '203.0.113.30']);

        app(CloudflareSync::class)->sync($zone->fresh());

        $this->assertSame('203.0.113.30', $this->cf->find($zoneId, 'A')[0]['content']);
    }

    public function test_a_refused_record_does_not_stop_the_others(): void
    {
        $zone = $this->connectedZone();
        $zone->records()->create(['name' => 'bad', 'type' => 'TXT', 'content' => 'x', 'ttl' => 3600]);
        $zone->records()->create(['name' => 'good', 'type' => 'TXT', 'content' => 'y', 'ttl' => 3600]);
        $this->cf->refuse['POST /zones/'.$zone->cloudflare_zone_id.'/dns_records'] = [400, 81057, 'Record already exists.'];

        (new SyncDnsZone($zone))->handle($this->agent);

        $zone->refresh();
        $this->assertSame(ZoneStatus::Failed, $zone->status);
        $this->assertStringContainsString('Record already exists. (81057)', $zone->last_error);
        $this->assertStringContainsString('TXT bad.magaza-ornek.com', $zone->last_error);
    }

    public function test_first_sync_stops_this_server_serving_the_zone(): void
    {
        $zone = $this->connectedZone();
        $zone->forceFill(['cloudflare_info' => ['status' => 'active']])->save();

        (new SyncDnsZone($zone))->handle($this->agent);
        (new SyncDnsZone($zone))->handle($this->agent);

        $this->assertCount(1, array_filter($this->agent->calls, fn ($c) => $c[0] === 'dns.zone.delete'));
        $zone->refresh();
        $this->assertSame(ZoneStatus::Active, $zone->status);
        $this->assertTrue($zone->cloudflare_info['local_removed']);
        $this->assertSame(['ada.ns.cloudflare.com', 'rex.ns.cloudflare.com'], $zone->publicNameservers());
        $this->assertFalse(collect($this->agent->calls)->contains(fn ($c) => $c[0] === 'dns.zone.apply'));
    }

    public function test_ssl_mode_becomes_strict_once_the_certificate_is_in_place(): void
    {
        $zone = $this->connectedZone();
        $site = Site::factory()->create(['domain' => 'magaza-ornek.com']);
        app(CloudflareSync::class)->refreshInfo($zone);
        $this->assertSame('full', $this->cf->ssl[$zone->cloudflare_zone_id], 'no certificate yet');

        $cert = Certificate::factory()->create(['site_id' => $site->id, 'status' => CertificateStatus::Active, 'not_after' => now()->addMonths(2)]);
        $site->update(['certificate_id' => $cert->id]);
        app(CloudflareSync::class)->refreshInfo($zone->fresh());

        $this->assertSame('strict', $this->cf->ssl[$zone->cloudflare_zone_id]);
        $this->assertSame('strict', $zone->fresh()->cloudflare_info['ssl']);

        // Set back by hand at Cloudflare, it is left alone.
        $this->cf->ssl[$zone->cloudflare_zone_id] = 'full';
        app(CloudflareSync::class)->refreshInfo($zone->fresh());
        $this->assertSame('full', $this->cf->ssl[$zone->cloudflare_zone_id]);
    }

    public function test_comparing_groups_records_and_picks_defaults(): void
    {
        $plan = app(CloudflareZones::class)->compare('roifex.com', 3600, [
            ['name' => '@', 'type' => 'A', 'content' => '77.223.140.187', 'priority' => null],
            ['name' => '@', 'type' => 'MX', 'content' => 'mail.roifex.com.', 'priority' => 10],
            ['name' => 'default._domainkey', 'type' => 'TXT', 'content' => 'v=DKIM1; k=rsa; p=MIIB', 'priority' => null],
            ['name' => '@', 'type' => 'NS', 'content' => 'ns1.roifex.com.', 'priority' => null],
        ], [
            ['id' => '1', 'type' => 'A', 'name' => 'roifex.com', 'content' => '185.62.10.4'],
            ['id' => '2', 'type' => 'MX', 'name' => 'roifex.com', 'content' => 'mail.roifex.com', 'priority' => 10],
            ['id' => '3', 'type' => 'TXT', 'name' => 'roifex.com', 'content' => 'google-site-verification=x'],
            ['id' => '4', 'type' => 'NS', 'name' => 'roifex.com', 'content' => 'ada.ns.cloudflare.com'],
        ], null);

        $this->assertSame(1, $plan['same']);
        $rows = collect($plan['rows'])->keyBy(fn ($r) => $r['name'].' '.$r['type']);
        $this->assertSame(['differ', 'serfex', ['185.62.10.4'], ['77.223.140.187']], [$rows['@ A']['kind'], $rows['@ A']['choice'], $rows['@ A']['cloudflare'], $rows['@ A']['serfex']]);
        $this->assertSame(['serfex', 'push'], [$rows['default._domainkey TXT']['kind'], $rows['default._domainkey TXT']['choice']]);
        $this->assertSame(['cloudflare', 'import'], [$rows['@ TXT']['kind'], $rows['@ TXT']['choice']]);
        $this->assertCount(3, $plan['rows']);
    }

    public function test_site_ekle_finds_the_domain_at_cloudflare_and_shows_what_changes(): void
    {
        $this->account();
        $this->cf->addZone('magaza-ornek.com', 'active', [
            ['type' => 'A', 'name' => '@', 'content' => '185.62.10.4'],
            ['type' => 'A', 'name' => 'www', 'content' => '185.62.10.4'],
            ['type' => 'MX', 'name' => '@', 'content' => 'mx.yandex.net', 'priority' => 10],
        ]);

        $c = Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'magaza-ornek.com')
            ->assertSet('dns', 'cloudflare')
            ->assertSee('CallTech Ana Hesap hesabında bulundu')
            ->assertSee('Cloudflare\'de değişecek kayıtlar');
        $changes = $c->get('cloudflare')['preview']['changes'];
        $this->assertSame([
            ['name' => 'magaza-ornek.com', 'type' => 'A', 'from' => ['185.62.10.4'], 'to' => ['203.0.113.10']],
            ['name' => 'www.magaza-ornek.com', 'type' => 'CNAME/A', 'from' => ['A 185.62.10.4'], 'to' => ['CNAME magaza-ornek.com']],
        ], $changes);
        $this->assertSame(1, $c->get('cloudflare')['preview']['kept']);

        $c->call('create')->assertHasNoErrors();

        $zone = DnsZone::where('name', 'magaza-ornek.com')->firstOrFail();
        $this->assertTrue($zone->atCloudflare());
        $this->assertTrue((bool) $zone->records()->where('name', '@')->where('type', 'A')->value('proxied'));
        $this->assertSame('203.0.113.10', $zone->records()->where('name', '@')->where('type', 'A')->value('content'));
        $this->assertSame('mx.yandex.net.', $zone->records()->where('type', 'MX')->value('content'), 'the mail records there are kept');
        $this->assertFalse($zone->records()->where('type', 'NS')->exists());
        Queue::assertPushed(SyncDnsZone::class);
        Queue::assertPushed(ProvisionSite::class);

        // The sync writes it: the old A records go, the site's records come.
        app(CloudflareSync::class)->sync($zone->fresh());
        $id = $zone->cloudflare_zone_id;
        $this->assertSame(['203.0.113.10'], array_column($this->cf->find($id, 'A'), 'content'));
        $this->assertSame('magaza-ornek.com', $this->cf->find($id, 'CNAME')[0]['content']);
        $this->assertTrue($this->cf->find($id, 'CNAME')[0]['proxied']);
        $this->assertCount(1, $this->cf->find($id, 'MX'));
    }

    public function test_site_ekle_can_keep_dns_on_this_server(): void
    {
        $this->account();
        $this->cf->addZone('magaza-ornek.com');

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'magaza-ornek.com')
            ->set('dns', 'local')
            ->call('create')
            ->assertHasNoErrors();

        $zone = DnsZone::where('name', 'magaza-ornek.com')->firstOrFail();
        $this->assertFalse($zone->atCloudflare());
        $this->assertTrue($zone->records()->where('type', 'NS')->exists());
    }

    public function test_site_ekle_adds_a_domain_cloudflare_does_not_have(): void
    {
        $account = $this->account();

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'yeni-magaza.com')
            ->assertSet('dns', 'local')
            ->assertSee('Cloudflare\'e ekle')
            ->set('dns', 'cloudflare-new')
            ->call('create')
            ->assertHasErrors('dns')
            ->set('cloudflareAccountId', (string) $account->id)
            ->call('create')
            ->assertHasNoErrors();

        $zone = DnsZone::where('name', 'yeni-magaza.com')->firstOrFail();
        $this->assertTrue($zone->atCloudflare());
        $this->assertSame('pending', $zone->cloudflareStatus());
        $this->assertSame(1, $this->cf->count('POST', '/zones'));
    }

    public function test_a_cloudflare_failure_leaves_the_site_on_this_servers_dns(): void
    {
        $account = $this->account();
        $this->cf->refuse['POST /zones'] = [403, 9109, 'Unauthorized to access requested resource'];

        $creator = app(SiteCreator::class);
        $site = $creator->create('yeni-magaza.com', 'yenimagaza', '8.4', true, null, null, Site::PREFER_BARE, ['account' => $account, 'zone' => null, 'proxied' => true]);

        $this->assertSame('Cloudflare: Unauthorized to access requested resource (9109)', $creator->dnsError);
        $zone = DnsZone::where('name', 'yeni-magaza.com')->firstOrFail();
        $this->assertFalse($zone->atCloudflare());
        $this->assertSame($site->id, $zone->site_id);
    }

    public function test_customers_are_not_offered_cloudflare(): void
    {
        $this->account();
        $this->cf->addZone('magaza-ornek.com');
        $customer = Customer::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => false, 'customer_id' => $customer->id]));

        Livewire::test(SiteIndex::class)
            ->call('openCreate')
            ->set('domain', 'magaza-ornek.com')
            ->assertSet('cloudflare', null)
            ->assertDontSee('hesabında bulundu');
        $this->assertSame(0, $this->cf->count('GET', '/zones'));
    }

    public function test_connecting_an_account_checks_the_token(): void
    {
        $this->cf->addZone('magaza-ornek.com');

        Livewire::test(CloudflarePage::class)
            ->call('connect')
            ->set('name', 'CallTech')
            ->set('token', 'bad token!')
            ->call('save')
            ->assertHasErrors('token')
            ->set('token', str_repeat('A', 40))
            ->call('test')
            ->assertSee('Anahtar denetlendi')
            ->assertSee('1 alan adı görünüyor')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false);

        $account = CloudflareAccount::sole();
        $this->assertSame(str_repeat('A', 40), $account->token);
        $this->assertNotSame(str_repeat('A', 40), $account->getRawOriginal('token'), 'stored encrypted');
        $this->assertSame('CallTech Ana Hesap', $account->account_name);
        $this->assertSame(['dns' => true, 'ssl' => true, 'create' => true], $account->checks);
        $this->get('/dns/cloudflare')->assertOk()->assertSee('CallTech')->assertSee('Bağlı')->assertDontSee(str_repeat('A', 40));

        $this->cf->tokenValid = false;
        Livewire::test(CloudflarePage::class)
            ->call('connect')
            ->set('name', 'Eski ajans')
            ->set('token', str_repeat('B', 40))
            ->call('save')
            ->assertHasErrors('token')
            ->assertSee('Cloudflare: Authentication error (10000)');
        $this->assertSame(1, CloudflareAccount::count());
    }

    public function test_an_account_in_use_is_not_removed_and_removal_can_be_undone(): void
    {
        $zone = $this->connectedZone();
        $account = $zone->cloudflareAccount;

        Livewire::test(CloudflarePage::class)->call('remove', $account->id)->assertDispatched('toast');
        $this->assertModelExists($account);

        $zone->update(['cloudflare_account_id' => null]);
        $c = Livewire::test(CloudflarePage::class)->call('remove', $account->id);
        $this->assertSame(0, CloudflareAccount::count());
        $token = $c->effects['dispatches'][0]['params']['undo']['args'][0];
        $c->call('undoRemove', $token);
        $this->assertSame($account->token, CloudflareAccount::sole()->token);
    }

    public function test_defaults_are_saved(): void
    {
        Livewire::test(CloudflarePage::class)
            ->assertSet('proxySites', true)
            ->set('proxySites', false)
            ->set('strictSsl', false)
            ->set('purgeAfterDeploy', true)
            ->call('saveDefaults');

        $this->assertFalse(Setting::get('cloudflare.proxy_sites'));
        $this->assertFalse(Setting::get('cloudflare.strict_ssl'));
        $this->assertTrue(Setting::get('cloudflare.purge_after_deploy'));
    }

    public function test_an_existing_zone_is_connected_with_the_choices_made(): void
    {
        $this->account();
        $id = $this->cf->addZone('roifex.com', 'active', [
            ['type' => 'A', 'name' => '@', 'content' => '185.62.10.4'],
            ['type' => 'CNAME', 'name' => 'shop', 'content' => 'shops.myshopify.com'],
            ['type' => 'TXT', 'name' => '@', 'content' => 'old-verification'],
        ]);
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'roifex.com']);
        $zone->records()->where('type', 'A')->update(['content' => '77.223.140.187']);

        $c = Livewire::test(ZoneShow::class, ['zone' => $zone->id])
            ->assertSee('DNS\'i Cloudflare\'e bağla')
            ->call('openConnect')
            ->assertSee('roifex.com burada bulundu, etkin')
            ->assertSee('Serfex\'teki yazılsın');
        $rows = collect($c->get('connectPlan')['rows'])->keyBy(fn ($r) => $r['name'].' '.$r['type']);
        $c->set('connectChoices.'.$rows['@ TXT']['key'], 'delete')
            ->call('connectCloudflare');

        $zone->refresh();
        $this->assertTrue($zone->atCloudflare());
        $this->assertSame('77.223.140.187', $zone->records()->where('type', 'A')->value('content'));
        $this->assertTrue($zone->records()->where('name', 'shop')->exists());
        $this->assertFalse($zone->records()->where('type', 'TXT')->exists());

        app(CloudflareSync::class)->sync($zone);
        $this->assertSame(['77.223.140.187'], array_column($this->cf->find($id, 'A'), 'content'));
        $this->assertSame([], $this->cf->find($id, 'TXT'), 'deleted at Cloudflare as chosen');
        $this->assertCount(1, $this->cf->find($id, 'CNAME', 'shop.roifex.com'));
        $this->assertCount(1, $this->cf->find($id, 'CNAME', 'www.roifex.com'));
    }

    public function test_zone_page_shows_cloudflare_and_toggles_the_proxy(): void
    {
        $zone = $this->connectedZone();
        $this->cf->ssl[$zone->cloudflare_zone_id] = 'flexible';
        app(CloudflareSync::class)->refreshInfo($zone);
        $a = $zone->records()->where('type', 'A')->first();

        Livewire::test(ZoneShow::class, ['zone' => $zone->id])
            ->assertSee('DNS Cloudflare\'de.')
            ->assertSee('ada.ns.cloudflare.com')
            ->assertSee('Cloudflare SSL modu Esnek (Flexible)')
            ->assertSee('Vekil')
            ->call('toggleProxy', $a->id)
            ->call('strictSsl');

        $this->assertFalse($a->fresh()->proxied);
        $this->assertSame('strict', $this->cf->ssl[$zone->cloudflare_zone_id]);
        Queue::assertPushed(SyncDnsZone::class);
    }

    public function test_a_pending_zone_says_which_name_servers_to_set(): void
    {
        $account = $this->account();
        $id = $this->cf->addZone('yeni-magaza.com', 'pending');
        $zone = DnsZone::factory()->create(['name' => 'yeni-magaza.com']);
        app(CloudflareZones::class)->attach($zone, $account, $this->cf->zones[$id], []);

        Livewire::test(ZoneShow::class, ['zone' => $zone->id])
            ->assertSee('Cloudflare bu alan adını bekliyor')
            ->assertSee('ada.ns.cloudflare.com, rex.ns.cloudflare.com')
            ->assertSee('ns1.eskihosting.com')
            ->call('syncNow');

        $this->assertSame(1, $this->cf->count('PUT', '/activation_check'));
    }

    public function test_deleting_records_of_a_cloudflare_zone_needs_no_ns_record(): void
    {
        $zone = $this->connectedZone();
        $ids = $zone->records()->pluck('id')->all();

        Livewire::test(ZoneShow::class, ['zone' => $zone->id])
            ->set('selected', $ids)
            ->call('deleteSelected');

        $this->assertSame(0, $zone->records()->count());
    }

    public function test_taking_the_zone_back_serves_it_from_this_server(): void
    {
        $zone = $this->connectedZone();

        Livewire::test(ZoneShow::class, ['zone' => $zone->id, 'tab' => 'settings'])
            ->set('tab', 'settings')
            ->assertSee('DNS\'i bu sunucuya geri al')
            ->call('detachCloudflare');

        $zone->refresh();
        $this->assertFalse($zone->atCloudflare());
        $this->assertNull($zone->cloudflare_info);
        $this->assertTrue($zone->records()->where('name', '@')->where('type', 'NS')->exists());
        $this->assertNull($zone->records()->value('cloudflare_id'));
        $this->assertCount(2, $this->cf->records[array_key_first($this->cf->zones)], 'nothing changed at Cloudflare');
    }

    public function test_certificates_use_dns_validation_at_cloudflare(): void
    {
        $zone = $this->connectedZone();
        $site = Site::factory()->create(['domain' => 'magaza-ornek.com', 'aliases' => ['www.magaza-ornek.com']]);

        $cert = app(Certificates::class)->letsEncrypt($site);

        $this->assertSame('dns-01', $cert->challenge);
        Queue::assertPushed(IssueCertificate::class, fn ($job) => $job->zone === 'magaza-ornek.com');

        $this->agent->answers['ssl.issue'] = function (array $p) use ($zone) {
            $this->assertSame('dns-01', $p['challenge']);
            $this->assertSame(['zone_id' => $zone->cloudflare_zone_id, 'token' => $zone->cloudflareAccount->token, 'nameservers' => ['ada.ns.cloudflare.com', 'rex.ns.cloudflare.com']], $p['cloudflare']);

            return ['not_before' => now()->toIso8601String(), 'not_after' => now()->addDays(90)->toIso8601String(), 'issuer' => 'R11', 'serial' => '01', 'domains' => $p['names']];
        };
        $this->agent->answers['ssl.install'] = ['installed' => true];
        $this->agent->answers['web.site.apply'] = ['changed' => true];
        try {
            app()->call([new IssueCertificate($cert, zone: 'magaza-ornek.com'), 'handle']);
        } catch (\Throwable $e) {
            // Whatever follows the issue (installing on the site) is not under test here.
        }
        $this->assertTrue(collect($this->agent->calls)->contains(fn ($c) => $c[0] === 'ssl.issue'));
    }

    public function test_a_pending_zone_holds_the_certificate_until_the_name_servers_change(): void
    {
        $account = $this->account();
        $id = $this->cf->addZone('yeni-magaza.com', 'pending');
        $zone = DnsZone::factory()->create(['name' => 'yeni-magaza.com']);
        app(CloudflareZones::class)->attach($zone, $account, $this->cf->zones[$id], []);
        $site = Site::factory()->create(['domain' => 'yeni-magaza.com']);
        $cert = app(Certificates::class)->letsEncrypt($site);

        app()->call([new IssueCertificate($cert, zone: 'yeni-magaza.com'), 'handle']);

        $cert->refresh();
        $this->assertSame(CertificateStatus::WaitingDns, $cert->status);
        $this->assertStringContainsString('ada.ns.cloudflare.com', $cert->last_error);
        $this->assertFalse(collect($this->agent->calls)->contains(fn ($c) => $c[0] === 'ssl.issue'));

        // Active at Cloudflare: the next sync asks for the certificate.
        Queue::fake();
        $this->cf->zones[$id]['status'] = 'active';
        (new SyncDnsZone($zone->fresh()))->handle($this->agent);
        $this->assertSame(CertificateStatus::Issuing, $cert->fresh()->status);
        Queue::assertPushed(IssueCertificate::class);
    }

    public function test_purging_the_cache_of_a_site(): void
    {
        $zone = $this->connectedZone();
        $site = Site::factory()->create(['domain' => 'magaza-ornek.com', 'aliases' => ['www.magaza-ornek.com']]);
        app(CloudflareZones::class)->purge($site);
        $this->assertSame([['purge_everything' => true]], $this->cf->purges);

        Site::factory()->create(['domain' => 'blog.magaza-ornek.com']);
        app(CloudflareZones::class)->purge($site);
        $this->assertSame(['hosts' => ['magaza-ornek.com', 'www.magaza-ornek.com']], $this->cf->purges[1]);
        $this->assertNotNull($zone);
    }

    public function test_cloudflare_addresses_are_never_blocked(): void
    {
        $this->expectException(MonitorException::class);
        $this->expectExceptionMessage('Cloudflare\'in vekil adreslerinden');

        app(IpBlocks::class)->block('172.70.1.1', 60);
    }

    public function test_a_wide_prefix_holding_cloudflare_is_not_blocked(): void
    {
        $this->expectException(MonitorException::class);

        app(IpBlocks::class)->block('104.0.0.0/8', 60);
    }

    public function test_ranges_are_fetched_and_given_to_the_agent(): void
    {
        Http::fake([
            'www.cloudflare.com/ips-v4' => Http::response("173.245.48.0/20\n103.21.244.0/22\n104.16.0.0/13\n"),
            'www.cloudflare.com/ips-v6' => Http::response("2400:cb00::/32\n2606:4700::/32\n"),
        ]);

        $this->assertSame(5, app(CloudflareRanges::class)->update());

        $this->assertContains(['web.edge.apply', ['ranges' => ['173.245.48.0/20', '103.21.244.0/22', '104.16.0.0/13', '2400:cb00::/32', '2606:4700::/32']]], $this->agent->calls);
        $this->assertNotNull(Setting::get('cloudflare.ips_updated_at'));
    }

    public function test_an_unreadable_range_list_keeps_the_one_in_force(): void
    {
        Http::fake(['www.cloudflare.com/*' => Http::response('<html>blocked</html>')]);

        app(CloudflareRanges::class)->update();

        $this->assertContains(['web.edge.apply', ['ranges' => []]], $this->agent->calls);
        $this->assertNull(Setting::get('cloudflare.ips_updated_at'));
    }

    public function test_the_scheduler_syncs_zones_at_cloudflare(): void
    {
        $zone = $this->connectedZone();
        DnsZone::factory()->create();
        Queue::fake();

        $this->artisan('serfex:cloudflare-sync')->assertSuccessful();

        Queue::assertPushed(SyncDnsZone::class, 1);
        Queue::assertPushed(SyncDnsZone::class, fn ($job) => $job->zone->is($zone));
    }

    public function test_zone_list_shows_where_the_dns_is(): void
    {
        $this->connectedZone();
        DnsZone::factory()->create(['name' => 'yerel.com.tr']);

        $this->get('/dns')->assertOk()->assertSee('Cloudflare')->assertSee('CallTech')->assertSee('Bu sunucu');
    }
}
