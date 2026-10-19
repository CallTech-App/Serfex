<?php

namespace App\Livewire\Databases;

use App\Models\Setting;
use App\Services\Databases\DatabaseServers;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The database servers (administrators only): which are installed and
 * running, adding PostgreSQL next to MySQL/MariaDB, repairing, and the
 * web database manager's port.
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
        $this->port = $servers->managerPort();
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

    public function savePort(DatabaseServers $servers): void
    {
        $this->validate([
            'port' => ['required', 'integer', 'between:1024,65535', 'not_in:3306,5432,8080,8081'],
        ], [
            'port.between' => '1024 ile 65535 arasında bir port girin.',
            'port.not_in' => 'Bu port başka bir hizmetin; başka bir port seçin.',
        ], ['port' => 'port']);
        Setting::put('databases.manager_port', $this->port);
        if ($servers->products() !== []) {
            $this->startSetup($servers, array_values($servers->products()));
        }
        $this->dispatch('toast', message: "Veritabanı yöneticisi {$this->port} portunda açılacak. Güvenlik duvarında bu portun açık olduğundan emin olun.");
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
