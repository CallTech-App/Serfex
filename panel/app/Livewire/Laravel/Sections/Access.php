<?php

namespace App\Livewire\Laravel\Sections;

use App\Http\Controllers\Auth\InvitationController;
use App\Models\LaravelApiToken;
use App\Models\LaravelAppMember;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Lazy;

/**
 * Who can work on the application, with which role, and the API tokens
 * CI and the command line deploy with.
 */
#[Lazy]
class Access extends Section
{
    protected const MANAGE_METHODS = ['openAdd', 'add', 'setRole', 'remove', 'restoreMember', 'openToken', 'createToken', 'revoke', 'unrevoke', 'newInvite'];

    /** Minutes a revoked token can still be brought back. */
    public const UNDO_MINUTES = 10;

    public bool $adding = false;

    public string $email = '';

    public string $name = '';

    public string $role = 'developer';

    public bool $tokenForm = false;

    public string $tokenName = 'GitHub Actions';

    /** Shown once: a new token or invitation link. */
    public ?string $secret = null;

    public ?string $secretKind = null;

    public function openAdd(): void
    {
        $this->reset('email', 'name', 'role');
        $this->resetValidation();
        $this->adding = true;
    }

    public function add(): void
    {
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::in(array_keys(LaravelAppMember::ROLES))],
        ], [], ['email' => 'e-posta', 'name' => 'ad', 'role' => 'rol']);
        $app = $this->app();
        $user = User::where('email', $this->email)->first();
        if ($user?->is_admin) {
            $this->addError('email', 'Bu kişi sunucu yöneticisi; tüm uygulamalarda zaten sahip.');

            return;
        }
        if ($user !== null && $app->members()->where('user_id', $user->id)->exists()) {
            $this->addError('email', 'Bu kişi zaten ekli.');

            return;
        }
        $invite = null;
        if ($user === null) {
            $invite = Str::random(48);
            $user = User::forceCreate([
                'name' => trim($this->name) ?: Str::before($this->email, '@'),
                'email' => $this->email,
                'password' => Hash::make(Str::random(40)),
                'is_admin' => false,
                'invite_token' => hash('sha256', $invite),
                'invited_at' => now(),
            ]);
        }
        $app->members()->create(['user_id' => $user->id, 'role' => $this->role]);
        $this->adding = false;
        if ($invite !== null) {
            $this->showSecret(route('invitation', $invite), 'invite');
        } else {
            $this->dispatch('toast', message: $user->name.' eklendi.');
        }
    }

    /**
     * A new invitation link for someone who has not set a password yet.
     */
    public function newInvite(int $memberId): void
    {
        $member = $this->member($memberId);
        if ($member === null || ! $member->user->isInvited()) {
            return;
        }
        $invite = Str::random(48);
        $member->user->forceFill(['invite_token' => hash('sha256', $invite), 'invited_at' => now()])->save();
        $this->showSecret(route('invitation', $invite), 'invite');
    }

    public function setRole(int $memberId, string $role): void
    {
        $member = $this->member($memberId);
        if ($member === null || ! array_key_exists($role, LaravelAppMember::ROLES) || $member->role === $role) {
            return;
        }
        $before = $member->role;
        $member->update(['role' => $role]);
        $this->dispatch('toast',
            message: $member->user->name.' artık '.LaravelAppMember::ROLES[$role][0].'.',
            source: $this->getId(),
            undo: ['method' => 'setRole', 'args' => [$member->id, $before]],
        );
    }

    public function remove(int $memberId): void
    {
        $member = $this->member($memberId);
        if ($member === null) {
            return;
        }
        $copy = ['user_id' => $member->user_id, 'role' => $member->role];
        $member->delete();
        $this->dispatch('toast',
            message: $member->user->name.' bu uygulamadan çıkarıldı.',
            source: $this->getId(),
            undo: ['method' => 'restoreMember', 'args' => [$copy]],
        );
    }

    /**
     * @param  array{user_id: int, role: string}  $data
     */
    public function restoreMember(array $data): void
    {
        $app = $this->app();
        if (User::whereKey($data['user_id'] ?? 0)->exists() && array_key_exists($data['role'] ?? '', LaravelAppMember::ROLES)
            && $app->members()->where('user_id', $data['user_id'])->doesntExist()) {
            $app->members()->create(['user_id' => $data['user_id'], 'role' => $data['role']]);
        }
    }

    public function openToken(): void
    {
        $this->tokenName = 'GitHub Actions';
        $this->resetValidation();
        $this->tokenForm = true;
    }

    public function createToken(): void
    {
        $this->tokenName = trim($this->tokenName);
        $this->validate(['tokenName' => ['required', 'max:64']], [], ['tokenName' => 'ad']);
        $app = $this->app();
        if ($app->apiTokens()->whereNull('revoked_at')->count() >= 20) {
            $this->addError('tokenName', 'En çok 20 anahtar olabilir; kullanılmayanları iptal edin.');

            return;
        }
        [, $plain] = LaravelApiToken::issue($app, $this->tokenName, auth()->user());
        $this->tokenForm = false;
        $this->showSecret($plain, 'token');
    }

    public function revoke(int $id): void
    {
        $token = $this->token($id);
        if ($token === null || $token->revoked_at !== null) {
            return;
        }
        $token->update(['revoked_at' => now()]);
        $this->dispatch('toast',
            message: $token->name.' anahtarı iptal edildi; artık çalışmaz.',
            source: $this->getId(),
            undo: ['method' => 'unrevoke', 'args' => [$token->id]],
        );
    }

    public function unrevoke(int $id): void
    {
        $token = $this->token($id);
        if ($token !== null && $token->revoked_at?->gt(now()->subMinutes(self::UNDO_MINUTES))) {
            $token->update(['revoked_at' => null]);
        }
    }

    public function closeSecret(): void
    {
        $this->reset('secret', 'secretKind');
    }

    private function showSecret(string $secret, string $kind): void
    {
        $this->secret = $secret;
        $this->secretKind = $kind;
    }

    private function member(int $id): ?LaravelAppMember
    {
        return LaravelAppMember::with('user')->where('laravel_app_id', $this->appId)->find($id);
    }

    private function token(int $id): ?LaravelApiToken
    {
        return LaravelApiToken::where('laravel_app_id', $this->appId)->find($id);
    }

    public function render(): View
    {
        $app = $this->app();

        return view('livewire.laravel.sections.access', [
            'app' => $app,
            'members' => $app->members()->with('user')->get()->sortBy(fn ($m) => [array_search($m->role, array_keys(LaravelAppMember::ROLES)), $m->user->name])->values(),
            'admins' => User::where('is_admin', true)->orderBy('name')->get(['id', 'name', 'email']),
            'tokens' => $app->apiTokens()->whereNull('revoked_at')->get(),
            'manage' => auth()->user()->canOnApp($app, 'manage'),
            'inviteDays' => InvitationController::DAYS,
        ]);
    }
}
