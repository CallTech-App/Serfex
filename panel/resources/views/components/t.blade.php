@props(['text', 'with' => []])
{{ \App\Support\Locales::html($text, [...$with, ...array_diff_key($__laravel_slots ?? [], ['__default' => true])]) }}