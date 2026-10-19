<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one definition of which characters each kind of field may hold. The
 * admin panel (inputRules.ts) and the mobile app (validators.dart) carry the
 * same sets; this is the source of truth they are checked against.
 *
 * Length is left to `min`/`max` beside it, so each field keeps its own ceiling.
 */
final class Charset implements ValidationRule
{
    public const NAME = '/^[\p{L} .\'’-]+$/u';

    public const ADDRESS = '/^[\p{L}\p{N} ,.\-#\/]+$/u';

    // Organization, hotline and barangay names.
    public const LABEL = '/^[\p{L}\p{N} .,\-&\'’()]+$/u';

    // A hotline name also takes "/" ("Police / PNP").
    public const HOTLINE = '/^[\p{L}\p{N} .,\-&\'’()\/]+$/u';

    // Vehicle unit and specification.
    public const IDENTIFIER = '/^[A-Za-z0-9 ()\-]+$/';

    private function __construct(private readonly string $pattern, private readonly string $message) {}

    public static function name(): self
    {
        return new self(self::NAME, 'The :attribute can only contain letters, spaces, hyphens, apostrophes and periods.');
    }

    public static function address(): self
    {
        return new self(self::ADDRESS, 'The :attribute can only contain letters, numbers, spaces and , . - # /');
    }

    public static function label(): self
    {
        return new self(self::LABEL, 'The :attribute can only contain letters, numbers, spaces and . , - & \' ( )');
    }

    public static function hotline(): self
    {
        return new self(self::HOTLINE, 'The :attribute can only contain letters, numbers, spaces and . , - & \' ( ) /');
    }

    public static function identifier(): self
    {
        return new self(self::IDENTIFIER, 'The :attribute can only contain letters, numbers, spaces, hyphens and parentheses.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match($this->pattern, $value) !== 1) {
            $fail($this->message);
        }
    }
}
