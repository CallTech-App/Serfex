<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\ProtectedDirectory;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Security\WebAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Parola korumalı klasörler: folders of a site that ask visitors for a
 * user name and password before anything inside is served. Passwords are
 * kept only as hashes; removing a folder's protection has Undo.
 */
#[Layout('components.layouts.app')]
#[ForCustomers(feature: 'security')]
class SiteProtect extends Component
{
    public int $siteId;

    public bool $editing = false;

    public ?int $editId = null;

    public string $path = '';

    public string $realm = '';

    /** @var list<array{name: string, password: string}> an empty password keeps the old one */
    public array $logins = [];

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->findOrFail($this->siteId);
    }

    private function dir(int $id): ProtectedDirectory
    {
        return ProtectedDirectory::where('site_id', $this->siteId)->findOrFail($id);
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->editId = null;
        $this->path = '';
        $this->realm = '';
        $this->logins = [['name' => '', 'password' => '']];
        $this->editing = true;
    }

    public function openEdit(int $id): void
    {
        $dir = $this->dir($id);
        $this->resetValidation();
        $this->editId = $dir->id;
        $this->path = $dir->path;
        $this->realm = (string) $dir->realm;
        $this->logins = array_map(fn ($n) => ['name' => $n, 'password' => ''], $dir->userNames());
        $this->editing = true;
    }

    public function addLogin(): void
    {
        if (count($this->logins) < WebAccess::MAX_LOGINS) {
            $this->logins[] = ['name' => '', 'password' => ''];
        }
    }

    public function removeLogin(int $i): void
    {
        unset($this->logins[$i]);
        $this->logins = array_values($this->logins);
    }

    public function save(WebAccess $access): void
    {
        $this->resetValidation();
        $site = $this->site();
        $dir = $this->editId ? $this->dir($this->editId) : null;
        $path = WebAccess::folder($this->path);
        $this->realm = trim($this->realm);
        $logins = array_values(array_filter(array_map(fn ($l) => ['name' => trim((string) $l['name']), 'password' => (string) $l['password']], $this->logins), fn ($l) => $l['name'] !== '' || $l['password'] !== ''));
        $problems = $access->problems($site, $path, $this->realm, $logins, $dir);
        if ($problems !== []) {
            foreach ($problems as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }
        try {
            $dir = $access->protect($site, $path, $this->realm, $logins, $dir);
        } catch (AgentException $e) {
            $this->addError('path', WebAccess::message($e));

            return;
        }
        $this->editing = false;
        $this->logins = [];
        $this->dispatch('toast', message: __(':path artık parola soruyor.', ['path' => $dir->path]));
    }

    public function delete(int $id, WebAccess $access): void
    {
        $dir = $this->dir($id);
        try {
            $access->unprotect($dir);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: WebAccess::message($e));

            return;
        }
        $this->dispatch('toast', message: __(':path artık parola sormuyor.', ['path' => $dir->path]), source: $this->getId(), undo: ['method' => 'restore', 'args' => [$dir->id]]);
    }

    public function restore(int $id, WebAccess $access): void
    {
        $dir = ProtectedDirectory::onlyTrashed()->where('site_id', $this->siteId)->find($id);
        if ($dir === null) {
            return;
        }
        try {
            $access->restore($dir);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: WebAccess::message($e));
        }
    }

    public function render(): View
    {
        $site = $this->site();

        return view('livewire.sites.protect', [
            'site' => $site,
            'dirs' => ProtectedDirectory::where('site_id', $site->id)->orderBy('path')->get(),
        ])->title(__('Parola korumalı klasörler · :domain · Servik', ['domain' => $site->domain]));
    }
}
