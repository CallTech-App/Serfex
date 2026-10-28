<?php

namespace App\Services\Security;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Settings\Notifications;
use App\Services\Settings\OutgoingMail;
use App\Support\Locales;
use App\Support\ReadOnlyGuard;
use App\Support\When;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hesap bilgileri → E-posta: a person moves their own sign-in address.
 *
 * The address is where password reset links and the panel's notices go,
 * so a new one counts only once the link mailed to it is opened: a typo
 * can't lock the person out. When the panel can't send mail at all, no
 * link could arrive and no reset mail either; the address then changes at
 * once, the current password having been asked already.
 *
 * Wherever the old address stood for this person it moves along: the
 * customer record of a customer's login, the notification recipients and
 * the Let's Encrypt contact.
 */
class EmailChange
{
    /** How long a link works. */
    public const HOURS = 24;

    /** Links one account may be mailed in an hour. */
    private const PER_HOUR = 5;

    public function __construct(private readonly OutgoingMail $mail) {}

    /**
     * Whether $email is free for $user: no other account signs in with it.
     */
    public function available(User $user, string $email): bool
    {
        return ! User::whereRaw('lower(email) = ?', [Str::lower($email)])->whereKeyNot($user->id)->exists();
    }

    /**
     * Starts moving $user to $email. Returns 'sent' when a link went to the
     * new address, 'changed' when the panel can't send mail and the address
     * changed at once.
     *
     * @return 'sent'|'changed'
     *
     * @throws RuntimeException when the mail could not be sent
     */
    public function start(User $user, string $email, ?string $ip): string
    {
        $email = Str::lower(trim($email));
        if ($this->mail->problem() !== null) {
            // The page's toast says so in the log.
            $this->apply($user, $email, $ip, verified: false);

            return 'changed';
        }
        $this->send($user, $email, $ip);

        return 'sent';
    }

    /**
     * Mails the waiting address a new link; the previous one stops working.
     *
     * @throws RuntimeException
     */
    public function resend(User $user, ?string $ip): void
    {
        if ($user->pending_email === null) {
            return;
        }
        $this->send($user, $user->pending_email, $ip);
    }

    /**
     * Seconds until $user may ask for another link, 0 when they may now.
     */
    public function wait(User $user): int
    {
        if ($user->pending_email_at !== null && $user->pending_email_at->gt(now()->subMinute())) {
            return max(1, (int) ceil(now()->diffInSeconds($user->pending_email_at->copy()->addMinute())));
        }

        return RateLimiter::tooManyAttempts($this->limiterKey($user), self::PER_HOUR) ? RateLimiter::availableIn($this->limiterKey($user)) : 0;
    }

    /**
     * Forgets the waiting address; its link stops working.
     */
    public function cancel(User $user): void
    {
        $user->forceFill(['pending_email' => null, 'pending_email_token' => null, 'pending_email_at' => null])->save();
    }

    /**
     * The account a link is for, while the link works.
     */
    public function account(string $token): ?User
    {
        if (! preg_match('/^[A-Za-z0-9]{40,100}$/', $token)) {
            return null;
        }

        return User::where('pending_email_token', hash('sha256', $token))
            ->whereNotNull('pending_email')
            ->where('pending_email_at', '>', now()->subHours(self::HOURS))
            ->whereNull('removed_at')
            ->first();
    }

    /**
     * Opens the link: the waiting address becomes the sign-in address.
     *
     * @throws RuntimeException when another account took the address meanwhile
     */
    public function confirm(User $user, ?string $ip): void
    {
        $email = (string) $user->pending_email;
        if (! $this->available($user, $email)) {
            $this->cancel($user);

            throw new RuntimeException(__('Bu adresle artık başka bir panel hesabı var.'));
        }
        $this->apply($user, $email, $ip, verified: true);
    }

    /**
     * @throws RuntimeException
     */
    private function send(User $user, string $email, ?string $ip): void
    {
        if ($this->wait($user) > 0) {
            throw new RuntimeException(__('Az önce bir bağlantı gönderildi. Biraz sonra yeniden deneyin.'));
        }
        RateLimiter::hit($this->limiterKey($user), 3600);
        $token = Str::random(64);
        $user->forceFill(['pending_email' => $email, 'pending_email_token' => hash('sha256', $token), 'pending_email_at' => now()])->save();
        $url = rtrim(app(Notifications::class)->panelUrl(), '/').route('account.email.confirm', ['token' => $token], false);
        $error = Locales::using($user->preferredLocale(), fn () => $this->mail->send([$email], __('Serfex e-posta adresinizi doğrulayın'), implode("\n", [
            __('Merhaba :name,', ['name' => $user->name]),
            '',
            __('Serfex panelindeki hesabınızın e-posta adresini :email olarak değiştirmek istediniz (:ip).', ['email' => $email, 'ip' => $ip ?? '-']),
            __('Onaylamak için bu bağlantıyı açın. Bağlantı :hours saat geçerlidir; onaylayana kadar eski adresiniz :old geçerli kalır:', ['hours' => self::HOURS, 'old' => $user->email]),
            '',
            $url,
            '',
            __('Bu isteği siz yapmadıysanız bu e-postayı yok sayın; hiçbir şey değişmez.'),
            '',
        ])));
        if ($error !== null) {
            $this->cancel($user);

            throw new RuntimeException(__('Doğrulama e-postası gönderilemedi: :error', ['error' => $error]));
        }
    }

    /**
     * Moves $user and everything that names the old address for them to
     * $email, and tells the old address.
     */
    private function apply(User $user, string $email, ?string $ip, bool $verified): void
    {
        $old = $user->email;
        DB::transaction(function () use ($user, $email, $old, $verified) {
            $user->forceFill([
                'email' => $email,
                'email_verified_at' => $verified ? now() : null,
                'pending_email' => null, 'pending_email_token' => null, 'pending_email_at' => null,
            ])->save();
            // A reset link mailed to the old address stops working.
            DB::table('password_reset_tokens')->where('email', $old)->delete();
            ReadOnlyGuard::bookkeeping(function () use ($user, $email, $old) {
                if ($user->isCustomer()) {
                    // A customer's login and record carry the same address.
                    Customer::whereKey($user->customer_id)->update(['email' => $email]);
                }
                $this->replaceInSettings($old, $email);
            });
        });
        if ($verified) {
            AuditLog::record(__('E-posta adresini :old yerine :new yaptı; yeni adres doğrulandı.', ['old' => $old, 'new' => $email]),
                $user, extra: ['section' => __('Hesap bilgileri')]);
            Locales::using($user->preferredLocale(), fn () => $this->mail->send([$old], __('Serfex e-posta adresiniz değişti'), implode("\n", [
                __('Merhaba :name,', ['name' => $user->name]),
                '',
                __('Serfex panelindeki hesabınızın e-posta adresi :when tarihinde :email olarak değiştirildi (:ip). Bu adrese artık panelden e-posta gelmeyecek.', ['email' => $email, 'when' => When::dateTime(now()), 'ip' => $ip ?? '-']),
                '',
                __('Bunu siz yapmadıysanız hemen sunucu yöneticinize haber verin.'),
                '',
            ])));
        }
    }

    /**
     * The notification recipients and the Let's Encrypt contact follow the
     * person to the new address.
     */
    private function replaceInSettings(string $old, string $new): void
    {
        $saved = Setting::get(Notifications::KEY);
        if (is_array($saved) && isset($saved['emails']) && is_array($saved['emails'])) {
            $emails = array_map(fn ($e) => is_string($e) && Str::lower($e) === Str::lower($old) ? $new : $e, $saved['emails']);
            if ($emails !== $saved['emails']) {
                Setting::put(Notifications::KEY, ['emails' => array_values(array_unique($emails))] + $saved);
            }
        }
        $contact = Setting::get('ssl.email');
        if (is_string($contact) && Str::lower($contact) === Str::lower($old)) {
            Setting::put('ssl.email', $new);
        }
    }

    private function limiterKey(User $user): string
    {
        return 'email-change|'.$user->id;
    }
}
