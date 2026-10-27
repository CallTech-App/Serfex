<?php

namespace App\Livewire\Databases;

use App\Jobs\SetupDatabaseServers;
use App\Services\Components\Components;
use App\Services\Databases\DatabaseServers;
use Illuminate\Validation\Rule;

/**
 * The database servers' state for the database pages, and the setup form.
 */
trait ChecksDatabaseServers
{
    /**
     * State is one of ready, missing, installing, failed, stopped or
     * offline; step names what is being installed.
     *
     * @var array{state: string, step?: ?string, error?: ?string, stopped?: list<string>}
     */
    public array $server = ['state' => 'ready'];

    /** Setup form: mysql or mariadb. */
    public string $setupProduct = 'mysql';

    public bool $setupPostgres = false;

    /** @var array<string, string> product => the major version chosen (none: the default) */
    public array $setupVersions = [];

    /**
     * Polled while a server is being installed.
     */
    public function refreshServer(DatabaseServers $servers): void
    {
        $setup = $servers->setup();
        if (($setup['state'] ?? null) === 'installing') {
            $this->server = ['state' => 'installing', 'step' => $setup['step'] ?? null];

            return;
        }
        $status = $servers->status($error);
        if ($status === null) {
            $this->server = ['state' => 'offline', 'error' => $error];

            return;
        }
        $installed = collect($status['servers'] ?? [])->filter(fn ($s) => $s['installed'] ?? false);
        $stopped = $installed->reject(fn ($s) => $s['running'] ?? false);
        $this->server = match (true) {
            // A first setup that failed offers the choice again.
            $installed->isEmpty() => ['state' => 'missing', 'error' => ($setup['state'] ?? null) === 'failed' ? ($setup['error'] ?? null) : null],
            ($setup['state'] ?? null) === 'failed' => ['state' => 'failed', 'error' => $setup['error'] ?? null],
            $stopped->isNotEmpty() => ['state' => 'stopped', 'stopped' => $stopped->map(fn ($s) => DatabaseServers::PRODUCTS[$s['product']][1] ?? $s['product'])->values()->all(), 'error' => $stopped->first()['error'] ?? null],
            ! ($status['manager']['configured'] ?? false) => ['state' => 'nomanager'],
            default => ['state' => 'ready'],
        };
    }

    /**
     * Installs the chosen servers (or repairs the installed ones) in the
     * background.
     */
    public function setupServer(DatabaseServers $servers): void
    {
        $installed = $servers->products();
        if ($installed === []) {
            $this->validate([
                'setupProduct' => ['required', Rule::in(['mysql', 'mariadb'])],
                'setupPostgres' => ['boolean'],
            ]);
            $products = [$this->setupProduct, ...($this->setupPostgres ? ['postgresql'] : [])];
            $versions = [];
            foreach ($products as $product) {
                $major = $this->setupVersions[$product] ?? null;
                if ($major === null || $major === '') {
                    continue;
                }
                $offered = array_column(app(Components::class)->candidate($product)['choices'] ?? [], 'major');
                if (! in_array($major, $offered, true)) {
                    $this->addError('setupVersions', __(':name için bu sürüm sunulmuyor.', ['name' => DatabaseServers::PRODUCTS[$product][1] ?? $product]));

                    return;
                }
                $versions[$product] = $major;
            }
        } else {
            $products = array_values($installed);
            $versions = [];
        }
        $this->startSetup($servers, $products, $versions);
    }

    /**
     * @param  list<string>  $products
     * @param  array<string, string>  $versions  product => major version for a new server
     */
    protected function startSetup(DatabaseServers $servers, array $products, array $versions = []): void
    {
        $servers->saveSetup(['state' => 'installing', 'products' => $products, 'step' => $products[0] ?? 'manager', 'error' => null]);
        $this->server = ['state' => 'installing', 'step' => $products[0] ?? 'manager'];
        SetupDatabaseServers::dispatch($products, $versions);
    }
}
