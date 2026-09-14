<?php

namespace App\Mail;

use App\Models\Resident;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The mail-fallback half of the login MFA code. Distinct from
 * ResidentVerificationCode, which is the signup gate — the two must never
 * share a template, since "finish creating your account" is wrong copy for
 * someone who is already a resident logging back in.
 *
 * Not queued, for the same reason ResidentVerificationCode isn't: no queue
 * worker runs outside deployment.
 */
class ResidentLoginCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Resident $resident,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your SERBIS login code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.resident-login-code',
            with: [
                'firstName' => $this->resident->first_name,
                'code' => $this->code,
            ],
        );
    }
}
