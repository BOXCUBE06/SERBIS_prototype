<?php

namespace App\Mail;

use App\Models\Resident;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one email SERBIS sends. Carries the plain code, which exists only inside
 * AuthController::issueSignupCode() — the stored copy is hashed.
 *
 * Takes a first name and not a Resident: at signup time there is no row yet,
 * because the account is not created until the code comes back.
 *
 * Not queued. There is no queue worker running outside deployment, and a code
 * sitting in an unprocessed jobs table is a resident staring at a screen.
 */
class ResidentVerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $firstName,
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
                'firstName' => $this->firstName,
                'code' => $this->code,
                'minutes' => Resident::CODE_TTL_MINUTES,
            ],
        );
    }
}
