<?php

namespace Tests\Unit;

use App\Support\DnsRecords;
use Tests\TestCase;

class DnsRecordsTest extends TestCase
{
    public function test_normalizes_host_names(): void
    {
        $zone = 'kahve.com.tr';
        $this->assertSame('@', DnsRecords::normalizeName('', $zone));
        $this->assertSame('@', DnsRecords::normalizeName('@', $zone));
        $this->assertSame('@', DnsRecords::normalizeName('Kahve.com.tr.', $zone));
        $this->assertSame('www', DnsRecords::normalizeName(' WWW ', $zone));
        $this->assertSame('shop', DnsRecords::normalizeName('shop.kahve.com.tr', $zone));
        $this->assertSame('_dmarc', DnsRecords::normalizeName('_dmarc', $zone));
        $this->assertSame('*', DnsRecords::normalizeName('*', $zone));
        $this->assertNull(DnsRecords::normalizeName('a b', $zone));
        $this->assertNull(DnsRecords::normalizeName('a.*', $zone));
        $this->assertNull(DnsRecords::normalizeName('-x', $zone));
    }

    public function test_normalizes_values(): void
    {
        $zone = 'kahve.com.tr';
        $ok = [
            ['A', '185.98.142.21', '185.98.142.21'],
            ['AAAA', '2a0e:1c80:0004:002a:0:0:0:21', '2a0e:1c80:4:2a::21'],
            ['CNAME', 'Kahve.com.tr', 'kahve.com.tr.'],
            ['CNAME', 'shop', 'shop.kahve.com.tr.'],
            ['MX', 'mail.kahve.com.tr.', 'mail.kahve.com.tr.'],
            ['NS', 'ns1.servik.net.tr', 'ns1.servik.net.tr.'],
            ['SRV', '5 5060 sip', '5 5060 sip.kahve.com.tr.'],
            ['TXT', 'v=spf1 a mx ~all', 'v=spf1 a mx ~all'],
            ['CAA', '0 issue letsencrypt.org', '0 issue "letsencrypt.org"'],
            ['CAA', '128 ISSUEWILD "sectigo.com"', '128 issuewild "sectigo.com"'],
        ];
        foreach ($ok as [$type, $in, $want]) {
            $this->assertSame([$want, null], DnsRecords::normalizeContent($type, $in, $zone), "$type $in");
        }

        $bad = [['A', '1.2.3'], ['A', '::1'], ['AAAA', '1.2.3.4'], ['AAAA', '::ffff:1.2.3.4'], ['CNAME', 'a b'],
            ['SRV', 'sip.kahve.com.tr'], ['CAA', '0 policy x'], ['TXT', str_repeat('x', 4001)], ['A', '']];
        foreach ($bad as [$type, $in]) {
            [$content, $error] = DnsRecords::normalizeContent($type, $in, $zone);
            $this->assertNull($content, "$type $in");
            $this->assertNotNull($error);
        }
    }

    public function test_ttl_labels(): void
    {
        $this->assertSame('1 saat', DnsRecords::ttlLabel(3600));
        $this->assertSame('2 gün', DnsRecords::ttlLabel(172800));
        $this->assertSame('10 dakika', DnsRecords::ttlLabel(600));
        $this->assertSame('90 sn', DnsRecords::ttlLabel(90));
    }
}
