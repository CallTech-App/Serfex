<?php

namespace App\Livewire\Databases;

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Services\Agent\AgentException;
use App\Services\Databases\Databases;
use App\Support\DatabaseHosts;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The database user dialog (password, where it may connect from, which
 * databases it may use) and deleting a user with Undo, for the database
 * pages.
 */
trait EditsDatabaseUsers
{
    public bool $userDialog = false;

    public ?int $editingUser = null;

    public bool $changePassword = false;

    public string $editPassword = '';

    public bool $remote = false;

    public string $hostsText = '';

    /** @var array<int|string, string> database id => access, '' for none */
    public array $editGrants = [];

    /**
     * A password to show once: user, password, server, database.
     *
     * @var array{user: string, password: string, server: string, database: ?string, port: int}|null
     */
    public ?array $credentials = null;

    /**
     * Fills a password field with a new generated password.
     */
    public function newPassword(string $field): void
    {
        if (in_array($field, ['password', 'newUserPassword', 'editPassword'], true) && property_exists($this, $field)) {
            $this->{$field} = Databases::password();
        }
    }

    public function openUser(int $id, Databases $databases): void
    {
        $user = DatabaseUser::visibleTo(auth()->user())->findOrFail($id);
        $this->resetValidation();
        $this->editingUser = $user->id;
        $this->changePassword = false;
        $this->editPassword = Databases::password();
        $this->remote = ($user->hosts ?? []) !== [];
        $this->hostsText = implode("\n", $user->hosts ?? []);
        $current = $user->databases()->get()->mapWithKeys(fn ($d) => [$d->id => $d->pivot->access]);
        $this->editGrants = $databases->grantable($user)->mapWithKeys(fn ($d) => [$d->id => $current[$d->id] ?? ''])->all();
        $this->userDialog = true;
    }

    public function saveUser(Databases $databases): void
    {
        $user = DatabaseUser::visibleTo(auth()->user())->findOrFail($this->editingUser);
        $hosts = $this->remote ? DatabaseHosts::parse($this->hostsText) : [];
        $this->validate([
            'editPassword' => $this->changePassword ? ['required', 'string', 'min:12', 'max:128', 'regex:/^\S+$/'] : [],
            'hostsText' => [function ($attr, $value, $fail) use ($hosts) {
                if ($this->remote && $hosts === []) {
                    $fail(__('En az bir adres girin ya da her yerden bağlantıya izin verin (%).'));
                }
                if (count($hosts) > DatabaseHosts::MAX) {
                    $fail(__('En fazla :max adres girilebilir.', ['max' => DatabaseHosts::MAX]));
                }
                $bad = array_filter($hosts, fn ($h) => ! DatabaseHosts::valid($h));
                if ($bad !== []) {
                    $fail(__('Geçersiz adres: :bad. IP adresi, 10.0.0.0/24 gibi bir ağ, 10.8.0.% ya da % yazın.', ['bad' => implode(', ', $bad)]));
                }
            }],
            'editGrants.*' => ['nullable', Rule::in(['', ...array_keys(DatabaseUser::ACCESS)])],
        ], [
            'editPassword.min' => __('Parola en az 12 karakter olmalı.'),
            'editPassword.regex' => __('Parola boşluk içeremez.'),
        ], ['editPassword' => 'parola']);

        $allowed = $databases->grantable($user)->pluck('id')->all();
        // Grants on databases waiting out their Undo stay for it.
        $grants = $user->databases()->onlyTrashed()->get()->mapWithKeys(fn ($d) => [$d->id => ['access' => $d->pivot->access]])->all()
            + collect($this->editGrants)->filter(fn ($a, $id) => $a !== '' && in_array((int) $id, $allowed, true))
                ->mapWithKeys(fn ($a, $id) => [(int) $id => ['access' => $a]])->all();
        $before = [$user->hosts, $user->databases()->withTrashed()->get()->mapWithKeys(fn ($d) => [$d->id => ['access' => $d->pivot->access]])->all()];

        $user->update(['hosts' => $hosts]);
        $user->databases()->sync($grants);
        try {
            $databases->apply($user, $this->changePassword ? $this->editPassword : null);
        } catch (AgentException $e) {
            $user->update(['hosts' => $before[0]]);
            $user->databases()->sync($before[1]);
            $this->addError('hostsText', __('Sunucu değişikliği kabul etmedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->userDialog = false;
        if ($this->changePassword) {
            $this->credentials = $this->credentialsFor($user->name, $this->editPassword, $user->server, null);
        }
        $this->dispatch('toast', message: __(':name kullanıcısı güncellendi.', ['name' => $user->name]));
    }

    public function deleteUser(int $id, Databases $databases): void
    {
        $user = DatabaseUser::visibleTo(auth()->user())->findOrFail($id);
        $databases->deleteUser($user);
        $this->userDialog = false;
        $this->dispatch('toast',
            message: __(':name kullanıcısı silindi; hiçbir veritabanına bağlanamaz.', ['name' => $user->name]),
            source: $this->getId(),
            undo: ['method' => 'undoDeleteUser', 'args' => [$user->id]],
        );
    }

    public function undoDeleteUser(int $id, Databases $databases): void
    {
        $user = DatabaseUser::onlyTrashed()->visibleTo(auth()->user())->find($id);
        if ($user === null) {
            return;
        }
        try {
            $databases->restoreUser($user);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Kullanıcı geri getirildi ama sunucuya uygulanamadı: :reason', ['reason' => $e->reason]));
        }
    }

    /**
     * What the user dialog shows.
     *
     * @return array{editUser: ?DatabaseUser, grantable: Collection<int, Database>}
     */
    protected function userDialogData(): array
    {
        $user = $this->userDialog && $this->editingUser ? DatabaseUser::visibleTo(auth()->user())->find($this->editingUser) : null;

        return [
            'editUser' => $user,
            'grantable' => $user ? Database::whereIn('id', array_keys($this->editGrants))->orderBy('name')->get() : collect(),
        ];
    }

    /**
     * @return array{user: string, password: string, server: string, database: ?string, port: int}
     */
    protected function credentialsFor(string $user, string $password, string $server, ?string $database): array
    {
        return [
            'user' => $user,
            'password' => $password,
            'server' => $server,
            'database' => $database,
            'port' => $server === 'postgresql' ? 5432 : 3306,
        ];
    }
}
