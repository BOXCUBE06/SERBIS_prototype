<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use Illuminate\Http\Request;
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

        $recipients = Resident::whereIn('barangay_id', $validated['barangays'])
            ->where('status', 'Active')
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->pluck('phone_number')
            ->map(fn ($number) => ['phone_number' => $number])
            ->values()
            ->all();

        // Never call a billed endpoint with nothing to send.
        if ($recipients === []) {
            return response()->json([
                'message' => 'No active residents with a phone number in the selected barangays.',
                'sent'    => 0,
                'failed'  => 0,
            ], 422);
        }

        $response = Http::withHeaders([
            'X-API-Key'    => config('services.skysms.key'),
            'Content-Type' => 'application/json',
        ])->post('https://skysms.skyio.site/api/v1/sms/send-bulk', [
            'recipients' => $recipients,
            'message'    => $validated['message'],
        ]);

        if ($response->successful()) {
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
}
