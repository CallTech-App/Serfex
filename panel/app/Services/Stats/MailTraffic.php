<?php

namespace App\Services\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A mail domain's messages per day: sent to other servers, received in
 * its mailboxes, refused on the way in, and bounced.
 */
class MailTraffic
{
    public const COUNTS = ['received' => 'Gelen', 'sent' => 'Giden', 'rejected' => 'Reddedilen', 'bounced' => 'Teslim edilemeyen'];

    /**
     * The last $days days, newest first, with no gaps, and their totals.
     *
     * @param  list<string>  $domains
     * @return array{days: list<array{day: CarbonImmutable, received: int, sent: int, rejected: int, bounced: int}>, totals: array<string, int>}
     */
    public function days(array $domains, int $days = 30): array
    {
        $today = CarbonImmutable::now(config('serfex.timezone'))->startOfDay();
        $from = $today->subDays($days - 1);
        $rows = DB::table('mail_stats_daily')->whereIn('domain', $domains)->whereBetween('day', [$from->toDateString(), $today->toDateString()])
            ->groupBy('day')->selectRaw('day::text as day, sum(received) as received, sum(sent) as sent, sum(rejected) as rejected, sum(bounced) as bounced')
            ->get()->keyBy('day');
        $out = [];
        $totals = array_fill_keys(array_keys(self::COUNTS), 0);
        for ($d = $today; $d->gte($from); $d = $d->subDay()) {
            $r = $rows[$d->toDateString()] ?? null;
            $row = ['day' => $d];
            foreach (array_keys(self::COUNTS) as $c) {
                $row[$c] = (int) ($r->{$c} ?? 0);
                $totals[$c] += $row[$c];
            }
            $out[] = $row;
        }

        return ['days' => $out, 'totals' => $totals];
    }
}
