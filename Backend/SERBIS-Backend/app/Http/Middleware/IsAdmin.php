<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Deactivation has to bite here as well as at login (audit #29).
        // Closing an account revokes its tokens, but a token issued to an
        // account deactivated by some other path — a direct database edit — is
        // otherwise good for the rest of its 8-hour life.
        if ($user->isDeactivated()) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        // A temporary password proves who is signing in and nothing else. Until
        // it is replaced the account reaches only /me, /logout and
        // /admin/change-password, which sit outside this middleware. Enforced
        // here rather than in the panel so a hand-rolled request gets no
        // further than the browser does.
        if ($user->must_change_password) {
            return response()->json([
                'message' => 'Set a new password before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
