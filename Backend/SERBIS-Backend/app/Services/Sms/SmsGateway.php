<?php

namespace App\Services\Sms;

/**
 * The one way this application sends a text message.
 *
 * Every caller — the OTP paths, the reminders, the admin text blast — depends
 * on this and not on a vendor, so changing provider is a change to one class.
 * Implementations never throw for a delivery problem: they return an
 * SmsResult, because "the vendor refused", "we ran out of credits" and "the
 * request never came back" each need a different answer from the caller.
 */
interface SmsGateway
{
    /** Whether a vendor credential is present. A missing one means every send would fail. */
    public function configured(): bool;

    /** One message to one number, on a path a person may be waiting on. */
    public function sendOne(string $phone, string $message): SmsResult;

    /**
     * A code someone is waiting on (an OTP). Like sendOne(), plus one short
     * retry: a 429 that says to wait five seconds or less is waited out once,
     * because a person is looking at the screen. Anything longer, or no
     * Retry-After at all, comes straight back as the rate-limited rejection.
     */
    public function sendOtp(string $phone, string $message): SmsResult;

    /**
     * One message to many numbers in a single request. The caller chunks:
     * more than the vendor's bulk limit is a programming error, not a delivery
     * one.
     *
     * @param  array<int, string>  $phones
     */
    public function sendBulk(array $phones, string $message): SmsResult;

    /**
     * True when sends are suppressed locally (SERBIS_SMS_FAKE), so there is no
     * vendor state to read back.
     */
    public function faking(): bool;

    /**
     * One page of the vendor's message list, read-only and unbilled.
     *
     * @return array{data: list<array<string, mixed>>, last_page: int}|null null when the list could not be read
     */
    public function messages(string $from, string $to, int $page = 1): ?array;
}
