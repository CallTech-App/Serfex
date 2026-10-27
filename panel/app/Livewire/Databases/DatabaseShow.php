<?php

namespace App\Livewire\Databases;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\LaravelApp;
use App\Services\Agent\AgentException;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One database: what it is, how to connect, who may use it, and deleting
 * it with Undo.
 */
#[Layout('components.layouts.app')]
#[ForCustomers(except: ['setupServer'])]
class DatabaseShow extends Component
{
    use ChecksDatabaseServers, EditsDatabaseUsers;

    #[Locked]
    public int $databaseId;

    // "Kullanıcı ekle"
    public bool $adding = false;

    /** new or existing */
    public string $addMode = 'new';

    public string $newUserSuffix = '';

    public string $newUserPassword = '';

    public ?int $existingUser = null;

    public string $addAccess = 'all';

    // "Veritabanını sil"
    public bool $deleting = false;

    public string $confirmName = '';

    public bool $backup = true;

    public function mount(int $database, DatabaseServers $servers): void
    {
        $this->databaseId = Database::withTrashed()->visibleTo(auth()->user())->findOrFail($database)->id;
        $this->refreshServer($servers);
    }

    public function openAdd(): void
    {
        $this->resetValidation();
        $this->addMode = 'new';
        $this->newUserSuffix = '';
        $this->newUserPassword = Databases::password();
        $this->existingUser = $this->candidates($this->database())->first()?->id;
        $this->addAccess = 'all';
        $this->adding = true;
    }

    public function add(Databases $databases): void
    {
        $database = $this->database();
        abort_if($database->trashed() || $database->systemUser === null, 403);
        $prefix = Databases::prefix($database->systemUser);
        $this->newUserSuffix = trim($this->newUserSuffix);
        $this->validate($this->addMode === 'new' ? [
            'newUserSuffix' => ['required', 'regex:'.Databases::NAME_PATTERN, 'max:'.(Databases::MAX_USER - strlen($prefix)),
                fn ($a, $v, $fail) => DatabaseUser::withTrashed()->where('server', $database->server)->where('name', $prefix.$v)->exists() ? $fail(__('Bu adda bir kullanıcı zaten var.')) : null],
            'newUserPassword' => ['required', 'string', 'min:12', 'max:128', 'regex:/^\S+$/'],
            'addAccess' => ['required', Rule::in(array_keys(DatabaseUser::ACCESS))],
        ] : [
            'existingUser' => ['required', Rule::in($this->candidates($database)->pluck('id')->all())],
            'addAccess' => ['required', Rule::in(array_keys(DatabaseUser::ACCESS))],
        ], [
            'newUserSuffix.regex' => __('Yalnızca küçük harf, rakam ve _ kullanın.'),
            'newUserSuffix.max' => __('Kullanıcı adı en fazla :max karakter olabilir.'),
            'newUserPassword.min' => __('Parola en az 12 karakter olmalı.'),
            'newUserPassword.regex' => __('Parola boşluk içeremez.'),
            'existingUser.required' => __('Bir kullanıcı seçin.'),
        ], ['newUserSuffix' => __('kullanıcı adı'), 'newUserPassword' => 'parola']);

        try {
            if ($this->addMode === 'new') {
                $user = $databases->createUser($database->systemUser, $database->server, $prefix.$this->newUserSuffix, $this->newUserPassword, [], [$database->id => $this->addAccess]);
                $this->credentials = $this->credentialsFor($user->name, $this->newUserPassword, $database->server, $database->name);
            } else {
                $user = DatabaseUser::findOrFail($this->existingUser);
                $databases->grant($database, $user, $this->addAccess);
            }
        } catch (AgentException $e) {
            $this->addError($this->addMode === 'new' ? 'newUserSuffix' : 'existingUser', __('Sunucu değişikliği kabul etmedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->adding = false;
        $this->dispatch('toast', message: __(':name bu veritabanını kullanabilir.', ['name' => $user->name]));
    }

    public function setAccess(int $userId, string $access, Databases $databases): void
    {
        abort_unless(isset(DatabaseUser::ACCESS[$access]), 422);
        $database = $this->database();
        $user = $database->users()->whereKey($userId)->firstOrFail();
        $before = $user->pivot->access;
        try {
            $databases->grant($database, $user, $access);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Yetki değiştirilemedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->dispatch('toast',
            message: "{$user->name}: ".DatabaseUser::accessLabel($access).'.',
            source: $this->getId(),
            undo: ['method' => 'setAccess', 'args' => [$user->id, $before]],
        );
    }

    public function revoke(int $userId, Databases $databases): void
    {
        $database = $this->database();
        $user = $database->users()->whereKey($userId)->firstOrFail();
        $before = $user->pivot->access;
        try {
            $databases->revoke($database, $user);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Erişim kaldırılamadı: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->dispatch('toast',
            message: __(':name artık bu veritabanına bağlanamaz.', ['name' => $user->name]),
            source: $this->getId(),
            undo: ['method' => 'regrant', 'args' => [$user->id, $before]],
        );
    }

    public function regrant(int $userId, string $access, Databases $databases): void
    {
        $user = DatabaseUser::visibleTo(auth()->user())->findOrFail($userId);
        abort_unless(isset(DatabaseUser::ACCESS[$access]), 422);
        $databases->grant($this->database(), $user, $access);
    }

    public function openDelete(): void
    {
        $this->reset('confirmName');
        $this->backup = true;
        $this->resetValidation();
        $this->deleting = true;
    }

    public function delete(Databases $databases): void
    {
        $database = $this->database();
        if ($this->confirmName !== $database->name) {
            $this->addError('confirmName', __('Veritabanı adını tam olarak yazın.'));

            return;
        }
        $databases->delete($database, $this->backup);
        $this->deleting = false;
        $this->dispatch('toast',
            message: __(':name silindi.', ['name' => $database->name]),
            source: $this->getId(),
            undo: ['method' => 'undoDelete', 'args' => []],
        );
    }

    public function undoDelete(Databases $databases): void
    {
        $database = $this->database();
        if ($database->trashed()) {
            $databases->restore($database);
            $this->dispatch('toast', message: __(':name geri geldi; kullanıcıların erişimi yerinde.', ['name' => $database->name]));
        }
    }

    public function render(DatabaseServers $servers): View
    {
        $database = $this->database();
        $database->load(['site', 'systemUser.customer', 'users']);

        return view('livewire.databases.show', [
            'database' => $database,
            'serverLabel' => $servers->label($database->server),
            'apps' => LaravelApp::where('database_id', $database->id)->with('site')->get(),
            'candidates' => $this->candidates($database),
            'prefix' => $database->systemUser ? Databases::prefix($database->systemUser) : '',
            ...$this->userDialogData(),
        ])->title(__(':name · Veritabanları · Serfex', ['name' => $database->name]));
    }

    private function database(): Database
    {
        return Database::withTrashed()->visibleTo(auth()->user())->findOrFail($this->databaseId);
    }

    /**
     * Users of the same account and server without access yet.
     *
     * @return Collection<int, DatabaseUser>
     */
    private function candidates(Database $database): Collection
    {
        return DatabaseUser::where('server', $database->server)
            ->where('system_user_id', $database->system_user_id)
            ->whereDoesntHave('databases', fn ($q) => $q->whereKey($database->id))
            ->orderBy('name')->get();
    }
}
