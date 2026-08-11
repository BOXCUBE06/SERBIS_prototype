<?php

namespace App\Mail;

use App\Models\Resident;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one email SERBIS sends. Carries the plain code, which exists only between
 * Resident::issueVerificationCode() returning it and this message going out —
 * the stored copy is hashed.
 *
 * Not queued. There is no queue worker running outside deployment, and a code
 * sitting in an unprocessed jobs table is a resident staring at a screen.
 */
class ResidentVerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Resident $resident,
        public string $code,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your SERBIS verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.resident-verification-code',
            with: [
                'firstName' => $this->resident->first_name,
                'code' => $this->code,
                'minutes' => Resident::CODE_TTL_MINUTES,
            ],
        );
    }
}
