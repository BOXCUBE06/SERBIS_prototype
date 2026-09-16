<?php

namespace App\Console\Commands;

use App\Models\EquipmentBorrowing;
use App\Services\PhilSms;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Week 7 item 3. One SMS per borrowing that is out and due back today or
 * tomorrow. Marked reminded (return_reminder_sent_at set) only once PhilSMS
 * actually accepts the send — a rejected response or a thrown exception
 * leaves the row unmarked so the next run retries it, bounded by the
 * due-date window above (at most today and tomorrow ever match, so a
 * permanently-failing number is retried at most twice, not forever).
 *
 * Best-effort like ServiceRequestController::notifyResident(): never throws.
 * A failed send is logged (borrowing id only, never the phone number or
 * message body) rather than the row being falsely marked as delivered.
 */
class SendReturnDueReminders extends Command
{
    protected $signature = 'serbis:send-return-reminders';

    protected $description = 'Text residents whose released equipment is due back today or tomorrow';

    /**
     * Named here rather than trusted from app.timezone (UTC) — same reasoning
     * as ServiceRequestController::OFFICE_TIMEZONE: due dates are set and read
     * against the office's own day, not the server's.
     */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    /** One billed PhilSMS segment. Matches ServiceRequestController's cap. */
    private const SMS_SEGMENT_LIMIT = 160;

    public function handle(): int
    {
        // Read once and derive both from it — two separate Carbon::now() calls
        // straddling a real midnight would put $tomorrow a full day further out
        // than $today expects, silently dropping whichever borrowing was due on
        // the day in between.
        $now = Carbon::now(self::OFFICE_TIMEZONE);
        $today = $now->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();

        $borrowings = EquipmentBorrowing::with('equipment')
            ->where('status', 'Released')
            ->whereNull('return_reminder_sent_at')
            ->whereIn('due_date', [$today, $tomorrow])
            ->get();

        // Checked once per run, not once per borrowing: this reflects a
        // global config value (the token), never a fact about one row, and a
        // missing token means every row in the window is equally unreachable
        // — one warning naming the count, not N identical per-row log lines.
        if (! PhilSms::configured()) {
            Log::warning("PhilSMS not configured, {$borrowings->count()} reminder(s) skipped");
            $this->summary(sent: 0, failed: 0, skippedNoNumber: 0, skippedNotConfigured: $borrowings->count());

            return self::FAILURE;
        }

        $sent = 0;
        $failed = 0;
        $skippedNoNumber = 0;

        foreach ($borrowings as $borrowing) {
            match ($this->remind($borrowing, $today, $now)) {
                self::OUTCOME_SENT => $sent++,
                self::OUTCOME_FAILED => $failed++,
                self::OUTCOME_SKIPPED => $skippedNoNumber++,
            };
        }

        $this->summary($sent, $failed, $skippedNoNumber, skippedNotConfigured: 0);

        return self::SUCCESS;
    }

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
        // PhilSms::configured() is checked once in handle(), before this is
        // ever called — a missing token is a fact about the run, not this row.
        $number = PhilSms::normalize((string) $borrowing->resident->phone_number);

        if ($number === '') {
            return self::OUTCOME_SKIPPED;
        }

        try {
            $response = app(PhilSms::class)->send([$number], $this->reminderMessage($borrowing, $today));

            if (! PhilSms::accepted($response)) {
                Log::warning('Return-due reminder SMS not accepted, will retry', [
                    'borrow_id' => $borrowing->borrow_id,
                    'status' => $response->status(),
                ]);

                return self::OUTCOME_FAILED;
            }
        } catch (\Throwable $e) {
            Log::error('Return-due reminder SMS failed, will retry', [
                'borrow_id' => $borrowing->borrow_id,
                'error' => $e->getMessage(),
            ]);

            return self::OUTCOME_FAILED;
        }

        $borrowing->update(['return_reminder_sent_at' => $now]);

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

        $itemName = $borrowing->equipment->item_name ?? $borrowing->other_equipment_text ?? 'item';
        $budget = self::SMS_SEGMENT_LIMIT - mb_strlen($prefix) - mb_strlen($suffix);

        if (mb_strlen($itemName) > $budget) {
            $itemName = mb_substr($itemName, 0, max($budget - 1, 0)).'…';
        }

        return $prefix.$itemName.$suffix;
    }
}
