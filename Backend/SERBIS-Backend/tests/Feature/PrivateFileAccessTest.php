<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may stream a government ID scan, a site photo or a resident's face.
 *
 * These three routes sit OUTSIDE the `is.admin` group on purpose — staff read
 * any resident's file while a resident reads only their own, which a
 * middleware that refuses non-admins outright cannot express. The cost is that
 * neither check `is.admin` performs runs on them, and for the life of these
 * endpoints neither was performed anywhere else either: the only test was
 * `instanceof Resident`, so any `tbl_user` row passed, whatever its `role` and
 * whether or not it was deactivated.
 *
 * Deactivating through the panel revokes the account's tokens, which is why
 * this never showed up in practice. It does not close the case the guard is
 * for, and IsAdmin's own comment already names it: an account deactivated by a
 * direct database edit keeps every token it holds, and those tokens kept
 * reading ID scans for the rest of their 8-hour life.
 */
class PrivateFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private Resident $owner;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.uploads.private'));

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->owner = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171234567',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $service = Service::create([
            'service_name' => 'Ambulance/Medical Response',
            'code' => 'ambulance-medical-response',
        ]);

        $disk = Storage::disk(config('filesystems.uploads.private'));
        $disk->put('valid-ids/x.jpg', 'id-bytes');
        $disk->put('site-photos/x.jpg', 'site-bytes');

        $this->request = ServiceRequest::create([
            'resident_id' => $this->owner->resident_id,
            'service_id' => $service->service_id,
            'description' => 'Test',
            'valid_id' => 'valid-ids/x.jpg',
            'site_photo' => 'site-photos/x.jpg',
            'status' => 'Pending',
        ]);
    }

    private function admin(string $email, array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => $email,
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ], $overrides));
    }

    public function test_an_active_admin_can_read_any_residents_files(): void
    {
        Sanctum::actingAs($this->admin('active@test.local'));

        $this->get("/api/service-requests/{$this->request->request_id}/valid-id")->assertOk();
        $this->get("/api/service-requests/{$this->request->request_id}/site-photo")->assertOk();
    }

    public function test_the_owner_can_read_their_own_files(): void
    {
        Sanctum::actingAs($this->owner);

        $this->get("/api/service-requests/{$this->request->request_id}/valid-id")->assertOk();
        $this->get("/api/service-requests/{$this->request->request_id}/site-photo")->assertOk();
    }

    public function test_another_resident_gets_a_404_not_the_file(): void
    {
        $barangay = Barangay::first();

        $stranger = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'phone_number' => '09179999999',
            'email_address' => 'jose@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        Sanctum::actingAs($stranger);

        $this->get("/api/service-requests/{$this->request->request_id}/valid-id")->assertStatus(404);
        $this->get("/api/service-requests/{$this->request->request_id}/site-photo")->assertStatus(404);
        $this->get("/api/residents/{$this->owner->resident_id}/photo")->assertStatus(404);
    }

    /**
     * The case the guard exists for. Deactivation applied straight to the
     * column, exactly as a direct database edit would leave it — the account's
     * tokens are untouched and still authenticate.
     */
    public function test_a_deactivated_admin_cannot_read_an_id_scan(): void
    {
        $admin = $this->admin('closed@test.local');
        User::where('admin_id', $admin->admin_id)->update(['status' => 'Inactive']);

        Sanctum::actingAs($admin->fresh());

        $this->get("/api/service-requests/{$this->request->request_id}/valid-id")->assertStatus(403);
        $this->get("/api/service-requests/{$this->request->request_id}/site-photo")->assertStatus(403);
        $this->get("/api/residents/{$this->owner->resident_id}/photo")->assertStatus(403);
    }

    /**
     * `tbl_user.role` is an unconstrained varchar, so a row that is not an
     * admin can exist. `is.admin` refuses it everywhere else; these routes
     * used to hand it every ID scan in the system.
     */
    public function test_a_user_row_that_is_not_an_admin_gets_a_404(): void
    {
        Sanctum::actingAs($this->admin('staffer@test.local', ['role' => 'viewer']));

        $this->get("/api/service-requests/{$this->request->request_id}/valid-id")->assertStatus(404);
        $this->get("/api/service-requests/{$this->request->request_id}/site-photo")->assertStatus(404);
        $this->get("/api/residents/{$this->owner->resident_id}/photo")->assertStatus(404);
    }
}
