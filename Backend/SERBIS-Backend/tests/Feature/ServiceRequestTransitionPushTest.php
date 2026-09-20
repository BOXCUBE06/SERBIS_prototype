<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} — the generic transition push added to
 * update() (Pending/Booked -> Booked/Responding/Disapproved), which now
 * fires the same way regardless of service or which panel button produced
 * it. Two things this specifically guards against regressing:
 *
 * - Before this, only a *scheduled* ambulance booking's rejection pushed —
 *   every non-ambulance service, and an unscheduled ambulance request
 *   rejected from Pending, were silent.
 * - A scheduled ambulance booking's own Booked -> Responding still goes
 *   through approve()'s richer push (unit, scheduled time), not this
 *   generic one — the second-order guard in update() never lets that
 *   specific combination reach here.
 */
class ServiceRequestTransitionPushTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $roadClearing;

    private Service $ambulance;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->roadClearing = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Clearing roads of debris.',
        ]);

        $this->ambulance = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->actingAs($this->admin);

        Cache::put('fcm_access_token', 'fake-access-token', 3000);

        $path = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($path, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $path]);

        DeviceToken::create([
            'resident_id' => $this->resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200)]);
    }

    public function test_non_ambulance_pending_to_booked_pushes(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->service_id,
            'description' => 'Fallen tree blocking the road',
            'status' => 'Pending',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Booked'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Road Clearing')
            && str_contains($sent['message']['notification']['body'], 'booked'));
    }

    public function test_non_ambulance_pending_to_responding_pushes(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->service_id,
            'description' => 'Fallen tree blocking the road',
            'status' => 'Pending',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Responding'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Road Clearing')
            && str_contains($sent['message']['notification']['body'], 'approved'));
    }

    public function test_non_ambulance_booked_to_responding_pushes(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->service_id,
            'description' => 'Fallen tree blocking the road',
            'status' => 'Booked',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Responding'])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Road Clearing'));
    }

    public function test_non_ambulance_rejection_pushes_naming_the_service(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->service_id,
            'description' => 'Fallen tree blocking the road',
            'status' => 'Pending',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'Not an MDRRMO road.',
        ])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Road Clearing')
            && str_contains($sent['message']['notification']['body'], 'not approved')
            && str_contains($sent['message']['notification']['body'], 'Not an MDRRMO road.'));
    }

    /**
     * The exact regression this commit fixes: an unscheduled ambulance
     * request (no AmbulanceBooking row) rejected straight from Pending used
     * to be explicitly excluded by $wasBookingRejection ("not a booking").
     */
    public function test_unscheduled_ambulance_rejection_now_pushes(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->service_id,
            'description' => 'Unscheduled instant dispatch request',
            'status' => 'Pending',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit available.',
        ])->assertOk();

        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'Ambulance/Medical Response')
            && str_contains($sent['message']['notification']['body'], 'not approved'));
    }

    /** Resolved is transition 6 — explicitly not in scope for this batch. */
    public function test_resolving_a_request_sends_no_push(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->service_id,
            'description' => 'Fallen tree blocking the road',
            'status' => 'Responding',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Resolved'])->assertOk();

        Http::assertNothingSent();
    }

    /** A resend of the current status is a no-op, not a transition — must not push. */
    public function test_resending_the_same_status_sends_no_push(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->roadClearing->service_id,
            'description' => 'Fallen tree blocking the road',
            'status' => 'Booked',
        ]);

        $this->putJson("/api/service-requests/{$request->getKey()}", ['status' => 'Booked'])->assertOk();

        Http::assertNothingSent();
    }

    /**
     * A scheduled ambulance booking's Booked -> Responding still goes
     * through approve()'s own push (unit + scheduled time), not the generic
     * one added here — asserting the count stays at one confirms the two
     * paths did not stack.
     */
    public function test_scheduled_ambulance_approval_still_pushes_exactly_once(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->ambulance->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => Carbon::now('UTC')->addDays(2),
        ]);

        $vehicle = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $this->patchJson("/api/service-requests/{$request->getKey()}/approve", [
            'vehicle_id' => $vehicle->getKey(),
        ])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => str_contains($sent['message']['notification']['body'], 'approved'));
    }
}
