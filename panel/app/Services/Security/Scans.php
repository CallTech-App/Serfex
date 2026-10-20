<?php

namespace App\Services\Security;

use App\Enums\SiteStatus;
use App\Jobs\RunSecurityScan;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Components\Components;
use App\Services\Files\FileManager;
use Illuminate\Support\Facades\Cache;

/**
 * The security scan: the agent reads every file of a site (ClamAV when it
 * is installed, Servik's own web shell signatures, permissions, owners,
 * secrets and backups under the web root, outdated software) and the
 * panel keeps the findings. A finding keeps its row across scans until it
 * is fixed, ignored or no longer found. Fixes go through the agent and
 * each can be undone: a quarantined file waits in the site's trash.
 *
 * A server scan also looks at the server's own settings (firewall, SSH,
 * two-factor login, missing WAF and Fail2ban).
 */
class Scans
{
    /** How long quarantined files stay restorable (the agent's trash keeps them). */
    public const QUARANTINE_DAYS = 30;

    /** Cache of the open serious findings count, for the menu. */
    private const ATTENTION_KEY = 'security.attention';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly WebAccess $access,
    ) {}

    /**
     * Queues a scan of the whole server or one site. Returns why it cannot
     * start instead when the same scan is already running.
     */
    public function start(?Site $site, string $trigger = 'manual', ?User $by = null): SecurityScan|string
    {
        $running = SecurityScan::running()->where(fn ($q) => $q->where('scope', 'server')->when($site, fn ($q) => $q->orWhere('site_id', $site->id)))->first();
        if ($running !== null) {
            return $running->scope === 'server' ? __('Sunucu taraması sürüyor; bitince sonuçlar burada.') : __('Bu sitenin taraması sürüyor.');
        }
        $scan = SecurityScan::create([
            'scope' => $site ? 'site' : 'server',
            'site_id' => $site?->id,
            'trigger' => $trigger,
            'user_id' => $by?->id,
            'sites_total' => $site ? 1 : Site::where('status', SiteStatus::Active)->count(),
        ]);
        RunSecurityScan::dispatch($scan);

        return $scan;
    }

    /**
     * Runs a queued scan, one site at a time so the page shows progress.
     */
    public function run(SecurityScan $scan): void
    {
        $sites = $scan->scope === 'site'
            ? Site::with('systemUser')->whereKey($scan->site_id)->get()
            : Site::with('systemUser')->where('status', SiteStatus::Active)->orderBy('domain')->get();
        $scan->update(['status' => 'running', 'started_at' => now(), 'sites_total' => $sites->count(), 'sites_done' => 0, 'files' => 0]);
        $errors = [];
        foreach ($sites as $site) {
            try {
                $result = (array) $this->agent->call('scan.run', ['sites' => [$this->spec($site)]], 1800);
                $siteResult = $result['sites'][0] ?? [];
                if (($siteResult['error'] ?? '') !== '') {
                    $errors[] = $site->domain.': '.$siteResult['error'];
                } else {
                    $this->record($scan, $site, $siteResult['findings'] ?? [], true);
                }
                $scan->update(['clamav' => (bool) ($result['clamav'] ?? false), 'files' => $scan->files + (int) ($siteResult['files'] ?? 0)]);
            } catch (AgentUnavailableException $e) {
                $errors[] = __('Sunucu ajanına ulaşılamıyor.');
                break;
            } catch (AgentException $e) {
                $errors[] = $site->domain.': '.$e->reason;
            }
            $scan->increment('sites_done');
        }
        if ($scan->scope === 'server') {
            $this->checkServer($scan);
        }
        $failed = $errors !== [] && count($errors) >= max(1, $sites->count());
        $scan->update([
            'status' => $failed ? 'failed' : ($errors ? 'partial' : 'succeeded'),
            'error' => $errors ? mb_substr(implode("\n", $errors), 0, 2000) : null,
            'finished_at' => now(),
        ]);
        self::forget();
    }

    /**
     * Scans files just uploaded to a site (the file manager calls this).
     * Returns the new findings; nothing else of the site changes.
     *
     * @param  list<string>  $paths  relative to the site folder
     * @return list<SecurityFinding>
     */
    public function scanUploads(Site $site, array $paths): array
    {
        $site->loadMissing('systemUser');
        try {
            $result = (array) $this->agent->call('scan.files', ['domain' => $site->domain, 'user' => $site->systemUser->name, 'paths' => $paths], 120);
        } catch (AgentException) {
            return [];
        }
        $findings = $result['findings'] ?? [];
        if ($findings === []) {
            return [];
        }
        $scan = SecurityScan::create(['scope' => 'site', 'site_id' => $site->id, 'trigger' => 'upload', 'status' => 'succeeded',
            'sites_total' => 1, 'sites_done' => 1, 'files' => count($paths), 'clamav' => (bool) ($result['clamav'] ?? false),
            'started_at' => now(), 'finished_at' => now()]);
        $rows = $this->record($scan, $site, $findings, false);
        self::forget();

        return $rows;
    }

    /**
     * What the agent needs to scan a site.
     *
     * @return array<string, string>
     */
    private function spec(Site $site): array
    {
        return array_filter([
            'domain' => $site->domain,
            'user' => $site->systemUser->name,
            'php_version' => $site->php_version,
            'web_root' => $site->web_root,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Keeps a site's findings: a problem seen before keeps its row (and an
     * ignored one stays ignored); with $complete, open problems this scan
     * did not see are marked gone.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return list<SecurityFinding>
     */
    private function record(SecurityScan $scan, ?Site $site, array $findings, bool $complete, ?string $check = null): array
    {
        $seen = [];
        $rows = [];
        foreach ($findings as $f) {
            $fingerprint = self::fingerprint($site?->id, (string) $f['check'], (string) $f['code'], (string) ($f['path'] ?? ''));
            $seen[] = $fingerprint;
            $data = [
                'scan_id' => $scan->id,
                'check' => (string) $f['check'],
                'code' => (string) $f['code'],
                'severity' => (string) $f['severity'],
                'path' => mb_substr((string) ($f['path'] ?? ''), 0, 1000),
                'url' => isset($f['url']) && $f['url'] !== '' ? mb_substr((string) $f['url'], 0, 1000) : null,
                'detail' => isset($f['detail']) && $f['detail'] !== '' ? mb_substr((string) $f['detail'], 0, 300) : null,
                'fixes' => array_values($f['fixes'] ?? []),
                'count' => (int) ($f['count'] ?? 0),
            ];
            $row = SecurityFinding::where('fingerprint', $fingerprint)->where('site_id', $site?->id)->latest('id')->first();
            if ($row === null) {
                $row = SecurityFinding::create($data + ['site_id' => $site?->id, 'fingerprint' => $fingerprint]);
            } else {
                // A fixed problem that is back (a file restored from
                // quarantine, changed again) is open again.
                $reopen = in_array($row->status, ['fixed', 'gone'], true) ? ['status' => 'open', 'fix_action' => null, 'fix_data' => null, 'resolved_by' => null, 'resolved_at' => null] : [];
                $row->update($data + $reopen);
            }
            $rows[] = $row;
        }
        if ($complete) {
            SecurityFinding::open()->where('site_id', $site?->id)
                ->when($check, fn ($q) => $q->where('check', $check), fn ($q) => $q->where('check', '!=', 'server'))
                ->whereNotIn('fingerprint', $seen)
                ->update(['status' => 'gone', 'resolved_at' => now()]);
        }

        return $rows;
    }

    public static function fingerprint(?int $siteId, string $check, string $code, string $path): string
    {
        return hash('sha256', ($siteId ?? 0)."\0".$check."\0".$code."\0".$path);
    }

    /**
     * The server's own settings, as findings without a site.
     */
    private function checkServer(SecurityScan $scan): void
    {
        $components = app(Components::class);
        $components->statuses();
        $found = [];
        if (! User::where('is_admin', true)->whereNotNull('two_factor_confirmed_at')->exists()) {
            $found[] = ['no-2fa', 'medium'];
        }
        if (! app(Firewall::class)->enabled()) {
            $found[] = ['firewall-off', 'low'];
        }
        if (! $components->isInstalled('modsecurity')) {
            $found[] = ['waf-missing', 'medium'];
        }
        if (! $components->isInstalled('fail2ban')) {
            $found[] = ['fail2ban-missing', 'medium'];
        }
        if (config('app.debug') && app()->isProduction()) {
            $found[] = ['panel-debug', 'high'];
        }
        try {
            $ssh = app(Ssh::class)->status();
            if ($ssh['installed'] ?? false) {
                if (($ssh['root_login'] ?? '') === 'yes' && ($ssh['password_auth'] ?? false)) {
                    $found[] = ['ssh-root-password', 'high'];
                } elseif ($ssh['password_auth'] ?? false) {
                    $found[] = ['ssh-password', 'low'];
                }
            }
        } catch (AgentException) {
            // Not known this time; earlier SSH findings stay as they are.
        }
        $this->record($scan, null, array_map(fn ($f) => ['check' => 'server', 'code' => $f[0], 'severity' => $f[1], 'fixes' => []], $found), true, 'server');
    }

    // --- Fixes --------------------------------------------------------

    /**
     * Applies one of the finding's fixes.
     *
     * @throws AgentException
     */
    public function fix(SecurityFinding $finding, string $action, ?User $by): void
    {
        if (! in_array($action, $finding->fixes ?? [], true) || $finding->status !== 'open' || $finding->site === null) {
            return;
        }
        $site = $finding->site->loadMissing('systemUser');
        if ($action === 'deny') {
            $path = (string) $finding->url;
            if (str_ends_with($finding->path, '.git') || $finding->code === 'git') {
                $path = rtrim($path, '/').'/';
            }
            $this->access->deny($site, [$path]);
            $data = ['paths' => [$path]];
        } else {
            $data = (array) $this->agent->call('scan.fix', ['domain' => $site->domain, 'user' => $site->systemUser->name, 'path' => $finding->path, 'action' => $action], config('servik.agent.job_timeout'));
        }
        $finding->update(['status' => 'fixed', 'fix_action' => $action, 'fix_data' => $data, 'resolved_by' => $by?->id, 'resolved_at' => now()]);
        self::forget();
    }

    /**
     * Undoes a fix: the file comes back from quarantine, the old modes or
     * owners are put back, the path is served again.
     *
     * @throws AgentException
     */
    public function undo(SecurityFinding $finding): void
    {
        if ($finding->status !== 'fixed' || $finding->site === null) {
            return;
        }
        $site = $finding->site->loadMissing('systemUser');
        $data = $finding->fix_data ?? [];
        match ($finding->fix_action) {
            'quarantine' => ($data['trash_id'] ?? '') !== '' ? app(FileManager::class)->call($site, 'trash.restore', ['ids' => [$data['trash_id']]]) : null,
            'deny' => $this->access->allow($site, $data['paths'] ?? []),
            default => ($data['undo'] ?? []) !== [] ? $this->agent->call('scan.fix', ['domain' => $site->domain, 'user' => $site->systemUser->name, 'path' => $finding->path,
                'action' => $finding->fix_action, 'undo' => $data['undo']], config('servik.agent.job_timeout')) : null,
        };
        $finding->update(['status' => 'open', 'fix_action' => null, 'fix_data' => null, 'resolved_by' => null, 'resolved_at' => null]);
        self::forget();
    }

    public function ignore(SecurityFinding $finding, ?User $by): void
    {
        if ($finding->status === 'open') {
            $finding->update(['status' => 'ignored', 'resolved_by' => $by?->id, 'resolved_at' => now()]);
            self::forget();
        }
    }

    public function reopen(SecurityFinding $finding): void
    {
        if ($finding->status === 'ignored') {
            $finding->update(['status' => 'open', 'resolved_by' => null, 'resolved_at' => null]);
            self::forget();
        }
    }

    // --- Counts -------------------------------------------------------

    /**
     * Open critical and high findings the user may see: the menu and the
     * dashboard show them.
     */
    public static function attentionCount(?User $user = null): int
    {
        if ($user === null || $user->is_admin) {
            return (int) Cache::remember(self::ATTENTION_KEY, 60, fn () => SecurityFinding::open()->whereIn('severity', ['critical', 'high'])->count());
        }

        return SecurityFinding::open()->visibleTo($user)->whereIn('severity', ['critical', 'high'])->count();
    }

    public static function forget(): void
    {
        Cache::forget(self::ATTENTION_KEY);
    }

    public static function message(AgentException $e): string
    {
        return match (true) {
            $e instanceof AgentUnavailableException => __('Sunucu ajanına ulaşılamıyor.'),
            str_contains($e->reason, 'no longer exists') => __('Dosya artık yerinde değil; yeniden tarayın.'),
            str_contains($e->reason, 'nothing was quarantined') => __('Dosya karantinaya alınamadı; artık yerinde olmayabilir.'),
            default => __('Sunucu düzeltmeyi uygulayamadı: :reason', ['reason' => $e->reason]),
        };
    }
}
