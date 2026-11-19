<?php

namespace Tests\Unit;

use App\Services\Security\AddressList;
use Tests\TestCase;

class AddressListTest extends TestCase
{
    /**
     * @param  list<array{text: string, address: ?string, error: ?string}>  $entries
     * @return list<?string>
     */
    private static function addresses(array $entries): array
    {
        return array_column($entries, 'address');
    }

    public function test_lines_commas_semicolons_tabs_and_notes_are_understood(): void
    {
        $text = "45.9.150.221\r\n45.9.148.7/24\t194.26.135.91, 194.26.135.93;2A0B:F4C0:16C:4::1,\r\n"
            ."# kötü bot listesi, 19 Kas\n88.230.14.52 # ofis\n\n ,, ;\n\n45.9.150.221\n";
        $got = AddressList::parse($text);
        $this->assertSame(
            ['45.9.150.221', '45.9.148.0/24', '194.26.135.91', '194.26.135.93', '2a0b:f4c0:16c:4::1', '88.230.14.52'],
            self::addresses($got),
        );
        $this->assertSame([null, null, null, null, null, null], array_column($got, 'error'));
        $this->assertSame('45.9.148.7/24', $got[1]['text']);
    }

    public function test_odd_spellings_become_what_the_agent_stores(): void
    {
        foreach ([
            '::ffff:192.0.2.60' => '192.0.2.60',
            '::FFFF:192.0.2.77/120' => '192.0.2.0/24',
            '2001:DB8:0:0::1' => '2001:db8::1',
            '2001:db8:0:0:0:0:0:5/128' => '2001:db8::5',
            '198.51.100.77/24' => '198.51.100.0/24',
            '192.0.2.5/32' => '192.0.2.5',
        ] as $typed => $stored) {
            $this->assertSame([$stored], self::addresses(AddressList::parse($typed)), $typed);
        }
        // The same address written three ways is one address.
        $this->assertCount(1, AddressList::parse("192.0.2.5\n192.0.2.5/32 ::ffff:192.0.2.5"));
    }

    public function test_what_is_not_an_address_is_kept_with_its_reason(): void
    {
        $got = AddressList::parse("bozuk-satir\n62.210.0.0/12, 127.0.0.1\n192.0.2.1/33\n2001:db8::/32\n::ffff:192.0.2.0/64\nbozuk-satir\nexample.com");
        $this->assertSame(['bozuk-satir', '62.210.0.0/12', '127.0.0.1', '192.0.2.1/33', '2001:db8::/32', '::ffff:192.0.2.0/64', 'example.com'], array_column($got, 'text'));
        foreach ($got as $entry) {
            $this->assertNull($entry['address'], $entry['text']);
            $this->assertNotSame('', (string) $entry['error'], $entry['text']);
        }
        $this->assertStringContainsString('bir IP adresi değil', $got[0]['error']);
        $this->assertStringContainsString('aralık çok geniş', $got[1]['error']);
        $this->assertStringContainsString('yerel adres', $got[2]['error']);
    }

    public function test_an_empty_or_note_only_text_holds_nothing(): void
    {
        $this->assertSame([], AddressList::parse(''));
        $this->assertSame([], AddressList::parse("  \r\n# yalnızca not\n\t\n"));
    }

    public function test_a_token_too_long_to_be_an_address_is_shortened_in_the_report(): void
    {
        $got = AddressList::parse(str_repeat('a', 5000));
        $this->assertCount(1, $got);
        $this->assertLessThanOrEqual(64, mb_strlen($got[0]['text']));
        $this->assertNotNull($got[0]['error']);
    }
}
