<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PUT /api/service-requests/{id} rejecting a booking — extends update()
 * rather than a new route, since it only touches columns update() already
 * writes and syncFleet() already reconciles.
 *
 * PhilSMS has no sandbox — preventStrayRequests() is what makes a rejection
 * test safe to run at all, same as SmsBlastLoggingTest.
 */
class ServiceRequestRejectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ServiceRequest $request;

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

        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->request = ServiceRequest::create([
            'resident_id' => $resident->getKey(),
            'service_id' => $service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
            // A real Booked row always carries this; without it the rejection
            // notification's own guard (scheduled_at !== null) would silently
            // skip, and this fixture would not actually exercise that path.
            'scheduled_at' => Carbon::now('UTC')->addDays(2),
        ]);

        $this->actingAs($this->admin);
    }

    public function test_rejecting_without_remarks_is_refused(): void
    {
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
        ])->assertStatus(422)->assertJsonValidationErrors('remarks');

        $this->assertSame('Booked', $this->request->fresh()->status);
    }

    public function test_rejecting_with_remarks_succeeds(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk()->assertJsonPath('status', 'Disapproved');

        $fresh = $this->request->fresh();
        $this->assertSame('Disapproved', $fresh->status);
        $this->assertSame('No unit free for the requested window.', $fresh->remarks);
    }

    public function test_rejecting_texts_the_resident_the_reason(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://dashboard.philsms.com/api/v3/sms/send'
                && str_contains($request['message'], 'No unit free for the requested window.')
                && str_contains($request['message'], 'was not approved');
        });
    }

    public function test_a_send_failure_does_not_affect_the_rejection_itself(): void
    {
        // No fake registered for a success — preventStrayRequests() throws the
        // moment notifyResident() tries to send, which is exactly the failure
        // this proves survives: never thrown back to the caller, and the
        // rejection itself still commits and still answers 200.
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk()->assertJsonPath('status', 'Disapproved');

        $this->assertSame('Disapproved', $this->request->fresh()->status);
    }

    /**
     * $wasBookingRejection used to key off scheduled_at + target status alone,
     * with no check that status actually changed — a same-status resend of
     * Disapproved (the matrix's own no-op allowance) re-sent the identical
     * rejection SMS every time. PhilSMS bills per segment with no sandbox, so
     * that was an unpriced duplicate charge on every resend, same class of bug
     * as the approve() one.
     */
    public function test_resending_disapproved_sends_no_second_sms(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'No unit free for the requested window.',
        ])->assertOk();

        Http::assertSentCount(1);

        // Same status, a different remark — the panel resends the current
        // status on every PUT (see ServiceRequestQueue.vue's updateStatus()).
        $this->putJson("/api/service-requests/{$this->request->getKey()}", [
            'status' => 'Disapproved',
            'remarks' => 'Duplicate submission, closing out.',
        ])->assertOk();

        $this->assertSame('Duplicate submission, closing out.', $this->request->fresh()->remarks);
        Http::assertSentCount(1);
    }
}
