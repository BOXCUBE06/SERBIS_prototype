<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\InfoMaterial;
use App\Models\Resident;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/** Documents section: what upload stores, what the list returns to the panel and the app, and what delete removes. */
class InfoMaterialLifecycleTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    private string $disk;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = config('filesystems.uploads.public');
        Storage::fake($this->disk);
        $this->admin = $this->makeLimitedStaff([AdminSections::FILES]);
        Sanctum::actingAs($this->admin);
    }

    private function upload(string $title, string $name = 'map.pdf')
    {
        return $this->post('/api/admin/info-materials', [
            'title' => $title,
            'file' => UploadedFile::fake()->create($name, 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }

    public function test_upload_stores_the_file_and_the_row(): void
    {
        $id = $this->upload('Flood map')->assertCreated()->json('files_id');

        $material = InfoMaterial::find($id);
        $this->assertSame('Flood map', $material->title);
        $this->assertSame('pdf', $material->file_type);
        Storage::disk($this->disk)->assertExists($material->file_path);
    }

    public function test_the_list_returns_every_field_the_panel_and_the_app_read(): void
    {
        $id = $this->upload('Flood map')->json('files_id');
        $material = InfoMaterial::find($id);

        $row = $this->getJson('/api/admin/info-materials')->assertOk()->json('0');

        // Panel: title, file_type, file_size, created_at, full_url. App: the same plus files_id and the verified trio.
        foreach (['files_id', 'title', 'file_type', 'file_size', 'full_url', 'created_at', 'verified', 'verified_by_name', 'verified_by_role'] as $key) {
            $this->assertArrayHasKey($key, $row, "Missing {$key}");
        }
        $this->assertSame($id, $row['files_id']);
        $this->assertSame('Flood map', $row['title']);
        $this->assertSame('pdf', $row['file_type']);
        $this->assertSame($material->file_size, $row['file_size']);
        $this->assertGreaterThan(0, $row['file_size']);
        $this->assertStringEndsWith($material->file_path, $row['full_url']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertFalse($row['verified']);
        $this->assertNull($row['verified_by_name']);
        $this->assertNull($row['verified_by_role']);
    }

    public function test_the_resident_list_returns_the_same_fields(): void
    {
        $this->upload('Flood map');
        $resident = Resident::create([
            'barangay_id' => Barangay::create(['barangay_name' => 'San Fabian'])->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '+639171234567',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
        Sanctum::actingAs($resident);

        $row = $this->getJson('/api/info-materials')->assertOk()->json('0');

        foreach (['files_id', 'title', 'file_type', 'file_size', 'full_url', 'created_at', 'verified'] as $key) {
            $this->assertArrayHasKey($key, $row, "Missing {$key}");
        }
    }

    public function test_the_list_is_newest_first(): void
    {
        $this->upload('Older');
        $this->travel(1)->minutes();
        $this->upload('Newer');

        $titles = collect($this->getJson('/api/admin/info-materials')->assertOk()->json())->pluck('title')->all();

        $this->assertSame(['Newer', 'Older'], $titles);
    }

    public function test_delete_removes_the_row_and_the_stored_file(): void
    {
        $id = $this->upload('Flood map')->json('files_id');
        $path = InfoMaterial::find($id)->file_path;
        Storage::disk($this->disk)->assertExists($path);

        $this->deleteJson("/api/admin/info-materials/{$id}")->assertOk();

        $this->assertNull(InfoMaterial::find($id));
        $this->assertDatabaseMissing('tbl_info_materials', ['files_id' => $id]);
        Storage::disk($this->disk)->assertMissing($path);
    }

    public function test_a_deleted_material_leaves_both_lists_and_the_others_stay(): void
    {
        $gone = $this->upload('Gone', 'a.pdf')->json('files_id');
        $kept = $this->upload('Kept', 'b.pdf')->json('files_id');
        $keptPath = InfoMaterial::find($kept)->file_path;

        $this->deleteJson("/api/admin/info-materials/{$gone}")->assertOk();

        $this->assertSame([$kept], collect($this->getJson('/api/admin/info-materials')->json())->pluck('files_id')->all());
        $this->assertSame([$kept], collect($this->getJson('/api/info-materials')->json())->pluck('files_id')->all());
        Storage::disk($this->disk)->assertExists($keptPath);
    }

    public function test_a_legacy_storage_prefixed_path_is_still_deleted(): void
    {
        Storage::disk($this->disk)->put('info_materials/old.pdf', 'x');
        $material = InfoMaterial::create([
            'uploader_id' => $this->admin->admin_id,
            'title' => 'Old',
            'file_path' => 'storage/info_materials/old.pdf',
            'file_type' => 'pdf',
            'file_size' => 1,
        ]);

        $this->deleteJson("/api/admin/info-materials/{$material->files_id}")->assertOk();

        Storage::disk($this->disk)->assertMissing('info_materials/old.pdf');
    }

    public function test_deleting_twice_or_a_missing_id_is_a_404(): void
    {
        $id = $this->upload('Flood map')->json('files_id');

        $this->deleteJson("/api/admin/info-materials/{$id}")->assertOk();
        $this->deleteJson("/api/admin/info-materials/{$id}")->assertNotFound();
        $this->deleteJson('/api/admin/info-materials/999999')->assertNotFound();
    }

    public function test_without_the_files_section_nothing_is_deleted(): void
    {
        $id = $this->upload('Flood map')->json('files_id');
        $path = InfoMaterial::find($id)->file_path;

        Sanctum::actingAs($this->makeLimitedStaff([AdminSections::LOGS], 'other@test.local'));
        $this->deleteJson("/api/admin/info-materials/{$id}")->assertForbidden();

        $this->assertNotNull(InfoMaterial::find($id));
        Storage::disk($this->disk)->assertExists($path);
    }
}
