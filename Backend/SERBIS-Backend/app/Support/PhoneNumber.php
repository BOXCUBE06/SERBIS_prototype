<?php

namespace App\Support;

/**
 * Philippine mobile numbers, in one place: what a person may type, and the one
 * form the SMS vendor accepts.
 *
 * Used by every validation rule that takes a phone number and by every SMS
 * send, so the shapes accepted on entry and the shape sent out cannot drift
 * apart.
 */
class PhoneNumber
{
    // The three shapes normalize() accepts below, spelled out so a validation
    // rule can require a real mobile number at the point of entry instead of
    // accepting anything and failing silently later. 2026-08-31: the user
    // decided registration and every admin-facing edit MUST require a real
    // mobile number — no landline/undialable fallback going forward. Rows
    // written before this rule may still hold something else; those are simply
    // not reachable by SMS (normalize() returns '').
    public const REGEX = '/^(09\d{9}|639\d{9}|\+639\d{9})$/';

    /**
     * The vendor rejects anything that is not an E.164 Philippine number, while
     * tbl_residents.phone_number is a free string a resident typed. Accepts the
     * three shapes people actually enter — 09171234567, 639171234567,
     * +639171234567 — and returns an empty string for anything else so the
     * caller drops it rather than paying for a guaranteed failure.
     */
    /**
     * How a number reads to a person here: 09171234567. Storage and the SMS
     * vendor use +639171234567, but staff and residents write and dial the
     * national form, so anything the server composes for them to read — a
     * trip record's contact line, say — goes through this. A value that is not
     * a canonical number is returned unchanged.
     */
    public static function display(string $number): string
    {
        return preg_match('/^\+639(\d{9})$/', $number, $m) === 1 ? '09'.$m[1] : $number;
    }

    public static function normalize(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return match (true) {
            str_starts_with($digits, '639') && strlen($digits) === 12 => '+'.$digits,
            str_starts_with($digits, '09') && strlen($digits) === 11 => '+63'.substr($digits, 1),
            str_starts_with($digits, '9') && strlen($digits) === 10 => '+63'.$digits,
            default => '',
        };
    }
}
