<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DeviceToken;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Services\Fcm;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesFcm;
use Tests\TestCase;

/**
 * serbis:send-return-reminders — what the equipment push itself carries and how
 * each device outcome is treated. Marking, windows and staff follow-ups are in
 * SendReturnRemindersTest.
 */
class SendReturnRemindersPushTest extends TestCase
{
    use FakesFcm;
    use RefreshDatabase;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureFcm();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Manila'));

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 3,
            'status' => 'Available',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    private function released(Resident $resident): EquipmentBorrowing
    {
        return EquipmentBorrowing::create([
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Released',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'released_at' => now(),
        ]);
    }

    public function test_the_push_carries_the_title_the_body_and_the_borrow_id(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident);
        Http::fake(['fcm.googleapis.com/*' => $this->fcmAccepts()]);

        $borrowing = $this->released($resident);

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $request['message']['token'] === 'device-1'
            && $request['message']['notification']['title'] === 'SERBIS'
            && $request['message']['notification']['body'] === 'Your borrowed Rubber Boat is due back tomorrow (Sep 11). — MDRRMO Echague'
            && $request['message']['data'] === ['borrow_id' => (string) $borrowing->borrow_id]);
    }

    public function test_every_registered_device_is_pushed_to(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident, 'phone');
        $this->deviceFor($resident, 'tablet');
        Http::fake(['fcm.googleapis.com/*' => $this->fcmAccepts()]);

        $this->released($resident);

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_no_text_is_attempted_for_a_reminder(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident);
        Http::fake(['fcm.googleapis.com/*' => $this->fcmAccepts()]);

        $this->released($resident);

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'skysms'));
    }

    public function test_a_dead_token_is_deleted_and_counts_as_no_device(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404)]);

        $borrowing = $this->released($resident);

        $this->artisan('serbis:send-return-reminders')
            ->expectsOutputToContain('1 not delivered (no registered device)')
            ->assertExitCode(0);

        $this->assertSame(0, DeviceToken::count(), 'FCM said the token will never work again');
        $this->assertNull($borrowing->fresh()->return_reminder_sent_at);
    }

    public function test_notify_resident_reports_how_many_devices_accepted(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident, 'good');
        $this->deviceFor($resident, 'bad');

        Http::fake(['fcm.googleapis.com/*' => function (Request $request) {
            return $request['message']['token'] === 'good' ? $this->fcmAccepts() : $this->fcmRefuses();
        }]);

        $this->assertSame(1, app(Fcm::class)->notifyResident($resident->getKey(), 'SERBIS', 'Hello'));
        $this->assertSame(0, app(Fcm::class)->notifyResident(null, 'SERBIS', 'Hello'));
        $this->assertSame(0, app(Fcm::class)->notifyResident($this->resident('09172222222')->getKey(), 'SERBIS', 'Hello'));
    }

    public function test_a_broadcast_reaches_the_devices_after_one_that_fails(): void
    {
        $resident = $this->resident();
        $this->deviceFor($resident, 'bad');
        $this->deviceFor($resident, 'good');

        Http::fake(['fcm.googleapis.com/*' => function (Request $request) {
            return $request['message']['token'] === 'good' ? $this->fcmAccepts() : $this->fcmRefuses();
        }]);

        app(Fcm::class)->notifyAllResidents('SERBIS', 'Hello');

        // Both were tried: a failure on the first must not end the broadcast.
        Http::assertSentCount(2);
    }
}
