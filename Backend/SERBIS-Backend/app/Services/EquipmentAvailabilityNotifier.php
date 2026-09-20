<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Services\Sms\PacedSender;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsMessagePolicy;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

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
 * Push and SMS fail independently, same shape as SendReturnDueReminders:
 * the push is attempted regardless of whether SMS is reachable, and
 * availability_reconfirm_sent_at is set only once the SMS is actually
 * accepted, so a resident whose number is wrong is asked again the next
 * time stock ticks up rather than never again.
 */
class EquipmentAvailabilityNotifier
{
    private const PUSH_TITLE = 'SERBIS';

    public function __construct(
        private readonly Fcm $fcm,
        private readonly SmsGateway $gateway,
        // One per notifier, so the two-second spacing between texts is
        // measured across the whole loop over waiting borrowers.
        private readonly PacedSender $sender,
    ) {}

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
        $this->fcm->notifyResident(
            $borrowing->resident_id,
            self::PUSH_TITLE,
            $this->pushBody($equipment),
            ['borrow_id' => (string) $borrowing->borrow_id],
        );

        if (! $this->gateway->configured()) {
            return;
        }

        $number = PhoneNumber::normalize((string) $borrowing->resident?->phone_number);
        if ($number === '') {
            return;
        }

        $result = $this->sender->send($number, $this->smsMessage($equipment));

        if (! $result->isAccepted()) {
            Log::warning('Availability reconfirm SMS not accepted, will retry next restock', [
                'borrow_id' => $borrowing->borrow_id,
                'outcome' => $result->outcome,
                'reason' => $result->reason,
                'status' => $result->httpStatus,
            ]);

            return;
        }

        $borrowing->update(['availability_reconfirm_sent_at' => now()]);
    }

    private function itemName(Equipment $equipment): string
    {
        return $equipment->item_name;
    }

    private function smsMessage(Equipment $equipment): string
    {
        // ASCII only: the em dash this text used to carry is outside GSM-7 and
        // would have moved it to 70 characters a segment. The item name is
        // typed by staff and can hold curly quotes, so it goes through the same
        // swap.
        return 'SERBIS: '.SmsMessagePolicy::toGsmSafe($this->itemName($equipment)).' is available again. Still need it? '
            .'Request it from the app - your earlier request was not carried over.';
    }

    private function pushBody(Equipment $equipment): string
    {
        return $this->itemName($equipment).' is available again. Still need it? Request it from the app. — MDRRMO Echague';
    }
}
