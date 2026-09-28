<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST/DELETE /me/photo and GET /residents/{id}/photo.
 *
 * `photo` was previously an admin-settable free-form string that the panel
 * rendered straight into an <img src>. The tests that matter here are the ones
 * pinning that shut: the column is written by exactly one route, it never
 * reaches a client, and the image itself is readable only by its owner or by
 * staff.
 */
class ResidentPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    private Resident $resident;

    private Resident $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->other = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'juan@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);
    }

    private function disk(): string
    {
        return config('filesystems.uploads.private');
    }

    /**
     * `UploadedFile::fake()->image()` needs the GD extension, which is not
     * enabled on either development machine. Declaring the mime type directly
     * exercises the same validation rule without generating pixels.
     */
    private static function fakePhoto(int $kilobytes = 120): UploadedFile
    {
        return UploadedFile::fake()->create('face.jpg', $kilobytes, 'image/jpeg');
    }

    private function upload(Resident $as): TestResponse
    {
        return $this->actingAs($as)->post('/api/me/photo', [
            'photo' => self::fakePhoto(),
        ]);
    }

    public function test_a_resident_can_upload_their_own_photo(): void
    {
        $this->upload($this->resident)->assertOk();

        $this->resident->refresh();

        $this->assertNotNull($this->resident->photo);
        $this->assertStringStartsWith(
            'resident-photos/'.$this->resident->getKey().'/',
            $this->resident->photo
        );
        Storage::disk($this->disk())->assertExists($this->resident->photo);
    }

    public function test_the_stored_path_never_reaches_a_client(): void
    {
        $this->upload($this->resident)->assertOk()->assertJsonMissingPath('user.photo');

        // A path handed to a client is a path a client can ask for. Clients get
        // a boolean and fetch the image from the route instead.
        $this->actingAs($this->resident)->getJson('/api/me')
            ->assertOk()
            ->assertJsonMissingPath('user.photo')
            ->assertJsonPath('user.has_photo', true);
    }

    public function test_has_photo_is_false_before_any_upload(): void
    {
        $this->actingAs($this->resident)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.has_photo', false);
    }

    public function test_replacing_a_photo_deletes_the_file_it_replaced(): void
    {
        $this->upload($this->resident)->assertOk();
        $first = $this->resident->fresh()->photo;

        $this->upload($this->resident)->assertOk();
        $second = $this->resident->fresh()->photo;

        $this->assertNotSame($first, $second);
        // Without this the old file would sit on the disk for the life of the
        // deployment, referenced by nothing.
        Storage::disk($this->disk())->assertMissing($first);
        Storage::disk($this->disk())->assertExists($second);
    }

    public function test_a_resident_can_remove_their_photo(): void
    {
        $this->upload($this->resident)->assertOk();
        $path = $this->resident->fresh()->photo;

        $this->actingAs($this->resident)->deleteJson('/api/me/photo')
            ->assertOk()
            ->assertJsonPath('user.has_photo', false);

        $this->assertNull($this->resident->fresh()->photo);
        Storage::disk($this->disk())->assertMissing($path);
    }

    public function test_it_rejects_a_file_that_is_not_an_image(): void
    {
        $this->actingAs($this->resident)->postJson('/api/me/photo', [
            'photo' => UploadedFile::fake()->create('payload.php', 8),
        ])->assertStatus(422);

        $this->assertNull($this->resident->fresh()->photo);
    }

    public function test_it_rejects_an_image_over_the_size_ceiling(): void
    {
        $this->actingAs($this->resident)->postJson('/api/me/photo', [
            'photo' => self::fakePhoto(5000),
        ])->assertStatus(422);
    }

    public function test_an_admin_cannot_upload_on_a_residents_behalf(): void
    {
        // The column is the resident's own face. Admin CRUD has no file to
        // attach and no business naming one.
        $this->actingAs($this->admin)->post('/api/me/photo', [
            'photo' => self::fakePhoto(),
        ])->assertStatus(403);
    }

    public function test_the_admin_create_route_ignores_a_submitted_photo(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09173333333',
            'email_address' => 'ana@test.local',
            'password' => 'Password123',
            'status' => 'Active',
            'photo' => 'https://evil.example.com/tracker.png',
        ])->assertStatus(201);

        $created = Resident::where('email_address', 'ana@test.local')->first();

        $this->assertNotNull($created);
        $this->assertNull($created->photo);
    }

    public function test_the_owner_can_read_their_own_photo(): void
    {
        $this->upload($this->resident)->assertOk();

        $this->actingAs($this->resident)
            ->get('/api/residents/'.$this->resident->getKey().'/photo')
            ->assertOk();
    }

    public function test_staff_can_read_any_residents_photo(): void
    {
        // The admin list draws one photo per row, so read is deliberately wider
        // than write.
        $this->upload($this->resident)->assertOk();

        $this->actingAs($this->admin)
            ->get('/api/residents/'.$this->resident->getKey().'/photo')
            ->assertOk();
    }

    public function test_a_resident_cannot_read_another_residents_photo(): void
    {
        $this->upload($this->resident)->assertOk();

        // 404, not 403: the response must not confirm which accounts exist.
        $this->actingAs($this->other)
            ->getJson('/api/residents/'.$this->resident->getKey().'/photo')
            ->assertStatus(404);
    }

    public function test_a_resident_with_no_photo_reads_as_not_found(): void
    {
        $this->actingAs($this->resident)
            ->getJson('/api/residents/'.$this->resident->getKey().'/photo')
            ->assertStatus(404);
    }

    public function test_a_missing_file_behind_a_set_column_is_not_a_500(): void
    {
        $this->upload($this->resident)->assertOk();

        // What an ephemeral filesystem produces after a deploy: the row survives,
        // the file does not.
        Storage::disk($this->disk())->delete($this->resident->fresh()->photo);

        $this->actingAs($this->resident)
            ->getJson('/api/residents/'.$this->resident->getKey().'/photo')
            ->assertStatus(404);
    }

    public function test_every_photo_route_requires_authentication(): void
    {
        $this->postJson('/api/me/photo')->assertStatus(401);
        $this->deleteJson('/api/me/photo')->assertStatus(401);
        $this->getJson('/api/residents/'.$this->resident->getKey().'/photo')->assertStatus(401);
        $this->postJson('/api/residents/'.$this->resident->getKey().'/photo')->assertStatus(401);
        $this->deleteJson('/api/residents/'.$this->resident->getKey().'/photo')->assertStatus(401);
    }

    private function institution(string $type = Resident::TYPE_ORGANIZATION): Resident
    {
        $account = Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Contact',
            'last_name' => 'Person',
            'phone_number' => $type === Resident::TYPE_ORGANIZATION ? '09174444444' : '09175555555',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        $account->forceFill([
            'account_type' => $type,
            'organization_name' => $type === Resident::TYPE_ORGANIZATION ? 'Echague Community Council' : null,
        ])->save();

        return $account;
    }

    private function staffUpload(Resident $target): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/residents/'.$target->getKey().'/photo', [
            'photo' => self::fakePhoto(),
        ]);
    }

    private function photoLogs(Resident $target, string $action)
    {
        return DB::table('tbl_system_logs')
            ->where('auditable_type', Resident::class)
            ->where('auditable_id', $target->getKey())
            ->where('action_type', $action);
    }

    public function test_staff_can_set_replace_and_remove_an_organizations_photo(): void
    {
        $org = $this->institution();

        $this->staffUpload($org)->assertOk()->assertJsonPath('resident.has_photo', true);
        $first = $org->fresh()->photo;
        Storage::disk($this->disk())->assertExists($first);

        $this->staffUpload($org)->assertOk();
        $second = $org->fresh()->photo;
        $this->assertNotSame($first, $second);
        Storage::disk($this->disk())->assertMissing($first);
        Storage::disk($this->disk())->assertExists($second);

        $this->actingAs($this->admin)->deleteJson('/api/residents/'.$org->getKey().'/photo')
            ->assertOk()
            ->assertJsonPath('resident.has_photo', false);
        $this->assertNull($org->fresh()->photo);
        Storage::disk($this->disk())->assertMissing($second);

        // TracksHistory ignores the column, so these rows are the only trace.
        $this->assertSame(2, $this->photoLogs($org, 'photo_changed')->count());
        $this->assertSame(1, $this->photoLogs($org, 'photo_removed')->count());
    }

    public function test_the_photo_log_names_the_admin_and_never_holds_a_path(): void
    {
        $org = $this->institution();
        $this->staffUpload($org)->assertOk();

        $row = $this->photoLogs($org, 'photo_changed')->first();

        $this->assertSame($this->admin->getKey(), (int) $row->admin_id);
        $this->assertNull($row->resident_id);
        $this->assertNull($row->old_values);
        $this->assertNull($row->new_values);
    }

    public function test_staff_can_set_a_barangay_accounts_photo(): void
    {
        $hall = $this->institution(Resident::TYPE_BARANGAY);

        $this->staffUpload($hall)->assertOk();

        $this->assertNotNull($hall->fresh()->photo);
    }

    public function test_staff_cannot_set_a_head_of_the_familys_photo(): void
    {
        $this->staffUpload($this->resident)->assertStatus(422)->assertJsonValidationErrors('account_type');
        $this->actingAs($this->admin)->deleteJson('/api/residents/'.$this->resident->getKey().'/photo')->assertStatus(422);

        $this->assertNull($this->resident->fresh()->photo);
        $this->assertSame([], Storage::disk($this->disk())->allFiles('resident-photos'));
        $this->assertSame(0, $this->photoLogs($this->resident, 'photo_changed')->count());
    }

    public function test_staff_upload_rejects_a_non_image_and_an_unknown_id(): void
    {
        $org = $this->institution();

        $this->actingAs($this->admin)->postJson('/api/residents/'.$org->getKey().'/photo', [
            'photo' => UploadedFile::fake()->create('payload.php', 8),
        ])->assertStatus(422);
        $this->assertNull($org->fresh()->photo);

        $this->actingAs($this->admin)->postJson('/api/residents/999999/photo', [
            'photo' => self::fakePhoto(),
        ])->assertStatus(404);
    }

    public function test_a_resident_cannot_use_the_staff_photo_routes(): void
    {
        $org = $this->institution();

        $this->actingAs($this->other)->postJson('/api/residents/'.$org->getKey().'/photo', [
            'photo' => self::fakePhoto(),
        ])->assertStatus(403);
        $this->actingAs($this->other)->deleteJson('/api/residents/'.$org->getKey().'/photo')->assertStatus(403);

        $this->assertNull($org->fresh()->photo);
    }
}
