<?php

namespace Tests\Feature;

use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Livewire\Settings\ApiKeyIndex;
use App\Models\ApiKey;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\DnsZone;
use App\Models\Package;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\Zones;
use App\Services\Settings\ApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * API anahtarları and the panel API (/api/v1): a key is shown once, acts
 * as the full administrator who made it within its scope, and every
 * change it makes is in İşlem günlüğü.
 */
class PanelApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent(['php.versions' => ['8.4', '8.3']]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->admin = User::factory()->create(['name' => 'Sercan', 'role' => 'full']);
    }

    /**
     * @param  list<string>  $ips
     */
    private function key(string $scope = 'write', array $ips = [], ?User $by = null): string
    {
        return app(ApiKeys::class)->create($by ?? $this->admin, 'Faturalama', $scope, $ips, null)[1];
    }

    public function test_a_key_is_made_and_shown_once_and_revoked_with_undo(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(ApiKeyIndex::class)
            ->call('openCreate')
            ->set('name', 'Faturalama')
            ->set('scope', 'write')
            ->set('ips', '203.0.113.0/24, 300.1.1.1')
            ->call('create')
            ->assertHasErrors('ips')
            ->set('ips', '203.0.113.0/24 2001:db8::1')
            ->set('expires', '90')
            ->call('create')
            ->assertHasNoErrors();

        $plain = $page->get('created')['key'];
        $this->assertStringStartsWith(ApiKeys::PREFIX, $plain);
        $key = ApiKey::sole();
        $this->assertSame(hash('sha256', $plain), $key->token_hash);
        $this->assertSame(['203.0.113.0/24', '2001:db8::1'], $key->allowed_ips);
        $this->assertTrue($key->expires_at->between(now()->addDays(89), now()->addDays(91)));
        $page->assertSee($plain);
        $page->set('created', null);
        $this->get(route('settings.api'))->assertOk()->assertSee($key->prefix)->assertDontSee($plain);
        $this->assertStringNotContainsString($plain, AuditEntry::sole()->message);

        $page->call('confirmRevoke', $key->id)->call('revoke')
            ->assertDispatched('toast', message: 'Faturalama API anahtarı iptal edildi.', undo: ['method' => 'restore', 'args' => [$key->id]]);
        $this->assertFalse($key->fresh()->isUsable());
        $page->call('restore', $key->id);
        $this->assertTrue($key->fresh()->isUsable());
    }

    public function test_requests_need_a_usable_key_from_an_allowed_address(): void
    {
        $this->getJson('/api/v1/sites')->assertUnauthorized();
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer svk_panel_yanlis'])->assertUnauthorized();

        $limited = $this->key('read', ['198.51.100.7']);
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer '.$limited])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/api/v1/sites', ['Authorization' => 'Bearer '.$limited])->assertOk();

        $plain = $this->key('read');
        ApiKey::where('token_hash', hash('sha256', $plain))->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer '.$plain])->assertUnauthorized()->assertJson(['message' => 'This API key has expired.']);
    }

    public function test_a_read_key_only_reads_and_a_demoted_creator_stops_the_key(): void
    {
        $read = $this->key('read');
        $this->postJson('/api/v1/sites', ['domain' => 'kahve.com.tr'], ['Authorization' => 'Bearer '.$read])->assertForbidden();
        $this->getJson('/api/v1/server', ['Authorization' => 'Bearer '.$read])->assertOk()->assertJsonPath('data.panel_port', 8443);

        $this->admin->update(['role' => 'support']);
        $this->getJson('/api/v1/server', ['Authorization' => 'Bearer '.$read])->assertForbidden();
    }

    public function test_sites_are_listed_created_and_deleted(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->key()];

        $this->postJson('/api/v1/sites', ['domain' => 'not a domain'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/sites', ['domain' => 'kahve.com.tr', 'php_version' => '7.4'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/sites', ['domain' => 'Kahve.com.tr'], $headers)->assertStatus(202)
            ->assertJsonPath('data.domain', 'kahve.com.tr')
            ->assertJsonPath('data.php_version', '8.4')
            ->assertJsonPath('data.aliases', ['www.kahve.com.tr'])
            ->assertJsonPath('data.status', 'provisioning');
        Queue::assertPushed(ProvisionSite::class);
        $this->assertNotNull(DnsZone::where('name', 'kahve.com.tr')->first());

        $this->getJson('/api/v1/sites', $headers)->assertOk()->assertJsonPath('data.0.domain', 'kahve.com.tr');
        $this->getJson('/api/v1/sites/kahve.com.tr', $headers)->assertOk()->assertJsonPath('data.account', 'kahve');
        $this->getJson('/api/v1/sites/yok.com.tr', $headers)->assertNotFound();

        $this->deleteJson('/api/v1/sites/kahve.com.tr', [], $headers)->assertStatus(202)->assertJsonPath('data.status', 'deleting');
        Queue::assertPushed(DeleteSite::class);

        $entries = AuditEntry::orderBy('id')->get();
        $this->assertSame(['kahve.com.tr oluşturuluyor.', 'kahve.com.tr siliniyor.'], $entries->pluck('message')->all());
        $this->assertSame('API: Faturalama (Sercan)', $entries[0]->actor);
        $this->assertSame('api', $entries[0]->via);
    }

    public function test_a_customers_site_stays_within_their_package(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->key()];
        $customer = Customer::factory()->create(['username' => 'ada']);
        $customer->package->update(['max_sites' => 1, 'php_versions' => ['8.3']]);

        $this->postJson('/api/v1/sites', ['domain' => 'adapansiyon.com', 'customer_id' => $customer->id], $headers)->assertStatus(202)
            ->assertJsonPath('data.account', 'ada')
            ->assertJsonPath('data.php_version', '8.3')
            ->assertJsonPath('data.customer.id', $customer->id);
        $this->postJson('/api/v1/sites', ['domain' => 'ada2.com', 'customer_id' => $customer->id], $headers)->assertUnprocessable();
    }

    public function test_customers_are_created_suspended_and_brought_back(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->key()];
        $created = $this->postJson('/api/v1/customers', ['name' => 'Ada Yılmaz', 'email' => 'Ada@Pansiyon.com', 'username' => 'ada'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.email', 'ada@pansiyon.com')
            ->assertJsonPath('data.package.name', Package::default()->name);
        $id = $created->json('data.id');
        $this->assertStringContainsString('/davet/', $created->json('data.invitation_url'));
        $this->getJson('/api/v1/customers', $headers)->assertOk()->assertJsonPath('data.0.username', 'ada');
        $this->postJson('/api/v1/customers', ['name' => 'Ada', 'email' => 'ada@pansiyon.com', 'username' => 'ada'], $headers)->assertUnprocessable();

        $this->postJson("/api/v1/customers/{$id}/suspend", ['reason' => 'Ödeme gecikti'], $headers)->assertOk()->assertJsonPath('data.suspended', true);
        $this->assertNotNull(Customer::find($id)->suspended_at);
        $this->postJson("/api/v1/customers/{$id}/unsuspend", [], $headers)->assertOk()->assertJsonPath('data.suspended', false);
        $this->getJson("/api/v1/customers/{$id}", $headers)->assertOk()->assertJsonPath('data.usage.sites.used', 0);
        $this->getJson('/api/v1/packages', $headers)->assertOk()->assertJsonFragment(['name' => 'Profesyonel']);

        $this->assertSame(['Ada Yılmaz müşterisi eklendi.', 'Ada Yılmaz askıya alındı.', 'Ada Yılmaz yeniden etkin.'], AuditEntry::orderBy('id')->pluck('message')->all());
    }

    public function test_dns_records_are_read_added_and_deleted(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->key()];
        $zone = app(Zones::class)->create('kahve.com.tr');

        $this->getJson('/api/v1/dns/zones', $headers)->assertOk()->assertJsonPath('data.0.name', 'kahve.com.tr');
        $this->postJson('/api/v1/dns/zones/kahve.com.tr/records', ['type' => 'A', 'name' => 'api', 'content' => '999.1.1.1'], $headers)->assertUnprocessable();
        $id = $this->postJson('/api/v1/dns/zones/kahve.com.tr/records', ['type' => 'A', 'name' => 'api', 'content' => '203.0.113.20', 'ttl' => 300], $headers)
            ->assertCreated()->assertJsonPath('data.name', 'api')->json('data.id');
        $this->postJson('/api/v1/dns/zones/kahve.com.tr/records', ['type' => 'A', 'name' => 'api', 'content' => '203.0.113.20'], $headers)->assertStatus(409);
        $this->postJson('/api/v1/dns/zones/kahve.com.tr/records', ['type' => 'CNAME', 'name' => '@', 'content' => 'ornek.com'], $headers)->assertUnprocessable();

        $this->getJson('/api/v1/dns/zones/kahve.com.tr', $headers)->assertOk()->assertJsonFragment(['content' => '203.0.113.20']);
        $this->deleteJson("/api/v1/dns/zones/kahve.com.tr/records/{$id}", [], $headers)->assertNoContent();
        $this->assertNull($zone->records()->find($id));

        $ns = $zone->records()->where('name', '@')->where('type', 'NS')->get();
        foreach ($ns->slice(1) as $r) {
            $r->delete();
        }
        $this->deleteJson('/api/v1/dns/zones/kahve.com.tr/records/'.$ns->first()->id, [], $headers)->assertStatus(409);
    }

    public function test_keys_are_limited_to_sixty_requests_a_minute(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->key('read')];
        for ($i = 0; $i < ApiKeys::PER_MINUTE; $i++) {
            $this->getJson('/api/v1/packages', $headers)->assertOk();
        }
        $this->getJson('/api/v1/packages', $headers)->assertStatus(429)->assertHeader('Retry-After');
    }
}
