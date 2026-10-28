<?php

namespace App\Services\Sites;

use App\Jobs\RunSiteCommand;
use App\Models\Site;
use App\Models\SiteCommand;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\LaravelApps;
use Illuminate\Support\Str;

/**
 * Composer ve Node.js: the same tools as a Laravel application's commands,
 * in any folder of any site, run as the site's account. The Node.js line a
 * site's commands use (and its Laravel application's builds) can be
 * pinned; empty is the server's default.
 */
class SiteTools
{
    /** A Node.js release line, as the agent names it ("22"). */
    public const NODE_LINE = '/^[1-9][0-9]?$/';

    /** Commands at most running at once on a site. */
    public const MAX_RUNNING = 3;

    /** Offered as one-click commands. */
    public const QUICK = [
        'composer' => ['install --no-dev --optimize-autoloader', 'update --no-dev', 'dump-autoload -o', 'outdated --direct'],
        'node' => ['install', 'ci', 'run build', 'audit'],
    ];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Projects found on the site and the installed versions; null when the
     * agent cannot be reached.
     *
     * @return array{composer: string, node: array<string, string>, default_node: string, projects: list<array<string, mixed>>}|null
     */
    public function scan(Site $site): ?array
    {
        try {
            $tools = $this->agent->call('site.tool.scan', $site->toolTarget(), config('serfex.agent.job_timeout'));
        } catch (AgentException) {
            return null;
        }

        return [
            'composer' => (string) ($tools['composer'] ?? ''),
            'node' => (array) ($tools['node'] ?? []),
            'default_node' => (string) ($tools['default_node'] ?? ''),
            'projects' => array_values((array) ($tools['projects'] ?? [])),
        ];
    }

    /**
     * Queues a command; $tool is composer or a package manager.
     */
    public function run(Site $site, string $tool, string $path, string $command, ?User $user): SiteCommand
    {
        $command = $site->commands()->create([
            'user_id' => $user?->id,
            'tool' => $tool,
            'path' => $path,
            'command' => $command,
            'run' => 'site-'.Str::lower(Str::random(12)),
            'status' => 'queued',
        ]);
        RunSiteCommand::dispatch($command);

        return $command;
    }

    public static function isPackageManager(string $tool): bool
    {
        return in_array($tool, LaravelApps::PACKAGE_MANAGERS, true);
    }
}
