<?php

namespace App\Livewire\Databases;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every customer database on the server (or the customer's own), and
 * their users, on two tabs. A site's page links here filtered to it.
 */
#[Layout('components.layouts.app')]
#[Title('Veritabanları · Servik')]
#[ForCustomers(except: ['setupServer'])]
class DatabaseIndex extends Component
{
    use ChecksDatabaseServers, EditsDatabaseUsers, WithPagination;

    #[Url(as: 'sekme', except: 'veritabanlari')]
    public string $tab = 'veritabanlari';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Only the databases of one site. */
    #[Url(as: 'site', except: 0)]
    public int $siteId = 0;

    // "Veritabanı oluştur"
    public bool $creating = false;

    public ?int $formSite = null;

    public string $formServer = '';

    public string $dbSuffix = '';

    public bool $withUser = true;

    public string $userSuffix = '';

    public string $password = '';

    public string $access = 'all';

    // "Kullanıcı ekle"
    public bool $addingUser = false;

    public ?int $userDatabase = null;

    public string $newUserSuffix = '';

    public string $newUserPassword = '';

    public string $newUserAccess = 'all';

    public function mount(DatabaseServers $servers): void
    {
        $this->refreshServer($servers);
        if ($this->siteId !== 0 && Site::visibleTo(auth()->user())->whereKey($this->siteId)->doesntExist()) {
            $this->siteId = 0;
        }
        if (request()->query('olustur') !== null && $this->server['state'] === 'ready') {
            $this->openCreate($servers);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function openCreate(DatabaseServers $servers): void
    {
        $this->resetValidation();
        $sites = $this->sites();
        $this->formSite = $this->siteId !== 0 ? $this->siteId : $sites->first()?->id;
        $this->formServer = (string) $servers->defaultServer();
        $this->dbSuffix = '';
        $this->withUser = true;
        $this->userSuffix = '';
        $this->password = Databases::password();
        $this->access = 'all';
        $this->creating = true;
    }

    public function create(Databases $databases, DatabaseServers $servers): void
    {
        $site = Site::visibleTo(auth()->user())->with('systemUser.customer.package')->find($this->formSite);
        $prefix = $site ? Databases::prefix($site->systemUser) : '';
        $this->dbSuffix = trim($this->dbSuffix);
        $this->userSuffix = trim($this->userSuffix);
        $this->validate([
            'formSite' => ['required', fn ($a, $v, $fail) => $site === null ? $fail('Bir site seçin.') : null],
            'formServer' => ['required', Rule::in($servers->installed())],
            'dbSuffix' => ['required', 'regex:'.Databases::NAME_PATTERN, 'max:'.(Databases::MAX_DATABASE - strlen($prefix)),
                fn ($a, $v, $fail) => Database::withTrashed()->where('server', $this->formServer)->where('name', $prefix.$v)->exists() ? $fail('Bu adda bir veritabanı zaten var.') : null],
            'userSuffix' => $this->withUser ? ['required', 'regex:'.Databases::NAME_PATTERN, 'max:'.(Databases::MAX_USER - strlen($prefix)),
                fn ($a, $v, $fail) => DatabaseUser::withTrashed()->where('server', $this->formServer)->where('name', $prefix.$v)->exists() ? $fail('Bu adda bir kullanıcı zaten var.') : null] : [],
            'password' => $this->withUser ? ['required', 'string', 'min:12', 'max:128', 'regex:/^\S+$/'] : [],
            'access' => [Rule::in(array_keys(DatabaseUser::ACCESS))],
        ], [
            'dbSuffix.regex' => 'Yalnızca küçük harf, rakam ve _ kullanın.',
            'userSuffix.regex' => 'Yalnızca küçük harf, rakam ve _ kullanın.',
            'dbSuffix.max' => 'Ad en fazla :max karakter olabilir.',
            'userSuffix.max' => 'Kullanıcı adı en fazla :max karakter olabilir.',
            'password.min' => 'Parola en az 12 karakter olmalı.',
            'password.regex' => 'Parola boşluk içeremez.',
        ], ['dbSuffix' => 'veritabanı adı', 'userSuffix' => 'kullanıcı adı', 'password' => 'parola', 'formServer' => 'sunucu']);

        if ($refusal = $databases->refusal($site->systemUser)) {
            $this->addError('dbSuffix', $refusal);

            return;
        }
        try {
            $database = $databases->create($site, $this->formServer, $prefix.$this->dbSuffix,
                $this->withUser ? $prefix.$this->userSuffix : null, $this->password, $this->access);
        } catch (AgentException $e) {
            $this->addError('dbSuffix', 'Veritabanı oluşturulamadı: '.$e->reason);

            return;
        }
        $this->creating = false;
        if ($this->withUser) {
            $this->credentials = $this->credentialsFor($prefix.$this->userSuffix, $this->password, $database->server, $database->name);
        }
        $this->dispatch('toast', message: $this->withUser
            ? "{$database->name} veritabanı ve {$prefix}{$this->userSuffix} kullanıcısı oluşturuldu."
            : "{$database->name} veritabanı oluşturuldu.");
    }

    public function openAddUser(): void
    {
        $this->resetValidation();
        $this->userDatabase = $this->visibleDatabases()->first()?->id;
        $this->newUserSuffix = '';
        $this->newUserPassword = Databases::password();
        $this->newUserAccess = 'all';
        $this->addingUser = true;
    }

    public function addUser(Databases $databases): void
    {
        $database = Database::visibleTo(auth()->user())->with('systemUser')->find($this->userDatabase);
        $prefix = $database?->systemUser ? Databases::prefix($database->systemUser) : '';
        $this->newUserSuffix = trim($this->newUserSuffix);
        $this->validate([
            'userDatabase' => ['required', fn ($a, $v, $fail) => $database?->systemUser === null ? $fail('Bir veritabanı seçin.') : null],
            'newUserSuffix' => ['required', 'regex:'.Databases::NAME_PATTERN, 'max:'.(Databases::MAX_USER - strlen($prefix)),
                fn ($a, $v, $fail) => DatabaseUser::withTrashed()->where('server', $database?->server)->where('name', $prefix.$v)->exists() ? $fail('Bu adda bir kullanıcı zaten var.') : null],
            'newUserPassword' => ['required', 'string', 'min:12', 'max:128', 'regex:/^\S+$/'],
            'newUserAccess' => ['required', Rule::in(array_keys(DatabaseUser::ACCESS))],
        ], [
            'newUserSuffix.regex' => 'Yalnızca küçük harf, rakam ve _ kullanın.',
            'newUserSuffix.max' => 'Kullanıcı adı en fazla :max karakter olabilir.',
            'newUserPassword.min' => 'Parola en az 12 karakter olmalı.',
            'newUserPassword.regex' => 'Parola boşluk içeremez.',
        ], ['newUserSuffix' => 'kullanıcı adı', 'newUserPassword' => 'parola']);

        try {
            $user = $databases->createUser($database->systemUser, $database->server, $prefix.$this->newUserSuffix, $this->newUserPassword, [], [$database->id => $this->newUserAccess]);
        } catch (AgentException $e) {
            $this->addError('newUserSuffix', 'Kullanıcı oluşturulamadı: '.$e->reason);

            return;
        }
        $this->addingUser = false;
        $this->credentials = $this->credentialsFor($user->name, $this->newUserPassword, $user->server, $database->name);
        $this->dispatch('toast', message: "{$user->name} kullanıcısı eklendi.");
    }

    public function render(DatabaseServers $servers): View
    {
        $user = auth()->user();
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%';
        $databases = Database::visibleTo($user)
            ->with(['site', 'users', 'systemUser.customer'])
            ->when($this->siteId !== 0, fn ($q) => $q->where('site_id', $this->siteId))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhereHas('site', fn ($s) => $s->where('domain', 'like', $term))))
            ->orderBy('name')
            ->paginate(25, pageName: 'vt');
        $users = DatabaseUser::visibleTo($user)
            ->with('databases')
            ->when($this->siteId !== 0, fn ($q) => $q->whereHas('databases', fn ($d) => $d->where('site_id', $this->siteId)))
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', $term))
            ->orderBy('name')
            ->paginate(25, pageName: 'ku');
        $all = Database::visibleTo($user)->when($this->siteId !== 0, fn ($q) => $q->where('site_id', $this->siteId));

        return view('livewire.databases.index', [
            'databases' => $databases,
            'users' => $users,
            'total' => (clone $all)->count(),
            'totalSize' => (int) (clone $all)->sum('size_bytes'),
            'sites' => $this->sites(),
            'filterSite' => $this->siteId !== 0 ? Site::find($this->siteId) : null,
            'installed' => $servers->installed(),
            'labels' => collect($servers->installed())->mapWithKeys(fn ($s) => [$s => $servers->label($s)])->all(),
            'versions' => collect($servers->status()['servers'] ?? [])->filter(fn ($s) => $s['installed'] ?? false)->map(fn ($s, $k) => $servers->label($k).' '.preg_replace('/-.*$/', '', (string) ($s['version'] ?? '')))->values()->all(),
            'prefix' => ($site = $this->sites()->firstWhere('id', $this->formSite)) ? Databases::prefix($site->systemUser) : '',
            'userPrefix' => ($db = $this->visibleDatabases()->firstWhere('id', $this->userDatabase)) && $db->systemUser ? Databases::prefix($db->systemUser) : '',
            'userDatabases' => $this->visibleDatabases(),
            ...$this->userDialogData(),
        ]);
    }

    /**
     * Sites the account may create databases for.
     *
     * @return Collection<int, Site>
     */
    private function sites(): Collection
    {
        return Site::visibleTo(auth()->user())->whereDoesntHave('preview')->with('systemUser')->orderBy('domain')->get();
    }

    /**
     * @return Collection<int, Database>
     */
    private function visibleDatabases(): Collection
    {
        return Database::visibleTo(auth()->user())->with('systemUser')->orderBy('name')->get();
    }
}
