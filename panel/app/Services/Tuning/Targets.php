<?php

namespace App\Services\Tuning;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Php\PhpVersions;
use App\Services\System\SystemServices;

/**
 * Where an optimization change is written, by the kind of service:
 *
 * - database: the agent's database settings of the server;
 * - php: {ini: {...}} goes into the version's php.ini defaults (as Hizmetler
 *   → PHP sürümleri saves them), {site, pm | max_children} into one site's
 *   PHP settings (as PHP ve Web Sunucusu saves them).
 *
 * current() reads the values a change replaces, so that it can be undone
 * exactly; set() writes values and returns why not as a string.
 */
class Targets
{
    public function __construct(
        private readonly SystemServices $services,
        private readonly AgentClient $agent,
        private readonly PhpVersions $php,
    ) {}

    /**
     * @param  array<string, mixed>  $values  the change's new values
     * @return array<string, mixed>|string
     */
    public function current(string $unit, array $values): array|string
    {
        if (Optimization::kind($unit) === 'php') {
            if (isset($values['ini'])) {
                $info = $this->info($unit);
                if (is_string($info)) {
                    return $info;
                }

                return ['ini' => array_map(fn ($k) => (string) ($info['values'][$k] ?? ''), array_combine(array_keys($values['ini']), array_keys($values['ini'])))];
            }
            $site = Site::find($values['site'] ?? 0);
            if ($site === null) {
                return __('Site bulunamadı.');
            }
            $settings = $site->php_settings ?? [];
            $out = ['site' => $site->id];
            foreach (array_diff(array_keys($values), ['site']) as $key) {
                $out[$key] = $settings[$key] ?? null;
            }

            return $out;
        }
        $current = $this->services->settings($unit, 'database');

        return is_string($current) ? $current : array_intersect_key($current, $values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|string
     */
    public function set(string $unit, array $values): array|string
    {
        if (Optimization::kind($unit) === 'php') {
            return isset($values['ini']) ? $this->setIni($unit, (array) $values['ini']) : $this->setSite($values);
        }
        $current = $this->services->settings($unit, 'database');
        if (is_string($current)) {
            return $current;
        }

        return $this->services->applySettings($unit, 'database', array_merge($current, $values));
    }

    /**
     * @param  array<string, string>  $ini
     * @return array<string, mixed>|string
     */
    private function setIni(string $unit, array $ini): array|string
    {
        $info = $this->info($unit);
        if (is_string($info)) {
            return $info;
        }
        $values = [];
        foreach (PhpVersions::INI as $key => $_) {
            $values[$key] = (string) ($info['values'][$key] ?? ($info['defaults'][$key] ?? ''));
        }
        $values = array_filter(array_merge($values, array_map('strval', $ini)), fn ($v) => $v !== '');
        try {
            $info = (array) $this->agent->call('php.settings.apply', ['version' => self::version($unit), 'values' => $values], 120);
        } catch (AgentException $e) {
            return SystemServices::message($e);
        }
        $this->php->remember($info);

        return $info;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|string
     */
    private function setSite(array $values): array|string
    {
        $site = Site::find($values['site'] ?? 0);
        if ($site === null) {
            return __('Site bulunamadı.');
        }
        if ($site->status->isBusy()) {
            return __('Site şu an meşgul; biraz sonra yeniden deneyin.');
        }
        $before = $site->php_settings;
        $settings = $before ?? [];
        foreach (['pm', 'max_children'] as $key) {
            if (array_key_exists($key, $values)) {
                $settings[$key] = $values[$key];
            }
        }
        // The site's own settings keep ondemand and the default as null.
        if (($settings['pm'] ?? null) === 'ondemand') {
            $settings['pm'] = null;
        }
        $settings = array_filter($settings, fn ($v) => $v !== null);
        $site->php_settings = $settings === [] ? null : $settings;
        try {
            $this->agent->call('site.apply', $site->agentSpec(), config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->php_settings = $before;

            return SystemServices::message($e);
        }
        $site->save();

        return ['site' => $site->id];
    }

    /**
     * @return array<string, mixed>|string
     */
    private function info(string $unit): array|string
    {
        try {
            return (array) $this->php->info(self::version($unit));
        } catch (AgentException $e) {
            return SystemServices::message($e);
        }
    }

    public static function version(string $unit): string
    {
        return preg_match('/^php(\d\.\d)-fpm$/', $unit, $m) ? $m[1] : '';
    }
}
