<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Services\Settings\Administrators;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Araçlar ve Ayarlar → Yöneticiler: who runs the server with which role.
 * Full administrators do everything; Destek works on hosting and the
 * server but not on Araçlar ve Ayarlar; Salt okunur only looks.
 */
#[Layout('components.layouts.app')]
#[Title('Yöneticiler · Serfex')]
class AdminIndex extends Component
{
    public bool $inviting = false;

    public string $email = '';

    public string $name = '';

    public string $role = 'support';

    public ?int $editId = null;

    public string $editRole = 'support';

    public ?int $removingId = null;

    /** @var array{link: string, email: string, mailed: bool}|null shown once */
    public ?array $invitation = null;

    public function openInvite(): void
    {
        $this->resetValidation();
        $this->email = $this->name = '';
        $this->role = 'support';
        $this->inviting = true;
    }

    public function invite(Administrators $admins): void
    {
        $this->email = mb_strtolower(trim($this->email));
        $this->name = trim($this->name);
        $this->validate([
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
        ], ['email.unique' => __('Bu e-posta ile bir hesap zaten var.')], ['email' => 'e-posta', 'name' => 'ad', 'role' => 'rol']);
        [$user, $link, $mailed] = $admins->invite($this->email, $this->name, $this->role, auth()->user());
        $this->inviting = false;
        $this->invitation = ['link' => $link, 'email' => $user->email, 'mailed' => $mailed];
        $this->dispatch('toast', message: __(':email yönetici olarak davet edildi.', ['email' => $user->email]), source: $this->getId(), undo: ['method' => 'cancelInvite', 'args' => [$user->id]]);
    }

    /**
     * Undo of an invitation: the account goes before anyone used it.
     */
    public function cancelInvite(int $id): void
    {
        $user = User::where('is_admin', true)->whereNotNull('invite_token')->whereKeyNot(auth()->id())->find($id);
        if ($user === null) {
            return;
        }
        $user->delete();
        $this->dispatch('toast', message: __(':email daveti geri alındı.', ['email' => $user->email]));
    }

    public function reinvite(Administrators $admins, int $id): void
    {
        $user = $this->admin($id);
        if (! $user->isInvited()) {
            return;
        }
        $this->invitation = ['link' => $admins->reinvite($user), 'email' => $user->email, 'mailed' => false];
    }

    public function openEdit(int $id): void
    {
        $user = $this->admin($id);
        $this->resetValidation();
        $this->editId = $user->id;
        $this->editRole = $user->adminRole();
    }

    public function saveRole(Administrators $admins): void
    {
        if ($this->editId === null) {
            return;
        }
        $user = $this->admin($this->editId);
        if ($refusal = $admins->refusal(auth()->user(), $user, $this->editRole)) {
            $this->addError('editRole', $refusal);

            return;
        }
        $previous = $user->adminRole();
        $admins->changeRole($user, $this->editRole);
        $this->editId = null;
        $this->dispatch('toast', message: __(':name artık :roles.', ['name' => $user->name, 'roles' => User::ROLES[$this->editRole][0]]), source: $this->getId(), undo: ['method' => 'undoRole', 'args' => [$user->id, $previous]]);
    }

    public function undoRole(Administrators $admins, int $id, string $role): void
    {
        $user = $this->admin($id);
        if ($admins->refusal(auth()->user(), $user, $role) !== null) {
            return;
        }
        $admins->changeRole($user, $role);
        $this->dispatch('toast', message: __(':name yeniden :roles.', ['name' => $user->name, 'roles' => User::ROLES[$role][0]]));
    }

    public function confirmRemove(int $id): void
    {
        $this->removingId = $this->admin($id)->id;
    }

    public function remove(Administrators $admins): void
    {
        if ($this->removingId === null) {
            return;
        }
        $user = $this->admin($this->removingId);
        $this->removingId = null;
        if ($refusal = $admins->refusal(auth()->user(), $user, null)) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $admins->remove($user);
        $this->dispatch('toast', message: __(':name kaldırıldı; oturumları kapandı.', ['name' => $user->name]), source: $this->getId(), undo: ['method' => 'restore', 'args' => [$user->id]]);
    }

    public function restore(Administrators $admins, int $id): void
    {
        $user = User::where('is_admin', true)->whereNotNull('removed_at')->find($id);
        if ($user === null) {
            return;
        }
        $admins->restore($user);
        $this->dispatch('toast', message: __(':name geri alındı.', ['name' => $user->name]));
    }

    public function resetTwoFactor(Administrators $admins, int $id): void
    {
        $user = $this->admin($id);
        if ($user->id === auth()->id() || ! $user->hasTwoFactor()) {
            return;
        }
        $token = $admins->resetTwoFactor($user);
        $this->dispatch('toast', message: __(':name için iki adımlı doğrulama kapatıldı; bir sonraki girişte yeniden kurabilir.', ['name' => $user->name]), source: $this->getId(), undo: ['method' => 'undoTwoFactor', 'args' => [$token]]);
    }

    public function undoTwoFactor(Administrators $admins, string $token): void
    {
        if ($user = $admins->undoResetTwoFactor($token)) {
            $this->dispatch('toast', message: __(':name için iki adımlı doğrulama yeniden açıldı.', ['name' => $user->name]));
        }
    }

    public function render(): View
    {
        return view('livewire.settings.admins', [
            'admins' => User::where('is_admin', true)->whereNull('removed_at')->orderByRaw("case coalesce(role, 'full') when 'full' then 0 when 'support' then 1 else 2 end")->orderBy('name')->get(),
        ]);
    }

    private function admin(int $id): User
    {
        return User::where('is_admin', true)->whereNull('removed_at')->findOrFail($id);
    }
}
