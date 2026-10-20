<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * A site's logs through the agent's site.logs: the Nginx access and error
 * logs (subdomains and redirected domains write to them too), Apache's
 * error log for sites behind Apache, and PHP's error log.
 */
class SiteLogs
{
    /** The most read for the viewer, from the end of the file. */
    public const VIEW_BYTES = 512 * 1024;

    /** A download is read in parts of this size. */
    public const CHUNK_BYTES = 1024 * 1024;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The logs a site has: key => [name, file name].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function kinds(Site $site): array
    {
        $kinds = [
            'access' => ['Erişim', 'access.log'],
            'error' => ['Web sunucusu hataları', 'error.log'],
            'php' => ['PHP hataları', 'php-error.log'],
        ];
        if ($site->web_server === Site::APACHE) {
            $kinds['apache'] = ['Apache hataları', 'apache-error.log'];
        }

        return $kinds;
    }

    /**
     * @return array{kind: string, exists: bool, size: int, content: string, truncated: bool}
     *
     * @throws AgentException
     */
    public function read(Site $site, string $kind, int $bytes = self::VIEW_BYTES, ?int $offset = null): array
    {
        $site->loadMissing('systemUser');

        return $this->agent->call('site.logs', array_filter([
            'domain' => $site->domain,
            'user' => $site->systemUser->name,
            'kind' => $kind,
            'bytes' => $bytes,
            'offset' => $offset,
        ], fn ($v) => $v !== null));
    }

    /**
     * The last $count lines that contain $filter (any case) and, with
     * $problems, only failed requests or error lines.
     *
     * @return list<array{text: string, tone: string|null}>
     */
    public static function lines(string $content, bool $truncated, string $kind, string $filter, bool $problems, int $count): array
    {
        $lines = preg_split('/\r?\n/', rtrim($content, "\n")) ?: [];
        if ($truncated && $lines !== []) {
            array_shift($lines); // starts mid-line
        }
        $filter = mb_strtolower(trim($filter));
        $out = [];
        foreach ($lines as $line) {
            if ($line === '' || ($filter !== '' && ! str_contains(mb_strtolower($line), $filter))) {
                continue;
            }
            $tone = self::tone($kind, $line);
            if ($problems && $tone === null) {
                continue;
            }
            $out[] = ['text' => $line, 'tone' => $tone];
        }

        return array_slice($out, -$count);
    }

    /**
     * crit for server errors, warn for client errors and warnings, null
     * for the rest.
     */
    public static function tone(string $kind, string $line): ?string
    {
        if ($kind === 'access') {
            if (preg_match('/" (\d{3}) /', $line, $m)) {
                return match (true) {
                    $m[1] >= 500 => 'crit',
                    $m[1] >= 400 => 'warn',
                    default => null,
                };
            }

            return null;
        }
        if (preg_match('/\[(error|crit|alert|emerg)\]|PHP (Fatal|Parse) error|:error\]|:crit\]/i', $line)) {
            return 'crit';
        }
        if (preg_match('/\[warn\]|PHP Warning|:warn\]/i', $line)) {
            return 'warn';
        }

        return null;
    }
}
