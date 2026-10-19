<?php

namespace App\Livewire\Databases;

use App\Services\Databases\DatabaseServers;
use App\Services\Settings\PanelSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The database servers (administrators only): which are installed and
 * running, adding PostgreSQL next to MySQL/MariaDB and repairing. The web
 * database manager shares the panel's port (Araçlar ve Ayarlar → Genel).
 */
#[Layout('components.layouts.app')]
#[Title('Veritabanı sunucuları · Servik')]
class DatabaseSettingsPage extends Component
{
    use ChecksDatabaseServers;

    public int $port = DatabaseServers::DEFAULT_PORT;

    public function mount(DatabaseServers $servers): void
    {
        $this->refreshServer($servers);
        $this->port = app(PanelSettings::class)->port();
    }

    /**
     * Installs PostgreSQL next to the MySQL-compatible server.
     */
    public function addPostgres(DatabaseServers $servers): void
    {
        if (! $servers->isInstalled(DatabaseServers::POSTGRESQL)) {
            $this->startSetup($servers, [...array_values($servers->products()), 'postgresql']);
        }
    }

    public function render(DatabaseServers $servers): View
    {
        $status = $servers->status() ?? [];

        return view('livewire.databases.settings', [
            'servers' => $status['servers'] ?? [],
            'manager' => $status['manager'] ?? [],
            'installed' => $servers->products(),
        ]);
    }
}
