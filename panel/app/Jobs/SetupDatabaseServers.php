<?php

namespace App\Jobs;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\DatabaseServers;
use App\Services\Settings\PanelSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Installs (or repairs) database servers through the agent one by one,
 * then the web database manager for them. A server the agent installed
 * in a failed step is removed again by the agent; the ones before it
 * stay.
 *
 * Progress is kept in the "databases.setup" setting (see
 * DatabaseServers): the step running, then ready or failed with the
 * error.
 */
class SetupDatabaseServers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Installing packages can take a while on a slow mirror. */
    public int $timeout = 1800;

    /**
     * @param  list<string>  $products  mysql, mariadb and/or postgresql
     * @param  array<string, string>  $versions  product => the major version a new server gets (none: the agent's default)
     */
    public function __construct(public array $products, public array $versions = []) {}

    public function handle(AgentClient $agent, DatabaseServers $servers): void
    {
        $servers->saveSetup(['state' => 'installing', 'products' => $this->products, 'error' => null]);
        try {
            foreach ($this->products as $product) {
                $servers->saveSetup(['step' => $product]);
                $agent->call('db.server.install', ['product' => $product, ...(isset($this->versions[$product]) ? ['version' => $this->versions[$product]] : [])], 840);
            }
            $servers->saveSetup(['step' => 'manager']);
            $agent->call('db.manager.setup', app(PanelSettings::class)->managerSetupParams(), 840);
        } catch (AgentException $e) {
            $servers->status();
            $servers->saveSetup(['state' => 'failed', 'error' => $e->reason]);

            return;
        }
        // Records which products are installed now.
        $servers->status();
        $servers->saveSetup(['state' => 'ready', 'step' => null, 'error' => null, 'setup_at' => now()->toIso8601String()]);
    }
}
