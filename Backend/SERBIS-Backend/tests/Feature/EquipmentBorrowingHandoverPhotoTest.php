<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Equipment;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST, GET and DELETE on /api/borrowings/{id}/photo.
 *
 * The three things worth pinning: the photo lands on the PRIVATE disk and is
 * never reachable by URL, staff write it and the borrower can only read it,
 * and a missing photo blocks nothing at all.
 *
 * Removal has its own section at the bottom. Its window is narrower than the
 * one for adding — a photo goes only while the borrowing is still in the stage
 * that photo belongs to — so the asymmetry is asserted, not just the happy
 * path: a release photo can still be ADDED to a Returned borrowing and can no
 * longer be DELETED from one.
 */
class EquipmentBorrowingHandoverPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Resident $otherResident;

    private User $admin;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $barangay = Barangay::create(['barangay_name' => 'San Antonio Ugad']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->otherResident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'jose@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->equipment = Equipment::create([
            'item_name' => 'Rubber Boat',
            'total_quantity' => 4,
            'available_quantity' => 4,
            'status' => 'Available',
        ]);
    }

    private function borrowing(string $status = 'Released'): EquipmentBorrowing
    {
        return EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Barangay flood drill',
            'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------- upload

    public function test_staff_can_add_a_release_photo_to_a_released_borrowing(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $path = $borrowing->fresh()->release_photo_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        // Foldered by borrow_id, uuid-named: a client filename never reaches
        // the filesystem.
        $this->assertStringStartsWith("borrowing-photos/{$borrowing->borrow_id}/", $path);
        $this->assertStringNotContainsString('boat', $path);
    }

    public function test_a_return_photo_needs_the_item_to_be_back(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'return',
            'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
        ])->assertStatus(422);

        $this->assertNull($borrowing->fresh()->return_photo_path);
    }

    public function test_a_release_photo_is_refused_before_anything_has_changed_hands(): void
    {
        // Pending and Approved are both refused: a photo filed against either
        // would be evidence of a handover that has not happened.
        foreach (['Pending', 'Approved'] as $status) {
            $borrowing = $this->borrowing($status);

            Sanctum::actingAs($this->admin);

            $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
                'stage' => 'release',
                'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
            ])->assertStatus(422);

            $this->assertNull($borrowing->fresh()->release_photo_path, "status {$status}");
        }
    }

    public function test_a_release_photo_can_still_be_added_after_the_item_is_back(): void
    {
        // Staff photographing after the fact is a late record, not a false one.
        $borrowing = $this->borrowing('Returned');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $this->assertNotNull($borrowing->fresh()->release_photo_path);
    }

    public function test_re_uploading_replaces_the_photo_and_deletes_the_old_file(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('first.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $first = $borrowing->fresh()->release_photo_path;

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('second.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $second = $borrowing->fresh()->release_photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertExists($second);
        // One photo per stage means the replaced file is not left behind.
        Storage::disk('local')->assertMissing($first);
    }

    public function test_a_non_image_is_refused(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors(['photo']);
    }

    public function test_a_photo_over_the_size_cap_is_refused(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            // 4MB cap, matching site_photo.
            'photo' => UploadedFile::fake()->create('huge.jpg', 4097, 'image/jpeg'),
        ])->assertStatus(422)->assertJsonValidationErrors(['photo']);
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'inspection',
            'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
        ])->assertStatus(422)->assertJsonValidationErrors(['stage']);
    }

    public function test_a_resident_cannot_upload_a_photo_to_their_own_borrowing(): void
    {
        // The evidence must not be supplied by one side of the dispute it
        // exists to settle. The route sits in the admin group.
        $borrowing = $this->borrowing('Released');

        $this->actingAs($this->resident)
            ->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
                'stage' => 'release',
                'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
            ])->assertForbidden();

        $this->assertNull($borrowing->fresh()->release_photo_path);
    }

    // ------------------------------------------------------------------ read

    public function test_the_borrower_can_read_their_own_handover_photo(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $this->actingAs($this->resident)
            ->get("/api/borrowings/{$borrowing->borrow_id}/photo/release")
            ->assertOk();
    }

    public function test_another_resident_gets_a_404_rather_than_a_403(): void
    {
        // 404 so the response does not confirm the record exists, the same
        // rule cancel() and show() follow.
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => 'release',
            'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $this->actingAs($this->otherResident)
            ->getJson("/api/borrowings/{$borrowing->borrow_id}/photo/release")
            ->assertStatus(404);
    }

    public function test_reading_a_stage_with_no_photo_is_a_404(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/borrowings/{$borrowing->borrow_id}/photo/return")
            ->assertStatus(404);
    }

    public function test_an_unknown_stage_on_read_is_a_404(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/borrowings/{$borrowing->borrow_id}/photo/inspection")
            ->assertStatus(404);
    }

    // ------------------------------------------------------- never a blocker

    public function test_a_borrowing_with_no_photo_still_releases_and_returns(): void
    {
        // The whole point of "optional". A record that was never photographed
        // must complete its lifecycle untouched by any of this.
        $borrowing = $this->borrowing('Approved');

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/borrowings/{$borrowing->borrow_id}", ['status' => 'Released'])
            ->assertOk();
        $this->patchJson("/api/borrowings/{$borrowing->borrow_id}", ['status' => 'Returned'])
            ->assertOk();

        $borrowing = $borrowing->fresh();
        $this->assertSame('Returned', $borrowing->status);
        $this->assertNull($borrowing->release_photo_path);
        $this->assertNull($borrowing->return_photo_path);
    }

    // ------------------------------------------------------------- insurance

    public function test_a_photo_path_cannot_be_mass_assigned(): void
    {
        // Neither column is in $fillable, deliberately: a filesystem path is
        // written by uploadPhoto() and by nothing else. Leaving it
        // mass-assignable would let a future splat point a record at an
        // arbitrary file on the private disk, which is where government ID
        // scans live.
        $borrowing = EquipmentBorrowing::create([
            'resident_id' => $this->resident->getKey(),
            'equipment_id' => $this->equipment->getKey(),
            'quantity' => 1,
            'purpose' => 'Barangay flood drill',
            'status' => 'Released',
            'release_photo_path' => 'valid-ids/1/someone-elses-government-id.jpg',
        ]);

        $this->assertNull($borrowing->fresh()->release_photo_path);
    }

    public function test_update_cannot_write_a_photo_path_either(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/borrowings/{$borrowing->borrow_id}", [
            'status' => 'Returned',
            'release_photo_path' => 'valid-ids/1/someone-elses-government-id.jpg',
        ])->assertOk();

        $this->assertNull($borrowing->fresh()->release_photo_path);
    }

    // --------------------------------------------------------------- removal

    /**
     * Puts a real file on the fake disk through the endpoint itself, so the
     * removal tests below start from the same state the office would be in.
     */
    private function withPhoto(EquipmentBorrowing $borrowing, string $stage): string
    {
        $this->postJson("/api/borrowings/{$borrowing->borrow_id}/photo", [
            'stage' => $stage,
            'photo' => UploadedFile::fake()->create('boat.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $column = $stage === 'release' ? 'release_photo_path' : 'return_photo_path';
        $path = $borrowing->fresh()->{$column};

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        return $path;
    }

    public function test_a_release_photo_can_be_removed_while_the_item_is_still_out(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);
        $path = $this->withPhoto($borrowing, 'release');

        $this->deleteJson("/api/borrowings/{$borrowing->borrow_id}/photo/release")
            ->assertOk()
            ->assertJsonPath('has_release_photo', false);

        $this->assertNull($borrowing->fresh()->release_photo_path);
        // The file, not only the column: a row cleared with the image left on
        // the private disk is the leak this endpoint would otherwise create.
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_return_photo_can_be_removed_while_the_record_is_returned(): void
    {
        $borrowing = $this->borrowing('Returned');

        Sanctum::actingAs($this->admin);
        $path = $this->withPhoto($borrowing, 'return');

        $this->deleteJson("/api/borrowings/{$borrowing->borrow_id}/photo/return")
            ->assertOk()
            ->assertJsonPath('has_return_photo', false);

        $this->assertNull($borrowing->fresh()->return_photo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_release_photo_cannot_be_removed_once_the_item_is_back(): void
    {
        // The asymmetry with upload, asserted directly: this same borrowing
        // would accept a release photo (see
        // test_a_release_photo_can_still_be_added_after_the_item_is_back) and
        // refuses to give this one up.
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);
        $path = $this->withPhoto($borrowing, 'release');

        $borrowing->update(['status' => 'Returned']);

        $this->deleteJson("/api/borrowings/{$borrowing->borrow_id}/photo/release")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Released'));

        $this->assertSame($path, $borrowing->fresh()->release_photo_path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_terminal_record_keeps_its_photos(): void
    {
        // Denied and Cancelled cannot hold a photo in the first place, so the
        // case that matters is a finished loan: nothing may be erased from it.
        $borrowing = $this->borrowing('Returned');

        Sanctum::actingAs($this->admin);
        $release = $this->withPhoto($borrowing, 'release');
        $return = $this->withPhoto($borrowing, 'return');

        $this->deleteJson("/api/borrowings/{$borrowing->borrow_id}/photo/release")
            ->assertStatus(422);

        $borrowing->refresh();
        $this->assertSame($release, $borrowing->release_photo_path);
        $this->assertNotNull($borrowing->return_photo_path);
        Storage::disk('local')->assertExists($release);
        Storage::disk('local')->assertExists($return);
    }

    public function test_removing_a_stage_that_has_no_photo_is_a_404(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/borrowings/{$borrowing->borrow_id}/photo/release")
            ->assertStatus(404);
    }

    public function test_an_unknown_stage_on_removal_is_a_404(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/borrowings/{$borrowing->borrow_id}/photo/handover")
            ->assertStatus(404);
    }

    public function test_a_resident_cannot_remove_a_photo_from_their_own_borrowing(): void
    {
        $borrowing = $this->borrowing('Released');

        Sanctum::actingAs($this->admin);
        $path = $this->withPhoto($borrowing, 'release');

        // Read is wider than write here, and removal is the far end of write:
        // the borrower can see this photo and must not be able to delete it.
        Sanctum::actingAs($this->resident);

        $this->deleteJson("/api/borrowings/{$borrowing->borrow_id}/photo/release")
            ->assertStatus(403);

        $this->assertSame($path, $borrowing->fresh()->release_photo_path);
        Storage::disk('local')->assertExists($path);
    }
}
