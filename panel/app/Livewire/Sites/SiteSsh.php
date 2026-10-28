<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Models\SshKey;
use App\Models\SystemUser;
use App\Services\Agent\AgentException;
use App\Services\Files\FileManager;
use App\Services\Files\SshAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * SSH erişimi: whether the site's Linux account may sign in over SSH
 * (SFTP in its home, or a shell an administrator gives), its public keys
 * and an optional password shown once. Changes can be undone.
 */
#[Layout('components.layouts.app')]
#[ForCustomers('ssh')]
class SiteSsh extends Component
{
    public int $siteId;

    public string $access = SshAccess::OFF;

    public bool $adding = false;

    public string $keyName = '';

    public string $publicKey = '';

    public ?int $deletingId = null;

    public bool $removingPassword = false;

    /** @var array<string, string>|null shown once after a new password */
    public ?array $credentials = null;

    public function mount(int $site): void
    {
        $site = Site::visibleTo(auth()->user())->findOrFail($site);
        $this->siteId = $site->id;
        $this->access = $site->systemUser->ssh_access;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package')->findOrFail($this->siteId);
    }

    private function account(): SystemUser
    {
        return $this->site()->systemUser;
    }

    private function key(int $id, bool $trashed = false): SshKey
    {
        $query = $trashed ? SshKey::onlyTrashed() : SshKey::query();

        return $query->visibleTo(auth()->user())->where('system_user_id', $this->account()->id)->findOrFail($id);
    }

    /**
     * Saves the access chosen in the form; a shell only from an
     * administrator.
     */
    public function saveAccess(SshAccess $ssh): void
    {
        $account = $this->account();
        $allowed = auth()->user()->is_admin ? array_keys(SshAccess::LABELS) : [SshAccess::OFF, SshAccess::SFTP];
        if ($account->ssh_access === SshAccess::SHELL && $this->access === SshAccess::SHELL) {
            $allowed[] = SshAccess::SHELL;
        }
        if (! in_array($this->access, $allowed, true)) {
            $this->access = $account->ssh_access;

            return;
        }
        $before = $account->ssh_access;
        if ($before === $this->access) {
            return;
        }
        if (! $this->change(fn () => $ssh->setAccess($account, $this->access))) {
            $this->access = $before;

            return;
        }
        $message = match ($this->access) {
            SshAccess::OFF => __(':user için SSH erişimi kapatıldı.', ['user' => $account->name]),
            SshAccess::SHELL => __(':user artık SSH ile kabuğa bağlanabilir.', ['user' => $account->name]),
            default => __(':user artık SFTP ile bağlanabilir; ana klasörüne kilitli.', ['user' => $account->name]),
        };
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: ['method' => 'undoAccess', 'args' => [$before]]);
    }

    public function undoAccess(SshAccess $ssh, string $access): void
    {
        $account = $this->account();
        if (! array_key_exists($access, SshAccess::LABELS) || ($access === SshAccess::SHELL && ! auth()->user()->is_admin)) {
            return;
        }
        if ($this->change(fn () => $ssh->setAccess($account, $access))) {
            $this->access = $access;
            $this->dispatch('toast', message: __('SSH erişimi geri alındı.'));
        }
    }

    public function openAdd(): void
    {
        $this->resetValidation();
        $this->keyName = '';
        $this->publicKey = '';
        $this->adding = true;
    }

    public function addKey(SshAccess $ssh): void
    {
        if (mb_strlen(trim($this->keyName)) > 100) {
            $this->addError('keyName', __('Ad en fazla 100 karakter olabilir.'));

            return;
        }
        $account = $this->account();
        try {
            $key = $ssh->addKey($account, $this->keyName, $this->publicKey);
        } catch (AgentException $e) {
            $this->addError('publicKey', FileManager::message($e));

            return;
        }
        if (is_string($key)) {
            $this->addError('publicKey', $key);

            return;
        }
        $this->adding = false;
        $this->dispatch('toast', message: __(':name anahtarı eklendi.', ['name' => $key->name]));
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $this->key($id)->id;
    }

    public function deleteKey(SshAccess $ssh): void
    {
        if ($this->deletingId === null) {
            return;
        }
        $key = $this->key($this->deletingId);
        $this->deletingId = null;
        if ($this->change(fn () => $ssh->deleteKey($key))) {
            $this->dispatch('toast', message: __(':name anahtarı silindi; artık bağlanamaz.', ['name' => $key->name]), source: $this->getId(), undo: ['method' => 'restoreKey', 'args' => [$key->id]]);
        }
    }

    public function restoreKey(SshAccess $ssh, int $id): void
    {
        $key = SshKey::onlyTrashed()->visibleTo(auth()->user())->where('system_user_id', $this->account()->id)->find($id);
        if ($key !== null && $this->change(fn () => $ssh->restoreKey($key))) {
            $this->dispatch('toast', message: __(':name anahtarı geri alındı.', ['name' => $key->name]));
        }
    }

    public function newPassword(SshAccess $ssh): void
    {
        $site = $this->site();
        $account = $site->systemUser;
        $password = null;
        if (! $this->change(function () use ($ssh, $account, &$password) {
            $password = $ssh->setPassword($account);
        })) {
            return;
        }
        $connection = $ssh->connection($site, $account);
        $this->credentials = [
            __('Protokol') => $account->ssh_access === SshAccess::SHELL ? 'SSH / SFTP' : 'SFTP',
            __('Sunucu') => $connection['host'],
            __('Port') => (string) $connection['port'],
            __('Kullanıcı') => $account->name,
            __('Parola') => $password,
        ];
    }

    public function removePassword(SshAccess $ssh): void
    {
        $this->removingPassword = false;
        $account = $this->account();
        $at = $account->ssh_password_at?->toIso8601String();
        if ($at === null) {
            return;
        }
        if ($this->change(fn () => $ssh->setPasswordLogin($account, null))) {
            $this->dispatch('toast', message: __(':user için parolayla giriş kapatıldı.', ['user' => $account->name]), source: $this->getId(), undo: ['method' => 'restorePassword', 'args' => [$at]]);
        }
    }

    public function restorePassword(SshAccess $ssh, string $at): void
    {
        $account = $this->account();
        if ($account->ssh_password_at === null && $this->change(fn () => $ssh->setPasswordLogin($account, $at))) {
            $this->dispatch('toast', message: __('Parolayla giriş geri açıldı.'));
        }
    }

    /**
     * Runs a change on the server; a refusal shows in a toast.
     */
    private function change(callable $do): bool
    {
        $site = $this->site();
        if ($refusal = app(SshAccess::class)->refusal(auth()->user(), $site->systemUser)) {
            $this->dispatch('toast', message: $refusal);

            return false;
        }
        try {
            $do();
        } catch (AgentException $e) {
            $this->dispatch('toast', message: FileManager::message($e));

            return false;
        }

        return true;
    }

    public function render(SshAccess $ssh): View
    {
        $site = $this->site();
        $account = $site->systemUser;

        return view('livewire.sites.ssh', [
            'site' => $site,
            'account' => $account,
            'keys' => $account->sshKeys()->orderBy('name')->get(),
            'otherSites' => $account->sites()->whereKeyNot($site->id)->orderBy('domain')->pluck('domain'),
            'connection' => $ssh->connection($site, $account),
            'suspended' => (bool) $account->customer?->isSuspended(),
        ])->title(__('SSH erişimi · :domain · Serfex', ['domain' => $site->domain]));
    }
}
