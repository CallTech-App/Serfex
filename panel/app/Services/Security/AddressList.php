<?php

namespace App\Services\Security;

use App\Services\Monitor\IpBlocks;

/**
 * A pasted list of addresses, for blocking in bulk: one per line, or
 * separated by commas, semicolons, spaces or tabs, as lists are copied
 * from mail, spreadsheets and other firewalls. "#" starts a note.
 */
class AddressList
{
    /** No address or prefix is longer than this. */
    private const LONGEST = 64;

    /**
     * Every entry once, in the order typed. An entry that is an address
     * or prefix has it as the agent stores it; any other has what is
     * wrong with it, worded to follow the entry ("… bir IP adresi değil").
     *
     * @return list<array{text: string, address: ?string, error: ?string}>
     */
    public static function parse(string $text): array
    {
        $entries = [];
        $seen = [];
        foreach (preg_split('/\R/u', mb_scrub($text)) ?: [] as $line) {
            $line = explode('#', $line, 2)[0];
            // Lists copied from web pages carry no-break and zero-width spaces.
            foreach (preg_split('/[\s,;\x{00A0}\x{200B}\x{FEFF}]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $token = mb_substr($token, 0, self::LONGEST);
                $error = IpBlocks::flaw($token);
                $address = $error === null ? IpBlocks::normalize($token) : null;
                $key = $address ?? '!'.$token;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $entries[] = ['text' => $token, 'address' => $address, 'error' => $error];
            }
        }

        return $entries;
    }
}
