<?php

namespace App\Services\Laravel;

use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;

/**
 * Maintenance mode of an application: Serfex's page or custom HTML shown
 * by Nginx (the application keeps running; listed addresses and holders
 * of the bypass link get through), or the application's own
 * php artisan down. Each method returns why it failed, or null.
 */
class Maintenance
{
    public function __construct(private readonly AgentClient $agent, private readonly Processes $processes) {}

    /**
     * @param  array{maintenance_secret: string, maintenance_retry: int, maintenance_allow: string|null, maintenance_html: string|null}  $settings
     */
    public function down(LaravelApp $app, string $mode, array $settings): ?string
    {
        if ($mode === 'app') {
            $error = $this->artisan($app, true, $settings['maintenance_secret'], $settings['maintenance_retry']);
            if ($error === null) {
                $app->update(['maintenance_mode' => 'app'] + $settings);
            }

            return $error;
        }

        return $this->web($app, ['maintenance_on' => true, 'maintenance_mode' => $mode] + $settings);
    }

    public function up(LaravelApp $app): ?string
    {
        if ($app->nginxMaintenance() && ($error = $this->web($app, ['maintenance_on' => false])) !== null) {
            return $error;
        }
        if (($this->status($app)['maintenance'] ?? null) !== null) {
            return $this->artisan($app, false, (string) $app->maintenance_secret, $app->maintenance_retry);
        }

        return null;
    }

    /**
     * What laravel.status says, [] when the agent cannot tell.
     *
     * @return array<string, mixed>
     */
    public function status(LaravelApp $app): array
    {
        try {
            return (array) $this->agent->call('laravel.status', $app->agentApp(), config('serfex.agent.job_timeout'));
        } catch (AgentException) {
            return [];
        }
    }

    /**
     * Changes the application and rewrites its Nginx configuration; puts
     * the old values back when the server refuses.
     *
     * @param  array<string, mixed>  $values
     */
    public function web(LaravelApp $app, array $values): ?string
    {
        $before = $app->only(array_keys($values));
        $app->update($values);
        try {
            $this->processes->applyWeb($app);
        } catch (AgentException $e) {
            $app->update($before);

            return self::message($e);
        }

        return null;
    }

    public function artisan(LaravelApp $app, bool $down, string $secret, int $retry): ?string
    {
        try {
            $result = $this->agent->call('laravel.maintenance', $app->agentApp() + ['down' => $down, 'secret' => $secret, 'retry' => $retry], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            return self::message($e);
        }
        if (($result['exit_code'] ?? 1) !== 0) {
            return __('Komut başarısız oldu: :output', ['output' => trim((string) ($result['output'] ?? ''))]);
        }

        return null;
    }

    private static function message(AgentException $e): string
    {
        return $e instanceof AgentUnavailableException
            ? __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.')
            : $e->reason;
    }
}
