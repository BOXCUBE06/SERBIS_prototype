<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsBlastCode;
use App\Models\SmsLog;
use App\Services\PhilSms;
use App\Traits\PaginatesLists;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SmsController extends Controller
{
    use PaginatesLists;

    public function sendBlast(Request $request, PhilSms $philSms)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:160',
            'barangays' => 'required|array|min:1',
            'barangays.*' => 'integer|exists:tbl_barangay,barangay_id',
            'code' => 'required|string',
        ]);

        // Before anything is resolved or sent. This endpoint is the only one in
        // the application that spends money, and until now the sole thing
        // standing in front of it was a client-side dialog — see the route
        // definition, which notes that dialog does not survive a second tab, a
        // reload mid-request, or a token replayed by hand. There is no role
        // system (five admins, equal privileges — MDRRMO feedback,
        // 2026-09-19), so this checks a code shared between the two staff who
        // are supposed to know it, not the caller's own account password:
        // any admin token can reach this route, but only someone who was
        // told the code can make it actually send.
        $this->assertCurrentCode($request);

        $residents = $this->resolveRecipients($validated['barangays']);

        // Never call a billed endpoint with nothing to send.
        if ($residents->isEmpty()) {
            return response()->json([
                'message' => 'No residents in the selected barangays are active, opted in to SMS and have a reachable phone number.',
                'sent' => 0,
                'failed' => 0,
            ], 422);
        }

        $recipients = $residents
            ->pluck('phone_number')
            ->values()
            ->all();

        try {
            $response = $philSms->send($recipients, $validated['message'], PhilSms::BLAST_TIMEOUT);
        } catch (ConnectionException $e) {
            // The request left and the reply never came back. cURL 28 with zero
            // bytes received means PhilSMS almost certainly accepted, processed
            // and billed it — we stopped listening; the send did not stop.
            //
            // Recorded rather than rolled back, and deliberately NOT as
            // 'Failed'. Rolling back leaves no trace of a blast that reached
            // real handsets, and a 'Failed' row invites someone to send it
            // again and pay again — which is the exact behaviour this exists to
            // prevent.
            Log::warning('PhilSMS send timed out — delivery unconfirmed', [
                'recipients' => count($recipients),
                'error' => $e->getMessage(),
            ]);

            $this->recordBlast(
                $request->user()->admin_id,
                $residents,
                $validated['message'],
                'Unconfirmed',
                // No response, so no job id. Nothing to reconcile this against
                // later except the vendor's own dashboard.
                null,
            );

            // 202, not 200 and not 5xx. We cannot confirm delivery, so this is
            // not success; but a 5xx is what staff are currently retrying.
            return response()->json([
                'message' => 'PhilSMS did not answer in time, but the message was most likely sent and billed. Do NOT send it again — check the PhilSMS dashboard, or ask a recipient, before resending.',
                'unconfirmed' => true,
                'sent' => 0,
                'failed' => 0,
                'recipients' => count($recipients),
            ], 202);
        }

        $succeeded = PhilSms::accepted($response);

        // Recorded either way. A failed blast is the more important of the two to
        // have written down — it is the one somebody will ask about afterwards —
        // and the advisory feed withholds only Failed rows, so a blast that
        // never left cannot masquerade as a warning that went out. (Unconfirmed
        // is a third case and is shown; see advisories().)
        $this->recordBlast(
            $request->user()->admin_id,
            $residents,
            $validated['message'],
            $succeeded ? 'Sent' : 'Failed',
            $response->json('data.uid') ?? $response->json('job_id') ?? $response->json('id'),
        );

        if ($succeeded) {
            return response()->json([
                'message' => 'Text blast completed.',
                'sent' => count($recipients),
                'failed' => 0,
            ]);
        }

        Log::error('PhilSMS send failed', [
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return response()->json([
            'message' => 'Failed to send blast.',
            'sent' => 0,
            'failed' => count($recipients),
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
            'barangays' => 'required|array|min:1',
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
                'message' => 'No PhilSMS token is configured on this server.',
            ]);
        }

        try {
            $response = $philSms->balance();
        } catch (\Throwable $e) {
            Log::warning('PhilSMS balance lookup failed', ['error' => $e->getMessage()]);

            return response()->json([
                'available' => false,
                'message' => 'Could not reach PhilSMS to read the credit balance.',
            ]);
        }

        // A 200 carrying status "error" is a refusal, not a balance — the same
        // trap PhilSms::accepted() exists for on the send path.
        if (! PhilSms::accepted($response)) {
            Log::warning('PhilSMS balance refused', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return response()->json([
                'available' => false,
                'message' => $response->json('message') ?? 'PhilSMS refused the balance request.',
            ]);
        }

        return response()->json([
            'available' => true,
            'data' => $response->json('data'),
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
    /**
     * How many wrong codes this gate accepts, and for how long.
     *
     * Five matches the tight tier of the 'login' limiter in AppServiceProvider,
     * so the number this check allows is the same one everywhere in this
     * application. The window is fifteen minutes rather than login's one,
     * because the two endpoints are used at completely different rates: an
     * office signs in repeatedly through a day, but sends a blast rarely, so a
     * long decay costs a legitimate sender nothing.
     *
     * Only FAILURES are counted, and a success clears the tally, so an admin
     * sending several blasts in a row is never throttled by this. The route
     * itself is deliberately not throttled — that would cap legitimate sends.
     */
    private const CODE_ATTEMPTS = 5;

    private const CODE_DECAY_SECONDS = 900;

    /**
     * Proves the caller knows the shared text-blast code, not their own
     * account password — there is no role system in this application (five
     * admin accounts, equal privileges), so "knows the code" is the only
     * distinction between "may send a blast" and "may not" that exists.
     *
     * $inputKey lets rotateBlastCode() reuse this same check against its own
     * `current_code` field rather than duplicating the rate limit, the hash
     * check and the logging.
     *
     * Every attempt is logged with the admin who made it — success and
     * failure both — because this gate is the one thing standing between an
     * admin token and a billed vendor call, and "who tried the code, and did
     * it work" is exactly what gets asked about afterwards.
     */
    private function assertCurrentCode(Request $request, string $inputKey = 'code'): void
    {
        $admin = $request->user();
        $code = (string) $request->input($inputKey, '');

        if (! $admin) {
            throw ValidationException::withMessages([
                $inputKey => 'Enter the text blast code.',
            ]);
        }

        // Keyed on the account, not the IP: this route is behind auth:sanctum,
        // so there is always an account to key on, and an office on one CGNAT
        // address must not be able to lock its colleagues out of sending.
        // Shared across sendBlast() and rotateBlastCode() on purpose — both
        // are "prove you know the code" checks, and a caller should not get a
        // second guessing budget by rotating instead of sending.
        $key = 'sms-blast-code:'.$admin->admin_id;

        // Checked BEFORE the hash comparison, so once the limit is reached even
        // the correct code is refused until the window passes. A gate that let
        // a correct guess through on the sixth try would not be a limit.
        if (RateLimiter::tooManyAttempts($key, self::CODE_ATTEMPTS)) {
            Log::warning('SMS blast code attempt blocked: too many failures', [
                'admin_id' => $admin->admin_id,
            ]);

            throw new ThrottleRequestsException(
                'Too many incorrect codes. Try again in '
                .ceil(RateLimiter::availableIn($key) / 60).' minute(s).'
            );
        }

        $blastCode = SmsBlastCode::first();

        // Distinct from a wrong code: nobody has set one yet (a fresh
        // deployment with no seed, or the seed step was skipped), and no
        // code the caller types is ever going to satisfy that. Same shape as
        // Fcm::sendToDevice()'s "not configured" branch — a missing setup
        // step must not look identical to "you typed it wrong" in the logs.
        if (! $blastCode) {
            Log::error('SMS blast code attempt failed: no code configured', [
                'admin_id' => $admin->admin_id,
            ]);

            throw ValidationException::withMessages([
                $inputKey => 'No text blast code has been set yet. Ask an administrator to set one.',
            ]);
        }

        if (! Hash::check($code, $blastCode->code_hash)) {
            RateLimiter::hit($key, self::CODE_DECAY_SECONDS);

            Log::warning('SMS blast code attempt failed: wrong code', [
                'admin_id' => $admin->admin_id,
            ]);

            throw ValidationException::withMessages([
                $inputKey => 'That code is not correct.',
            ]);
        }

        // Cleared on the way through, so a typo followed by the right code
        // leaves nothing behind to count against the next legitimate blast.
        RateLimiter::clear($key);

        Log::info('SMS blast code accepted', ['admin_id' => $admin->admin_id]);
    }

    /**
     * Rotation requires the current code, so no admin can reset it without
     * already knowing it — the same reasoning AuthController's password
     * change applies to a user's own password, here applied to the one code
     * five equal accounts share.
     */
    public function rotateBlastCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_code' => 'required|string',
            'new_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'new_code.regex' => 'The new code must be exactly 6 digits.',
        ]);

        $this->assertCurrentCode($request, 'current_code');

        $admin = $request->user();

        // firstOrNew, not firstOrFail: the very first rotation (replacing a
        // seeded code, or setting one for the first time if the seed step was
        // skipped) has no row to update yet.
        $blastCode = SmsBlastCode::firstOrNew();
        $blastCode->code_hash = Hash::make($validated['new_code']);
        $blastCode->updated_by = $admin->admin_id;
        $blastCode->save();

        Log::info('SMS blast code rotated', ['admin_id' => $admin->admin_id]);

        return response()->json(['message' => 'Text blast code updated.']);
    }

    /**
     * What the admin panel shows on the rotation form: who set the current
     * code and when — never the code, never its hash.
     */
    public function blastCodeStatus(): JsonResponse
    {
        $blastCode = SmsBlastCode::with('updatedByAdmin:admin_id,first_name,last_name')->first();

        if (! $blastCode) {
            return response()->json(['configured' => false]);
        }

        return response()->json([
            'configured' => true,
            'updated_by' => $blastCode->updatedByAdmin
                ? $blastCode->updatedByAdmin->first_name.' '.$blastCode->updatedByAdmin->last_name
                : 'Unknown',
            'updated_at' => $blastCode->updated_at,
        ]);
    }

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
            // 'Unconfirmed' included on purpose. It means PhilSMS never
            // answered, not that nothing was sent — the handset most likely has
            // the message, and a feed that omits it would contradict the phone
            // the resident is holding. Only 'Failed' is withheld, which is the
            // case where nothing went out at all.
            ->whereIn('status', ['Sent', 'Unconfirmed'])
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
            'sms_log_id' => $log->sms_log_id,
            'created_at' => $log->created_at,
            'user' => [
                // A blast outlives the admin who sent it: tbl_user rows can be
                // removed, and a history row with a blank sender is worse than
                // one that says so.
                'name' => $log->sender
                    ? $log->sender->first_name.' '.$log->sender->last_name
                    : 'Unknown sender',
            ],
            'barangay' => $log->barangay?->barangay_name ?? 'Unknown barangay',
            'message' => $log->message_body,
            'recipient_count' => $log->recipients_count,
            // 'Sent' or 'Failed'. Failed rows are shown here on purpose — this is
            // the record somebody consults after a blast did not arrive. Only the
            // resident-facing advisory feed filters them out.
            'status' => $log->status,
        ]);

        return response()->json([
            'success' => true,
            'data' => $mapped,
            'meta' => $this->paginationMeta($logs),
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
        string $status,
        ?string $apiJobId,
    ): void {
        DB::transaction(function () use ($senderId, $residents, $message, $status, $apiJobId) {
            foreach ($residents->groupBy('barangay_id') as $barangayId => $group) {
                $log = SmsLog::create([
                    'sender_id' => $senderId,
                    'target_area_id' => $barangayId,
                    'api_job_id' => $apiJobId,
                    'message_body' => $message,
                    'status' => $status,
                ]);

                Recipient::insert(
                    $group->map(fn ($resident) => [
                        'sms_log_id' => $log->sms_log_id,
                        'resident_id' => $resident->resident_id,
                        'status' => $status,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                );
            }
        });
    }
}
