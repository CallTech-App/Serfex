<?php

namespace App\Support;

use App\Models\User;

/**
 * An administrator viewing the panel as one of their customers. The
 * administrator's id waits in the session until they return.
 */
final class Impersonation
{
    public const KEY = 'servik.impersonator';

    public const SUSPENDED = 'Hesabınız askıya alındı. Barındırma sağlayıcınızla iletişime geçin.';

    public static function active(): bool
    {
        return session()->has(self::KEY);
    }

    /**
     * The administrator behind the current session, if any.
     */
    public static function administrator(): ?User
    {
        $id = session(self::KEY);
        $user = $id ? User::find($id) : null;

        return $user?->is_admin ? $user : null;
    }
}
