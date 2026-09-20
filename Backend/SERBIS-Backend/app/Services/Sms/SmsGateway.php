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
     * One message to many numbers in a single request. The caller chunks:
     * more than the vendor's bulk limit is a programming error, not a delivery
     * one.
     *
     * @param  array<int, string>  $phones
     */
    public function sendBulk(array $phones, string $message): SmsResult;
}
