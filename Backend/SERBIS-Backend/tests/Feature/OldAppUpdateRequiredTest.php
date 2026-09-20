<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSkySms;
use Tests\TestCase;

/**
 * The app that shipped before phone login signs up and in with an email
 * address. It shows the server's `message` as-is on any failed request, so each
 * route it used answers 410 with an "update the app" line in English and
 * Filipino. Removed in a later release, once no such app is still in use.
 */
class OldAppUpdateRequiredTest extends TestCase
{
    use FakesSkySms, RefreshDatabase;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSkySms();
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
    }

    private function assertUpdateRequired($response): void
    {
        $response->assertStatus(410)->assertJsonPath('code', 'app_update_required');

        // Both languages in the one string, because the old app shows it verbatim.
        $message = (string) $response->json('message');
        $this->assertStringContainsString('Please update the SERBIS app', $message);
        $this->assertStringContainsString('Paki-update ang SERBIS app', $message);
    }

    public function test_the_email_routes_answer_410_and_do_nothing(): void
    {
        $this->assertUpdateRequired($this->postJson('/api/resident/verify-email', ['email_address' => 'a@b.co', 'code' => '123456']));
        $this->assertUpdateRequired($this->postJson('/api/resident/verify-email/resend', ['email_address' => 'a@b.co']));

        Http::assertNothingSent();
    }

    public function test_register_and_login_with_an_email_answer_410(): void
    {
        $this->assertUpdateRequired($this->postJson('/api/register', [
            'first_name' => 'Old', 'last_name' => 'App', 'barangay_id' => 1,
            'phone_number' => '09171234567', 'email_address' => 'old@test.local',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ]));

        $this->assertUpdateRequired($this->postJson('/api/resident/login', [
            'email_address' => 'maria@test.local', 'password' => 'Password123',
        ]));

        Http::assertNothingSent();
    }

    public function test_editing_an_email_or_a_phone_in_the_profile_answers_410_and_changes_nothing(): void
    {
        $this->assertUpdateRequired($this->actingAs($this->resident)->patchJson('/api/me', ['email_address' => 'new@test.local']));
        $this->assertUpdateRequired($this->actingAs($this->resident)->patchJson('/api/me', ['phone_number' => '09179999999']));
        // Even alongside an ordinary field: nothing is half-applied.
        $this->assertUpdateRequired($this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Rewritten', 'phone_number' => '09179999999',
        ]));

        $fresh = $this->resident->fresh();
        $this->assertSame('maria@test.local', $fresh->email_address);
        $this->assertSame('+639171111111', $fresh->phone_number);
        $this->assertSame('Maria', $fresh->first_name);
    }

    public function test_the_ordinary_profile_fields_still_work_for_an_old_app_already_signed_in(): void
    {
        $this->actingAs($this->resident)->patchJson('/api/me', [
            'first_name' => 'Maria Clara',
            'street_address' => 'Purok 5',
            'sms_opt_in' => false,
        ])->assertOk()->assertJsonPath('user.first_name', 'Maria Clara');

        $fresh = $this->resident->fresh();
        $this->assertSame('Purok 5', $fresh->street_address);
        $this->assertFalse($fresh->sms_opt_in);
    }

    public function test_an_account_with_no_email_reads_back_a_null_the_old_app_tolerates(): void
    {
        $noEmail = Resident::create([
            'barangay_id' => $this->resident->barangay_id,
            'first_name' => 'No', 'last_name' => 'Email',
            'phone_number' => '09172223333',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);

        $this->actingAs($noEmail)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email_address', null)
            ->assertJsonPath('user.phone_number', '+639172223333');
    }
}
