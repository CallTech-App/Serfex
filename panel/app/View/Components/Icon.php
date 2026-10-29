<?php

namespace App\View\Components;

use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * Inline Lucide icon (ISC licence) from resources/icons. Icons are inlined
 * so the panel needs no icon font or CDN.
 */
class Icon extends Component
{
    /** @var array<string, string> */
    private static array $cache = [];

    public function __construct(public string $name) {}

    public function render(): string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $this->name)) {
            throw new InvalidArgumentException("Invalid icon name [{$this->name}].");
        }

        $paths = self::$cache[$this->name] ??= trim((string) file_get_contents(resource_path("icons/{$this->name}.svg")));

        return <<<BLADE
            <svg {{ \$attributes->merge(['class' => 'w-4 h-4 shrink-0', 'stroke-width' => '2']) }} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{$paths}</svg>
            BLADE;
    }
}
