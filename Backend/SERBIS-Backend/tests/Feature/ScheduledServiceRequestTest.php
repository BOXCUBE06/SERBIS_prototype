<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST /api/service-requests — a resident booking a scheduled ambulance
 * through the existing filing path, not a parallel endpoint. scheduled_at is
 * optional; its absence must leave the request exactly as it always behaved.
 */
class ScheduledServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Service $service;

    /** @var array<int, Vehicle> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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

        foreach (['AMB-01', 'AMB-02', 'AMB-03', 'AMB-04'] as $identifier) {
            $this->units[] = Vehicle::create([
                'unit_identifier' => $identifier,
                'type' => 'Ambulance',
                'specification' => 'Type I',
                'status' => 'Available',
            ]);
        }

        $this->actingAs($this->resident);
    }

    /**
     * `description` is deliberately absent: store() composes it server-side for
     * an ambulance request and ignores whatever a client sends. patient_name
     * and destination are the two fields store() requires for this service —
     * the other six structured columns stay optional there, unlike the walk-in
     * counter form, so a resident on a phone can file without them.
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->service->service_id,
            'patient_name' => 'Maria Santos',
            'destination' => 'Echague District Hospital',
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ], $overrides);
    }

    /** A Manila-local naive string — no offset, the same shape the mobile app's picker would send. */
    private function manilaString(Carbon $instant): string
    {
        return $instant->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s');
    }

    public function test_a_scheduled_booking_lands_booked_with_no_vehicle(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0); // well past the lead time

        $response = $this->postJson('/api/service-requests', $this->payload([
            'scheduled_at' => $this->manilaString($target),
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('status', 'Booked')
            ->assertJsonPath('vehicle_id', null);

        $created = ServiceRequest::findOrFail($response->json('request_id'));
        $this->assertNotNull($created->scheduled_at);
        $this->assertTrue($created->scheduled_at->utc()->equalTo($target));
        $this->assertNull($created->scheduled_end);
        $this->assertNull($created->vehicle_id);
    }

    public function test_inside_the_lead_time_is_rejected(): void
    {
        $tooSoon = Carbon::now('UTC')->addMinutes(30);

        $response = $this->postJson('/api/service-requests', $this->payload([
            'scheduled_at' => $this->manilaString($tooSoon),
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_in_the_past_is_rejected(): void
    {
        $past = Carbon::now('UTC')->subDay();

        $response = $this->postJson('/api/service-requests', $this->payload([
            'scheduled_at' => $this->manilaString($past),
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_fully_booked_window_is_rejected(): void
    {
        $target = Carbon::now('UTC')->addDays(2)->setTime(6, 0, 0);

        // Every unit already booked across the exact window this request will ask for.
        foreach ($this->units as $vehicle) {
            ServiceRequest::create([
                'service_id' => $this->service->service_id,
                'vehicle_id' => $vehicle->vehicle_id,
                'description' => 'Existing booking',
                'status' => 'Booked',
                'scheduled_at' => $target->copy(),
                'scheduled_end' => $target->copy()->addHours(2),
            ]);
        }

        $response = $this->postJson('/api/service-requests', $this->payload([
            'scheduled_at' => $this->manilaString($target),
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('scheduled_at');

        // The 4 pre-existing bookings only — this request must not have landed.
        $this->assertSame(4, ServiceRequest::count());

        // The uploaded valid_id was cleaned up on this rejection, same as the
        // existing no-available-vehicle path — nothing left under this
        // resident's upload folder.
        $this->assertEmpty(Storage::disk('local')->files('valid-ids/'.$this->resident->getKey()));
    }

    public function test_an_unscheduled_request_is_unaffected(): void
    {
        $response = $this->postJson('/api/service-requests', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'Pending')
            ->assertJsonPath('vehicle_id', null);

        $created = ServiceRequest::findOrFail($response->json('request_id'));
        $this->assertNull($created->scheduled_at);
        $this->assertNull($created->scheduled_end);
    }
}
