<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PhilSMS has no sandbox: every call is a real send to a real handset, billed.
 * preventStrayRequests() is what makes these tests safe to run — a request that
 * escapes the fake fails the test instead of costing money.
 */
class SmsBlastLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Barangay $barangayA;
    private Barangay $barangayB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);

        $this->barangayA = Barangay::create(['barangay_name' => 'San Fabian']);
        $this->barangayB = Barangay::create(['barangay_name' => 'San Miguel']);
    }

    private function resident(Barangay $barangay, string $status, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('password123'),
            'status' => $status,
        ]);
    }

    public function test_a_successful_blast_is_recorded_per_barangay_with_its_recipients(): void
    {
        Http::fake(['app.philsms.com/*' => Http::response(['job_id' => 'job-123'], 200)]);

        $a1 = $this->resident($this->barangayA, 'Active', '09171111111');
        $a2 = $this->resident($this->barangayA, 'Active', '09172222222');
        $b1 = $this->resident($this->barangayB, 'Active', '09173333333');

        $response = $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Evacuate low-lying areas immediately.',
            'barangays' => [$this->barangayA->barangay_id, $this->barangayB->barangay_id],
        ]);

        $response->assertOk()->assertJson(['sent' => 3, 'failed' => 0]);

        // One log per barangay, not one per blast: "what was sent to my barangay"
        // is the question a resident asks.
        $this->assertSame(2, SmsLog::count());
        $this->assertSame(3, Recipient::count());

        $logA = SmsLog::where('target_area_id', $this->barangayA->barangay_id)->firstOrFail();
        $this->assertSame('Sent', $logA->status);
        $this->assertSame('job-123', $logA->api_job_id);
        $this->assertNull($logA->disaster_id);
        $this->assertSame('Evacuate low-lying areas immediately.', $logA->message_body);
        $this->assertEqualsCanonicalizing(
            [$a1->resident_id, $a2->resident_id],
            $logA->recipients->pluck('resident_id')->all(),
        );

        $logB = SmsLog::where('target_area_id', $this->barangayB->barangay_id)->firstOrFail();
        $this->assertSame([$b1->resident_id], $logB->recipients->pluck('resident_id')->all());
    }

    public function test_residents_who_were_not_sent_to_are_not_recorded_as_recipients(): void
    {
        Http::fake(['app.philsms.com/*' => Http::response([], 200)]);

        $active = $this->resident($this->barangayA, 'Active', '09171111111');
        $this->resident($this->barangayA, 'Inactive', '09174444444');
        // The column is NOT NULL, so a resident with no usable number stores an
        // empty string — which is exactly why the recipient query filters on
        // both null and ''.
        $this->resident($this->barangayA, 'Active', '');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Test advisory.',
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk()->assertJson(['sent' => 1]);

        $this->assertSame([$active->resident_id], Recipient::pluck('resident_id')->all());
    }

    public function test_a_failed_blast_is_recorded_but_never_reaches_the_advisory_feed(): void
    {
        Http::fake(['app.philsms.com/*' => Http::response(['error' => 'upstream down'], 500)]);

        $resident = $this->resident($this->barangayA, 'Active', '09171111111');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'This one never went out.',
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(500);

        $this->assertSame('Failed', SmsLog::firstOrFail()->status);

        $this->actingAs($resident)->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_advisories_are_scoped_to_the_resident_who_received_them(): void
    {
        Http::fake(['app.philsms.com/*' => Http::response([], 200)]);

        $inA = $this->resident($this->barangayA, 'Active', '09171111111');
        $inB = $this->resident($this->barangayB, 'Active', '09172222222');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Flooding on the national road.',
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertOk();

        $this->actingAs($inA)->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_body', 'Flooding on the national road.')
            ->assertJsonPath('data.0.barangay.barangay_name', 'San Fabian');

        $this->actingAs($inB)->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_blast_with_no_eligible_recipients_records_nothing_and_does_not_call_the_vendor(): void
    {
        Http::fake();

        $this->resident($this->barangayA, 'Inactive', '09171111111');

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Nobody to send this to.',
            'barangays' => [$this->barangayA->barangay_id],
        ])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }
}
