<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Philippine mobile number in any spelling PhoneNumber::normalize() takes (09…, 639…, +639…). */
final class MobileNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(PhoneNumber::REGEX, $value) !== 1) {
            $fail('The :attribute must be a mobile number like 09171234567.');
        }
    }
}
