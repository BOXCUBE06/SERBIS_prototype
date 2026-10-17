<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\SmsLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/** A resident token on every is.admin route: refused before any controller runs. */
class ResidentCannotReachAdminRoutesTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    public function test_a_resident_token_gets_403_on_every_admin_route(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        Sanctum::actingAs(Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '+639171234567',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]));

        // Bound models resolve before is.admin runs, so a missing id answers 404 first. Give {smsLog} a real row.
        $log = SmsLog::create([
            'sender_id' => $this->makeStaff()->admin_id,
            'target_area_id' => $barangay->barangay_id,
            'message_body' => 'Test',
            'status' => 'Queued',
        ]);

        $checked = 0;
        $reached = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('is.admin', $route->gatherMiddleware(), true)) {
                continue;
            }

            $uri = '/'.str_replace('{smsLog}', (string) $log->getKey(), preg_replace('/\{(?!smsLog)[^}]+\}/', '1', $route->uri()));

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $checked++;

                if ($this->json($method, $uri)->status() !== 403) {
                    $reached[] = "{$method} {$uri}";
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'Walked suspiciously few admin routes.');
        $this->assertSame([], $reached, 'Admin routes a resident token did not get 403 on');
    }

    public function test_a_resident_gets_403_not_404_for_a_delivery_id_that_does_not_exist(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        Sanctum::actingAs(Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '+639171234567',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]));

        $this->postJson('/api/sms/deliveries/999999/check')->assertForbidden();
        $this->getJson('/api/sms/deliveries/999999/recipients')->assertForbidden();
    }

    public function test_an_admin_still_gets_404_for_a_delivery_id_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->makeStaff());

        $this->postJson('/api/sms/deliveries/999999/check')->assertNotFound();
    }
}
