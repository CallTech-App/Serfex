<?php

namespace App\Livewire\Laravel;

/**
 * A component of one Laravel application's page. Every call to it is
 * checked against the caller's role on the application: view, operate or
 * manage (see User::canOnApp).
 *
 * Classes list the methods anyone who can see the application may call in
 * VIEW_METHODS and those only owners may call in MANAGE_METHODS; every
 * other method needs operate.
 */
trait GuardsAppCalls
{
    public function ability(string $method): string
    {
        // Livewire's own calls: $refresh, $set, __dispatch and the like.
        if (str_starts_with($method, '$') || str_starts_with($method, '__') || $method === 'readRun') {
            return 'view';
        }
        if (in_array($method, static::VIEW_METHODS, true)) {
            return 'view';
        }

        return in_array($method, static::MANAGE_METHODS, true) ? 'manage' : 'operate';
    }
}
