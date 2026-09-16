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

        $sent = 0;
        $skipped = 0;

        foreach ($borrowings as $borrowing) {
            if ($this->remind($borrowing, $today, $now)) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        $this->info("{$sent} reminder(s) sent, {$skipped} skipped (no reachable phone number).");

        return self::SUCCESS;
    }

    /**
     * True on an attempted send (marks the row so it is not retried), false
     * on a true skip (no reachable number — left unmarked, so a resident who
     * fixes their number before the due date still gets reminded).
     *
     * resident_id is a required, non-nullable FK on this table — unlike
     * ServiceRequest, every borrowing has a resident to read a number from.
     */
    private function remind(EquipmentBorrowing $borrowing, string $today, Carbon $now): bool
    {
        if (! PhilSms::configured()) {
            return false;
        }

        $number = PhilSms::normalize((string) $borrowing->resident->phone_number);

        if ($number === '') {
            return false;
        }

        try {
            $response = app(PhilSms::class)->send([$number], $this->reminderMessage($borrowing, $today));

            if (! PhilSms::accepted($response)) {
                Log::warning('Return-due reminder SMS not accepted, will retry', [
                    'borrow_id' => $borrowing->borrow_id,
                    'status' => $response->status(),
                ]);

                return false;
            }
        } catch (\Throwable $e) {
            Log::error('Return-due reminder SMS failed, will retry', [
                'borrow_id' => $borrowing->borrow_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $borrowing->update(['return_reminder_sent_at' => $now]);

        return true;
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
