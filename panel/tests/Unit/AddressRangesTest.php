<?php

namespace Tests\Unit;

use App\Services\Security\AddressRanges;
use PHPUnit\Framework\TestCase;

class AddressRangesTest extends TestCase
{
    public function test_an_address_or_prefix_overlaps_what_holds_it_and_what_it_holds(): void
    {
        $set = AddressRanges::of(['198.51.100.0/24', '203.0.114.77', '2001:db8:5::/48', '2001:db8:9::1']);

        $this->assertSame('198.51.100.0/24', $set->overlapping('198.51.100.9'));
        $this->assertSame('198.51.100.0/24', $set->overlapping('198.51.100.128/25'));
        $this->assertSame('198.51.100.0/24', $set->overlapping('198.51.0.0/16'));
        $this->assertSame('203.0.114.77', $set->overlapping('203.0.114.0/24'));
        $this->assertSame('2001:db8:5::/48', $set->overlapping('2001:db8:5:1::7'));
        $this->assertSame('2001:db8:9::1', $set->overlapping('2001:db8:9::/64'));
    }

    public function test_neighbours_the_other_family_and_the_entry_itself_do_not_overlap(): void
    {
        $set = AddressRanges::of(['198.51.100.0/24', '203.0.114.77', '2001:db8:5::/48']);

        $this->assertNull($set->overlapping('198.51.99.255'));
        $this->assertNull($set->overlapping('198.51.101.0'));
        $this->assertNull($set->overlapping('198.51.101.0/24'));
        $this->assertNull($set->overlapping('203.0.114.76'));
        $this->assertNull($set->overlapping('2001:db8:6::1'));
        $this->assertNull($set->overlapping('::c633:6409'), 'the same bytes in the other family');
        // The same address or prefix is that entry, not an overlap with it.
        $this->assertNull($set->overlapping('198.51.100.0/24'));
        $this->assertNull($set->overlapping('203.0.114.77'));
        $this->assertNull(AddressRanges::of([])->overlapping('192.0.2.1'));
    }

    public function test_entries_that_hold_one_another_are_all_seen(): void
    {
        // The entry just before 10.0.200.1 is the small one; the wide one still holds it.
        $set = AddressRanges::of(['10.0.5.0/24', '10.0.0.0/16', '10.0.5.7', '10.2.0.0/16']);

        $this->assertSame('10.0.0.0/16', $set->overlapping('10.0.200.1'));
        $this->assertContains($set->overlapping('10.0.0.0/8'), ['10.0.5.0/24', '10.0.0.0/16', '10.0.5.7', '10.2.0.0/16']);
        $this->assertSame('10.0.0.0/16', $set->overlapping('10.0.128.0/17'));
        $this->assertContains($set->overlapping('10.0.5.7'), ['10.0.5.0/24', '10.0.0.0/16']);
        $this->assertNull($set->overlapping('10.1.0.1'));
    }

    public function test_covering_is_an_entry_that_holds_all_of_it(): void
    {
        $set = AddressRanges::of(['88.230.14.0/24', '78.224.63.12', '2a00:1450:4000::/48', '88.230.14.9']);

        $this->assertContains($set->covering('88.230.14.9'), ['88.230.14.0/24', '88.230.14.9']);
        $this->assertSame('88.230.14.0/24', $set->covering('88.230.14.52'));
        $this->assertSame('88.230.14.0/24', $set->covering('88.230.14.128/25'));
        $this->assertSame('88.230.14.0/24', $set->covering('88.230.14.0/24'));
        $this->assertSame('78.224.63.12', $set->covering('78.224.63.12'));
        $this->assertSame('2a00:1450:4000::/48', $set->covering('2a00:1450:4000:5::1'));
        // A wider prefix is not covered by a narrower entry inside it.
        $this->assertNull($set->covering('88.230.0.0/16'));
        $this->assertNull($set->covering('78.224.63.0/24'));
        $this->assertNull($set->covering('88.230.15.1'));
    }

    public function test_entries_added_later_are_found_wherever_they_fall(): void
    {
        $set = AddressRanges::of(['10.5.0.0/16']);
        foreach (['10.9.0.0/16', '10.1.0.5', '10.7.7.0/24', '2001:db8::1', '10.0.0.1'] as $address) {
            $set->add($address);
        }

        $this->assertSame('10.9.0.0/16', $set->overlapping('10.9.3.3'));
        $this->assertSame('10.1.0.5', $set->overlapping('10.1.0.0/24'));
        $this->assertSame('10.7.7.0/24', $set->overlapping('10.7.7.200'));
        $this->assertSame('10.5.0.0/16', $set->overlapping('10.5.1.1'));
        $this->assertSame('10.0.0.1', $set->overlapping('10.0.0.0/30'));
        $this->assertSame('2001:db8::1', $set->overlapping('2001:db8::/64'));
        $this->assertNull($set->overlapping('10.8.0.1'));
        $this->assertSame(6, $set->count());
    }

    public function test_what_is_not_an_address_is_left_out(): void
    {
        $set = AddressRanges::of(['bozuk', '', '192.0.2.0/24']);

        $this->assertSame(1, $set->count());
        $this->assertNull($set->overlapping('bozuk'));
        $this->assertNull($set->covering('bozuk'));
    }
}
