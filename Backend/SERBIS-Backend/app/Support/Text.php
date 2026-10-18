<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Free text as it is stored: no HTML, no control characters, trimmed, and the
 * first letter capitalized. Run on the request before validate() so the
 * length rules and the saved value both see the cleaned string.
 */
final class Text
{
    // Longest free-text intake field; the clients' maxlength and counter match.
    public const MAX_NOTE = 500;

    public static function clean(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return self::sentence(trim($value));
    }

    public static function sentence(string $value): string
    {
        return $value === '' ? $value : mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    /** Cleans the named string inputs in place; a field that cleans to nothing becomes null. */
    public static function tidy(Request $request, array $fields): void
    {
        $cleaned = [];

        foreach ($fields as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $cleaned[$field] = self::clean($value) ?: null;
            }
        }

        $request->merge($cleaned);
    }
}
