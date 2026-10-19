<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Files\FileManager;
use App\Services\Files\FtpAccounts;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * FTP hesapları: a site's SFTP accounts, each locked in one folder of the
 * site. A new password is shown once; deleting has a 10-minute Undo.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteFtp extends Component
{
    public int $siteId;

    public bool $editing = false;

    public ?int $editId = null;

    public string $suffix = '';

    public string $directory = '';

    public string $note = '';

    public bool $changePassword = false;

    public string $password = '';

    /** @var array<string, string>|null shown once after a create or password change */
    public ?array $credentials = null;

    public ?int $deletingId = null;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'laravelApp')->findOrFail($this->siteId);
    }

    private function account(int $id): FtpAccount
    {
        return FtpAccount::visibleTo(auth()->user())->where('site_id', $this->siteId)->findOrFail($id);
    }

    public function openCreate(FtpAccounts $accounts): void
    {
        $site = $this->site();
        if ($refusal = $accounts->refusal($site->systemUser)) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $this->resetValidation();
        $this->editId = null;
        $this->suffix = '';
        // Laravel deploys into releases/ next to public_html: the whole
        // site folder; anything else lives in public_html.
        $this->directory = $site->laravelApp ? '' : 'public_html';
        $this->note = '';
        $this->changePassword = true;
        $this->password = FtpAccounts::password();
        $this->editing = true;
    }

    public function openEdit(int $id): void
    {
        $account = $this->account($id);
        $this->resetValidation();
        $this->editId = $account->id;
        $this->suffix = substr($account->name, strlen(FtpAccounts::prefix($account->systemUser)));
        $this->directory = $account->directory;
        $this->note = (string) $account->note;
        $this->changePassword = false;
        $this->password = FtpAccounts::password();
        $this->editing = true;
    }

    public function newPassword(): void
    {
        $this->password = FtpAccounts::password();
    }

    public function save(FtpAccounts $accounts): void
    {
        $site = $this->site();
        $this->directory = trim(trim($this->directory), '/');
        $this->note = trim($this->note);
        $account = $this->editId ? $this->account($this->editId) : null;
        $name = $account?->name ?? FtpAccounts::prefix($site->systemUser).trim($this->suffix);
        $problems = $accounts->problems($site, $name, $this->directory, $account?->id);
        if ($this->changePassword && (strlen($this->password) < 12 || strlen($this->password) > 128 || preg_match('/[\s:]/', $this->password))) {
            $problems['password'] = 'Parola 12-128 karakter olmalı; boşluk ve iki nokta içeremez.';
        }
        if (mb_strlen($this->note) > 100) {
            $problems['note'] = 'Not en fazla 100 karakter olabilir.';
        }
        if ($problems !== []) {
            $this->resetValidation();
            foreach ($problems as $field => $message) {
                $this->addError($field === 'name' ? 'suffix' : $field, $message);
            }

            return;
        }
        if ($account === null && ($refusal = $accounts->refusal($site->systemUser))) {
            $this->addError('suffix', $refusal);

            return;
        }

        $password = $this->changePassword ? $this->password : null;
        try {
            if ($account === null) {
                $account = $accounts->create($site, $name, $this->directory, $password, $this->note ?: null);
            } else {
                $accounts->update($account, $this->directory, $this->note ?: null, $password);
            }
        } catch (AgentException $e) {
            $this->addError('suffix', FileManager::message($e));

            return;
        }
        $this->editing = false;
        if ($password !== null) {
            $this->credentials = [
                'Protokol' => 'SFTP',
                'Sunucu' => $site->domain,
                'Port' => (string) $accounts->port(),
                'Kullanıcı' => $account->name,
                'Parola' => $password,
                'Klasör' => $account->remoteFolder(),
            ];
        } else {
            $this->dispatch('toast', message: $account->name.' kaydedildi.');
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $this->account($id)->id;
    }

    public function delete(FtpAccounts $accounts): void
    {
        if ($this->deletingId === null) {
            return;
        }
        $account = $this->account($this->deletingId);
        $this->deletingId = null;
        try {
            $accounts->delete($account);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: FileManager::message($e));

            return;
        }
        $this->dispatch('toast', message: $account->name.' silindi; artık bağlanamaz.', source: $this->getId(), undo: ['method' => 'restore', 'args' => [$account->id]]);
    }

    public function restore(FtpAccounts $accounts, int $id): void
    {
        $account = FtpAccount::onlyTrashed()->visibleTo(auth()->user())->where('site_id', $this->siteId)->find($id);
        if ($account === null) {
            return;
        }
        try {
            $accounts->restore($account);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: FileManager::message($e));

            return;
        }
        $this->dispatch('toast', message: $account->name.' geri alındı.');
    }

    public function render(FtpAccounts $accounts): View
    {
        $site = $this->site();
        $customer = $site->customer();
        $usage = $customer ? app(Quotas::class)->report($customer)['ftp_accounts'] : null;

        return view('livewire.sites.ftp', [
            'site' => $site,
            'accounts' => FtpAccount::where('site_id', $site->id)->orderBy('name')->get(),
            'prefix' => FtpAccounts::prefix($site->systemUser),
            'root' => FileManager::root($site),
            'port' => $accounts->port(),
            'usage' => $usage,
            'refusal' => $accounts->refusal($site->systemUser),
        ])->title('FTP hesapları · '.$site->domain.' · Servik');
    }
}
