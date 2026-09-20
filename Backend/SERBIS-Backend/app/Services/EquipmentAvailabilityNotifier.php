<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Support\ReminderFollowUp;

/**
 * MDRRMO feedback, 2026-09-18: a resident denied for "the equipment is not
 * available" hears nothing again unless they think to ask a second time.
 * Called wherever an equipment row's available_quantity goes from having
 * nothing to having something — a return, or an admin's own manual stock
 * edit — and asks every resident still waiting on that specific item whether
 * they still need it.
 *
 * Deliberately does not build a confirm/decline flow of its own: "still
 * need it?" is answered the same way every other request is made — by
 * filing one — so the message points back at the same borrow screen rather
 * than adding a second way to say yes.
 *
 * Push only. It used to text as well; that moved off SMS so the notice costs
 * no credits, and there is no SMS fallback. availability_reconfirm_sent_at is
 * set only once FCM accepts the push for at least one of the resident's
 * devices, so a resident with no device is asked again the next time stock
 * ticks up, and is put in front of staff (ReminderFollowUp) to ring instead.
 */
class EquipmentAvailabilityNotifier
{
    private const PUSH_TITLE = 'SERBIS';

    public function __construct(private readonly Fcm $fcm) {}

    public function notifyIfAvailable(Equipment $equipment): void
    {
        if ($equipment->available_quantity <= 0) {
            return;
        }

        $waiting = EquipmentBorrowing::with('resident')
            ->where('equipment_id', $equipment->equipment_id)
            ->where('status', 'Denied')
            ->where('denial_reason_code', 'Unavailable')
            ->whereNull('availability_reconfirm_sent_at')
            ->get();

        foreach ($waiting as $borrowing) {
            $this->notifyOne($borrowing, $equipment);
        }
    }

    private function notifyOne(EquipmentBorrowing $borrowing, Equipment $equipment): void
    {
        $accepted = $this->fcm->notifyResident(
            $borrowing->resident_id,
            self::PUSH_TITLE,
            $this->pushBody($equipment),
            ['borrow_id' => (string) $borrowing->borrow_id],
        );

        if ($accepted === 0) {
            ReminderFollowUp::record($borrowing, ReminderFollowUp::AVAILABILITY);

            return;
        }

        $borrowing->update(['availability_reconfirm_sent_at' => now()]);
    }

    private function pushBody(Equipment $equipment): string
    {
        return $equipment->item_name.' is available again. Still need it? Request it from the app. — MDRRMO Echague';
    }
}
