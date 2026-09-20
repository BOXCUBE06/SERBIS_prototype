<?php

namespace App\Support;

/**
 * Works out what putting every stored phone number into its canonical form
 * (+639XXXXXXXXX) would do, before anything is written.
 *
 * Kept apart from the migration that uses it so the rules can be tested without
 * building a database that already holds bad rows — the unique index the
 * migration adds is exactly what stops such rows existing afterwards.
 */
class PhoneBackfill
{
    /**
     * @param  array<int, mixed>  $phones  resident id => the number as stored
     * @return array{updates: array<int, string>, invalid: array<int, string>, duplicates: array<string, array<int, int>>}
     *                                                                                                                     - updates: id => canonical, only for rows that are not already canonical
     *                                                                                                                     - invalid: id => stored value, for numbers that cannot be made canonical (blank, landline, malformed)
     *                                                                                                                     - duplicates: canonical => the ids that would share it
     */
    public static function plan(array $phones): array
    {
        $updates = [];
        $invalid = [];
        $byCanonical = [];

        foreach ($phones as $id => $stored) {
            $stored = (string) $stored;
            $canonical = PhoneNumber::normalize($stored);

            if ($canonical === '') {
                $invalid[(int) $id] = $stored;

                continue;
            }

            $byCanonical[$canonical][] = (int) $id;

            if ($canonical !== $stored) {
                $updates[(int) $id] = $canonical;
            }
        }

        $duplicates = array_filter($byCanonical, fn (array $ids) => count($ids) > 1);

        return ['updates' => $updates, 'invalid' => $invalid, 'duplicates' => $duplicates];
    }

    /**
     * What the migration says when it refuses. Resident ids only: the numbers
     * are personal data and this text ends up in deploy logs.
     *
     * @param  array{updates: array<int, string>, invalid: array<int, string>, duplicates: array<string, array<int, int>>}  $plan
     */
    public static function describe(array $plan): string
    {
        $lines = ['tbl_residents.phone_number cannot be made canonical and unique. Nothing was changed.'];

        if ($plan['invalid'] !== []) {
            $lines[] = 'Blank or not a Philippine mobile number, resident ids: '.implode(', ', array_keys($plan['invalid']));
        }

        foreach ($plan['duplicates'] as $ids) {
            $lines[] = 'Same number once normalised, resident ids: '.implode(', ', $ids);
        }

        $lines[] = 'Fix or remove these rows, then run the migration again.';

        return implode(PHP_EOL, $lines);
    }
}
