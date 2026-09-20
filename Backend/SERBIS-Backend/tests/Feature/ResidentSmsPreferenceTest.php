<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsBlastCode;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A resident can turn the MDRRMO's text blasts off, and the blast has to obey
 * it. Two halves that are easy to ship apart: a preference nothing reads is a
 * switch wired to nothing, and it looks identical to a working one from the
 * app.
 *
 * Http::preventStrayRequests() for the same reason as SmsBlastLoggingTest —
 * SkySMS has no sandbox and every escaped request is a billed send.
 */
class ResidentSmsPreferenceTest extends TestCase
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
        ]);

        SmsBlastCode::create([
            'code_hash' => Hash::make('123456'),
            'updated_by' => $this->admin->admin_id,
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    /**
     * Reloaded before it is returned. create() leaves the model holding only
     * what was passed to it, so a column filled by its database default is
     * absent from the instance — and actingAs() would then authenticate a user
     * whose preference reads null instead of the true that is actually stored.
     */
    private function resident(string $phone, array $overrides = []): Resident
    {
        return Resident::create(array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ], $overrides))->fresh();
    }

    public function test_a_resident_is_opted_in_by_default(): void
    {
        // Nothing in the payload mentions the preference: this is the state an
        // account created before the column existed comes back with, and the
        // one a fresh sign-up gets. Both must be "still receiving warnings".
        $resident = $this->resident('09171111111');

        $this->assertTrue($resident->fresh()->sms_opt_in);
    }

    public function test_get_me_reports_the_preference_as_a_boolean(): void
    {
        $resident = $this->resident('09171111111');

        // Asserted as a real boolean, not a truthy 1. Without the model cast
        // MySQL returns 1/0 and a client switch bound straight to the value
        // renders from a number.
        $this->actingAs($resident)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.sms_opt_in', true);
    }

    public function test_a_resident_can_opt_out_and_back_in(): void
    {
        $resident = $this->resident('09171111111');

        $this->actingAs($resident)->patchJson('/api/me', ['sms_opt_in' => false])
            ->assertOk()
            ->assertJsonPath('user.sms_opt_in', false);

        $this->assertFalse($resident->fresh()->sms_opt_in);

        $this->actingAs($resident)->patchJson('/api/me', ['sms_opt_in' => true])
            ->assertOk()
            ->assertJsonPath('user.sms_opt_in', true);

        $this->assertTrue($resident->fresh()->sms_opt_in);
    }

    public function test_the_string_zero_opts_out_rather_than_in(): void
    {
        // A form-encoded client sends "0", and validate() hands back the string
        // it was given. Assigned straight to a boolean column that is true.
        $resident = $this->resident('09171111111');

        $this->actingAs($resident)->patchJson('/api/me', ['sms_opt_in' => '0'])
            ->assertOk()
            ->assertJsonPath('user.sms_opt_in', false);

        $this->assertFalse($resident->fresh()->sms_opt_in);
    }

    public function test_a_profile_edit_that_omits_the_preference_leaves_it_alone(): void
    {
        $resident = $this->resident('09171111111');
        $resident->sms_opt_in = false;
        $resident->save();

        $this->actingAs($resident)->patchJson('/api/me', ['first_name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('user.first_name', 'Renamed')
            ->assertJsonPath('user.sms_opt_in', false);

        $this->assertFalse($resident->fresh()->sms_opt_in);
    }

    public function test_a_non_boolean_preference_is_rejected(): void
    {
        $resident = $this->resident('09171111111');

        $this->actingAs($resident)->patchJson('/api/me', ['sms_opt_in' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sms_opt_in');

        $this->assertTrue($resident->fresh()->sms_opt_in);
    }

    public function test_an_admin_cannot_set_the_preference_through_patch_me(): void
    {
        // PATCH /me is resident-only for every field; the preference does not
        // change that. An admin has no /me profile here at all.
        $this->actingAs($this->admin)->patchJson('/api/me', ['sms_opt_in' => false])
            ->assertStatus(403);
    }

    public function test_the_blast_skips_residents_who_opted_out(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response(['job_id' => 'job-1'], 200)]);

        $optedIn = $this->resident('09171111111');
        $optedOut = $this->resident('09172222222', ['sms_opt_in' => false]);

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Evacuate low-lying areas immediately.',
            'code' => '123456',
            'barangays' => [$this->barangay->barangay_id],
        ])->assertOk()->assertJson(['queued' => 1, 'failed' => 0]);

        // Both halves matter. The recipient row is the record of who the agency
        // says it warned, so an opted-out resident must be absent from it...
        $this->assertSame([$optedIn->resident_id], Recipient::pluck('resident_id')->all());

        // ...and their number must not have reached the vendor, which is the
        // half that actually costs money and delivers a text.
        Http::assertSent(function ($request) {
            // SkySMS takes a list of E.164 numbers, so the
            // assertion is on the normalised form, not on what the resident typed.
            return $request['recipients'] === [['phone_number' => '+639171111111']];
        });
    }

    public function test_an_opted_out_resident_sees_no_advisory_for_a_blast_they_missed(): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response([], 200)]);

        $this->resident('09171111111');
        $optedOut = $this->resident('09172222222', ['sms_opt_in' => false]);

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Flooding on the national road.',
            'code' => '123456',
            'barangays' => [$this->barangay->barangay_id],
        ])->assertOk();

        // The feed reads tbl_recipients, so this follows from the filter above
        // rather than being enforced separately — and that is the behaviour we
        // want: the app does not show a warning as received when no text was.
        $this->actingAs($optedOut)->getJson('/api/advisories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_barangay_where_everyone_opted_out_never_calls_the_vendor(): void
    {
        Http::fake();

        $this->resident('09171111111', ['sms_opt_in' => false]);

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => 'Nobody wants this one.',
            'code' => '123456',
            'barangays' => [$this->barangay->barangay_id],
        ])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }
}
