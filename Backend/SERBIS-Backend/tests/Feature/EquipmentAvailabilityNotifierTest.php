<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\SystemLog;
use App\Services\EquipmentAvailabilityNotifier;
use App\Support\ReminderFollowUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesFcm;
use Tests\TestCase;

/**
 * App\Services\EquipmentAvailabilityNotifier — the "still needed?" reconfirm
 * for a resident denied because the equipment was not available (MDRRMO
 * feedback, 2026-09-18). Push only: a resident FCM cannot reach stays unmarked,
 * so they are asked again at the next restock, and staff are told to ring them.
 */
class EquipmentAvailabilityNotifierTest extends TestCase
{
    use FakesFcm;
    use RefreshDatabase;

    private const FCM = 'fcm.googleapis.com/*';

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureFcm();

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 0,
            'status' => 'Available',
        ]);
    }

    private function resident(string $phone = '09171111111', bool $withDevice = true): Resident
    {
        $barangay = Barangay::firstOrCreate(['barangay_name' => 'San Antonio Ugad']);

        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'email_address' => uniqid('resident').'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        if ($withDevice) {
            $this->deviceFor($resident, 'device-'.$resident->getKey());
        }

        return $resident;
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

    private function restock(int $quantity = 2): void
    {
        $this->equipment->update(['available_quantity' => $quantity]);

        app(EquipmentAvailabilityNotifier::class)->notifyIfAvailable($this->equipment->fresh());
    }

    public function test_pushes_and_marks_a_resident_denied_for_unavailability(): void
    {
        $borrowing = $this->deniedForUnavailability($this->resident());
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $this->restock();

        Http::assertSent(fn (Request $request) => $request['message']['notification']['title'] === 'SERBIS'
            && $request['message']['notification']['body'] === 'Rubber Boat is available again. Still need it? Request it from the app. — MDRRMO Echague'
            && $request['message']['data'] === ['borrow_id' => (string) $borrowing->borrow_id]);
        $this->assertNotNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }

    public function test_no_text_is_sent_and_the_item_name_goes_in_as_typed(): void
    {
        $borrowing = $this->deniedForUnavailability($this->resident());
        $this->equipment->update(['item_name' => 'Tent.com Set']);
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $this->restock();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request['message']['notification']['body'], 'Tent.com Set is available again'));
        $this->assertNotNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }

    public function test_a_resident_with_no_device_is_left_unmarked_and_staff_are_told(): void
    {
        $resident = $this->resident(withDevice: false);
        $borrowing = $this->deniedForUnavailability($resident);
        Http::fake();

        $this->restock();

        // Nothing was sent anywhere: no push to attempt and no text to fall back on.
        Http::assertNothingSent();
        $this->assertNull($borrowing->fresh()->availability_reconfirm_sent_at);

        $log = SystemLog::where('action_type', ReminderFollowUp::ACTION)->sole();
        $this->assertSame($resident->getKey(), $log->resident_id);
        $this->assertSame($borrowing->getKey(), (int) $log->auditable_id);
        $this->assertSame(ReminderFollowUp::AVAILABILITY, $log->new_values['kind']);
    }

    public function test_a_push_fcm_refuses_leaves_the_row_unmarked_and_is_a_follow_up(): void
    {
        $borrowing = $this->deniedForUnavailability($this->resident());
        Http::fake([self::FCM => $this->fcmRefuses()]);

        $this->restock();

        $this->assertNull($borrowing->fresh()->availability_reconfirm_sent_at);
        $this->assertSame(1, SystemLog::where('action_type', ReminderFollowUp::ACTION)->count());
    }

    public function test_one_device_taking_it_is_enough_to_mark_the_row(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident, 'second-device');
        $borrowing = $this->deniedForUnavailability($resident);

        Http::fake([self::FCM => Http::sequence()
            ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
            ->push(['name' => 'projects/x/messages/0:2'], 200)]);

        $this->restock();

        $this->assertNotNull($borrowing->fresh()->availability_reconfirm_sent_at);
        $this->assertSame(0, SystemLog::where('action_type', ReminderFollowUp::ACTION)->count());
    }

    public function test_an_unreached_resident_is_asked_again_at_the_next_restock(): void
    {
        $resident = $this->resident(withDevice: false);
        $borrowing = $this->deniedForUnavailability($resident);
        Http::fake([self::FCM => $this->fcmAccepts()]);

        $this->restock();
        $this->assertNull($borrowing->fresh()->availability_reconfirm_sent_at);

        $this->deviceFor($resident);

        $this->restock(3);
        $this->assertNotNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }

    public function test_the_follow_up_is_not_repeated_within_a_day(): void
    {
        $this->deniedForUnavailability($this->resident(withDevice: false));
        Http::fake();

        $this->restock();
        $this->restock(3);

        $this->assertSame(1, SystemLog::where('action_type', ReminderFollowUp::ACTION)->count());
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

        $this->restock();

        Http::assertNothingSent();
        $this->assertNull($borrowing->fresh()->availability_reconfirm_sent_at);
    }

    public function test_does_not_notify_twice(): void
    {
        $this->deniedForUnavailability($this->resident(), ['availability_reconfirm_sent_at' => now()]);

        $this->restock();

        Http::assertNothingSent();
    }
}
