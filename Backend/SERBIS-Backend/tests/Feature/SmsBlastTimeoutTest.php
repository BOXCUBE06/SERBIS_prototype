<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsBlastCode;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\PhilSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What happens when PhilSMS accepts a blast and never answers.
 *
 * Production, 2026-09-03: cURL error 28, timed out after 6002ms with 0 bytes
 * received. The message was delivered and billed — the vendor processed the
 * request, the instance stopped waiting for the reply. The ConnectionException
 * aborted the controller before recordBlast(), so the transaction rolled back,
 * nothing was written anywhere, and the panel showed "Server Error". Staff
 * reading that would reasonably send it again and pay twice.
 *
 * The rule this pins: a timeout is not a failed send. It is a send whose
 * outcome is unknown, and the expensive mistake is treating unknown as "nothing
 * happened".
 *
 * PhilSMS has no sandbox, so preventStrayRequests() is what makes this safe to
 * run — a request escaping the fake fails the test instead of costing money.
 */
class SmsBlastTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

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
            'status' => 'Active',
        ]);

        SmsBlastCode::create([
            'code_hash' => Hash::make('123456'),
            'updated_by' => $this->admin->admin_id,
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09171111111',
            'email_address' => 'r1@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }

    /** Reproduces the production failure: the send throws rather than answering. */
    private function fakeTimeout(): void
    {
        Http::fake([
            'dashboard.philsms.com/*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 6002 milliseconds with 0 bytes received',
            ),
        ]);
    }

    private function blast(): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/sms/blast', [
            'message' => 'MDRRMO Echague weather advisory: heavy rain expected.',
            'code' => '123456',
            'barangays' => [$this->barangay->barangay_id],
        ]);
    }

    public function test_a_timeout_answers_202_and_tells_staff_not_to_resend(): void
    {
        $this->fakeTimeout();

        $response = $this->blast()
            ->assertStatus(202)
            ->assertJsonPath('unconfirmed', true);

        // The wording is the whole point of the response — staff act on this
        // sentence, not on the status code.
        $this->assertStringContainsString('Do NOT send it again', $response->json('message'));
    }

    /**
     * The regression that matters most. Before this, the exception aborted the
     * controller and DB::transaction rolled back, so a blast that reached real
     * handsets left no row anywhere.
     */
    public function test_a_timeout_is_still_recorded_rather_than_rolled_back(): void
    {
        $this->fakeTimeout();
        $this->blast();

        $log = SmsLog::firstOrFail();

        $this->assertSame('Unconfirmed', $log->status);
        $this->assertSame($this->barangay->barangay_id, $log->target_area_id);
        $this->assertNull($log->api_job_id, 'There was no response, so there is no job id to record.');

        $this->assertSame(1, Recipient::where('sms_log_id', $log->sms_log_id)->count());
        $this->assertSame('Unconfirmed', Recipient::firstOrFail()->status);
    }

    /**
     * 'Unconfirmed' must not be filed as 'Failed'. A failed row is the one that
     * invites a resend, and this send most likely went out.
     */
    public function test_a_timeout_is_not_recorded_as_failed(): void
    {
        $this->fakeTimeout();
        $this->blast();

        $this->assertSame(0, SmsLog::where('status', 'Failed')->count());
        $this->assertSame(0, SmsLog::where('status', 'Sent')->count());
    }

    /**
     * The resident's advisory feed shows it. The handset most likely has the
     * message; a feed that omitted it would contradict the phone in their hand.
     * Only 'Failed' is withheld — see SmsController::advisories().
     */
    public function test_an_unconfirmed_blast_still_reaches_the_resident_advisory_feed(): void
    {
        $this->fakeTimeout();
        $this->blast();

        $resident = Resident::firstOrFail();
        $resident->markEmailAsVerified();

        Sanctum::actingAs($resident->fresh());

        $this->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'Unconfirmed');
    }

    /**
     * The blast gets its own, longer timeout because no phone is waiting on it.
     * The three OTP and notification callers keep the 6s default, which is bound
     * by the mobile client's own 15s per-request limit.
     */
    public function test_the_blast_uses_the_longer_timeout_and_not_the_otp_default(): void
    {
        $this->assertSame(20, PhilSms::BLAST_TIMEOUT);

        $reflected = new \ReflectionMethod(PhilSms::class, 'send');
        $default = $reflected->getParameters()[2]->getDefaultValue();

        $this->assertSame(6, $default, 'The OTP paths must keep the short timeout.');
    }
}
