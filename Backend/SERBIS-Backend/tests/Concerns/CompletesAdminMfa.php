<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * Admin login is two requests now: password, then a TOTP code. Tests that
 * only care about "did the login succeed" would otherwise have to repeat the
 * challenge/secret plumbing every time — this gives them one call that
 * behaves like the old one-step /admin/login.
 */
trait CompletesAdminMfa
{
    /**
     * Performs both halves of admin login and returns the response from the
     * verify step — the one that carries the token — so a caller asserts
     * against it exactly as it would have against the old single-step login.
     * If the first step doesn't return an mfa_required challenge (a wrong
     * password, a deactivated account), that response is returned instead so
     * the caller's own assertions about it still work.
     */
    protected function loginAdmin(string $username, string $password): TestResponse
    {
        $first = $this->postJson('/api/admin/login', [
            'username' => $username,
            'password' => $password,
        ]);

        if ($first->status() !== 403 || $first->json('code') !== 'mfa_required') {
            return $first;
        }

        $admin = User::where('username', strtolower($username))->firstOrFail();

        return $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->currentAdminTotpCode($admin),
        ]);
    }

    protected function currentAdminTotpCode(User $admin): string
    {
        $secret = app(Totp::class)->secretFor($admin->admin_id);

        return (new Google2FA)->getCurrentOtp($secret);
    }
}
