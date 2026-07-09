<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    // 🧪 TEST MODE — hardcoded numbers, replace with real logic later
    private array $testNumbers = [
        '+639391145133', // replace with your number
        '+639677161687', // replace with your friend's number
    ];

    public function sendBlast(Request $request)
{
    $validated = $request->validate([
        'message'     => 'required|string|max:160',
        'barangays'   => 'required|array',
        'barangays.*' => 'string'
    ]);

    $apiKey = env('SKYSMS_API_KEY');

    // 🧪 TEST MODE — hardcoded numbers
    $recipients = [
        ['phone_number' => '+639391145133'], // your number
        ['phone_number' => '+639677161687'], // friend's number
    ];

    $response = Http::withHeaders([
        'X-API-Key'    => $apiKey,
        'Content-Type' => 'application/json',
    ])->post('https://skysms.skyio.site/api/v1/sms/send -bulk', [
        'recipients' => $recipients,
        'message'    => $validated['message'],
    ]);

    if ($response->successful()) {
        return response()->json([
            'message' => 'Text blast completed.',
            'sent'    => count($recipients),
            'failed'  => 0
        ]);
    }

    Log::error('SkySMS bulk failed', [
        'status'   => $response->status(),
        'response' => $response->body()
    ]);

    return response()->json([
        'message' => 'Failed to send blast.',
        'sent'    => 0,
        'failed'  => count($recipients)
    ], 500);
}
}