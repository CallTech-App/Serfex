@props(['text', 'with' => [], 'count' => null])
{{ \App\Support\Locales::html($text, [...$with, ...array_diff_key($__laravel_slots ?? [], ['__default' => true])], $count) }}