<?php

namespace App\Console\Commands;

use App\Mail\EquipmentDueTomorrow;
use App\Models\AmbulanceBooking;
use App\Models\EquipmentBorrowing;
use App\Models\User;
use App\Services\Fcm;
use App\Services\Sms\PacedSender;
use App\Services\Sms\SmsGateway;
use App\Support\PhoneNumber;
use App\Support\ReminderFollowUp;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Week 7 item 3, extended to every request type with a date to remind about
 * (MDRRMO feedback, 2026-09-17): equipment due back today or tomorrow, and a
 * confirmed ambulance booking scheduled today or tomorrow.
 *
 * The two kinds no longer share a channel:
 * - Equipment is push only. return_reminder_sent_at is set once FCM accepts the
 *   push for at least one of the resident's devices. A resident with none is
 *   left unmarked, so the next run tries again (at most today and tomorrow ever
 *   match, so twice, not forever), and is put in front of staff to ring
 *   (ReminderFollowUp). There is no SMS fallback.
 * - An ambulance booking is still SMS and push. The two fail independently and
 *   neither gates the other: the push is attempted for every row, and
 *   scheduled_reminder_sent_at is set only once SkySMS accepts the text — a
 *   successful push never sets it on its own, and a rejected response or a
 *   thrown exception leaves the row unmarked so the next run retries it. The
 *   push has no marker and repeats on a retry; accepted here rather than adding
 *   a second tracking column for a two-run-wide window.
 *
 * The other five services carry no due or scheduled date at all, so there is
 * nothing here for them to remind about.
 *
 * Ambulance bookings filed by a walk-in (no resident_id) are skipped
 * entirely: there is no app account to push to, and reaching them by SMS
 * only would need a second, push-less code path this feedback item did not
 * ask for.
 */
class SendReturnDueReminders extends Command
{
    protected $signature = 'serbis:send-return-reminders';

    protected $description = 'Push-notify residents whose released equipment is due, and text and push-notify those with a confirmed ambulance booking, today or tomorrow';

    /**
     * Named here rather than trusted from app.timezone (UTC) — same reasoning
     * as ServiceRequestController::OFFICE_TIMEZONE: due dates are set and read
     * against the office's own day, not the server's.
     */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

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

        // Independent of the SkySMS gate below on purpose: the admin email
        // has nothing to do with SMS being configured, and a SkySMS outage
        // must not also silence the office's own copy of this reminder.
        $this->notifyAdminsOfDueTomorrow(
            $borrowings->filter(fn (EquipmentBorrowing $b) => $b->due_date->toDateString() === $tomorrow)
        );

        $sent = 0;
        $noDevice = 0;

        // Equipment first, and whether or not SMS is configured: it is push
        // only, and a missing SkySMS key has nothing to do with it.
        foreach ($borrowings as $borrowing) {
            match ($this->remind($borrowing, $today, $now)) {
                self::OUTCOME_SENT => $sent++,
                self::OUTCOME_NO_DEVICE => $noDevice++,
            };
        }

        // Checked once per run, not once per row: this reflects a global
        // config value (the token), never a fact about one row, and a
        // missing token means every booking in the window is equally
        // unreachable by text — one warning naming the count, not N identical
        // per-row log lines. Only bookings are behind it.
        if (! app(SmsGateway::class)->configured()) {
            Log::warning("SkySMS not configured, {$bookings->count()} booking reminder(s) skipped");
            $this->summary($sent, 0, 0, $noDevice, skippedNotConfigured: $bookings->count());

            return self::FAILURE;
        }

        $failed = 0;
        $skippedNoNumber = 0;

        // One sender for the whole run, so its two-second spacing is measured
        // across every booking reminder — the account allows 30 texts a minute.
        $this->sender = app(PacedSender::class);

        foreach ($bookings as $booking) {
            match ($this->remindBooking($booking, $today, $now)) {
                self::OUTCOME_SENT => $sent++,
                self::OUTCOME_FAILED => $failed++,
                self::OUTCOME_SKIPPED => $skippedNoNumber++,
            };
        }

        $this->summary($sent, $failed, $skippedNoNumber, $noDevice, skippedNotConfigured: 0);

        return self::SUCCESS;
    }

    /**
     * The office's own copy of the reminder — one email, not one per
     * borrowing, and best-effort like every other channel here: a mail
     * failure is logged and swallowed, never thrown, so it cannot affect
     * whether the resident's own push/SMS gets sent or marked. Independent
     * in the other direction too — called before the SmsGateway::configured()
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

    /** A push-only reminder that FCM accepted for no device: staff are told, the row stays unmarked. */
    private const OUTCOME_NO_DEVICE = 'no_device';

    private function summary(int $sent, int $failed, int $skippedNoNumber, int $noDevice, int $skippedNotConfigured): void
    {
        $this->info(
            "{$sent} sent, {$failed} failed (will retry), ".
            "{$skippedNoNumber} skipped (no usable number), ".
            "{$noDevice} not delivered (no registered device), ".
            "{$skippedNotConfigured} skipped (not configured)."
        );
    }

    /**
     * Push only. 'sent' once FCM has accepted the push for at least one device,
     * which marks the row so it is not reminded again. 'no_device' means FCM
     * reached nobody — no registered device, FCM unconfigured, or every device
     * refused — and leaves the row unmarked so the next run tries again, with a
     * follow-up for staff recorded. Accepted is not shown: a resident who has
     * turned notifications off in the phone's own settings is accepted too.
     *
     * resident_id is a required, non-nullable FK on this table — unlike
     * ServiceRequest, every borrowing has a resident to push to.
     */
    private function remind(EquipmentBorrowing $borrowing, string $today, Carbon $now): string
    {
        $accepted = app(Fcm::class)->notifyResident(
            $borrowing->resident_id,
            self::PUSH_TITLE,
            $this->reminderPushBody($borrowing, $today),
            ['borrow_id' => (string) $borrowing->borrow_id],
        );

        if ($accepted === 0) {
            ReminderFollowUp::record($borrowing, ReminderFollowUp::DUE_REMINDER);

            return self::OUTCOME_NO_DEVICE;
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

        // Fired before the SMS channel below and never touches $booking's
        // marker — see the class docblock for why the two channels stay
        // independent here.
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

    /** The push text for an equipment loan. No length budget: the item name goes in whole. */
    private function reminderPushBody(EquipmentBorrowing $borrowing, string $today): string
    {
        $when = $borrowing->due_date->toDateString() === $today ? 'today' : 'tomorrow';
        $itemName = $borrowing->equipment->item_name ?? $borrowing->other_equipment_text ?? 'item';

        return 'Your borrowed '.$itemName.' is due back '.$when.' ('.$borrowing->due_date->format('M j').'). — MDRRMO Echague';
    }

    /**
     * "Today"/"tomorrow" read against the office calendar, the same day the
     * push bodies use — a booking at 12:30 AM Manila time is still "today"
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

    /** No 160-character segment budget here. */
    private function bookingReminderPushBody(AmbulanceBooking $booking, string $today): string
    {
        return 'Your ambulance is scheduled '.$this->bookingWhen($booking, $today)
            .' at '.$this->bookingTime($booking).'. — MDRRMO Echague';
    }
}
