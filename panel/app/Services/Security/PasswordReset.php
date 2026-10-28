<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Settings\Notifications;
use App\Services\Settings\OutgoingMail;
use App\Support\Locales;
use App\Support\When;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * "Parolamı unuttum": a one-time link, mailed through the panel's own mail
 * settings in the person's language, or printed on the server by
 * `sudo serfex reset-password` when the panel can't send mail. Links work
 * for an hour, only the newest one works, and only its hash is stored.
 * Setting a new password signs the account out everywhere; two-step
 * login, when it is on, is still asked at the next sign-in.
 */
class PasswordReset
{
    /** How long a link works. config/auth.php says the same to the broker. */
    public const MINUTES = 60;

    /** Links one address may be mailed in an hour. */
    private const PER_HOUR = 5;

    public function __construct(private readonly OutgoingMail $mail) {}

    /**
     * The account a reset is for: signed-up, not removed. Pending
     * invitations use their invitation link instead.
     */
    public function account(string $email): ?User
    {
        return User::whereRaw('lower(email) = ?', [Str::lower(trim($email))])
            ->whereNull('removed_at')->whereNull('invite_token')->first();
    }

    /**
     * Why the panel can't mail a link now, or null when it can.
     */
    public function mailProblem(): ?string
    {
        return $this->mail->problem();
    }

    /**
     * Mails a link to the account at $email, if there is one. Says
     * nothing about whether there was: the page answers the same either
     * way.
     */
    public function request(string $email, ?string $ip): void
    {
        $user = $this->account($email);
        $key = 'password-reset|'.Str::lower(trim($email));
        if ($user === null || RateLimiter::tooManyAttempts($key, self::PER_HOUR) || $this->broker()->getRepository()->recentlyCreatedToken($user)) {
            return;
        }
        RateLimiter::hit($key, 3600);
        $url = $this->link($user, app(Notifications::class)->panelUrl());
        $error = Locales::using($user->preferredLocale(), fn () => $this->mail->send([$user->email], __('Serfex parolanızı sıfırlayın'), implode("\n", [
            __('Merhaba :name,', ['name' => $user->name]),
            '',
            __(':email hesabınız için parola sıfırlama istendi (:ip).', ['email' => $user->email, 'ip' => $ip ?? '-']),
            __('Yeni bir parola belirlemek için bu bağlantıyı açın. Bağlantı :minutes dakika geçerlidir ve bir kez kullanılır:', ['minutes' => self::MINUTES]),
            '',
            $url,
            '',
            __('Bu isteği siz yapmadıysanız bu e-postayı yok sayın; parolanız değişmez.'),
            '',
        ])));
        AuditLog::record($error === null
            ? __('Parola sıfırlama bağlantısı e-postayla gönderildi.')
            : __('Parola sıfırlama e-postası gönderilemedi: :error', ['error' => $error]), $user, extra: $this->where());
        if ($error !== null) {
            $this->broker()->deleteToken($user);
        }
    }

    /**
     * A new one-time link for $user on $base (https://host:port); the
     * previous link stops working.
     */
    public function link(User $user, string $base): string
    {
        $token = $this->broker()->createToken($user);

        return rtrim($base, '/').route('password.reset', ['token' => $token, 'email' => $user->email], false);
    }

    /**
     * The links `serfex reset-password` prints, one for each address the
     * panel answers on.
     *
     * @param  list<string>  $bases
     * @return array{urls: list<string>, expires_at: Carbon}
     */
    public function linksForServer(User $user, array $bases): array
    {
        $token = $this->broker()->createToken($user);
        $path = route('password.reset', ['token' => $token, 'email' => $user->email], false);
        AuditLog::record(__('Sunucuda komut satırıyla parola sıfırlama bağlantısı oluşturuldu.'), $user, 'system', ['actor' => 'root'] + $this->where());

        return [
            'urls' => array_map(fn (string $base) => rtrim($base, '/').$path, $bases),
            'expires_at' => now()->addMinutes(self::MINUTES),
        ];
    }

    public function valid(?User $user, string $token): bool
    {
        return $user !== null && $this->broker()->tokenExists($user, $token);
    }

    /**
     * Sets the new password, voids the link, signs the account out
     * everywhere and tells the person by mail.
     */
    public function reset(User $user, string $password, ?string $ip): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        $this->broker()->deleteToken($user);
        app(Sessions::class)->revokeOthers($user, null);
        AuditLog::record(__('Parolasını sıfırlama bağlantısıyla değiştirdi.'), $user, extra: $this->where());
        if ($this->mail->problem() === null) {
            Locales::using($user->preferredLocale(), fn () => $this->mail->send([$user->email], __('Serfex parolanız değiştirildi'), implode("\n", [
                __('Merhaba :name,', ['name' => $user->name]),
                '',
                __(':email hesabınızın parolası :when tarihinde sıfırlama bağlantısıyla değiştirildi (:ip). Açık oturumlarınız kapatıldı.', ['email' => $user->email, 'when' => When::dateTime(now()), 'ip' => $ip ?? '-']),
                '',
                __('Bunu siz yapmadıysanız hemen sunucu yöneticinize haber verin.'),
                '',
            ])));
        }
    }

    /**
     * Admins set at least 12 characters, as at setup; other accounts 10,
     * as when accepting an invitation.
     */
    public function minLength(User $user): int
    {
        return $user->is_admin ? 12 : 10;
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker();
    }

    /** @return array{section: string} */
    private function where(): array
    {
        return ['section' => __('Hesap güvenliği')];
    }
}
