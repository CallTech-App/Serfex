<?php

namespace App\Services\Security;

use App\Models\Site;
use App\Models\User;
use App\Models\WafExclusion;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Components\Components;
use Illuminate\Support\Collection;

/**
 * The web application firewall: ModSecurity with the OWASP Core Rule Set
 * in front of every site, whichever web server stack it runs (Nginx sees
 * every request first). The server has a mode (Yazılım Yönetimi keeps it
 * with the component); a site follows it or has its own, and may turn off
 * single rules that block its legitimate requests.
 */
class Waf
{
    /** A site's own modes; null follows the server. */
    public const MODES = [
        'off' => 'Kapalı',
        'detect' => 'Yalnızca izle',
        'block' => 'Engelle',
    ];

    /** The agent's answer while ModSecurity is not installed. */
    public const NOT_INSTALLED = -32009;

    /** A URL path the agent accepts. */
    public const PATH_PATTERN = '#^/[A-Za-z0-9._~/%@+-]{0,200}$#';

    public const MAX_EXCLUSIONS = 100;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Components $components,
    ) {}

    public function installed(): bool
    {
        return $this->components->isInstalled('modsecurity');
    }

    /**
     * The server's mode (detect or block) and paranoia level (1-4).
     *
     * @return array{mode: string, paranoia: int}
     */
    public function server(): array
    {
        $settings = $this->components->statuses()['modsecurity']['settings'] ?? [];

        return ['mode' => ($settings['mode'] ?? 'detect') === 'block' ? 'block' : 'detect', 'paranoia' => (int) ($settings['paranoia'] ?? 1)];
    }

    /**
     * Changes the server's mode and paranoia level.
     *
     * @throws AgentException
     */
    public function configureServer(string $mode, int $paranoia): void
    {
        $this->agent->call('component.configure', ['id' => 'modsecurity', 'settings' => ['modsecurity_mode' => $mode, 'modsecurity_paranoia' => $paranoia]], config('servik.agent.job_timeout'));
    }

    /**
     * The mode a site's requests meet now.
     */
    public function effectiveMode(Site $site, ?string $server = null): string
    {
        return $site->waf_mode ?? $server ?? $this->server()['mode'];
    }

    /**
     * How the site page names a site's setting: "Sunucu ayarı (Yalnızca izle)".
     */
    public function modeLabel(Site $site, ?string $server = null): string
    {
        if (! $this->installed()) {
            return 'Kurulu değil';
        }
        if ($site->waf_mode === null) {
            return 'Sunucu ayarı ('.self::MODES[$server ?? $this->server()['mode']].')';
        }

        return self::MODES[$site->waf_mode];
    }

    /**
     * Sends the site's mode and exclusions to the server.
     *
     * @throws AgentException
     */
    public function apply(Site $site): void
    {
        $this->agent->call('waf.site.apply', [
            'domain' => $site->domain,
            'mode' => $site->waf_mode ?? '',
            'exclusions' => WafExclusion::where('site_id', $site->id)->orderBy('id')->get()
                ->map(fn (WafExclusion $e) => ['rule_id' => $e->rule_id, 'path' => $e->path])->values()->all(),
        ], config('servik.agent.job_timeout'));
    }

    /**
     * Gives a site its own mode, or (null) makes it follow the server.
     * Nothing changes when the server refuses.
     *
     * @throws AgentException
     */
    public function setMode(Site $site, ?string $mode): void
    {
        $before = $site->waf_mode;
        $site->update(['waf_mode' => $mode]);
        try {
            $this->apply($site);
        } catch (AgentException $e) {
            $site->update(['waf_mode' => $before]);
            throw $e;
        }
    }

    /**
     * Checks an exclusion; returns an error per field.
     *
     * @return array<string, string>
     */
    public function problems(Site $site, int $ruleId, string $path): array
    {
        $out = [];
        if ($ruleId < 1 || $ruleId > 99999999) {
            $out['rule'] = 'Kural numarası 1 ile 99999999 arasında olmalı (örneğin 942100).';
        }
        if ($path !== '' && (! preg_match(self::PATH_PATTERN, $path) || str_contains($path, '//') || str_contains($path, '/../'))) {
            $out['path'] = 'Yol / ile başlamalı; harf, rakam ve ._~/%@+- içerebilir.';
        }
        if ($out === [] && WafExclusion::where('site_id', $site->id)->where('rule_id', $ruleId)->where('path', $path)->exists()) {
            $out['rule'] = 'Bu kural bu yolda zaten kapalı.';
        }
        if ($out === [] && WafExclusion::where('site_id', $site->id)->count() >= self::MAX_EXCLUSIONS) {
            $out['rule'] = 'Bir sitede en fazla '.self::MAX_EXCLUSIONS.' istisna olabilir.';
        }

        return $out;
    }

    /**
     * Turns a rule off for the site (under $path, or everywhere).
     *
     * @throws AgentException
     */
    public function exclude(Site $site, int $ruleId, string $path, ?string $note, ?User $by): WafExclusion
    {
        $exclusion = WafExclusion::create(['site_id' => $site->id, 'rule_id' => $ruleId, 'path' => $path, 'note' => $note, 'user_id' => $by?->id]);
        try {
            $this->apply($site);
        } catch (AgentException $e) {
            $exclusion->forceDelete();
            throw $e;
        }

        return $exclusion;
    }

    /**
     * Turns the rule back on; Undo restores the exclusion.
     *
     * @throws AgentException
     */
    public function removeExclusion(WafExclusion $exclusion): void
    {
        $exclusion->delete();
        try {
            $this->apply($exclusion->site);
        } catch (AgentException $e) {
            $exclusion->restore();
            throw $e;
        }
    }

    /**
     * @throws AgentException
     */
    public function restoreExclusion(WafExclusion $exclusion): void
    {
        $exclusion->restore();
        try {
            $this->apply($exclusion->site);
        } catch (AgentException $e) {
            $exclusion->delete();
            throw $e;
        }
    }

    /**
     * Requests the firewall flagged, newest first, each with the site it
     * was for (null when the host is no site's). $sites limits them to
     * those sites' host names.
     *
     * @param  Collection<int, Site>|null  $sites
     * @return array{installed: bool, events: list<array<string, mixed>>}
     *
     * @throws AgentException
     */
    public function events(?Collection $sites = null, ?string $ip = null, int $limit = 100, ?\DateTimeInterface $since = null): array
    {
        $all = $sites ?? Site::with('systemUser')->get();
        $byHost = [];
        foreach ($all as $site) {
            foreach ($site->hostnames() as $host) {
                $byHost[strtolower($host)] = $site;
            }
        }
        $params = ['limit' => $limit];
        if ($sites !== null) {
            if ($byHost === []) {
                return ['installed' => $this->installed(), 'events' => []];
            }
            $params['hosts'] = array_keys($byHost);
        }
        if ($ip !== null && $ip !== '') {
            $params['ip'] = $ip;
        }
        if ($since !== null) {
            $params['since'] = $since->format(DATE_RFC3339);
        }
        $result = (array) $this->agent->call('waf.events', $params, 30);
        $events = [];
        foreach ($result['events'] ?? [] as $e) {
            $host = strtolower(preg_replace('/:\d+$/', '', (string) ($e['host'] ?? '')));
            $events[] = $e + ['site' => $byHost[$host] ?? null];
        }

        return ['installed' => (bool) ($result['installed'] ?? false), 'events' => $events];
    }

    /**
     * Writes every site's own setting again, after ModSecurity was
     * installed (removing it deleted them on the server).
     */
    public function syncAll(): void
    {
        $sites = Site::whereNotNull('waf_mode')->orWhereHas('wafExclusions')->get();
        foreach ($sites as $site) {
            try {
                $this->apply($site);
            } catch (AgentException) {
                // The site page shows the mode; the next change sends it again.
            }
        }
    }

    /**
     * A message for the person.
     */
    public static function message(AgentException $e): string
    {
        return match (true) {
            $e instanceof AgentUnavailableException => 'Sunucu ajanına ulaşılamıyor.',
            $e->getCode() === self::NOT_INSTALLED => 'ModSecurity kurulu değil. Yazılım Yönetimi → Diğer bileşenler sayfasından kurabilirsiniz.',
            str_contains($e->reason, 'nginx rejected') => 'Nginx yeni ayarı kabul etmedi; önceki ayar korundu.',
            default => 'Sunucu ayarı uygulayamadı: '.$e->reason,
        };
    }
}
