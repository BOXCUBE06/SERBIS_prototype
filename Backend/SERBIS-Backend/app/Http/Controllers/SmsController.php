<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use App\Models\Barangay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class SmsController extends Controller
{
    // Fetch available barangays for the dropdown
    public function getBarangays()
    {
        return response()->json(Barangay::all());
    }

    public function sendBlast(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:160',
            'barangays' => 'required|array', // Array of barangay IDs or ['all']
            'barangays.*' => 'string' 
        ]);

        $query = Resident::whereNotNull('phone_number');

        // Apply targeting if 'all' is not selected
        if (!in_array('all', $validated['barangays'])) {
            $query->whereIn('barangay_id', $validated['barangays']);
        }

        // Chunking prevents server memory crashes when querying thousands of users
        $successCount = 0;
        $failCount = 0;

        $query->chunk(100, function ($residents) use ($validated, &$successCount, &$failCount) {
            foreach ($residents as $resident) {
                
                // Example using a generic SMS API structure (Adapt to your specific provider)
                $response = Http::post('https://api.your-sms-provider.com/messages', [
                    'apikey' => env('SMS_API_KEY'),
                    'number' => $resident->phone_number,
                    'message' => $validated['message'],
                    'sendername' => 'MDRRMO'
                ]);

                if ($response->successful()) {
                    $successCount++;
                    // Optional: Insert into tbl_sms_logs here
                } else {
                    $failCount++;
                }
            }
        });

        return response()->json([
            'message' => 'Text blast processing completed.',
            'sent' => $successCount,
            'failed' => $failCount
        ]);
    }
}