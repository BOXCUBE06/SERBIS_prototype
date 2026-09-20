<?php

namespace App\Support;

use App\Models\EquipmentBorrowing;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Log;

/**
 * A push-only notice that reached nobody: the resident has no registered
 * device, FCM is not configured, or every device refused it. With no SMS behind
 * it, this is the only trace that the notice never arrived.
 *
 * Written as a system-log row, so it shows on the Logs page and in the
 * dashboard's follow-up list (AnalyticsController), where staff can ring the
 * resident. One row per borrowing and kind a day: the reminder command retries
 * an unmarked row on its next run and would otherwise repeat itself.
 */
class ReminderFollowUp
{
    public const ACTION = 'reminder_not_delivered';

    public const DUE_REMINDER = 'due_reminder';

    public const AVAILABILITY = 'availability';

    public const LABELS = [
        self::DUE_REMINDER => 'Equipment due-back reminder',
        self::AVAILABILITY => 'Equipment available-again notice',
    ];

    public static function record(EquipmentBorrowing $borrowing, string $kind): void
    {
        Log::warning('Push-only notice reached no device; staff should follow up', [
            'borrow_id' => $borrowing->borrow_id,
            'resident_id' => $borrowing->resident_id,
            'kind' => $kind,
            'reason' => 'no_registered_device',
        ]);

        $alreadyToday = SystemLog::where('action_type', self::ACTION)
            ->where('auditable_type', EquipmentBorrowing::class)
            ->where('auditable_id', $borrowing->getKey())
            ->where('new_values', 'like', '%"kind":"'.$kind.'"%')
            ->where('created_at', '>=', now()->subHours(20))
            ->exists();

        if ($alreadyToday) {
            return;
        }

        SystemLog::create([
            'resident_id' => $borrowing->resident_id,
            'action_type' => self::ACTION,
            'auditable_type' => EquipmentBorrowing::class,
            'auditable_id' => $borrowing->getKey(),
            'new_values' => ['kind' => $kind, 'reason' => 'no_registered_device'],
        ]);

        // The dashboard payload is cached for five minutes; the office opens it
        // just after the 08:00 run, so it must not serve the list from before.
        AnalyticsCache::flush();
    }
}
