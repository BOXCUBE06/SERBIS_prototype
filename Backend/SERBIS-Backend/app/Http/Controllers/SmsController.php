<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsBlastCode;
use App\Models\SmsLog;
use App\Models\SmsQueueId;
use App\Services\Sms\SkySmsGateway;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsMessagePolicy;
use App\Services\Sms\SmsResult;
use App\Support\PhoneNumber;
use App\Traits\PaginatesLists;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SmsController extends Controller
{
    use PaginatesLists;

    /** Residents per bulk request (the vendor's own cap); a bigger audience goes out as several requests, each recorded on its own. */
    private const BULK_CHUNK = SkySmsGateway::MAX_BULK;

    public function sendBlast(Request $request, SmsGateway $gateway)
    {
        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:160',
                // The vendor bills a link or domain 10-50 credits and does not deliver it; the panel checks first, this holds for other callers.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (SmsMessagePolicy::containsLink((string) $value)) {
                        $fail('A text blast cannot contain a link, web address or domain. The SMS provider penalises them and does not deliver the message. Remove it and send again.');
                    }
                },
            ],
            'barangays' => 'required|array|min:1',
            'barangays.*' => 'integer|exists:tbl_barangay,barangay_id',
            'code' => 'required|string',
        ]);

        // One attempt per Idempotency-Key: a resubmit gets the stored result, not a second billed send (Cache::add is atomic).
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Missing Idempotency-Key header.',
            ]);
        }

        $cacheKey = 'sms-blast:'.$request->user()->admin_id.':'.$idempotencyKey;

        if (! Cache::add($cacheKey, ['status' => 'processing'], now()->addMinutes(10))) {
            $stored = Cache::get($cacheKey);

            if (is_array($stored) && ($stored['status'] ?? null) === 'done') {
                return response()->json($stored['body'], $stored['http_status']);
            }

            return response()->json([
                'message' => 'This blast is already being sent. Wait for it to finish before retrying.',
            ], 409);
        }

        // Before anything is resolved or sent: holding the Text Blast section only opens the page, sending needs the shared code (MDRRMO feedback, 2026-09-19).
        try {
            $this->assertCurrentCode($request);
        } catch (\Throwable $e) {
            // No billed action happened, so the key must not stay claimed — a
            // wrong code is retried against the same dialog and the same key.
            Cache::forget($cacheKey);
            throw $e;
        }

        $residents = $this->resolveRecipients($validated['barangays']);

        // Never call a billed endpoint with nothing to send.
        if ($residents->isEmpty()) {
            return $this->respond($cacheKey, response()->json([
                'message' => 'No residents in the selected barangays are active, opted in to SMS and have a reachable phone number.',
                'sent' => 0,
                'failed' => 0,
            ], 422));
        }

        // Several bulk requests, and each may wait on a rate limit: the default
        // 30 seconds is not enough for a municipality.
        @set_time_limit(180);

        $groups = [];
        $firstFailure = null;
        $outOfCredits = false;

        foreach ($residents->chunk(self::BULK_CHUNK) as $chunk) {
            // Credits ran out on an earlier chunk: nothing more can go, so the
            // rest are recorded as not sent instead of asking the vendor again.
            $result = $outOfCredits
                ? SmsResult::rejected(SmsResult::REASON_OUT_OF_CREDITS)
                : $this->sendChunk($gateway, $chunk->pluck('phone_number')->all(), $validated['message']);

            $outOfCredits = $outOfCredits || $result->isOutOfCredits();

            if (! $result->isAccepted() && ! $result->isUnknown()) {
                $firstFailure ??= $result;
            }

            $groups[] = [
                'residents' => $chunk->values(),
                // Accepted is Queued, never Sent (only GET /sms/messages may say Sent); Unknown is Unconfirmed, not failed, so nobody resends and pays twice.
                'status' => match (true) {
                    $result->isAccepted() => Recipient::QUEUED,
                    $result->isUnknown() => Recipient::UNCONFIRMED,
                    default => Recipient::FAILED,
                },
                'job' => $result->queueId,
                'queue_ids' => $result->queueIds,
            ];
        }

        $this->recordBlast($request->user()->admin_id, $groups, $validated['message']);

        $count = fn (string $status) => collect($groups)
            ->where('status', $status)
            ->sum(fn ($group) => $group['residents']->count());

        $queued = $count(Recipient::QUEUED);
        $unconfirmed = $count(Recipient::UNCONFIRMED);
        $failed = $count(Recipient::FAILED);

        if ($unconfirmed > 0) {
            // 202, not 200 or 5xx: delivery is unconfirmed, and a retry of a message that probably went out is a second bill.
            return $this->respond($cacheKey, response()->json([
                'message' => 'SkySMS did not answer in time for '.$unconfirmed.' recipient(s), but the messages were most likely sent and billed. Do NOT send them again — check with a recipient before resending.',
                'unconfirmed' => true,
                'queued' => $queued,
                'failed' => $failed,
                'unconfirmed_count' => $unconfirmed,
                'recipients' => $residents->count(),
            ], 202));
        }

        if ($failed === 0) {
            return $this->respond($cacheKey, response()->json([
                'message' => 'Text blast queued. SkySMS has accepted it and billed the credits; delivery is not confirmed yet. Check status under Recent blasts.',
                'queued' => $queued,
                'failed' => 0,
            ]));
        }

        Log::error('SkySMS text blast had failures', [
            'queued' => $queued,
            'failed' => $failed,
            'reason' => $firstFailure?->reason,
            'status' => $firstFailure?->httpStatus,
        ]);

        // Some chunks were queued before one failed: report the split rather than
        // an error, so the desk knows part of the audience is already queued.
        if ($queued > 0) {
            return $this->respond($cacheKey, response()->json([
                'message' => 'Part of the blast was queued: '.$queued.' queued, '.$failed.' failed. Do not resend the whole message — the recipients that were queued would get it twice.',
                'queued' => $queued,
                'failed' => $failed,
            ]));
        }

        return $this->respond($cacheKey, $this->blastFailureResponse($firstFailure, $failed));
    }

    /** Stores the finished response under its idempotency key so a resubmit returns it, then returns it unchanged. */
    private function respond(string $cacheKey, JsonResponse $response): JsonResponse
    {
        Cache::put($cacheKey, [
            'status' => 'done',
            'body' => $response->getData(true),
            'http_status' => $response->getStatusCode(),
        ], now()->addMinutes(10));

        return $response;
    }

    /** @param array<int, string> $phones One bulk request, retried on vendor 429 with Retry-After or a doubling wait. */
    private function sendChunk(SmsGateway $gateway, array $phones, string $message): SmsResult
    {
        $base = (float) config('services.skysms.retry_base_seconds', 2);
        $maxRetries = (int) config('services.skysms.max_retries', 3);

        $result = $gateway->sendBulk($phones, $message);

        for ($attempt = 0; $attempt < $maxRetries && $result->isRateLimited(); $attempt++) {
            $wait = max((float) ($result->retryAfter ?? 0), $base * (2 ** $attempt));

            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            $result = $gateway->sendBulk($phones, $message);
        }

        return $result;
    }

    /** What the admin is told when nothing at all went out, by why. */
    private function blastFailureResponse(?SmsResult $failure, int $failed): JsonResponse
    {
        $body = ['sent' => 0, 'failed' => $failed];

        return match ($failure?->reason) {
            SmsResult::REASON_OUT_OF_CREDITS => response()->json($body + [
                'message' => 'The SMS credits are used up, so nothing was sent. Top up the SkySMS account, then send again.',
                'code' => 'out_of_credits',
            ], 402),
            SmsResult::REASON_WARNING => response()->json($body + [
                'message' => 'The SMS provider flagged this message'.($failure->detail ? ' ("'.$failure->detail.'")' : '').' and will not deliver it. Reword it and send again.',
                'code' => 'flagged',
            ], 422),
            SmsResult::REASON_RATE_LIMITED => response()->json($body + [
                'message' => 'The SMS provider is limiting how fast this account can send. Wait a minute, then send again.',
                'code' => 'rate_limited',
            ], 429, array_filter(['Retry-After' => $failure->retryAfter])),
            default => response()->json($body + ['message' => 'Failed to send blast.'], 500),
        };
    }

    /** The pre-send recipient count; runs the same resolveRecipients() as the send so the quoted size matches the blast. */
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

    /** Remaining SMS credit for the page header: always 200, and the balance as of the last message, not a live read (SkySMS has no balance endpoint). */
    public function balance(SmsGateway $gateway)
    {
        if (! $gateway->configured()) {
            return response()->json([
                'available' => false,
                'message' => 'No SkySMS API key is configured on this server.',
            ]);
        }

        if (Cache::get(SkySmsGateway::CACHE_OUT_OF_CREDITS)) {
            return response()->json([
                'available' => false,
                'out_of_credits' => true,
                'message' => 'Out of SMS credits. Top up the SkySMS account before sending.',
            ]);
        }

        $credits = Cache::get(SkySmsGateway::CACHE_CREDITS);

        if ($credits === null) {
            return response()->json([
                'available' => false,
                'message' => 'The credit balance appears after the next message is sent.',
            ]);
        }

        return response()->json([
            'available' => true,
            'data' => [
                'remaining_credits' => (int) $credits,
                'as_of' => Cache::get(SkySmsGateway::CACHE_CREDITS_AT),
            ],
        ]);
    }

    /** Wrong codes allowed (5, like the login limiter) per 15-minute window; only failures count and a success clears the tally. */
    private const CODE_ATTEMPTS = 5;

    private const CODE_DECAY_SECONDS = 900;

    /** Proves the caller knows the shared blast code, not their password; rotateBlastCode() reuses it via $inputKey, and every attempt is logged with the admin. */
    private function assertCurrentCode(Request $request, string $inputKey = 'code'): void
    {
        $admin = $request->user();
        $code = (string) $request->input($inputKey, '');

        if (! $admin) {
            throw ValidationException::withMessages([
                $inputKey => 'Enter the text blast code.',
            ]);
        }

        // Keyed on the account, not the IP (CGNAT offices), and shared with rotateBlastCode() so rotating is not a second guessing budget.
        $key = 'sms-blast-code:'.$admin->admin_id;

        // Checked before the hash, so once the limit is hit even the correct code is refused until the window passes.
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

        // Distinct from a wrong code: none has been set yet (seed skipped), and that must not look like a typo in the logs.
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

    /** Rotation requires the current code, so no admin can reset the shared code without already knowing it. */
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

        // firstOrNew: the very first rotation has no row to update yet.
        $blastCode = SmsBlastCode::firstOrNew();
        $blastCode->code_hash = Hash::make($validated['new_code']);
        $blastCode->updated_by = $admin->admin_id;
        $blastCode->save();

        Log::info('SMS blast code rotated', ['admin_id' => $admin->admin_id]);

        return response()->json(['message' => 'Text blast code updated.']);
    }

    /** What the rotation form shows: who set the code and when, never the code or its hash. */
    public function blastCodeStatus(): JsonResponse
    {
        $blastCode = SmsBlastCode::with('updatedByAdmin:admin_id,first_name,last_name,username')->first();

        if (! $blastCode) {
            return response()->json(['configured' => false]);
        }

        return response()->json([
            'configured' => true,
            'updated_by' => $blastCode->updatedByAdmin
                ? $blastCode->updatedByAdmin->displayName()
                : 'Unknown',
            'updated_at' => $blastCode->updated_at,
        ]);
    }

    /** The one definition of who receives a blast, shared by the send and the preview (the last filter is PHP, so no COUNT(*)); ids come back for tbl_recipients. @param array<int, int> $barangayIds */
    private function resolveRecipients(array $barangayIds)
    {
        $residents = Resident::whereIn('barangay_id', $barangayIds)
            // Barangay and organization accounts are institutions, so a blast to a barangay's residents skips them.
            ->where('account_type', Resident::TYPE_HEAD_OF_FAMILY)
            ->where('status', 'Active')
            // The resident's own choice (PATCH /me); compared on the column, not the boolean cast, since this is SQL.
            ->where('sms_opt_in', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get(['resident_id', 'barangay_id', 'phone_number']);

        // A number the vendor would reject is not a recipient, so tbl_recipients only records who was actually texted.
        return $residents
            ->filter(fn ($resident) => PhoneNumber::normalize($resident->phone_number) !== '')
            // One text per phone, not per resident row, when a household number is shared.
            ->unique(fn ($resident) => PhoneNumber::normalize($resident->phone_number))
            ->values();
    }

    /** Residents' advisories: only blasts this resident was a recipient of, not their barangay's. */
    public function advisories(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof Resident) {
            return response()->json([
                'message' => 'This endpoint is for resident accounts.',
            ], 403);
        }

        $advisories = SmsLog::query()
            // Shows what MDRRMO issued, not proof of delivery: Queued, Sent and Unconfirmed count; a blast that failed outright or for this resident's number is withheld.
            ->whereIn('status', [Recipient::QUEUED, Recipient::SENT, Recipient::UNCONFIRMED])
            ->whereHas('recipients', fn ($query) => $query
                ->where('resident_id', $user->getKey())
                ->where('status', '!=', Recipient::FAILED))
            ->with('barangay:barangay_id,barangay_name')
            ->latest()
            ->get(['sms_log_id', 'target_area_id', 'message_body', 'status', 'created_at']);

        return response()->json(['data' => $advisories]);
    }

    /** The SMS History tab: shaped to the keys the table asks for (user.name, message, recipient_count), with recipients as a count, not a loaded relation. */
    public function history(Request $request)
    {
        $query = SmsLog::query()
            ->with([
                'sender:admin_id,first_name,last_name,username',
                'barangay:barangay_id,barangay_name',
            ])
            ->withCount('recipients')
            ->latest()
            // Tiebreaker: a blast writes one row per barangay in the same second, so created_at alone can repeat or skip rows when paginated.
            ->orderBy('sms_log_id', 'desc');

        $this->applyHistorySearch($query, (string) $request->query('search', ''));

        // One row per barangay per blast, so this grows fast; see PaginatesLists for why other lists stay bare arrays.
        $logs = $query->paginate($this->resolvePerPage($request));

        $mapped = collect($logs->items())->map(fn (SmsLog $log) => [
            'sms_log_id' => $log->sms_log_id,
            'created_at' => $log->created_at,
            'user' => [
                // A blast outlives its sender (tbl_user rows can be removed), so say so rather than show a blank.
                'name' => $log->sender
                    ? $log->sender->displayName()
                    : 'Unknown sender',
            ],
            'barangay' => $log->barangay?->barangay_name ?? 'Unknown barangay',
            'message' => $log->message_body,
            'recipient_count' => $log->recipients_count,
            // Queued, Sent, Unconfirmed or Failed; Failed is shown on purpose, since this is the record checked after a blast did not arrive.
            'status' => $log->status,
        ]);

        return response()->json([
            'success' => true,
            'data' => $mapped,
            'meta' => $this->paginationMeta($logs),
        ]);
    }

    /** Server-side search for the SMS History tab, so a paged table finds blasts instead of hiding them; every searched column is stored. */
    private function applyHistorySearch($query, string $search): void
    {
        // TrimStrings and ConvertEmptyStringsToNull already ran, so an empty box arrives as ''; the trim guards callers that skip that middleware.
        $search = trim($search);

        // Cost only: on today's schema '%%' matches every row, but this skips two EXISTS subqueries on every unfiltered load.
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
                        ->orWhere('username', 'like', $term)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term]);
                });
        });
    }

    /** One log row per barangay per outcome, one recipient row per resident; a chunk's queue ids go on every row it produced (the vendor answers per request). @param array<int, array{residents: Collection, status: string, job: ?string, queue_ids: list<string>}> $groups */
    private function recordBlast(int $senderId, array $groups, string $message): void
    {
        DB::transaction(function () use ($senderId, $groups, $message) {
            foreach ($groups as $group) {
                foreach ($group['residents']->groupBy('barangay_id') as $barangayId => $residents) {
                    $log = SmsLog::create([
                        'sender_id' => $senderId,
                        'target_area_id' => $barangayId,
                        'api_job_id' => $group['job'],
                        'message_body' => $message,
                        'status' => $group['status'],
                    ]);

                    if ($group['queue_ids'] !== []) {
                        SmsQueueId::insert(array_map(fn (string $queueId) => [
                            'sms_log_id' => $log->sms_log_id,
                            'queue_id' => $queueId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ], $group['queue_ids']));
                    }

                    Recipient::insert(
                        $residents->map(fn ($resident) => [
                            'sms_log_id' => $log->sms_log_id,
                            'resident_id' => $resident->resident_id,
                            'status' => $group['status'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])->all(),
                    );
                }
            }
        });
    }
}
