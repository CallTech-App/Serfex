<?php

namespace App\Services\Security;

use App\Models\Setting;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-step login with an authenticator app (TOTP: Google Authenticator,
 * Microsoft Authenticator, 1Password, Bitwarden...). It is optional for
 * every account; administrators may require it for administrators or for
 * everyone. Ten one-time recovery codes stand in for a lost phone.
 */
class TwoFactor
{
    public const REQUIRE = [
        'none' => 'Zorunlu değil',
        'admins' => 'Yöneticiler için zorunlu',
        'all' => 'Herkes için zorunlu',
    ];

    private const KEY = 'security.require_2fa';

    public function __construct(private readonly Google2FA $google) {}

    public function required(): string
    {
        $value = (string) Setting::get(self::KEY, 'none');

        return array_key_exists($value, self::REQUIRE) ? $value : 'none';
    }

    public function setRequired(string $value): void
    {
        Setting::put(self::KEY, $value);
    }

    /**
     * True when the account has to set up two-step login before using
     * the panel.
     */
    public function mustSetUp(User $user): bool
    {
        return ! $user->hasTwoFactor() && $this->isRequiredFor($user);
    }

    /**
     * True when the account may not go without two-step login.
     */
    public function isRequiredFor(User $user): bool
    {
        return match ($this->required()) {
            'all' => true,
            'admins' => (bool) $user->is_admin,
            default => false,
        };
    }

    public function newSecret(): string
    {
        return $this->google->generateSecretKey(32);
    }

    /**
     * The QR code an authenticator app scans, as an inline SVG.
     */
    public function qrSvg(User $user, string $secret): string
    {
        $url = $this->google->getQRCodeUrl('Serfex ('.parse_url((string) config('app.url'), PHP_URL_HOST).')', $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(184, 0), new SvgImageBackEnd)))->writeString($url);

        return trim(preg_replace('/^<\?xml[^>]*>\s*/', '', $svg));
    }

    /**
     * Checks a six-digit code; a code is accepted once.
     */
    public function checkCode(string $secret, string $code, int $userId): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = '2fa.last.'.$userId;
        $at = $this->google->verifyKeyNewer($secret, $code, Cache::get($key), 1);
        if ($at === false) {
            return false;
        }
        Cache::put($key, $at === true ? $this->google->getTimestamp() : $at, 300);

        return true;
    }

    /**
     * Turns two-step login on once the app's first code matches. Returns
     * the recovery codes to show once, or null for a wrong code.
     *
     * @return list<string>|null
     */
    public function enable(User $user, string $secret, string $code): ?array
    {
        if (! $this->checkCode($secret, $code, $user->id)) {
            return null;
        }
        $codes = self::recoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    /**
     * New recovery codes; the old ones stop working.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $codes = self::recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    /**
     * Checks a code from the app or a recovery code (used up).
     */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        if ($this->checkCode($user->two_factor_secret, $code, $user->id)) {
            return true;
        }
        $hash = hash('sha256', Str::upper(preg_replace('/[\s-]+/', '', $code)));
        $codes = $user->two_factor_recovery_codes ?? [];
        if (in_array($hash, $codes, true)) {
            $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

            return true;
        }

        return false;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Ten codes like 7KQ4-M2XD.
     *
     * @return list<string>
     */
    private static function recoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $c = '';
            for ($j = 0; $j < 8; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = $c;
        }

        return $codes;
    }

    /**
     * A recovery code as shown: 7KQ4-M2XD.
     */
    public static function format(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
