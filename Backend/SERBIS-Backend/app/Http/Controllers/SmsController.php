<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsLog;
use App\Services\PhilSms;
use App\Traits\PaginatesLists;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    use PaginatesLists;

    public function sendBlast(Request $request, PhilSms $philSms)
    {
        $validated = $request->validate([
            'message'     => 'required|string|max:160',
            'barangays'   => 'required|array|min:1',
            'barangays.*' => 'integer|exists:tbl_barangay,barangay_id',
        ]);

        $residents = $this->resolveRecipients($validated['barangays']);

        // Never call a billed endpoint with nothing to send.
        if ($residents->isEmpty()) {
            return response()->json([
                'message' => 'No residents in the selected barangays are active, opted in to SMS and have a reachable phone number.',
                'sent'    => 0,
                'failed'  => 0,
            ], 422);
        }

        $recipients = $residents
            ->pluck('phone_number')
            ->values()
            ->all();

        $response = $philSms->send($recipients, $validated['message']);

        $succeeded = PhilSms::accepted($response);

        // Recorded either way. A failed blast is the more important of the two to
        // have written down — it is the one somebody will ask about afterwards —
        // and the advisory feed reads only the sent ones, so a failure cannot
        // masquerade as a warning that went out.
        $this->recordBlast(
            $request->user()->admin_id,
            $residents,
            $validated['message'],
            $succeeded,
            $response->json('data.uid') ?? $response->json('job_id') ?? $response->json('id'),
        );

        if ($succeeded) {
            return response()->json([
                'message' => 'Text blast completed.',
                'sent'    => count($recipients),
                'failed'  => 0,
            ]);
        }

        Log::error('PhilSMS send failed', [
            'status'   => $response->status(),
            'response' => $response->body(),
        ]);

        return response()->json([
            'message' => 'Failed to send blast.',
            'sent'    => 0,
            'failed'  => count($recipients),
        ], 500);
    }

    /**
     * The recipient count the Text Blast page shows before the Send button.
     *
     * Deliberately runs the identical resolution the send runs — see
     * resolveRecipients() — rather than a cheaper SELECT COUNT(*). A count that
     * disagrees with the send is worse than no count: it is quoted to the desk
     * as the size of a blast that has not happened yet.
     */
    public function recipientCount(Request $request)
    {
        $validated = $request->validate([
            'barangays'   => 'required|array|min:1',
            'barangays.*' => 'integer|exists:tbl_barangay,barangay_id',
        ]);

        return response()->json([
            'count' => $this->resolveRecipients($validated['barangays'])->count(),
        ]);
    }

    /**
     * Remaining SMS credit, for the Text Blast page's header.
     *
     * Always 200, including on every failure path. The balance is decoration on
     * a page whose actual job is sending: a 500 here would surface as a red
     * alert on a form that works fine, and an unreachable vendor dashboard is
     * not a reason to hold back an advisory. Callers branch on `available`,
     * never on the status code.
     *
     * `data` is passed through untouched. PhilSMS documents it only as "sms unit
     * with all details", so this endpoint refuses to reshape a body whose shape
     * is not actually pinned down.
     */
    public function balance(PhilSms $philSms)
    {
        if (! PhilSms::configured()) {
            return response()->json([
                'available' => false,
                'message'   => 'No PhilSMS token is configured on this server.',
            ]);
        }

        try {
            $response = $philSms->balance();
        } catch (\Throwable $e) {
            Log::warning('PhilSMS balance lookup failed', ['error' => $e->getMessage()]);

            return response()->json([
                'available' => false,
                'message'   => 'Could not reach PhilSMS to read the credit balance.',
            ]);
        }

        // A 200 carrying status "error" is a refusal, not a balance — the same
        // trap PhilSms::accepted() exists for on the send path.
        if (! PhilSms::accepted($response)) {
            Log::warning('PhilSMS balance refused', [
                'status'   => $response->status(),
                'response' => $response->body(),
            ]);

            return response()->json([
                'available' => false,
                'message'   => $response->json('message') ?? 'PhilSMS refused the balance request.',
            ]);
        }

        return response()->json([
            'available' => true,
            'data'      => $response->json('data'),
        ]);
    }

    /**
     * The single definition of "who receives a blast to these barangays", shared
     * by the send and by the page's pre-send preview.
     *
     * Extracted rather than copied because the last filter is PHP, not SQL. A
     * preview written as a ->count() would count residents whose stored number
     * PhilSms::normalize() rejects, and so quote a number the send would never
     * match. Anything added here has to stay in one place for the two to keep
     * agreeing.
     *
     * Resident ids come back alongside the numbers: the vendor only needs the
     * number, but tbl_recipients records who was included, and that is not
     * derivable afterwards — the filters here mean barangay membership is a
     * different set.
     *
     * @param  array<int, int>  $barangayIds
     */
    private function resolveRecipients(array $barangayIds)
    {
        $residents = Resident::whereIn('barangay_id', $barangayIds)
            ->where('status', 'Active')
            // The resident's own choice, set from the mobile app via PATCH /me.
            // Compared against the column rather than the model's boolean cast
            // because this runs as SQL; the column is NOT NULL with a default of
            // 1, so there is no third state to account for.
            ->where('sms_opt_in', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get(['resident_id', 'barangay_id', 'phone_number']);

        // A number the vendor will reject is not a recipient. Dropping those here
        // rather than inside the send keeps tbl_recipients honest: it records who
        // the message actually went to, and a resident whose number cannot be
        // dialled did not receive it.
        return $residents->filter(
            fn ($resident) => PhilSms::normalize($resident->phone_number) !== ''
        )->values();
    }

    /**
     * Residents read their advisories here. Scoped to the blasts this resident
     * was actually a recipient of, not to their barangay: a resident who was
     * Inactive when the warning went out did not receive it, and showing it to
     * them now would misrepresent what the agency sent.
     */
    public function advisories(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        $advisories = SmsLog::query()
            ->where('status', 'Sent')
            ->whereHas('recipients', fn ($query) => $query->where('resident_id', $user->getKey()))
            ->with('barangay:barangay_id,barangay_name')
            ->latest()
            ->get(['sms_log_id', 'target_area_id', 'message_body', 'status', 'created_at']);

        return response()->json(['data' => $advisories]);
    }

    /**
     * The admin panel's SMS History tab.
     *
     * The tab has existed for as long as the Logs page has, fetching GET
     * /logs/sms — a route that was never registered, so the table was handed a
     * 404 body where its rows should have been. Nothing was recorded to serve
     * it either until 2026-08-03; sendBlast() posted to the vendor and
     * persisted nothing at all.
     *
     * Shaped to the keys the table already asks for (user.name, message,
     * recipient_count) rather than returning the model raw, mirroring
     * SystemLogController. The recipient count is a withCount, not a loaded
     * relation: a blast to a whole municipality is thousands of rows and the
     * table shows a number.
     */
    public function history(Request $request)
    {
        $query = SmsLog::query()
            ->with([
                'sender:admin_id,first_name,last_name',
                'barangay:barangay_id,barangay_name',
            ])
            ->withCount('recipients')
            ->latest()
            // Tiebreaker, and this table needs it more than most: a blast
            // writes one row per barangay inside the same second, so ties are
            // the normal case here, not an edge one. Ordering by created_at
            // alone lets a paginated read repeat or skip a row.
            ->orderBy('sms_log_id', 'desc');

        $this->applyHistorySearch($query, (string) $request->query('search', ''));

        // One row per barangay per blast, so this grows faster than the number
        // of messages actually sent. See App\Traits\PaginatesLists for why the
        // other list endpoints were left returning bare arrays.
        $logs = $query->paginate($this->resolvePerPage($request));

        $mapped = collect($logs->items())->map(fn (SmsLog $log) => [
            'sms_log_id'      => $log->sms_log_id,
            'created_at'      => $log->created_at,
            'user'            => [
                // A blast outlives the admin who sent it: tbl_user rows can be
                // removed, and a history row with a blank sender is worse than
                // one that says so.
                'name' => $log->sender
                    ? $log->sender->first_name.' '.$log->sender->last_name
                    : 'Unknown sender',
            ],
            'barangay'        => $log->barangay?->barangay_name ?? 'Unknown barangay',
            'message'         => $log->message_body,
            'recipient_count' => $log->recipients_count,
            // 'Sent' or 'Failed'. Failed rows are shown here on purpose — this is
            // the record somebody consults after a blast did not arrive. Only the
            // resident-facing advisory feed filters them out.
            'status'          => $log->status,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $mapped,
            'meta'    => $this->paginationMeta($logs),
        ]);
    }

    /**
     * Server-side search for the SMS History tab. The Logs page's single
     * search box filters both tabs, and it used to be Vuetify's client-side
     * filter over the whole table; once the endpoint pages, a box that only
     * searched the loaded page would hide blasts rather than find them.
     *
     * Every column this searches is stored, unlike the system-log tab where
     * two of them are built in PHP.
     */
    private function applyHistorySearch($query, string $search): void
    {
        // Laravel's global TrimStrings and ConvertEmptyStringsToNull middleware
        // have already run by this point, so a whitespace-only box arrives here
        // as null and `(string) null` is ''. The trim is kept as belt-and-braces
        // for any caller that reaches this method without passing through that
        // middleware stack.
        $search = trim($search);

        // Not behaviour on today's schema — message_body is NOT NULL, so the
        // '%%' this would otherwise build matches every row and the result set
        // is identical. It is here for cost: without it, every unfiltered load
        // of the Logs page runs the two orWhereHas EXISTS subqueries below for
        // nothing.
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function ($q) use ($term) {
            $q->where('message_body', 'like', $term)
              ->orWhere('status', 'like', $term)
              ->orWhereHas('barangay', fn ($b) => $b->where('barangay_name', 'like', $term))
              ->orWhereHas('sender', function ($sender) use ($term) {
                  $sender->where('first_name', 'like', $term)
                         ->orWhere('last_name', 'like', $term)
                         ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term]);
              });
        });
    }

    /**
     * One log row per barangay, and one recipient row per resident under it. The
     * vendor call is a single request for everyone, but "what was sent to my
     * barangay" is the question the record has to answer.
     */
    private function recordBlast(
        int $senderId,
        $residents,
        string $message,
        bool $succeeded,
        ?string $apiJobId,
    ): void {
        $status = $succeeded ? 'Sent' : 'Failed';

        DB::transaction(function () use ($senderId, $residents, $message, $status, $apiJobId) {
            foreach ($residents->groupBy('barangay_id') as $barangayId => $group) {
                $log = SmsLog::create([
                    'sender_id'      => $senderId,
                    'target_area_id' => $barangayId,
                    'api_job_id'     => $apiJobId,
                    'message_body'   => $message,
                    'status'         => $status,
                ]);

                Recipient::insert(
                    $group->map(fn ($resident) => [
                        'sms_log_id'  => $log->sms_log_id,
                        'resident_id' => $resident->resident_id,
                        'status'      => $status,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ])->all(),
                );
            }
        });
    }
}
