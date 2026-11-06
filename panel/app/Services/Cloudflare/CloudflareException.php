<?php

namespace App\Services\Cloudflare;

use RuntimeException;

/**
 * A call to Cloudflare failed. The message is Cloudflare's own, or says
 * Cloudflare could not be reached; the code is Cloudflare's error code.
 */
class CloudflareException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly int $status = 0)
    {
        parent::__construct($message, $code);
    }

    /**
     * Whether the token lacks a permission or is no longer valid.
     */
    public function isAuthorization(): bool
    {
        return in_array($this->status, [401, 403], true) || in_array($this->getCode(), [9109, 10000, 10001], true);
    }
}
