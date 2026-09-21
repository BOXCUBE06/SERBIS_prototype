<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `section:requests,ambulance` — the caller must be allowed at least one of the
 * named admin-panel sections (App\Support\AdminSections).
 *
 * Runs after is.admin on the admin routes, so everyone it sees there is an
 * admin. It is also put on routes the mobile app shares with the panel
 * (service requests, borrowings, resident photos), where the controller
 * already branches on who is asking. On those it only ever restricts staff:
 * a resident is not a User, so they pass straight through to the ownership
 * scoping the controller applies, and so does a tbl_user row that is not an
 * admin, which the controller refuses in its own words.
 *
 * Several sections on one route means "any of": a lookup that two pages both
 * need is open to whoever holds either.
 */
class EnsureSection
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$sections): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            return $next($request);
        }

        foreach ($sections as $section) {
            if ($user->canAccess($section)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have access to this section. Ask a super admin to give it to you.',
            'code' => 'section_forbidden',
            'sections' => $sections,
        ], 403);
    }
}
