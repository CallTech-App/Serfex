<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * The panel's validator: in Turkish a field name starting a message with
 * "i" is capitalized as "İ" ("İzinli IP gerekli."), not "I".
 */
class PanelValidator extends Validator
{
    protected function replaceAttributePlaceholder($message, $value)
    {
        if (app()->getLocale() === 'tr' && str_starts_with((string) $value, 'i')) {
            $message = str_replace(':Attribute', 'İ'.substr((string) $value, 1), $message);
        }

        return parent::replaceAttributePlaceholder($message, $value);
    }
}
