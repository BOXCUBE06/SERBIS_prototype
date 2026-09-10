<?php

namespace Tests\Feature;

use App\Models\AmbulanceBooking;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PATCH /api/service-requests/{id}/reschedule — its own route for the same
 * reason approve() gets one: a locked availability re-check update() cannot
 * do.
 *
 * PhilSMS has no sandbox — preventStrayRequests() is what makes a reschedule
 * test safe to run at all, same as SmsBlastLoggingTest.
 */
class ServiceRequestRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $resident;

    private Service $service;

    private Vehicle $amb01;

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

        $this->service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'description' => 'Emergency medical response and ambulance services.',
        ]);

        $this->amb01 = Vehicle::create([
            'unit_identifier' => 'AMB-01',
            'type' => 'Ambulance',
            'specification' => 'Type I',
            'status' => 'Available',
        ]);

        $this->actingAs($this->admin);
    }

    private function bookedRequest(?Carbon $scheduledAt = null, ?Vehicle $vehicle = null): ServiceRequest
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Scheduled hospital transfer',
            'status' => 'Booked',
            'vehicle_id' => $vehicle?->vehicle_id,
        ]);

        AmbulanceBooking::create([
            'request_id' => $request->getKey(),
            'scheduled_at' => $scheduledAt ?? Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0),
        ]);

        return $request;
    }

    private function manilaString(Carbon $instant): string
    {
        return $instant->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s');
    }

    public function test_admin_reschedules_an_unapproved_booking(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);
        $newEnd = $newStart->copy()->addHours(2);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newEnd),
            'remarks' => 'Resident asked to move the pickup later.',
        ])->assertOk();

        $fresh = $request->fresh();
        $this->assertTrue($fresh->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
        $this->assertTrue($fresh->ambulanceBooking->scheduled_end->utc()->equalTo($newEnd));
        $this->assertSame('Resident asked to move the pickup later.', $fresh->remarks);
    }

    public function test_reschedule_requires_remarks(): void
    {
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
        ])->assertStatus(422)->assertJsonValidationErrors('remarks');
    }

    public function test_reschedule_is_refused_when_no_unit_is_free_for_the_new_window(): void
    {
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);
        $newEnd = $newStart->copy()->addHours(2);

        // The only Ambulance unit is already committed elsewhere for that window.
        $conflicting = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->service_id,
            'description' => 'Another booking',
            'status' => 'Booked',
            'vehicle_id' => $this->amb01->vehicle_id,
        ]);

        AmbulanceBooking::create([
            'request_id' => $conflicting->getKey(),
            'scheduled_at' => $newStart->copy(),
            'scheduled_end' => $newEnd->copy(),
        ]);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newEnd),
            'remarks' => 'Trying to move it anyway.',
        ])->assertStatus(422);

        $this->assertNotNull($request->fresh()->ambulanceBooking->scheduled_at);
    }

    /**
     * Approved bookings keep their own unit through a reschedule as long as
     * that unit is still free for the new time — the point of excluding this
     * request's own row from the availability check, since otherwise its own
     * not-yet-updated window would count as a conflict against itself.
     */
    public function test_rescheduling_an_approved_booking_keeps_its_own_unit(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $originalStart = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);
        $request = $this->bookedRequest($originalStart, $this->amb01);
        $request->ambulanceBooking->update(['scheduled_end' => $originalStart->copy()->addHours(2)]);

        // Overlaps the original window — without self-exclusion this would
        // wrongly read as "AMB-01 is busy" against its own old booking.
        $newStart = $originalStart->copy()->addHour();
        $newEnd = $newStart->copy()->addHours(2);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newEnd),
            'remarks' => 'Pushed back an hour at the office\'s request.',
        ])->assertOk();

        $fresh = $request->fresh();
        $this->assertSame($this->amb01->vehicle_id, $fresh->vehicle_id);
        $this->assertTrue($fresh->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
    }

    public function test_rescheduling_texts_the_resident_the_new_time_and_reason(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => 'Resident asked to move the pickup later.',
        ])->assertOk();

        Http::assertSent(function ($sent) {
            return $sent->url() === 'https://dashboard.philsms.com/api/v3/sms/send'
                && str_contains($sent['message'], 'Resident asked to move the pickup later.')
                && str_contains($sent['message'], 'moved to');
        });
    }

    public function test_a_send_failure_does_not_affect_the_reschedule_itself(): void
    {
        // No fake registered — preventStrayRequests() throws the moment
        // notifyResident() tries to send, proving the failure never reaches
        // the caller: the reschedule itself still commits and answers 200.
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => 'Resident asked to move the pickup later.',
        ])->assertOk();

        $this->assertTrue($request->fresh()->ambulanceBooking->scheduled_at->utc()->equalTo($newStart));
    }

    /**
     * PhilSMS bills per segment and has no sandbox, so the length of this body
     * is a cost, not a cosmetic detail. `remarks` was `required|string` with no
     * ceiling while SmsController::sendBlast had capped its own message at 160
     * from the start — the two paths that text one resident simply never got
     * the same treatment.
     */
    public function test_an_over_long_remark_is_refused_rather_than_billed(): void
    {
        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => str_repeat('a', 161),
        ])->assertStatus(422)->assertJsonValidationErrors('remarks');
    }

    /**
     * The cap on the field is not on its own enough: the template adds about
     * ninety characters of its own, so a remark at the limit would still bill
     * two segments. The assembled body is what has to fit.
     */
    public function test_the_assembled_text_stays_within_one_segment(): void
    {
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $request = $this->bookedRequest();
        $newStart = Carbon::now('UTC')->addDays(5)->setTime(9, 0, 0);

        $this->patchJson("/api/service-requests/{$request->getKey()}/reschedule", [
            'scheduled_at' => $this->manilaString($newStart),
            'scheduled_end' => $this->manilaString($newStart->copy()->addHours(2)),
            'remarks' => str_repeat('a', 160),
        ])->assertOk();

        Http::assertSent(function ($sent) {
            // The attribution survives the trim — it is the reason, not the
            // sender, that gets cut.
            return mb_strlen($sent['message']) <= 160
                && str_contains($sent['message'], 'MDRRMO Echague');
        });
    }
}
