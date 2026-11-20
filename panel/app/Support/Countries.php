<?php

namespace App\Support;

use App\Services\Stats\SiteStats;
use Collator;

/**
 * The countries a block can name: ISO 3166-1, by their two-letter code.
 * The registries the country table is built from use the same codes.
 */
class Countries
{
    /** @var list<string> */
    public const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ',
        'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ',
        'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ',
        'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET',
        'FI', 'FJ', 'FK', 'FM', 'FO', 'FR',
        'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY',
        'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT',
        'JE', 'JM', 'JO', 'JP',
        'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
        'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
        'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
        'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ',
        'OM',
        'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY',
        'QA',
        'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ',
        'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
        'UA', 'UG', 'UM', 'US', 'UY', 'UZ',
        'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU',
        'WF', 'WS',
        'YE', 'YT',
        'ZA', 'ZM', 'ZW',
    ];

    public static function valid(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /**
     * "Rusya" for RU, in the reader's language.
     */
    public static function name(string $code): string
    {
        return SiteStats::country($code);
    }

    /**
     * The countries whose code is $term or whose name holds it: the code
     * first, then the names that start with it, then the rest, each in
     * the order of the reader's alphabet.
     *
     * @return list<string>
     */
    public static function find(string $term): array
    {
        $term = self::fold($term);
        if ($term === '') {
            return [];
        }
        $exact = [];
        $starts = [];
        $holds = [];
        foreach (self::CODES as $code) {
            $name = self::name($code);
            $folded = self::fold($name);
            if (strtolower($code) === $term) {
                $exact[$code] = $name;
            } elseif (str_starts_with($folded, $term)) {
                $starts[$code] = $name;
            } elseif (str_contains($folded, $term)) {
                $holds[$code] = $name;
            }
        }

        return [...array_keys($exact), ...array_keys(self::sorted($starts)), ...array_keys(self::sorted($holds))];
    }

    /**
     * Names in the order of the reader's alphabet, keys kept.
     *
     * @template K of array-key
     *
     * @param  array<K, string>  $names
     * @return array<K, string>
     */
    public static function sorted(array $names): array
    {
        if (class_exists(Collator::class) && ($collator = Collator::create(app()->getLocale())) !== null) {
            $collator->asort($names);
        } else {
            asort($names);
        }

        return $names;
    }

    /**
     * Text as it is compared in a search: lower case, and Turkish's
     * dotted capital İ and dotless ı as a plain i, so "isveç" finds
     * "İsveç" and "irak" finds "Irak".
     */
    public static function fold(string $text): string
    {
        return str_replace(["\u{0307}", "\u{0131}"], ['', 'i'], mb_strtolower(trim($text)));
    }
}
