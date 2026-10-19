<?php

namespace App\Services\Databases;

use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;

/**
 * The database servers customers' databases live on: MySQL or MariaDB
 * (one of them, server "mysql") and PostgreSQL, and the web database
 * manager (phpMyAdmin, Adminer) on Servik's own web server.
 *
 * The setup's progress is kept in the "databases.setup" setting: state
 * installing, ready or failed, the products asked for, the step running
 * and the error.
 */
class DatabaseServers
{
    public const MYSQL = 'mysql';

    public const POSTGRESQL = 'postgresql';

    /** Products: key => [server, label]. */
    public const PRODUCTS = [
        'mysql' => [self::MYSQL, 'MySQL'],
        'mariadb' => [self::MYSQL, 'MariaDB'],
        'postgresql' => [self::POSTGRESQL, 'PostgreSQL'],
    ];

    /** Where the web database manager listens (HTTPS). */
    public const DEFAULT_PORT = 8443;

    /** @var array<string, mixed>|null db.status, once per request */
    private ?array $status = null;

    /** @var array<string, string>|null */
    private ?array $products = null;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The agent's db.status: servers.{mysql,postgresql} and manager; null
     * when the agent cannot be reached. With $error the reason is set.
     *
     * @return array<string, mixed>|null
     */
    public function status(?string &$error = null): ?array
    {
        if ($this->status !== null) {
            return $this->status;
        }
        try {
            $this->status = (array) $this->agent->call('db.status');
        } catch (AgentException $e) {
            $error = $e instanceof AgentUnavailableException ? null : $e->reason;

            return null;
        }
        // Remember which product serves "mysql" for labels.
        $products = [];
        foreach ($this->status['servers'] ?? [] as $server => $s) {
            if ($s['installed'] ?? false) {
                $products[$server] = $s['product'];
            }
        }
        if ($products !== $this->products()) {
            Setting::put('databases.products', $products);
            $this->products = $products;
        }

        return $this->status;
    }

    /**
     * Installed servers as server => product, as last seen.
     *
     * @return array<string, string>
     */
    public function products(): array
    {
        return $this->products ??= Setting::get('databases.products', []);
    }

    /**
     * The installed servers: mysql first.
     *
     * @return list<string>
     */
    public function installed(): array
    {
        return array_values(array_intersect([self::MYSQL, self::POSTGRESQL], array_keys($this->products())));
    }

    public function isInstalled(string $server): bool
    {
        return isset($this->products()[$server]);
    }

    /**
     * "MySQL", "MariaDB" or "PostgreSQL" for a server.
     */
    public function label(string $server): string
    {
        $product = $this->products()[$server] ?? $server;

        return self::PRODUCTS[$product][1] ?? ($server === self::POSTGRESQL ? 'PostgreSQL' : 'MySQL');
    }

    /**
     * The server new databases go to when none is chosen.
     */
    public function defaultServer(): ?string
    {
        return $this->installed()[0] ?? null;
    }

    /**
     * @return array{state?: string, products?: list<string>, step?: ?string, error?: ?string}
     */
    public function setup(): array
    {
        return Setting::get('databases.setup', []);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function saveSetup(array $state): void
    {
        Setting::put('databases.setup', [...$this->setup(), ...$state, 'at' => now()->toIso8601String()]);
    }

    public function managerPort(): int
    {
        return (int) Setting::get('databases.manager_port', self::DEFAULT_PORT);
    }
}
