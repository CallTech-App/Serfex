<?php

namespace App\Support;

/**
 * What a certificate looks like in lists and badges: the request status
 * combined with how long the installed certificate is still valid.
 * Satisfies <x-status-badge>.
 */
final class CertificateState
{
    public function __construct(
        private readonly string $label,
        private readonly string $tone,
        private readonly bool $busy = false,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Design token for the status colour: ok, warn, crit, info or muted.
     */
    public function tone(): string
    {
        return $this->tone;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function needsAttention(): bool
    {
        return $this->tone === 'warn' || $this->tone === 'crit';
    }
}
