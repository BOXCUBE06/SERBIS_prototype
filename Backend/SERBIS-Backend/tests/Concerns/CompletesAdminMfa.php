<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * Admin login is one request while ADMIN_MFA_ENABLED is off (the default) and
 * two when it is on: password, then the code texted to the staff member. Tests
 * that only care about "did the login succeed" call loginAdmin() either way.
 *
 * With the flag on, the caller must fake SkySMS (FakesSkySms): the code is read
 * back off the faked outgoing text, since only its hash is stored.
 */
trait CompletesAdminMfa
{
    /**
     * Performs both halves of admin login when a code is asked for and returns
     * the response that carries the token. If the first step does not answer
     * mfa_required (flag off, wrong password, deactivated account, no phone),
     * that response is returned as-is so the caller's assertions still work.
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

        return $this->postJson('/api/admin/login/verify', [
            'challenge_id' => $first->json('challenge_id'),
            'code' => $this->lastAdminCodeTexted(),
        ]);
    }

    protected function lastAdminCodeTexted(): string
    {
        $codes = Http::recorded()
            ->map(fn ($pair) => preg_match('/[0-9]{6}/', $pair[0]['message'] ?? '', $m) ? $m[0] : null)
            ->filter()
            ->values()
            ->all();

        return (string) end($codes);
    }

    /** Dormant: the TOTP flow this served is switched off (see App\Services\Totp). */
    protected function currentAdminTotpCode(User $admin): string
    {
        return (new Google2FA)->getCurrentOtp(app(Totp::class)->secretFor($admin->admin_id));
    }
}
