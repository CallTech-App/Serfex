<?php

namespace App\Services\Security;

/**
 * A set of addresses and prefixes that answers two questions quickly:
 * which entry overlaps this address or prefix, and which entry holds all
 * of it. Every entry is kept as its first and last address, in order, so
 * a paste of a thousand lines is checked against thousands of blocks in
 * a few steps each, not one by one.
 *
 * Entries are written the way IpBlocks::normalize() writes them. IPv4
 * and IPv6 never meet.
 */
final class AddressRanges
{
    /** @var array<int, list<array{0: string, 1: string, 2: string}>> by bytes in an address (4 or 16): first, last, as written; ordered by first */
    private array $entries = [4 => [], 16 => []];

    /** @var array<int, list<string>> the greatest last address up to each entry, since entries may hold one another */
    private array $reach = [4 => [], 16 => []];

    /**
     * @param  iterable<string>  $addresses  what is not an address or prefix is left out
     */
    public static function of(iterable $addresses): self
    {
        $set = new self;
        foreach ($addresses as $address) {
            $bounds = self::bounds((string) $address);
            if ($bounds !== null) {
                $set->entries[strlen($bounds[0])][] = [$bounds[0], $bounds[1], (string) $address];
            }
        }
        foreach ($set->entries as $family => $entries) {
            usort($entries, fn (array $a, array $b) => strcmp($a[0], $b[0]));
            $set->entries[$family] = $entries;
            $set->reachFrom($family, 0);
        }

        return $set;
    }

    public function add(string $address): void
    {
        $bounds = self::bounds($address);
        if ($bounds === null) {
            return;
        }
        $family = strlen($bounds[0]);
        $at = $this->after($family, $bounds[0]) + 1;
        array_splice($this->entries[$family], $at, 0, [[$bounds[0], $bounds[1], $address]]);
        $this->reachFrom($family, $at);
    }

    public function count(): int
    {
        return count($this->entries[4]) + count($this->entries[16]);
    }

    /**
     * An entry that shares at least one address with $address, as it was
     * written; null when there is none. The entry that is $address
     * itself does not count.
     */
    public function overlapping(string $address): ?string
    {
        $bounds = self::bounds($address);
        if ($bounds === null) {
            return null;
        }
        [$first, $last] = $bounds;
        $family = strlen($first);
        // Entries that start at or before the last address of $address, the nearest first.
        for ($i = $this->after($family, $last); $i >= 0 && strcmp($this->reach[$family][$i], $first) >= 0; $i--) {
            $entry = $this->entries[$family][$i];
            if (strcmp($entry[1], $first) >= 0 && $entry[2] !== $address) {
                return $entry[2];
            }
        }

        return null;
    }

    /**
     * An entry that holds all of $address (the same entry counts), as it
     * was written; null when no entry does.
     */
    public function covering(string $address): ?string
    {
        $bounds = self::bounds($address);
        if ($bounds === null) {
            return null;
        }
        [$first, $last] = $bounds;
        $family = strlen($first);
        for ($i = $this->after($family, $first); $i >= 0 && strcmp($this->reach[$family][$i], $last) >= 0; $i--) {
            if (strcmp($this->entries[$family][$i][1], $last) >= 0) {
                return $this->entries[$family][$i][2];
            }
        }

        return null;
    }

    /**
     * The index of the last entry that starts at or before $point; -1
     * when every entry starts after it.
     */
    private function after(int $family, string $point): int
    {
        $entries = $this->entries[$family];
        $low = 0;
        $high = count($entries) - 1;
        $found = -1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if (strcmp($entries[$mid][0], $point) <= 0) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found;
    }

    private function reachFrom(int $family, int $from): void
    {
        $reach = $from > 0 ? $this->reach[$family][$from - 1] : '';
        $this->reach[$family] = array_slice($this->reach[$family], 0, $from);
        for ($i = $from, $n = count($this->entries[$family]); $i < $n; $i++) {
            $last = $this->entries[$family][$i][1];
            $reach = strcmp($last, $reach) > 0 ? $last : $reach;
            $this->reach[$family][] = $reach;
        }
    }

    /**
     * The first and last address of an address or prefix, as bytes.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function bounds(string $address): ?array
    {
        [$base, $bits] = array_pad(explode('/', $address, 2), 2, null);
        $packed = $base === '' ? false : @inet_pton($base);
        if ($packed === false) {
            return null;
        }
        $size = strlen($packed) * 8;
        $bits = $bits === null ? $size : (int) $bits;
        if ($bits < 0 || $bits > $size) {
            return null;
        }
        $first = '';
        $last = '';
        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = (0xFF << (8 - $keep)) & 0xFF;
            $first .= chr(ord($byte) & $mask);
            $last .= chr(ord($byte) | (~$mask & 0xFF));
        }

        return [$first, $last];
    }
}
