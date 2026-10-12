<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\SmsLog;
use App\Services\Sms\SmsGateway;
use App\Support\PhoneNumber;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

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
    use PaginatesLists;

    /** Every status a recipient can hold, as ?status= accepts them. */
    private const STATUSES = [Recipient::QUEUED, Recipient::PENDING, Recipient::SENT, Recipient::FAILED, Recipient::UNCONFIRMED, Recipient::OTHER];

    /** Blasts shown. A blast is one send, however many barangays it reached. */
    private const RECENT = 10;

    /** Barangay rows read to find those blasts: a send to every barangay is 60-odd rows. */
    private const WINDOW = 400;

    /** Rows of one send are written within a second or two; a repeat of the same text minutes later is a new blast. */
    private const SAME_BLAST_SECONDS = 120;

    /** 100 messages a page. Past this the admin is looking at a window too big to read from a button. */
    private const MAX_PAGES = 10;

    /** A second press inside this window is answered from what is stored, not from the vendor. */
    private const RECHECK_SECONDS = 20;

    /**
     * The ten most recent blasts, each with its barangays and the counts as last
     * recorded. No vendor call: this is what the page shows on load. Recipients
     * are not listed here (see recipients()): a send to a whole municipality
     * would put thousands of names in a list nobody opens.
     */
    public function index(): JsonResponse
    {
        $logs = SmsLog::query()
            ->with(['sender:admin_id,first_name,last_name,username', 'barangay:barangay_id,barangay_name'])
            ->withCount('queueIds')
            ->latest()
            ->orderByDesc('sms_log_id')
            ->limit(self::WINDOW)
            ->get();

        $counts = $this->countsFor($logs->modelKeys());

        return response()->json([
            'data' => $this->groupIntoBlasts($logs)
                ->take(self::RECENT)
                ->map(fn (Collection $blast) => $this->presentBlast($blast, $counts))
                ->values(),
        ]);
    }

    /**
     * Who one barangay's message went to, one page at a time: name, masked
     * number, status and when that status last changed. Read when a barangay
     * is opened, not on the list load, because a send to a whole municipality
     * is thousands of residents.
     *
     * ?status= narrows to one status, ?search= matches a name or a number
     * (typed as 0917…, 63917… or +63917…), ?page= and ?per_page= page it.
     * The number is masked to its first and last four digits: the panel needs
     * to tell residents apart, not to read the number back.
     */
    public function recipients(Request $request, SmsLog $smsLog): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Recipient::query()
            ->join('tbl_residents', 'tbl_residents.resident_id', '=', 'tbl_recipients.resident_id')
            ->where('tbl_recipients.sms_log_id', $smsLog->getKey())
            ->select(['tbl_recipients.recipient_id', 'tbl_recipients.resident_id', 'tbl_recipients.status', 'tbl_recipients.created_at', 'tbl_recipients.updated_at'])
            ->with('resident:resident_id,first_name,last_name,phone_number')
            ->orderBy('tbl_residents.last_name')
            ->orderBy('tbl_residents.first_name')
            ->orderBy('tbl_recipients.recipient_id');

        if (! empty($filters['status'])) {
            $query->where('tbl_recipients.status', $filters['status']);
        }

        if (($term = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $number = PhoneNumber::normalize($term);

            $query->where(function ($q) use ($like, $number) {
                $q->where('tbl_residents.first_name', 'like', $like)
                    ->orWhere('tbl_residents.last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(tbl_residents.first_name, ' ', tbl_residents.last_name) LIKE ?", [$like])
                    ->orWhere('tbl_residents.phone_number', 'like', $like);

                if ($number !== '') {
                    $q->orWhere('tbl_residents.phone_number', $number);
                }
            });
        }

        $page = $query->paginate($this->resolvePerPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Recipient $r) => [
                'recipient_id' => $r->getKey(),
                'name' => trim(($r->resident?->first_name ?? '').' '.($r->resident?->last_name ?? '')) ?: 'Unknown resident',
                'phone' => $this->maskPhone((string) $r->resident?->phone_number),
                'status' => $r->status,
                'queued_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ])->values(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /** 0917 ••• 4821: the first and last four digits, nothing between. A value too short to mask meaningfully is hidden whole. */
    private function maskPhone(string $number): string
    {
        $digits = preg_replace('/D/', '', PhoneNumber::display($number)) ?? '';

        return strlen($digits) >= 8
            ? substr($digits, 0, 4).' ••• '.substr($digits, -4)
            : '••••';
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

        // Only a recipient whose status actually changes is written: updated_at
        // is what the panel shows as "when it changed", so a re-check that finds
        // the same status must not move it.
        foreach ($byStatus as $status => $ids) {
            Recipient::whereIn('recipient_id', $ids)->where('status', '!=', $status)->update(['status' => $status]);
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
     * The barangay rows of one send, newest send first. A send writes one row
     * per barangay (SmsController::recordBlast), all within a second or two with
     * the same sender and text, so those three together are what makes rows one
     * blast. The same text sent again minutes later stays a separate blast.
     *
     * @param  Collection<int, SmsLog>  $logs  newest first
     * @return Collection<int, Collection<int, SmsLog>>
     */
    private function groupIntoBlasts(Collection $logs): Collection
    {
        $blasts = [];

        foreach ($logs as $log) {
            $home = null;

            foreach ($blasts as $i => $blast) {
                $first = $blast->first();

                if ($first->sender_id === $log->sender_id
                    && $first->message_body === $log->message_body
                    && abs($first->created_at->diffInSeconds($log->created_at, false)) <= self::SAME_BLAST_SECONDS) {
                    $home = $i;
                    break;
                }
            }

            if ($home === null) {
                $blasts[] = collect([$log]);
            } else {
                $blasts[$home]->push($log);
            }
        }

        return collect($blasts);
    }

    /**
     * One send as the panel draws it: totals for the whole send, then each
     * barangay's own counts. Totals are sums of the barangay rows, so they can
     * never disagree with the rows beneath them.
     *
     * @param  Collection<int, SmsLog>  $blast
     * @param  array<int, array<string, int>>  $counts
     */
    private function presentBlast(Collection $blast, array $counts): array
    {
        $rows = $blast
            ->map(fn (SmsLog $log) => $this->present($log, $counts[$log->getKey()] ?? []))
            ->sortBy(fn (array $row) => mb_strtolower($row['barangay']))
            ->values();

        $first = $blast->first();
        $totals = [];

        foreach (array_keys($rows->first()['counts']) as $state) {
            $totals[$state] = $rows->sum(fn (array $row) => $row['counts'][$state]);
        }

        return [
            'blast_id' => $first->getKey(),
            'created_at' => $blast->min('created_at'),
            'sender' => $rows->first()['sender'],
            'message' => $first->message_body,
            'barangay_count' => $rows->count(),
            'recipient_count' => array_sum($totals),
            'counts' => $totals,
            'checkable' => $rows->contains('checkable', true),
            'delivery_checked_at' => $rows->pluck('delivery_checked_at')->filter()->max(),
            'barangays' => $rows,
        ];
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
