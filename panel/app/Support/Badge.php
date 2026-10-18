<?php

namespace App\Support;

/**
 * A label with a status colour for <x-status-badge>, for states that are
 * not an enum.
 */
final class Badge
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
}
