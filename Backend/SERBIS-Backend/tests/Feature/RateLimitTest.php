<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}
