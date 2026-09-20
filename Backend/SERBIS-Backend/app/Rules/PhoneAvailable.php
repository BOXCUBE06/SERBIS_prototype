<?php

namespace App\Rules;

use App\Models\Resident;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number is the login for a resident account, so no two accounts may
 * hold the same one.
 *
 * Compared in canonical form (+639XXXXXXXXX): "09171234567" and
 * "+639171234567" are the same handset, and a plain `unique` rule would treat
 * them as two. A value that is not a dialable number is left for the format
 * rule beside this one to report.
 *
 * A sign-up that has not finished does not hold a number — it lives in the
 * cache — so this only ever refuses a real account.
 */
class PhoneAvailable implements ValidationRule
{
    public const DEFAULT_MESSAGE = 'That number is already registered to an account.';

    public function __construct(
        private readonly ?int $ignoreResidentId = null,
        private readonly ?string $message = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $canonical = PhoneNumber::normalize((string) $value);

        if ($canonical === '') {
            return;
        }

        $taken = Resident::query()
            ->where('phone_number', $canonical)
            ->when($this->ignoreResidentId !== null, fn ($query) => $query->where('resident_id', '!=', $this->ignoreResidentId))
            ->exists();

        if ($taken) {
            $fail($this->message ?? self::DEFAULT_MESSAGE);
        }
    }
}
