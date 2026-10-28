<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Önbellek ve hız: the pages Nginx keeps and sends again without running
 * PHP (page cache), how long browsers keep images, styles, scripts and
 * fonts (browser cache), and compression. Everything is off until the
 * site turns it on. The page cache never keeps a response that sets a
 * cookie or that the application marks private, nor sign-in, cart or
 * administration pages (the agent's list), so signed-in visitors always
 * get fresh pages.
 */
class SiteCache
{
    /** Minutes a cached page is sent again, with their labels. */
    public const PAGE_MINUTES = [1 => '1 dakika', 5 => '5 dakika', 10 => '10 dakika', 30 => '30 dakika', 60 => '1 saat', 360 => '6 saat', 1440 => '1 gün'];

    /** Days browsers keep static files. */
    public const BROWSER_DAYS = [1 => '1 gün', 7 => '7 gün', 30 => '30 gün', 90 => '90 gün', 365 => '1 yıl'];

    public const DEFAULT_MINUTES = 10;

    public const DEFAULT_DAYS = 30;

    public const MAX_EXCLUDE = 30;

    /** What the agent accepts as an excluded path. */
    public const EXCLUDE_PATTERN = '~^/[A-Za-z0-9._\~!$&\'()*+,;=:@%/-]{0,199}$~';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The site's settings as the form shows them, defaults filled in.
     *
     * @return array{page: bool, page_minutes: int, exclude: list<string>, browser: bool, browser_days: int, gzip: bool}
     */
    public static function settings(Site $site): array
    {
        $cache = $site->cache ?? [];

        return [
            'page' => (bool) ($cache['page'] ?? false),
            'page_minutes' => (int) ($cache['page_minutes'] ?? self::DEFAULT_MINUTES),
            'exclude' => array_values($cache['exclude'] ?? []),
            'browser' => (bool) ($cache['browser'] ?? false),
            'browser_days' => (int) ($cache['browser_days'] ?? self::DEFAULT_DAYS),
            'gzip' => (bool) ($cache['gzip'] ?? false),
        ];
    }

    /**
     * The cache part of the agent's site.apply spec; null when nothing is
     * on.
     *
     * @return array<string, mixed>|null
     */
    public static function spec(Site $site): ?array
    {
        $s = self::settings($site);
        if (! $s['page'] && ! $s['browser'] && ! $s['gzip']) {
            return null;
        }

        return $s;
    }

    /**
     * Whether the page cache can work on the site: an Octane application
     * answers through a proxy, which the page cache does not cover.
     */
    public static function pageCacheBlocked(Site $site): ?string
    {
        $app = $site->laravelApp;
        if ($app !== null && $app->processes->where('enabled', true)->where('kind', 'octane')->isNotEmpty()) {
            return __('Octane ile çalışan uygulamalarda sayfa önbelleği kullanılmaz; Octane zaten hızlıdır.');
        }

        return null;
    }

    /**
     * Pages kept now: files and bytes; null when the agent cannot tell.
     *
     * @return array{files: int, bytes: int}|null
     */
    public function usage(Site $site): ?array
    {
        try {
            $usage = $this->agent->call('site.cache.status', ['domain' => $site->domain]);
        } catch (AgentException) {
            return null;
        }

        return ['files' => (int) ($usage['files'] ?? 0), 'bytes' => (int) ($usage['bytes'] ?? 0)];
    }

    /**
     * Empties the site's page cache; returns the files that were removed.
     * Quietly does nothing when the site has no page cache.
     *
     * @throws AgentException
     */
    public function purge(Site $site): int
    {
        if (! self::settings($site)['page']) {
            return 0;
        }
        $before = $this->agent->call('site.cache.purge', ['domain' => $site->domain], config('serfex.agent.job_timeout'));

        return (int) ($before['files'] ?? 0);
    }

    /**
     * purge() for after a change of files or databases: a failure is only
     * reported, the pages expire on their own.
     */
    public function purgeQuietly(Site $site): void
    {
        try {
            $this->purge($site);
        } catch (AgentException $e) {
            report($e);
        }
    }
}
