<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    public function sendBlast(Request $request)
    {
        $validated = $request->validate([
            'message'     => 'required|string|max:160',
            'barangays'   => 'required|array|min:1',
            'barangays.*' => 'integer|exists:tbl_barangay,barangay_id',
        ]);

        // Resident ids come back alongside the numbers now: the vendor only needs
        // the number, but tbl_recipients records who was included, and that is
        // not derivable afterwards — the filters below (Active, has a number)
        // mean barangay membership is a different set.
        $residents = Resident::whereIn('barangay_id', $validated['barangays'])
            ->where('status', 'Active')
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get(['resident_id', 'barangay_id', 'phone_number']);

        // Never call a billed endpoint with nothing to send.
        if ($residents->isEmpty()) {
            return response()->json([
                'message' => 'No active residents with a phone number in the selected barangays.',
                'sent'    => 0,
                'failed'  => 0,
            ], 422);
        }

        $recipients = $residents
            ->map(fn ($resident) => ['phone_number' => $resident->phone_number])
            ->values()
            ->all();

        $response = Http::withHeaders([
            'X-API-Key'    => config('services.skysms.key'),
            'Content-Type' => 'application/json',
        ])->post('https://skysms.skyio.site/api/v1/sms/send-bulk', [
            'recipients' => $recipients,
            'message'    => $validated['message'],
        ]);

        $succeeded = $response->successful();

        // Recorded either way. A failed blast is the more important of the two to
        // have written down — it is the one somebody will ask about afterwards —
        // and the advisory feed reads only the sent ones, so a failure cannot
        // masquerade as a warning that went out.
        $this->recordBlast(
            $request->user()->admin_id,
            $residents,
            $validated['message'],
            $succeeded,
            $response->json('job_id') ?? $response->json('id'),
        );

        if ($succeeded) {
            return response()->json([
                'message' => 'Text blast completed.',
                'sent'    => count($recipients),
                'failed'  => 0,
            ]);
        }

        Log::error('SkySMS bulk failed', [
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
            ->get(['sms_log_id', 'target_area_id', 'disaster_id', 'message_body', 'status', 'created_at']);

        return response()->json(['data' => $advisories]);
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
                    // Nullable since 2026-08-03: a blast is worth recording
                    // whether or not the emergency has been classified.
                    'disaster_id'    => null,
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
