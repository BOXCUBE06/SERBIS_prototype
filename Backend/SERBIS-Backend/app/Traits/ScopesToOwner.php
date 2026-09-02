<?php

namespace App\Traits;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ownership scoping for the endpoints that serve residents and staff from one
 * method.
 *
 * These routes sit OUTSIDE the `is.admin` group on purpose — staff read any
 * resident's record while a resident reads only their own, which a middleware
 * that refuses non-admins outright cannot express. The cost is that neither
 * check `is.admin` performs runs on them, so both have to run here instead:
 *
 *  - `isAdmin()`, because `tbl_user.role` is an unconstrained varchar and an
 *    `instanceof User` test alone would let a row with any other role read
 *    every resident's record in the system.
 *  - `isDeactivated()`, because deactivating an account through the panel
 *    revokes its tokens but a direct database edit does not — the exact case
 *    IsAdmin's own comment names. Without this a closed account keeps reading
 *    for the rest of its token's 8-hour life.
 *
 * Defined once because the bug this closes came from having it in several
 * places. The two file-streaming routes carried the whole check, while
 * ServiceRequestController::show()/cancel() and both EquipmentBorrowing reads
 * carried only the `instanceof Resident` half — an `if` with no `else`, so any
 * token that was not a resident's fell past the scope and ran the query
 * unfiltered. Same reasoning as ResolvesUploadDisks: a check worth making in
 * five places is a check worth defining in one.
 */
trait ScopesToOwner
{
    /**
     * Scopes $query to the caller's own rows when they are a resident, and
     * vets them as active staff when they are not. Returns null to proceed, or
     * the response to send instead.
     *
     * The refusal for an unrecognised account is a 404, not a 403: wherever a
     * record id is in the URL the response must not confirm that the record
     * exists, and the answer has to match the one a non-owner already gets. A
     * deactivated account is told so outright — the holder of that token was
     * staff, and it discloses nothing they did not already know.
     */
    protected function scopeToOwner(Request $request, $query, string $missingMessage): ?JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Resident) {
            $query->where('resident_id', $user->getKey());

            return null;
        }

        if (! $user instanceof User || ! $user->isAdmin()) {
            return response()->json(['message' => $missingMessage], 404);
        }

        if ($user->isDeactivated()) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        return null;
    }
}
