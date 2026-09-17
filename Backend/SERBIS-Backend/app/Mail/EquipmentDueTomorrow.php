<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The admin-side half of the return-due reminder (MDRRMO feedback,
 * 2026-09-18) — SendReturnDueReminders already texts and pushes the
 * borrower; this is the one email that tells staff the same fact, once per
 * run, for every borrowing due back tomorrow.
 *
 * One summary email, not one per borrowing: a day with five items due sends
 * five residents five separate pushes (each is about their own loan) but
 * staff reading their inbox want one list, not five emails that all say
 * "something is due tomorrow."
 */
class EquipmentDueTomorrow extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{item: string, borrower: string, due_date: string}>  $rows
     */
    public function __construct(public array $rows) {}

    public function envelope(): Envelope
    {
        $count = count($this->rows);

        return new Envelope(
            subject: $count === 1
                ? '1 item due back tomorrow'
                : "{$count} items due back tomorrow",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.equipment-due-tomorrow',
            with: ['rows' => $this->rows],
        );
    }
}
