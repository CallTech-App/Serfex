<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A read-only administrator tried to change something.
 */
class ReadOnlyAccount extends HttpException
{
    public const MESSAGE = 'Salt okunur hesabınızla değişiklik yapamazsınız. Bir tam yönetici rolünüzü değiştirebilir.';

    public function __construct()
    {
        parent::__construct(403, __(self::MESSAGE));
    }
}
