<?php

namespace App\Livewire\Attributes;

use Attribute;

/**
 * Marks a Livewire component every signed-in account may use: it acts
 * only on the account itself (Hesap güvenliği).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ForEveryAccount {}
