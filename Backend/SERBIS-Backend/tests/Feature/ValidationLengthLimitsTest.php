<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * audits/input-validation.md Finding 1: twenty `string` rules had no `max:`,
 * so an over-length value was rejected by MySQL's strict mode (error 1406)
 * rather than the validator — a 500 where the client should see a 422. Every
 * assertion below is 422, not merely "not 500", so a rule that regresses back
 * to unbounded is caught even if the database silently accepted the value.
 *
 * Finding 2 (`phone_number` format): a first attempt at the regex broke
 * ResidentEmailVerificationTest, which proved the app deliberately accepted
 * an undialable number (a landline, a typo) at registration and fell back to
 * emailing the OTP. 2026-08-31: the user decided registration and every
 * admin-facing edit MUST require a real mobile number going forward — that
 * fallback path is now unreachable through validated input, though
 * AuthController::smsIsUsableFor() still honours it for any row written
 * before this rule (see ResidentEmailVerificationTest's two tests that
 * construct such a row directly). PhilSms::PHONE_REGEX is the single
 * definition; every rule below references it rather than re-typing it.
 */
class ValidationLengthLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // register() texts an OTP through PhilSMS, which has no sandbox — an
        // escaped request here would be a billed real send.
        Http::fake(['dashboard.philsms.com/*' => Http::response(['status' => 'success'], 200)]);

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('Password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function actingAsAdmin(): self
    {
        Sanctum::actingAs($this->admin);

        return $this;
    }

    private function resident(array $overrides = []): Resident
    {
        return Resident::create(array_merge([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ], $overrides))->fresh();
    }

    // --- Finding 1: varchar(255) columns ------------------------------------

    public function test_admin_creating_a_resident_with_a_300_char_first_name_gets_422_not_500(): void
    {
        $this->actingAsAdmin()->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => str_repeat('a', 300),
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'new-resident@test.local',
            'password' => 'Password123',
            'status' => 'Active',
        ])->assertStatus(422)->assertJsonValidationErrors('first_name');
    }

    public function test_admin_updating_a_resident_with_a_300_char_last_name_gets_422_not_500(): void
    {
        $resident = $this->resident();

        $this->actingAsAdmin()->putJson("/api/residents/{$resident->resident_id}", [
            'first_name' => 'Maria',
            'last_name' => str_repeat('b', 300),
            'phone_number' => '09171111111',
            'email_address' => $resident->email_address,
            'barangay_id' => $this->barangay->barangay_id,
            'status' => 'Active',
        ])->assertStatus(422)->assertJsonValidationErrors('last_name');
    }

    public function test_self_registration_with_a_300_char_middle_name_gets_422(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'middle_name' => str_repeat('c', 300),
            'last_name' => 'Santos',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171111111',
            'email_address' => 'reg@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422)->assertJsonValidationErrors('middle_name');
    }

    public function test_equipment_item_name_over_255_chars_gets_422(): void
    {
        $this->actingAsAdmin()->postJson('/api/equipments', [
            'item_name' => str_repeat('d', 300),
            'total_quantity' => 5,
            'status' => 'Available',
        ])->assertStatus(422)->assertJsonValidationErrors('item_name');
    }

    // --- Finding 1: text columns ---------------------------------------------

    public function test_service_request_description_over_5000_chars_gets_422_not_500(): void
    {
        $service = Service::create(['service_name' => 'Ambulance/Medical Response']);

        Sanctum::actingAs($this->resident());

        $this->postJson('/api/service-requests', [
            'service_id' => $service->service_id,
            'description' => str_repeat('e', 70000),
            'valid_id' => UploadedFile::fake()->create('valid-id.jpg', 200, 'image/jpeg'),
        ])->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_walk_in_service_request_description_over_5000_chars_gets_422(): void
    {
        $service = Service::create(['service_name' => 'Ambulance/Medical Response']);

        $this->actingAsAdmin()->postJson('/api/admin/service-requests', [
            'walk_in_name' => 'Juan Dela Cruz',
            'walk_in_contact_number' => '09171234567',
            'service_id' => $service->service_id,
            'description' => str_repeat('f', 70000),
        ])->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_service_description_over_5000_chars_gets_422(): void
    {
        $this->actingAsAdmin()->postJson('/api/services', [
            'service_name' => 'Community First Aid',
            'description' => str_repeat('g', 70000),
        ])->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_conduction_request_medical_diagnosis_over_5000_chars_gets_422(): void
    {
        $this->actingAsAdmin()->postJson('/api/conduction-requests', [
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 3, San Isidro',
            'patient_contact_number' => '09171234567',
            'medical_diagnosis' => str_repeat('h', 70000),
            'origin' => 'San Isidro',
            'destination' => 'Echague District Hospital',
        ])->assertStatus(422)->assertJsonValidationErrors('medical_diagnosis');
    }

    public function test_trip_log_others_over_5000_chars_gets_422(): void
    {
        $conductionRequest = $this->actingAsAdmin()->postJson('/api/conduction-requests', [
            'patient_name' => 'Juan Dela Cruz',
            'patient_address' => 'Purok 3, San Isidro',
            'patient_contact_number' => '09171234567',
            'medical_diagnosis' => 'Suspected stroke',
            'origin' => 'San Isidro',
            'destination' => 'Echague District Hospital',
        ])->json('conduction_request_id');

        $this->actingAsAdmin()->patchJson("/api/conduction-requests/{$conductionRequest}/trip-log", [
            'others' => str_repeat('i', 70000),
        ])->assertStatus(422)->assertJsonValidationErrors('others');
    }

    // --- Finding 1: cache-backed MFA codes ------------------------------------

    public function test_an_oversized_challenge_id_is_rejected_before_it_reaches_the_cache(): void
    {
        $this->postJson('/api/admin/login/verify', [
            'challenge_id' => str_repeat('j', 200),
            'code' => '123456',
        ])->assertStatus(422)->assertJsonValidationErrors('challenge_id');
    }

    public function test_a_verification_code_that_is_not_six_characters_is_rejected(): void
    {
        $this->postJson('/api/resident/verify-email', [
            'email_address' => 'nobody@test.local',
            'code' => '12345',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_phone_number_over_20_chars_still_gets_422_via_the_length_ceiling(): void
    {
        $this->actingAsAdmin()->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => str_repeat('1', 21),
            'email_address' => 'longphone@test.local',
            'password' => 'Password123',
            'status' => 'Active',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }

    // --- Finding 2: phone_number must be a real mobile number ----------------

    public function test_self_registration_rejects_a_malformed_phone_number(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => 'not-a-phone-number',
            'email_address' => 'badphone@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }

    public function test_self_registration_rejects_a_landline(): void
    {
        // The specific shape this decision gave up: a landline used to
        // register successfully and fall back to email OTP. It no longer can.
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '(078) 305 1234',
            'email_address' => 'landline@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }

    public function test_admin_creating_a_resident_rejects_a_malformed_phone_number(): void
    {
        $this->actingAsAdmin()->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '5551234',
            'email_address' => 'badphone2@test.local',
            'password' => 'Password123',
            'status' => 'Active',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }

    public function test_admin_editing_a_resident_rejects_a_malformed_phone_number(): void
    {
        $resident = $this->resident();

        $this->actingAsAdmin()->putJson("/api/residents/{$resident->resident_id}", [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => 'abc123',
            'email_address' => $resident->email_address,
            'barangay_id' => $this->barangay->barangay_id,
            'status' => 'Active',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }

    public function test_a_resident_editing_their_own_phone_number_is_rejected_if_malformed(): void
    {
        $resident = $this->resident();

        Sanctum::actingAs($resident);

        $this->patchJson('/api/me', [
            'phone_number' => 'abc123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }

    public function test_registration_accepts_the_local_09_shape(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234567',
            'email_address' => 'shape09@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);
    }

    public function test_registration_accepts_the_country_code_shape_without_a_plus(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '639171234567',
            'email_address' => 'shape639@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);
    }

    public function test_registration_accepts_the_country_code_shape_with_a_plus(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '+639171234567',
            'email_address' => 'shapeplus639@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);
    }
}
