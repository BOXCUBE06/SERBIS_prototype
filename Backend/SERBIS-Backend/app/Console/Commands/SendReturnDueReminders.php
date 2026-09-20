<?php

namespace App\Console\Commands;

use App\Mail\EquipmentDueTomorrow;
use App\Models\AmbulanceBooking;
use App\Models\EquipmentBorrowing;
use App\Models\User;
use App\Services\Fcm;
use App\Services\Sms\PacedSender;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsMessagePolicy;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Week 7 item 3, extended to every request type with a date to remind about
 * (MDRRMO feedback, 2026-09-17): equipment due back today or tomorrow, and a
 * confirmed ambulance booking scheduled today or tomorrow. One SMS and one
 * push per row, in either case.
 *
 * The other five services carry no due or scheduled date at all, so there is
 * nothing here for them to remind about.
 *
 * The two channels fail independently and neither gates the other, for both
 * kinds of row:
 * - The push is attempted for every row in the window, regardless of
 *   whether this resident even has an SMS-reachable number — Fcm::notifyResident()
 *   is itself a best-effort boundary (never throws, logs and swallows any
 *   failure), so it cannot affect what happens to the SMS side below it.
 * - Marked reminded (return_reminder_sent_at / scheduled_reminder_sent_at
 *   set) only once PhilSMS actually accepts the send — a successful push
 *   never sets it on its own, and a rejected SMS response or a thrown
 *   exception leaves the row unmarked so the next run retries it, bounded by
 *   the date window above (at most today and tomorrow ever match, so a
 *   permanently-failing number is retried at most twice, not forever). The
 *   push has no such marker and is attempted again on every retry — a
 *   resident whose SMS keeps failing could get the push twice; accepted here
 *   rather than adding a second tracking column for a two-run-wide window.
 *
 * Ambulance bookings filed by a walk-in (no resident_id) are skipped
 * entirely: there is no app account to push to, and reaching them by SMS
 * only would need a second, push-less code path this feedback item did not
 * ask for.
 */
class SendReturnDueReminders extends Command
{
    protected $signature = 'serbis:send-return-reminders';

    protected $description = 'Text and push-notify residents whose released equipment, or confirmed ambulance booking, falls due today or tomorrow';

    /**
     * Named here rather than trusted from app.timezone (UTC) — same reasoning
     * as ServiceRequestController::OFFICE_TIMEZONE: due dates are set and read
     * against the office's own day, not the server's.
     */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    /** One billed SMS segment: 160 GSM-7 characters is one credit. */
    private const SMS_SEGMENT_LIMIT = 160;

    /** Shown as the notification's title on every push this command sends, matching the two controllers. */
    private const PUSH_TITLE = 'SERBIS';

    public function handle(): int
    {
        // Read once and derive both from it — two separate Carbon::now() calls
        // straddling a real midnight would put $tomorrow a full day further out
        // than $today expects, silently dropping whichever borrowing was due on
        // the day in between.
        $now = Carbon::now(self::OFFICE_TIMEZONE);
        $today = $now->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();

        // 'resident' added for notifyAdminsOfDueTomorrow()'s own use — remind()
        // itself still reads $borrowing->resident lazily, unchanged.
        $borrowings = EquipmentBorrowing::with('equipment', 'resident')
            ->where('status', 'Released')
            ->whereNull('return_reminder_sent_at')
            ->whereIn('due_date', [$today, $tomorrow])
            ->get();

        // Confirmed (approved_at set) and still scheduled (status Booked —
        // Responding/Resolved/Cancelled/Disapproved all mean the appointment
        // is no longer a future thing to be reminded about). resident_id
        // required: see the class docblock on walk-in bookings.
        $bookings = AmbulanceBooking::with('serviceRequest.resident', 'serviceRequest.service')
            ->whereNotNull('approved_at')
            ->whereNull('scheduled_reminder_sent_at')
            ->where(fn ($q) => $q->whereDate('scheduled_at', $today)->orWhereDate('scheduled_at', $tomorrow))
            ->whereHas('serviceRequest', fn ($q) => $q->where('status', 'Booked')->whereNotNull('resident_id'))
            ->get();

        // Independent of the PhilSMS gate below on purpose: the admin email
        // has nothing to do with SMS being configured, and a PhilSMS outage
        // must not also silence the office's own copy of this reminder.
        $this->notifyAdminsOfDueTomorrow(
            $borrowings->filter(fn (EquipmentBorrowing $b) => $b->due_date->toDateString() === $tomorrow)
        );

        // Checked once per run, not once per row: this reflects a global
        // config value (the token), never a fact about one row, and a
        // missing token means every row in the window is equally unreachable
        // — one warning naming the count, not N identical per-row log lines.
        if (! app(SmsGateway::class)->configured()) {
            $total = $borrowings->count() + $bookings->count();
            Log::warning("SkySMS not configured, {$total} reminder(s) skipped");
            $this->summary(sent: 0, failed: 0, skippedNoNumber: 0, skippedNotConfigured: $total);

            return self::FAILURE;
        }

        $sent = 0;
        $failed = 0;
        $skippedNoNumber = 0;

        // One sender for the whole run, so its two-second spacing is measured
        // across every reminder — the account allows 30 texts a minute.
        $this->sender = app(PacedSender::class);

        foreach ($borrowings as $borrowing) {
            match ($this->remind($borrowing, $today, $now)) {
                self::OUTCOME_SENT => $sent++,
                self::OUTCOME_FAILED => $failed++,
                self::OUTCOME_SKIPPED => $skippedNoNumber++,
            };
        }

        foreach ($bookings as $booking) {
            match ($this->remindBooking($booking, $today, $now)) {
                self::OUTCOME_SENT => $sent++,
                self::OUTCOME_FAILED => $failed++,
                self::OUTCOME_SKIPPED => $skippedNoNumber++,
            };
        }

        $this->summary($sent, $failed, $skippedNoNumber, skippedNotConfigured: 0);

        return self::SUCCESS;
    }

    /**
     * The office's own copy of the reminder — one email, not one per
     * borrowing, and best-effort like every other channel here: a mail
     * failure is logged and swallowed, never thrown, so it cannot affect
     * whether the resident's own push/SMS gets sent or marked. Independent
     * in the other direction too — called before the PhilSms::configured()
     * gate, so an SMS outage does not also silence this.
     *
     * Deliberately not marked anywhere: unlike return_reminder_sent_at,
     * there is nothing to protect against here re-sending — this command
     * only ever runs once a day, and the query already excludes anything
     * already marked reminded on the resident side, so a row appears in this
     * email at most once regardless.
     */
    private function notifyAdminsOfDueTomorrow(Collection $dueTomorrow): void
    {
        if ($dueTomorrow->isEmpty()) {
            return;
        }

        $recipients = User::where('status', 'Active')->pluck('email_address')->filter()->values();

        if ($recipients->isEmpty()) {
            Log::warning('Admin due-tomorrow email skipped: no active admin has an email address');

            return;
        }

        $rows = $dueTomorrow->map(fn (EquipmentBorrowing $b) => [
            'item' => $b->equipment?->item_name ?? $b->other_equipment_text ?? 'item',
            'borrower' => $b->resident
                ? trim("{$b->resident->first_name} {$b->resident->last_name}")
                : 'Unknown borrower',
            'due_date' => $b->due_date->format('M j, Y'),
        ])->all();

        try {
            Mail::to($recipients->all())->send(new EquipmentDueTomorrow($rows));
        } catch (\Throwable $e) {
            Log::error('Admin due-tomorrow email failed', ['error' => $e->getMessage()]);
        }
    }

    private PacedSender $sender;

    private const OUTCOME_SENT = 'sent';

    private const OUTCOME_FAILED = 'failed';

    private const OUTCOME_SKIPPED = 'skipped';

    private function summary(int $sent, int $failed, int $skippedNoNumber, int $skippedNotConfigured): void
    {
        $this->info(
            "{$sent} sent, {$failed} failed (will retry), ".
            "{$skippedNoNumber} skipped (no usable number), ".
            "{$skippedNotConfigured} skipped (not configured)."
        );
    }

    /**
     * 'sent' only on an accepted send (marks the row so it is not retried).
     * 'failed' (a rejected response or a thrown exception) and 'skipped' (no
     * reachable number) both leave the row unmarked, so a resident who fixes
     * their number, or a send that goes through next time, still gets
     * reminded on a later run — the distinction is for the summary line
     * only, both are retried identically.
     *
     * resident_id is a required, non-nullable FK on this table — unlike
     * ServiceRequest, every borrowing has a resident to read a number from.
     */
    private function remind(EquipmentBorrowing $borrowing, string $today, Carbon $now): string
    {
        // Fired before the SMS channel below and never touches $borrowing —
        // see the class docblock for why the two channels stay independent.
        app(Fcm::class)->notifyResident(
            $borrowing->resident_id,
            self::PUSH_TITLE,
            $this->reminderPushBody($borrowing, $today),
            ['borrow_id' => (string) $borrowing->borrow_id],
        );

        // The gateway's configured() check runs once in handle(), before this
        // is ever called — a missing key is a fact about the run, not this row.
        $number = PhoneNumber::normalize((string) $borrowing->resident->phone_number);

        if ($number === '') {
            return self::OUTCOME_SKIPPED;
        }

        $result = $this->sender->send($number, $this->reminderMessage($borrowing, $today));

        if (! $result->isAccepted()) {
            // Rejected and unknown both leave the row unmarked. An unknown send
            // may have gone out, so the next run can text this person twice; a
            // resident hearing a reminder twice is the cheaper mistake than
            // one hearing nothing.
            Log::warning('Return-due reminder SMS not accepted, will retry', [
                'borrow_id' => $borrowing->borrow_id,
                'outcome' => $result->outcome,
                'reason' => $result->reason,
                'status' => $result->httpStatus,
            ]);

            return self::OUTCOME_FAILED;
        }

        $borrowing->update(['return_reminder_sent_at' => $now]);

        return self::OUTCOME_SENT;
    }

    /**
     * Same shape as remind(), for a confirmed ambulance booking instead of an
     * equipment loan. resident_id is guaranteed present here — the query in
     * handle() filters walk-in bookings out before this is ever called.
     */
    private function remindBooking(AmbulanceBooking $booking, string $today, Carbon $now): string
    {
        $residentId = $booking->serviceRequest->resident_id;

        app(Fcm::class)->notifyResident(
            $residentId,
            self::PUSH_TITLE,
            $this->bookingReminderPushBody($booking, $today),
            [
                'request_id' => (string) $booking->request_id,
                'service_type' => $booking->serviceRequest->service->service_name,
            ],
        );

        $number = PhoneNumber::normalize((string) $booking->serviceRequest->resident->phone_number);

        if ($number === '') {
            return self::OUTCOME_SKIPPED;
        }

        $result = $this->sender->send($number, $this->bookingReminderMessage($booking, $today));

        if (! $result->isAccepted()) {
            Log::warning('Scheduled-booking reminder SMS not accepted, will retry', [
                'request_id' => $booking->request_id,
                'outcome' => $result->outcome,
                'reason' => $result->reason,
                'status' => $result->httpStatus,
            ]);

            return self::OUTCOME_FAILED;
        }

        $booking->update(['scheduled_reminder_sent_at' => $now]);

        return self::OUTCOME_SENT;
    }

    /**
     * Equipment name and due date only. The name is the one part of this
     * message with no length cap of its own — other_equipment_text is
     * varchar(255) — so it is trimmed to whatever keeps the whole body inside
     * one segment, the same budget-then-trim shape as
     * ServiceRequestController::rescheduleMessage().
     */
    private function reminderMessage(EquipmentBorrowing $borrowing, string $today): string
    {
        $when = $borrowing->due_date->toDateString() === $today ? 'today' : 'tomorrow';
        $prefix = 'SERBIS: your borrowed ';
        $suffix = " is due back {$when} ({$borrowing->due_date->format('M j')}).";

        // Curly quotes and the like are swapped for plain ASCII: one character
        // outside GSM-7 turns the whole text into a 70-character-per-segment
        // message. The ellipsis below is three ASCII dots for the same reason,
        // and counted as three.
        $itemName = SmsMessagePolicy::toGsmSafe($borrowing->equipment->item_name ?? $borrowing->other_equipment_text ?? 'item');
        $budget = self::SMS_SEGMENT_LIMIT - mb_strlen($prefix) - mb_strlen($suffix);

        if (mb_strlen($itemName) > $budget) {
            $itemName = mb_substr($itemName, 0, max($budget - 3, 0)).'...';
        }

        return $prefix.$itemName.$suffix;
    }

    /**
     * Same fact as reminderMessage(), for the push channel — no 160-character
     * segment budget to trim against here, so the item name goes in whole.
     */
    private function reminderPushBody(EquipmentBorrowing $borrowing, string $today): string
    {
        $when = $borrowing->due_date->toDateString() === $today ? 'today' : 'tomorrow';
        $itemName = $borrowing->equipment->item_name ?? $borrowing->other_equipment_text ?? 'item';

        return 'Your borrowed '.$itemName.' is due back '.$when.' ('.$borrowing->due_date->format('M j').'). — MDRRMO Echague';
    }

    /**
     * "Today"/"tomorrow" read against the office calendar, same as
     * reminderMessage() — a booking at 12:30 AM Manila time is still "today"
     * even though its UTC date rolled over hours earlier.
     */
    private function bookingWhen(AmbulanceBooking $booking, string $today): string
    {
        return $booking->scheduled_at->copy()->timezone(self::OFFICE_TIMEZONE)->toDateString() === $today
            ? 'today'
            : 'tomorrow';
    }

    /** Time only — the day is already said by "today"/"tomorrow", saying both would repeat the fact. */
    private function bookingTime(AmbulanceBooking $booking): string
    {
        return $booking->scheduled_at->copy()->timezone(self::OFFICE_TIMEZONE)->format('g:i A');
    }

    private function bookingReminderMessage(AmbulanceBooking $booking, string $today): string
    {
        return 'SERBIS: your ambulance is scheduled '.$this->bookingWhen($booking, $today)
            .' at '.$this->bookingTime($booking).'. Please be ready.';
    }

    /** No 160-character segment budget here, same as reminderPushBody(). */
    private function bookingReminderPushBody(AmbulanceBooking $booking, string $today): string
    {
        return 'Your ambulance is scheduled '.$this->bookingWhen($booking, $today)
            .' at '.$this->bookingTime($booking).'. — MDRRMO Echague';
    }
}
