<?php

namespace Tests\Feature;

use App\Mail\EquipmentDueTomorrow;
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
use Tests\TestCase;

/**
 * serbis:send-return-reminders — the admin-side email added alongside the
 * existing resident push/SMS (MDRRMO feedback, 2026-09-18). Independent in
 * both directions: a mail failure must not block the resident's own channels
 * from marking a borrowing sent, and SkySMS being unconfigured must not
 * silence this email.
 */
class SendReturnRemindersAdminEmailTest extends TestCase
{
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

    public function test_active_admins_are_emailed_a_summary_of_what_is_due_tomorrow(): void
    {
        Mail::fake();
        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Mail::assertSent(EquipmentDueTomorrow::class, function ($mail) {
            return $mail->hasTo('ana@test.local') && count($mail->rows) === 1 && $mail->rows[0]['item'] === 'Rubber Boat';
        });
    }

    public function test_nothing_due_tomorrow_sends_no_email(): void
    {
        Mail::fake();
        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        // Due today, not tomorrow — outside this email's scope.
        $this->released($this->resident(), now()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_a_deactivated_admin_is_not_emailed(): void
    {
        Mail::fake();
        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        $this->admin->update(['status' => 'Deactivated']);
        $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    /** The independent-failure rule, admin-email direction: SkySMS being down must not silence the office's own copy. */
    public function test_the_admin_email_still_sends_when_skysms_is_not_configured(): void
    {
        Mail::fake();
        // No Http::fake for SkySMS and no token configured — PhilSms::configured() is false.
        config(['services.skysms.api_key' => null]);

        $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(1);

        Mail::assertSent(EquipmentDueTomorrow::class);
    }

    /** The independent-failure rule, the other direction: a mail failure must not block the resident's own reminder from being marked sent. */
    public function test_a_mail_failure_does_not_block_the_resident_reminder_from_being_marked_sent(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));
        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        $borrowing = $this->released($this->resident(), now()->addDay()->format('Y-m-d'));

        $this->artisan('serbis:send-return-reminders')->assertExitCode(0);

        $this->assertNotNull($borrowing->fresh()->return_reminder_sent_at);
    }
}
