<?php

namespace App\Services\Settings;

use App\Http\Controllers\Auth\InvitationController;
use App\Jobs\PurgeAdministrator;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Araçlar ve Ayarlar → Yöneticiler: administrator accounts and their
 * roles. A new administrator gets an invitation link (seven days) to set
 * their own password. There is always at least one full administrator,
 * and nobody changes or removes their own account here.
 */
class Administrators
{
    /** Minutes a removed administrator can be brought back. */
    public const UNDO_MINUTES = 10;

    public function __construct(private readonly OutgoingMail $mail) {}

    /**
     * Why $actor may not change $admin's role to $role (null: remove), or null.
     */
    public function refusal(User $actor, User $admin, ?string $role): ?string
    {
        return match (true) {
            ! $admin->is_admin => __('Bu hesap bir yönetici değil.'),
            $admin->id === $actor->id => __('Kendi hesabınızın rolünü değiştiremez, hesabınızı kaldıramazsınız.'),
            $role !== null && ! isset(User::ROLES[$role]) => __('Geçersiz rol.'),
            $admin->isFullAdmin() && $role !== 'full' && $this->fullCount() <= 1 => __('Panelin en az bir tam yöneticisi olmalı.'),
            default => null,
        };
    }

    /**
     * Invites $email as an administrator with $role. Returns the account
     * and the link (shown once), and whether it was also e-mailed.
     *
     * @return array{0: User, 1: string, 2: bool}
     */
    public function invite(string $email, string $name, string $role, User $by): array
    {
        $token = Str::random(48);
        $user = DB::transaction(fn () => User::create([
            'name' => $name !== '' ? $name : Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(40),
            'is_admin' => true,
            'role' => $role,
            'invite_token' => hash('sha256', $token),
            'invited_at' => now(),
        ]));
        $link = route('invitation', $token);
        // The new person has not picked a language yet: the server's default.
        $mailed = $this->mail->problem() === null && Locales::using(Locales::serverDefault(), fn () => $this->mail->send([$email], __('Serfex paneline davet edildiniz'),
            __(':by sizi Serfex paneline :role olarak ekledi.', ['by' => $by->name, 'role' => __(User::ROLES[$role][0])])."\n\n".__('Parolanızı belirlemek için bu bağlantıyı açın (:days gün geçerli):', ['days' => InvitationController::DAYS])."\n{$link}\n")) === null;

        return [$user, $link, $mailed];
    }

    /**
     * A new invitation link for an administrator who has not accepted yet.
     */
    public function reinvite(User $admin): string
    {
        $token = Str::random(48);
        $admin->forceFill(['invite_token' => hash('sha256', $token), 'invited_at' => now()])->save();

        return route('invitation', $token);
    }

    public function changeRole(User $admin, string $role): void
    {
        $admin->forceFill(['role' => $role])->save();
    }

    /**
     * Signs the administrator out everywhere and keeps the account for
     * the undo window, then deletes it (PurgeAdministrator).
     */
    public function remove(User $admin): void
    {
        $admin->forceFill(['removed_at' => now(), 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $admin->id)->delete();
        PurgeAdministrator::dispatch($admin->id)->delay(now()->addMinutes(self::UNDO_MINUTES));
    }

    public function restore(User $admin): void
    {
        $admin->forceFill(['removed_at' => null])->save();
    }

    /**
     * Turns off another administrator's two-step verification (a lost
     * phone). The secret is kept for the undo window.
     */
    public function resetTwoFactor(User $admin): string
    {
        $token = Str::random(32);
        Cache::put('serfex.admin-2fa.'.$token, [
            'user' => $admin->id,
            'secret' => $admin->two_factor_secret,
            'codes' => $admin->two_factor_recovery_codes,
            'confirmed' => $admin->two_factor_confirmed_at?->toIso8601String(),
        ], now()->addMinutes(self::UNDO_MINUTES));
        $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return $token;
    }

    public function undoResetTwoFactor(string $token): ?User
    {
        $saved = Cache::pull('serfex.admin-2fa.'.$token);
        $admin = $saved ? User::find($saved['user']) : null;
        if ($admin === null || $admin->hasTwoFactor()) {
            return null;
        }
        $admin->forceFill([
            'two_factor_secret' => $saved['secret'],
            'two_factor_recovery_codes' => $saved['codes'],
            'two_factor_confirmed_at' => $saved['confirmed'],
        ])->save();

        return $admin;
    }

    private function fullCount(): int
    {
        return User::where('is_admin', true)->whereNull('removed_at')->where(fn ($q) => $q->where('role', 'full')->orWhereNull('role'))->count();
    }
}
