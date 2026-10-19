<?php

namespace App\Support;

use App\Rules\Charset;
use App\Rules\MobileNumber;

/**
 * The rule sets controllers share, so a field means the same thing wherever
 * it is taken. Spread into a rule list: ['nullable', ...FieldRules::name()].
 */
final class FieldRules
{
    /** A person's name. */
    public static function name(): array
    {
        return ['string', 'min:2', 'max:50', Charset::name()];
    }

    /** A street, place or landmark. */
    public static function address(): array
    {
        return ['string', 'max:150', Charset::address()];
    }

    /** Free text; Text::tidy() has already stripped it by now. */
    public static function note(int $max = Text::MAX_NOTE): array
    {
        return ['string', 'max:'.$max];
    }

    /** Organization, hotline and barangay names. */
    public static function label(int $max = 100): array
    {
        return ['string', 'min:2', 'max:'.$max, Charset::label()];
    }

    /** Hotline names: the label charset plus "/". */
    public static function hotline(int $max = 100): array
    {
        return ['string', 'min:2', 'max:'.$max, Charset::hotline()];
    }

    public static function phone(): array
    {
        return ['string', 'max:32', new MobileNumber];
    }

    public static function age(): array
    {
        return ['integer', 'between:0,120'];
    }
}
