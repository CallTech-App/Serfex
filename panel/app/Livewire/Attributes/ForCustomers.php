<?php

namespace App\Livewire\Attributes;

use Attribute;

/**
 * Marks a Livewire component that customers may use too (on their own
 * sites; the component scopes its queries). Without it a component is for
 * administrators only.
 *
 * feature: the package feature the component needs (Package::FEATURES).
 * except: methods only administrators may call.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ForCustomers
{
    /**
     * @param  list<string>  $except
     */
    public function __construct(
        public ?string $feature = null,
        public array $except = [],
    ) {}
}
