<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * first_responded_at / resolved_at, stamped by ServiceRequest::booted().
 *
 * The rules under test, and why each one is not the obvious alternative:
 *
 * - first_responded_at is stamped only on a transition OUT OF Pending.
 *   Pending is the only status in which a request waits on the office. A
 *   request created already Booked never waited, and its later move to
 *   Responding is the trip starting on its appointed day — timing that from
 *   created_at would report the lead time to an appointment as staff delay.
 * - 'Cancelled' is not a response. cancel() is scoped to the owner; it is the
 *   resident withdrawing, so it closes the request without ever answering it.
 * - Neither column is overwritten once set.
 *
 * Every admin write path reaches these through the same model event, which is
 * what the bulk-disapprove and cancel cases below exist to prove: bulk
 * disapprove is N separate PUTs from the panel, not a server-side bulk route,
 * so if it stamped differently the two would have drifted.
 */
class ServiceRequestLifecycleTimestampsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->service = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Clear a blocked road',
        ]);
    }

    private function pendingRequest(): ServiceRequest
    {
        return ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Blocked road',
            'status' => 'Pending',
        ]);
    }

    public function test_a_new_pending_request_has_neither_timestamp(): void
    {
        $request = $this->pendingRequest();

        $this->assertNull($request->first_responded_at);
        $this->assertNull($request->resolved_at);
    }

    public static function respondingStatuses(): array
    {
        return [
            'booked' => ['Booked'],
            'responding' => ['Responding'],
            'disapproved' => ['Disapproved'],
        ];
    }

    /**
     * #[DataProvider] attribute, not a @dataProvider doc-comment: PHPUnit 12
     * no longer reads the annotation and would run this once with no
     * arguments instead of reporting it as misconfigured.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('respondingStatuses')]
    public function test_leaving_pending_stamps_the_first_response(string $status): void
    {
        $request = $this->pendingRequest();

        $request->update(['status' => $status]);

        $this->assertNotNull($request->fresh()->first_responded_at, "{$status} must count as a first response");
    }

    public function test_cancelling_closes_the_request_without_recording_a_response(): void
    {
        $request = $this->pendingRequest();

        $request->update(['status' => 'Cancelled']);

        $fresh = $request->fresh();

        // The office never answered this one — the resident withdrew it.
        $this->assertNull($fresh->first_responded_at);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_a_request_created_already_booked_never_gets_a_first_response(): void
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Scheduled pickup',
            'status' => 'Booked',
        ]);

        $this->assertNull($request->first_responded_at);

        // Its trip starting is not the office answering a queued request.
        $request->update(['status' => 'Responding']);

        $this->assertNull($request->fresh()->first_responded_at);
    }

    public function test_the_first_response_is_never_overwritten(): void
    {
        $request = $this->pendingRequest();

        $request->update(['status' => 'Responding']);
        $first = $request->fresh()->first_responded_at;

        $this->travel(2)->hours();
        $request->fresh()->update(['status' => 'Resolved']);

        $this->assertEquals($first, $request->fresh()->first_responded_at, 'first means first');
    }

    public function test_resolving_stamps_resolved_at_and_keeps_the_earlier_response(): void
    {
        $request = $this->pendingRequest();

        $request->update(['status' => 'Responding']);
        $this->travel(3)->hours();
        $request->fresh()->update(['status' => 'Resolved']);

        $fresh = $request->fresh();

        $this->assertNotNull($fresh->first_responded_at);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertTrue($fresh->resolved_at->greaterThan($fresh->first_responded_at));
    }

    public function test_an_edit_that_does_not_move_the_status_stamps_nothing(): void
    {
        $request = $this->pendingRequest();

        $request->update(['internal_notes' => 'Called the barangay captain']);

        $this->assertNull($request->fresh()->first_responded_at);
        $this->assertNull($request->fresh()->resolved_at);
    }

    /**
     * Bulk disapprove is not a server-side route: ServiceRequestQueue.vue
     * fires one PUT /service-requests/{id} per ticked row. Driving it through
     * the real endpoint is the only way to prove the panel's bulk action
     * stamps the same columns as a single decision.
     */
    public function test_bulk_disapprove_stamps_every_request_it_touches(): void
    {
        $requests = [$this->pendingRequest(), $this->pendingRequest(), $this->pendingRequest()];

        foreach ($requests as $request) {
            $this->actingAs($this->admin)
                ->putJson("/api/service-requests/{$request->request_id}", [
                    'status' => 'Disapproved',
                    'remarks' => 'Outside the municipality',
                ])
                ->assertOk();
        }

        foreach ($requests as $request) {
            $fresh = $request->fresh();
            $this->assertNotNull($fresh->first_responded_at, 'a refusal is still an answer');
            $this->assertNotNull($fresh->resolved_at);
        }
    }

    /**
     * cancel() writes through $serviceRequest->update() inside a transaction,
     * so it reaches the same model event. Driven through the resident's own
     * route because that is the only caller.
     */
    public function test_the_cancel_route_stamps_resolved_at_only(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->resident, 'sanctum')
            ->patchJson("/api/service-requests/{$request->request_id}/cancel")
            ->assertOk();

        $fresh = $request->fresh();

        $this->assertNotNull($fresh->resolved_at);
        $this->assertNull($fresh->first_responded_at);
    }

    /**
     * ConductionRequestController flips a Booked request to Responding when a
     * trip is dispatched. It is the one status write outside
     * ServiceRequestController, and it goes through the model, so it must
     * follow the created-at-Booked rule rather than inventing a response.
     */
    public function test_the_dispatch_flip_follows_the_same_rule(): void
    {
        Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'status' => 'Available',
        ]);

        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Scheduled transfer',
            'status' => 'Booked',
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->request_id,
            'patient_name' => 'Juan Cruz',
            'scheduled_at' => now()->addDay(),
        ]);

        $request->update(['status' => 'Responding']);

        $this->assertNull($request->fresh()->first_responded_at);
    }
}
