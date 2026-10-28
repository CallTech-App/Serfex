<?php

namespace App\Services\Git;

use RuntimeException;

/**
 * A GitHub, GitLab or Bitbucket request that did not work. $expired is
 * true when the account's sign-in no longer works and has to be connected
 * again; $status is the HTTP status (0 when the host could not be reached).
 */
class GitProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly bool $expired = false)
    {
        parent::__construct($message);
    }
}
