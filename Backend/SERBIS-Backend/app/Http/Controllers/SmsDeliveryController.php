<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\SmsLog;
use App\Services\Sms\SmsGateway;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * What became of a blast after SkySMS accepted it.
 *
 * A blast is recorded as Queued (SmsController::sendBlast): SkySMS took it and
 * billed the credits, and said nothing about delivery. The only place it says
 * more is GET /sms/messages, read here on request. Nothing in this class treats
 * Queued or Pending as delivered; a message is Sent only when the vendor's list
 * says sent.
 */
class SmsDeliveryController extends Controller
{
    private const RECENT = 10;

    /** 100 messages a page. Past this the admin is looking at a window too big to read from a button. */
    private const MAX_PAGES = 10;

    /** A second press inside this window is answered from what is stored, not from the vendor. */
    private const RECHECK_SECONDS = 20;

    /**
     * The ten most recent blast rows with the counts as last recorded. No vendor
     * call: this is what the page shows on load.
     */
    public function index(): JsonResponse
    {
        $logs = SmsLog::query()
            ->with(['sender:admin_id,first_name,last_name,username', 'barangay:barangay_id,barangay_name'])
            ->withCount('queueIds')
            ->latest()
            ->orderByDesc('sms_log_id')
            ->limit(self::RECENT)
            ->get();

        $counts = $this->countsFor($logs->modelKeys());

        return response()->json([
            'data' => $logs->map(fn (SmsLog $log) => $this->present($log, $counts[$log->getKey()] ?? [])),
        ]);
    }

    /**
     * Asks SkySMS about one blast row and records the answer on its recipients.
     *
     * A vendor message counts for a recipient only when its id is one of the
     * queue ids stored for this row AND its phone number is that recipient's.
     * The ids are kept per request, not per barangay, so the phone is what
     * keeps one barangay's row from counting another's messages.
     *
     * Always 200: "could not check" is an answer the panel shows, not an error.
     * The row comes back either way, so the panel always has something to draw.
     */
    public function check(SmsLog $smsLog, SmsGateway $gateway): JsonResponse
    {
        if ($gateway->faking()) {
            return $this->answer($smsLog, false, 'fake', 'SMS is faked locally, nothing to check.');
        }

        $queueIds = $smsLog->queueIds()->pluck('queue_id')->map(fn ($id) => (string) $id)->unique()->values();

        if ($queueIds->isEmpty()) {
            return $this->answer($smsLog, false, 'no_queue_ids', "This blast has no stored SkySMS message ids, so its delivery can't be checked.");
        }

        if ($smsLog->delivery_checked_at && $smsLog->delivery_checked_at->diffInSeconds(now(), true) < self::RECHECK_SECONDS) {
            return $this->answer($smsLog, true);
        }

        $found = $this->fetchMessages($gateway, $smsLog, $queueIds);

        if ($found === null) {
            return $this->answer($smsLog, false, 'unavailable', 'SkySMS could not be reached, or refused the request. Nothing was changed; try again shortly.');
        }

        $notFound = $this->record($smsLog, $found);

        return $this->answer($smsLog->refresh(), true, null, null, $notFound);
    }

    /**
     * The vendor messages whose id is one of $queueIds, keyed by normalised
     * phone number. Null when a page of the list could not be read: a half-read
     * list would report the unread part as "not found".
     *
     * @param  Collection<int, string>  $queueIds
     * @return array<string, array<string, mixed>>|null
     */
    private function fetchMessages(SmsGateway $gateway, SmsLog $log, Collection $queueIds): ?array
    {
        // From the day the blast was recorded, to the day after: the list
        // filters by date, and UTC midnight is easily crossed by a late send.
        $from = $log->created_at->copy()->utc()->toDateString();
        $to = $log->created_at->copy()->utc()->addDay()->toDateString();

        $wanted = $queueIds->flip();
        $byPhone = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $gateway->messages($from, $to, $page);

            if ($result === null) {
                return null;
            }

            foreach ($result['data'] as $message) {
                if (! $wanted->has((string) ($message['id'] ?? ''))) {
                    continue;
                }

                $phone = PhoneNumber::normalize((string) ($message['phone_number'] ?? ''));

                if ($phone !== '') {
                    $byPhone[$phone] = $message;
                }
            }

            if ($page >= $result['last_page']) {
                break;
            }
        }

        return $byPhone;
    }

    /**
     * Writes the vendor's status onto each recipient it matches, stamps the
     * check, and rolls the row up when every recipient agrees. A recipient with
     * no matching vendor message keeps the status it had.
     *
     * @param  array<string, array<string, mixed>>  $byPhone
     * @return int recipients the vendor list had no message for
     */
    private function record(SmsLog $log, array $byPhone): int
    {
        $byStatus = [];
        $notFound = 0;

        foreach ($log->recipients()->with('resident:resident_id,phone_number')->get() as $recipient) {
            $phone = PhoneNumber::normalize((string) $recipient->resident?->phone_number);

            if (! isset($byPhone[$phone])) {
                $notFound++;

                continue;
            }

            $byStatus[Recipient::fromVendor($byPhone[$phone]['status'] ?? null)][] = $recipient->getKey();
        }

        foreach ($byStatus as $status => $ids) {
            Recipient::whereIn('recipient_id', $ids)->update(['status' => $status]);
        }

        $counts = $this->countsFor([$log->getKey()])[$log->getKey()] ?? [];
        $total = array_sum($counts);

        // The row says Sent or Failed only when every recipient does. A mixed
        // or still-pending blast stays Queued.
        $log->status = match (true) {
            $total > 0 && ($counts[Recipient::SENT] ?? 0) === $total => Recipient::SENT,
            $total > 0 && ($counts[Recipient::FAILED] ?? 0) === $total => Recipient::FAILED,
            default => $log->status,
        };
        $log->delivery_checked_at = now();
        $log->save();

        return $notFound;
    }

    private function answer(SmsLog $log, bool $checked, ?string $reason = null, ?string $message = null, ?int $notFound = null): JsonResponse
    {
        $log->loadMissing(['sender:admin_id,first_name,last_name,username', 'barangay:barangay_id,barangay_name']);
        $log->loadCount('queueIds');

        return response()->json([
            'checked' => $checked,
            'reason' => $reason,
            'message' => $message,
            'not_found' => $notFound,
            'data' => $this->present($log, $this->countsFor([$log->getKey()])[$log->getKey()] ?? []),
        ]);
    }

    /**
     * @param  array<int, int|string>  $logIds
     * @return array<int, array<string, int>> recipient counts by status, keyed by log id
     */
    private function countsFor(array $logIds): array
    {
        $counts = [];

        Recipient::query()
            ->whereIn('sms_log_id', $logIds)
            ->selectRaw('sms_log_id, status, count(*) as total')
            ->groupBy('sms_log_id', 'status')
            ->get()
            ->each(function ($row) use (&$counts) {
                $counts[$row->sms_log_id][$row->status] = (int) $row->total;
            });

        return $counts;
    }

    /**
     * Every state is always present, zero included: the panel draws all of them,
     * and a state that is missing would read as "not tracked" instead of "none".
     *
     * @param  array<string, int>  $counts
     */
    private function present(SmsLog $log, array $counts): array
    {
        $states = [
            'queued' => Recipient::QUEUED,
            'pending' => Recipient::PENDING,
            'sent' => Recipient::SENT,
            'failed' => Recipient::FAILED,
            'unconfirmed' => Recipient::UNCONFIRMED,
            'other' => Recipient::OTHER,
        ];

        return [
            'sms_log_id' => $log->getKey(),
            'created_at' => $log->created_at,
            'barangay' => $log->barangay?->barangay_name ?? 'Unknown barangay',
            'sender' => $log->sender ? $log->sender->displayName() : 'Unknown sender',
            'message' => $log->message_body,
            'status' => $log->status,
            'recipient_count' => array_sum($counts),
            'checkable' => ($log->queue_ids_count ?? $log->queueIds()->count()) > 0,
            'delivery_checked_at' => $log->delivery_checked_at,
            'counts' => collect($states)->map(fn (string $status) => $counts[$status] ?? 0)->all(),
        ];
    }
}
