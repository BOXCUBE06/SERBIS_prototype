<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The P1 rate-limit audit (2026-09-15) found a single flat throttleApi('60,1')
 * on the whole 'api' middleware group — admin panel traffic shared the same
 * 60/min ceiling as public, unauthenticated routes, and a single admin
 * clicking through the request queue could trip it on ordinary navigation.
 * routes/api.php now attaches 'throttle:api' (60/min, public + resident) to
 * everything except the is.admin group, which gets 'throttle:admin-api'
 * (300/min) instead — not on top of it. See AppServiceProvider::boot().
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_route_is_throttled_past_sixty_per_minute(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->getJson('/api/barangays')->assertStatus(200);
        }

        $this->getJson('/api/barangays')->assertStatus(429);
    }

    public function test_an_admin_route_is_not_throttled_at_sixty_one_requests_per_minute(): void
    {
        Sanctum::actingAs($this->admin());

        // The old flat limit was 60/min shared across the whole API — this
        // proves admin traffic no longer shares that ceiling at all.
        for ($i = 1; $i <= 61; $i++) {
            $this->getJson('/api/vehicles')->assertStatus(200);
        }
    }

    public function test_an_admin_route_is_throttled_past_three_hundred_per_minute(): void
    {
        Sanctum::actingAs($this->admin());

        for ($i = 1; $i <= 300; $i++) {
            $this->getJson('/api/vehicles')->assertStatus(200);
        }

        $this->getJson('/api/vehicles')->assertStatus(429);
    }

    /**
     * A resident carries no admin_id at all — the 'admin-api' limiter's key
     * closure used to read $request->user()->admin_id unconditionally.
     * IsAdmin (routes/api.php's is.admin) rejects a non-admin before the
     * limiter ever runs (see the comment on that route group), so this
     * can't crash through the HTTP pipeline today — but a resident hitting
     * an admin route must still land on 403, not 429 or 500, regardless of
     * how that middleware order came to be.
     */
    public function test_a_resident_token_hitting_an_admin_route_gets_403(): void
    {
        Sanctum::actingAs($this->resident('one@test.local'));

        $this->getJson('/api/vehicles')->assertStatus(403);
    }

    /**
     * Exercises the 'admin-api' limiter's key closure directly rather than
     * through the route, since IsAdmin means a non-admin never reaches it
     * via HTTP (see the test above). Proves the null-safety fix itself:
     * no exception reading ->admin_id off a resident, and each caller gets
     * a distinct bucket key — an admin never collides with a resident, and
     * two different residents never collide with each other on some shared
     * fallback like an empty string.
     */
    public function test_the_admin_api_limiter_key_does_not_crash_or_collide_for_a_non_admin(): void
    {
        $limiter = RateLimiter::limiter('admin-api');

        $admin = $this->admin();
        $residentA = $this->resident('resident-a@test.local');
        $residentB = $this->resident('resident-b@test.local');

        $adminLimit = $limiter($this->requestAs($admin));
        $residentALimit = $limiter($this->requestAs($residentA));
        $residentBLimit = $limiter($this->requestAs($residentB));

        $this->assertSame('admin:'.$admin->admin_id, $adminLimit->key);
        $this->assertSame('user:'.$residentA->resident_id, $residentALimit->key);
        $this->assertSame('user:'.$residentB->resident_id, $residentBLimit->key);

        $this->assertNotSame($adminLimit->key, $residentALimit->key);
        $this->assertNotSame($residentALimit->key, $residentBLimit->key);
    }

    private function requestAs($user): Request
    {
        $request = Request::create('/api/vehicles');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function admin(): User
    {
        return User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);
    }

    private function resident(string $email): Resident
    {
        $barangay = Barangay::firstOrCreate(['barangay_name' => 'San Fabian']);

        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09'.random_int(100000000, 999999999),
            'email_address' => $email,
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
    }
}
