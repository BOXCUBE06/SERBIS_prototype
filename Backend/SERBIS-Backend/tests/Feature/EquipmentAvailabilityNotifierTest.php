<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Services\EquipmentAvailabilityNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * App\Services\EquipmentAvailabilityNotifier — the "still needed?" reconfirm
 * for a resident denied because the equipment was not available (MDRRMO
 * feedback, 2026-09-18). Same independent push/SMS-failure shape as
 * SendReturnDueReminders, tested the same way here.
 */
class EquipmentAvailabilityNotifierTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::put('fcm_access_token', 'fake-access-token', 3000);

        $path = tempnam(sys_get_temp_dir(), 'fcm_test_');
        file_put_contents($path, json_encode([
            'client_email' => 'fake@serbis-test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'project_id' => 'serbis-test-project',
        ]));
        config(['services.firebase.credentials' => $path]);

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 0,
            'status' => 'Available',
        ]);
    }

    private function resident(string $phone = '09171111111'): Resident
    {
        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'email_address' => uniqid('resident').'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    private function deniedForUnavailability(Resident $resident, array $overrides = []): EquipmentBorrowing
    {
        return EquipmentBorrowing::create(array_merge([
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Denied',
            'denial_reason' => 'None left this week.',
            'denial_reason_code' => 'Unavailable',
        ], $overrides));
    }

    public function test_notifies_and_marks_a_resident_denied_for_unavailability(): void
    {
        $resident = $this->resident();
        DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
        $borrowing = $this->deniedForUnavailability($resident);
        $this->equipment->update(['available_quantity' => 2]);

        Http::fake([
            'dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        app(EquipmentAvailabilityNotifier::class)->notifyIfAvailable($this->equipment->fresh());

        Http::assertSent(fn ($sent) => isset($sent['message']['notification'])
            && str_contains($sent['message']['notification']['body'], 'Rubber Boat'));
        $this->assertNotNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }

    public function test_does_nothing_while_still_out_of_stock(): void
    {
        $this->deniedForUnavailability($this->resident());
        // available_quantity stays 0 — see setUp().

        app(EquipmentAvailabilityNotifier::class)->notifyIfAvailable($this->equipment->fresh());

        Http::assertNothingSent();
    }

    public function test_ignores_a_denial_for_a_reason_other_than_unavailability(): void
    {
        $borrowing = $this->deniedForUnavailability($this->resident(), ['denial_reason_code' => 'Other']);
        $this->equipment->update(['available_quantity' => 2]);

        app(EquipmentAvailabilityNotifier::class)->notifyIfAvailable($this->equipment->fresh());

        Http::assertNothingSent();
        $this->assertNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }

    public function test_does_not_notify_twice(): void
    {
        $this->deniedForUnavailability($this->resident(), ['availability_reconfirm_sent_at' => now()]);
        $this->equipment->update(['available_quantity' => 2]);

        app(EquipmentAvailabilityNotifier::class)->notifyIfAvailable($this->equipment->fresh());

        Http::assertNothingSent();
    }

    /** A push failure must not stop the SMS from sending or from marking the row. */
    public function test_a_push_failure_does_not_affect_the_sms_side(): void
    {
        $resident = $this->resident();
        DeviceToken::create([
            'resident_id' => $resident->getKey(),
            'token' => 'device-1',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
        $borrowing = $this->deniedForUnavailability($resident);
        $this->equipment->update(['available_quantity' => 2]);

        Http::fake([
            'dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200),
            'fcm.googleapis.com/*' => Http::response([
                'error' => ['status' => 'UNAVAILABLE', 'message' => 'Server is overloaded.'],
            ], 503),
        ]);

        app(EquipmentAvailabilityNotifier::class)->notifyIfAvailable($this->equipment->fresh());

        $this->assertNotNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }

    /** A rejected SMS leaves the row unmarked even though the push succeeded. */
    public function test_a_rejected_sms_leaves_the_row_unmarked(): void
    {
        $resident = $this->resident();
        $borrowing = $this->deniedForUnavailability($resident);
        $this->equipment->update(['available_quantity' => 2]);

        Http::fake([
            'dashboard.philsms.com/*' => Http::response(['status' => 'error'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/0:1'], 200),
        ]);

        app(EquipmentAvailabilityNotifier::class)->notifyIfAvailable($this->equipment->fresh());

        $this->assertNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }
}
