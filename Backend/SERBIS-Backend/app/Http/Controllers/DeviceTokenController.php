<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\Resident;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /** Registers a device, or refreshes one already on file — same call either way, upserted by token. */
    public function store(Request $request)
    {
        $resident = $request->user();

        if (! $resident instanceof Resident) {
            return response()->json(['message' => 'This endpoint is for resident accounts.'], 403);
        }

        $validated = $request->validate([
            'token' => 'required|string|max:255',
            'platform' => 'required|string|max:20',
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'resident_id' => $resident->getKey(),
                'platform' => $validated['platform'],
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['message' => 'Registered.']);
    }

    /** Scoped to the caller's own resident_id — a token cannot be deleted on someone else's behalf. */
    public function destroy(Request $request)
    {
        $resident = $request->user();

        if (! $resident instanceof Resident) {
            return response()->json(['message' => 'This endpoint is for resident accounts.'], 403);
        }

        $validated = $request->validate([
            'token' => 'required|string|max:255',
        ]);

        DeviceToken::where('resident_id', $resident->getKey())
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
