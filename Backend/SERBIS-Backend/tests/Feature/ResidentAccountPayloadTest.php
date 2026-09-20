<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The signed-in user payload the mobile app builds its home screen from: the
 * account type, the organization name, the status and the barangay. Login,
 * verify and /me all return the same shape (the model plus its barangay), so
 * this pins it once for each way a resident gets a session.
 */
class ResidentAccountPayloadTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(['skysms.skyio.site/*' => Http::response(['status' => 'success'], 200)]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Miguel']);
    }

    private function account(string $type, ?string $organization, string $status): Resident
    {
        $resident = new Resident([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Ian',
            'last_name' => 'Uy',
            'phone_number' => '09171234567',
            'email_address' => "{$type}@test.local",
            'password' => Hash::make('Password123'),
            'status' => $status,
        ]);
        $resident->account_type = $type;
        $resident->organization_name = $organization;
        $resident->save();

        return $resident;
    }

    public function test_me_returns_the_type_name_status_and_barangay_for_an_organization(): void
    {
        $org = $this->account('organization', 'Isabela State University', 'Inactive');

        $this->actingAs($org)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.account_type', 'organization')
            ->assertJsonPath('user.organization_name', 'Isabela State University')
            ->assertJsonPath('user.status', 'Inactive')
            ->assertJsonPath('user.barangay.barangay_name', 'San Miguel');
    }

    public function test_me_returns_the_barangay_for_a_barangay_account_and_an_individual(): void
    {
        $hall = $this->account('barangay', null, 'Active');
        $this->actingAs($hall)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.account_type', 'barangay')
            ->assertJsonPath('user.organization_name', null)
            ->assertJsonPath('user.barangay.barangay_name', 'San Miguel');

        $head = $this->account('head_of_family', null, 'Active');
        $this->actingAs($head)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.account_type', 'head_of_family');
    }

    public function test_the_verify_step_of_a_new_organization_returns_the_same_payload(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Ian',
            'last_name' => 'Uy',
            'barangay_id' => $this->barangay->barangay_id,
            'phone_number' => '09171234567',
            'email_address' => 'isu@test.local',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'account_type' => 'organization',
            'organization_name' => 'Isabela State University',
        ])->assertStatus(201);

        $code = Http::recorded()
            ->map(function ($pair) {
                preg_match('/[0-9]{6}/', $pair[0]['message'] ?? '', $m);

                return $m[0] ?? null;
            })
            ->filter()->last();

        $this->postJson('/api/resident/verify-email', ['email_address' => 'isu@test.local', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.account_type', 'organization')
            ->assertJsonPath('user.organization_name', 'Isabela State University')
            ->assertJsonPath('user.status', 'Inactive')
            ->assertJsonPath('user.barangay.barangay_name', 'San Miguel');
    }
}
