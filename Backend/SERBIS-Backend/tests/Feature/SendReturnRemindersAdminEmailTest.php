<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesFcm;
use Tests\TestCase;

/**
 * serbis:send-return-reminders no longer emails staff: the addresses were
 * @serbis.com, a domain nobody owns, and staff now sign in with a username.
 * Residents keep their own push/SMS reminders.
 */
class SendReturnRemindersAdminEmailTest extends TestCase
{
    use FakesFcm;
    use RefreshDatabase;

    private Equipment $equipment;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Manila'));

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 3,
            'status' => 'Available',
        ]);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
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

    private function released(Resident $resident, string $dueDate, array $overrides = []): EquipmentBorrowing
    {
        return EquipmentBorrowing::create(array_merge([
            'resident_id' => $resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'status' => 'Released',
            'due_date' => $dueDate,
            'released_at' => now(),
        ], $overrides));
    }

    public function test_staff_are_not_emailed_about_what_is_due_tomorrow(): void
    {
        Mail::fake();
        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_the_resident_is_still_reminded_and_marked_sent(): void
    {
        Mail::fake();
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => $this->fcmAccepts()]);

        $resident = $this->resident();
        $this->deviceFor($resident);
        $borrowing = $this->released($resident, now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
        Mail::assertNothingSent();
    }
}
