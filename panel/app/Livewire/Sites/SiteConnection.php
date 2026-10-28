<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Database;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Services\Databases\DatabaseServers;
use App\Services\Dns\DnsSettings;
use App\Services\Files\FtpAccounts;
use App\Services\Files\SshAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Bağlantı bilgileri: what to type into an SFTP program, an application's
 * database settings or a terminal, on one card. Passwords are not kept, so
 * the card links to where a new one is set.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteConnection extends Component
{
    public int $siteId;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    public function render(FtpAccounts $ftp, SshAccess $ssh, DatabaseServers $servers, DnsSettings $dns): View
    {
        $site = Site::visibleTo(auth()->user())->with('systemUser.customer.package')->findOrFail($this->siteId);
        $account = $site->systemUser;
        $user = auth()->user();

        return view('livewire.sites.connection', [
            'site' => $site,
            'account' => $account,
            'ip' => $dns->ipv4(),
            'port' => $ftp->port(),
            'ftpAccounts' => FtpAccount::where('site_id', $site->id)->with('site')->orderBy('name')->get(),
            'databases' => Database::where('site_id', $site->id)->with('users')->orderBy('name')->get(),
            'serverLabels' => collect([DatabaseServers::MYSQL, DatabaseServers::POSTGRESQL])->mapWithKeys(fn ($s) => [$s => $servers->label($s)]),
            'ssh' => $user->allows('ssh') ? $ssh->connection($site, $account) : null,
            'sshAccess' => $account->ssh_access,
        ])->title(__('Bağlantı bilgileri · :domain · Serfex', ['domain' => $site->domain]));
    }
}
