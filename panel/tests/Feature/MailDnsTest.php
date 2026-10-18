<?php

namespace Tests\Feature;

use App\Jobs\SyncDnsZone;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Services\Mail\MailDns;
use App\Services\Mail\PublicDns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailDnsTest extends TestCase
{
    use RefreshDatabase;

    private const DKIM = 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::put('dns.ipv4', '203.0.113.10');
        Setting::put('mail.hostname', 'mail.servik.net.tr');
    }

    private function dns(): MailDns
    {
        return app(MailDns::class);
    }

    /** @return list<string> */
    private function records(DnsZone $zone): array
    {
        return $zone->records()->get()
            ->map(fn ($r) => trim("{$r->name} {$r->type} ".($r->priority !== null ? $r->priority.' ' : '')."{$r->content}"))
            ->all();
    }

    public function test_writes_mail_records_into_the_domains_zone(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr', 'dkim_record' => self::DKIM]);

        $this->assertNotNull($this->dns()->apply($domain));

        $records = $this->records($zone);
        foreach ([
            '@ MX 10 mail.kahve.com.tr.',
            'mail A 203.0.113.10',
            '@ TXT v=spf1 a mx ip4:203.0.113.10 ~all',
            'servik._domainkey TXT '.self::DKIM,
            '_dmarc TXT v=DMARC1; p=none',
            'autoconfig CNAME mail.servik.net.tr.',
            'autodiscover CNAME mail.servik.net.tr.',
            '_autodiscover._tcp SRV 0 1 443 mail.servik.net.tr.',
            '_submissions._tcp SRV 0 1 465 mail.servik.net.tr.',
            '_imaps._tcp SRV 0 1 993 mail.servik.net.tr.',
        ] as $want) {
            $this->assertContains($want, $records);
        }
        Queue::assertPushed(SyncDnsZone::class);

        // Applying again changes nothing.
        $this->assertNull($this->dns()->apply($domain));
        $this->assertSame('Kayıtlar tamam', $this->dns()->summary($domain)->label());
    }

    public function test_leaves_records_the_user_changed_unless_asked_to_fix(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $zone->records()->create(['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 include:_spf.google.com ~all', 'ttl' => 3600]);
        $zone->records()->create(['name' => '@', 'type' => 'TXT', 'content' => 'google-site-verification=abc', 'ttl' => 3600]);
        $zone->records()->create(['name' => 'servik._domainkey', 'type' => 'TXT', 'content' => 'v=DKIM1; p=old', 'ttl' => 3600]);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr', 'dkim_record' => self::DKIM]);

        $this->dns()->apply($domain);
        $records = $this->records($zone);
        $this->assertContains('@ TXT v=spf1 include:_spf.google.com ~all', $records);
        $this->assertNotContains('@ TXT v=spf1 a mx ip4:203.0.113.10 ~all', $records);
        // Servik's own DKIM key is always kept current.
        $this->assertContains('servik._domainkey TXT '.self::DKIM, $records);
        $this->assertNotContains('servik._domainkey TXT v=DKIM1; p=old', $records);

        $spf = collect($this->dns()->check($domain, public: false))->firstWhere('key', 'spf');
        $this->assertSame('different', $spf['zone']);
        $this->assertSame(['v=spf1 include:_spf.google.com ~all'], $spf['current']);
        $this->assertSame('1 kayıt farklı', $this->dns()->summary($domain)->label());

        $this->dns()->apply($domain, fix: true);
        $records = $this->records($zone);
        $this->assertContains('@ TXT v=spf1 a mx ip4:203.0.113.10 ~all', $records);
        $this->assertNotContains('@ TXT v=spf1 include:_spf.google.com ~all', $records);
        $this->assertContains('@ TXT google-site-verification=abc', $records, 'other TXT records stay');
    }

    public function test_subdomain_records_go_into_the_parent_zone(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = MailDomain::factory()->create(['name' => 'magaza.kahve.com.tr']);

        $this->dns()->apply($domain);

        $records = $this->records($zone);
        $this->assertContains('magaza MX 10 mail.magaza.kahve.com.tr.', $records);
        $this->assertContains('mail.magaza A 203.0.113.10', $records);
        $this->assertContains('_dmarc.magaza TXT v=DMARC1; p=none', $records);
    }

    public function test_remove_takes_back_only_servik_records(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr', 'dkim_record' => self::DKIM]);
        $this->dns()->apply($domain);
        $zone->records()->where('type', 'TXT')->where('name', '_dmarc')->update(['content' => 'v=DMARC1; p=reject']);

        $this->dns()->remove($domain);

        $this->assertSame([
            '@ NS ns1.servik.net.tr.',
            '@ NS ns2.servik.net.tr.',
            '@ A 203.0.113.10',
            'www CNAME kahve.com.tr.',
            '_dmarc TXT v=DMARC1; p=reject',
        ], $this->records($zone));
    }

    public function test_domain_without_a_zone_here_lists_records_to_add_elsewhere(): void
    {
        $domain = MailDomain::factory()->create(['name' => 'disarida.com.tr']);
        $this->assertNull($this->dns()->apply($domain));
        $this->assertSame('DNS başka yerde', $this->dns()->summary($domain)->label());

        $this->app->instance(PublicDns::class, new class extends PublicDns
        {
            public function lookup(string $name, string $type): ?array
            {
                return match ("{$name} {$type}") {
                    'disarida.com.tr MX' => ['10 mail.disarida.com.tr.'],
                    'disarida.com.tr TXT' => ['v=spf1 a mx ip4:203.0.113.10 ~all', 'other'],
                    default => [],
                };
            }
        });
        $rows = collect(app(MailDns::class)->check($domain))->keyBy('key');
        $this->assertSame('Yayıldı', $rows['mx']['badge']->label());
        $this->assertSame('Yayıldı', $rows['spf']['badge']->label());
        $this->assertSame('Eksik', $rows['dmarc']['badge']->label());
        $this->assertSame('nozone', $rows['dmarc']['zone']);
    }

    public function test_records_written_but_not_yet_seen_publicly(): void
    {
        DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
        $domain = MailDomain::factory()->create(['name' => 'kahve.com.tr']);
        $this->dns()->apply($domain);
        $this->app->instance(PublicDns::class, new class extends PublicDns
        {
            public function lookup(string $name, string $type): ?array
            {
                return $type === 'MX' ? ['10 mail.kahve.com.tr.'] : [];
            }
        });

        $rows = collect(app(MailDns::class)->check($domain))->keyBy('key');
        $this->assertSame('Yayıldı', $rows['mx']['badge']->label());
        $this->assertSame('Yazıldı', $rows['spf']['badge']->label());
        $this->assertFalse($rows['spf']['public']);
    }
}
