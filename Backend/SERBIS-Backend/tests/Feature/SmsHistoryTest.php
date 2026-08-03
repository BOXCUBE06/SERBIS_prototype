<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /api/logs/sms — the admin panel's SMS History tab.
 *
 * The tab shipped fetching this route before the route existed, so the table
 * was rendering a 404 body. These tests assert the response shape the table
 * actually binds to, not just a 200: wrong keys would leave the columns blank
 * and look identical to having no history.
 *
 * Http::preventStrayRequests() is inherited discipline from SmsBlastLoggingTest
 * — SkySMS has no sandbox, so an escaped request is a billed real send.
 */
class SmsHistoryTest extends TestCase
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
            'role' => 'admin',
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $phone, string $status = 'Active'): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('password123'),
            'status' => $status,
        ]);
    }

    private function blast(string $message, int $status = 200): void
    {
        Http::fake(['skysms.skyio.site/*' => Http::response(['job_id' => 'job-1'], $status)]);

        $this->actingAs($this->admin)->postJson('/api/sms/blast', [
            'message' => $message,
            'barangays' => [$this->barangay->barangay_id],
        ]);
    }

    public function test_history_returns_the_keys_the_panel_table_binds_to(): void
    {
        $this->resident('09171111111');
        $this->resident('09172222222');

        $this->blast('Evacuate low-lying areas immediately.');

        $response = $this->actingAs($this->admin)->getJson('/api/logs/sms')->assertOk();

        $response->assertJsonPath('data.0.message', 'Evacuate low-lying areas immediately.')
            ->assertJsonPath('data.0.user.name', 'MDRRMO Admin')
            ->assertJsonPath('data.0.barangay', 'San Fabian')
            ->assertJsonPath('data.0.recipient_count', 2)
            ->assertJsonPath('data.0.status', 'Sent');
    }

    public function test_a_failed_blast_appears_in_the_history(): void
    {
        $this->resident('09171111111');

        // 500 from the vendor. The advisory feed hides these from residents; the
        // admin history must not, because this is the record somebody consults
        // when a warning did not arrive.
        $this->blast('Typhoon signal number 3.', 500);

        $this->actingAs($this->admin)->getJson('/api/logs/sms')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'Failed')
            ->assertJsonPath('data.0.message', 'Typhoon signal number 3.');
    }

    public function test_the_newest_blast_is_first(): void
    {
        $this->resident('09171111111');

        $this->blast('First warning.');
        $this->travel(1)->minutes();
        $this->blast('Second warning.');

        $this->actingAs($this->admin)->getJson('/api/logs/sms')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Second warning.')
            ->assertJsonPath('data.1.message', 'First warning.');
    }

    public function test_the_count_reflects_who_was_actually_sent_to(): void
    {
        $this->resident('09171111111');
        // Excluded by the recipient query: Inactive, and a blank number. The
        // count has to follow the recipient rows, not barangay membership.
        $this->resident('09174444444', 'Inactive');
        $this->resident('');

        $this->blast('Boil water advisory.');

        $this->actingAs($this->admin)->getJson('/api/logs/sms')
            ->assertOk()
            ->assertJsonPath('data.0.recipient_count', 1);
    }

    public function test_a_resident_cannot_read_the_blast_history(): void
    {
        $resident = $this->resident('09171111111');

        // The route is inside the is.admin group. A resident reads their own
        // advisories through GET /advisories, which is scoped to blasts they
        // were a recipient of.
        $this->actingAs($resident)->getJson('/api/logs/sms')->assertForbidden();
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->getJson('/api/logs/sms')->assertUnauthorized();
    }
}
