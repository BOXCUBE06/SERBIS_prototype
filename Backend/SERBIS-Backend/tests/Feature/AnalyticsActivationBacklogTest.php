<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/admin/analytics — section 11, account activation backlog.
 *
 * 'Inactive' on tbl_residents means self-registered and waiting for
 * activation — NOT 'Deactivated', which is an admin turning an account off
 * on purpose (Resident::isDeactivated() documents the split). Counting the
 * wrong one, or both, would misreport a closed account as part of the
 * backlog an admin actually needs to act on.
 */
class AnalyticsActivationBacklogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->actingAs($this->admin);
    }

    private function residentWithStatus(string $status, string $phone): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => $phone,
            'email_address' => $phone.'@test.local',
            'password' => Hash::make('password123'),
            'status' => $status,
        ]);
    }

    private function activation(array $query = []): array
    {
        return $this->getJson('/api/admin/analytics?'.http_build_query($query))
            ->assertOk()
            ->json()['activation'];
    }

    public function test_the_backlog_counts_inactive_and_never_deactivated(): void
    {
        $this->residentWithStatus('Inactive', '09171111111');
        $this->residentWithStatus('Inactive', '09172222222');
        $this->residentWithStatus('Deactivated', '09173333333');
        $this->residentWithStatus('Active', '09174444444');

        $activation = $this->activation();

        $this->assertSame(2, $activation['backlog'], 'Deactivated is a closed account, not a waiting one, and must not be counted');
    }

    public function test_the_backlog_ignores_the_date_window(): void
    {
        $resident = $this->residentWithStatus('Inactive', '09171111111');
        DB::table('tbl_residents')->where('resident_id', $resident->resident_id)->update(['created_at' => now()->subMonths(6)]);

        // A narrow window that would exclude a 6-month-old sign-up.
        $activation = $this->activation(['preset' => 'month']);

        $this->assertSame(1, $activation['backlog'], 'a stale sign-up nobody activated must not disappear because the filter says this month');
    }

    public function test_signups_by_month_are_windowed(): void
    {
        $inWindow = $this->residentWithStatus('Inactive', '09171111111');
        DB::table('tbl_residents')->where('resident_id', $inWindow->resident_id)->update(['created_at' => '2026-09-05 00:00:00']);

        $outOfWindow = $this->residentWithStatus('Active', '09172222222');
        DB::table('tbl_residents')->where('resident_id', $outOfWindow->resident_id)->update(['created_at' => '2026-08-01 00:00:00']);

        $activation = $this->activation(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(1, $activation['signupsByMonth']['total']);
    }

    public function test_the_barangay_filter_narrows_the_backlog(): void
    {
        $otherBarangay = Barangay::create(['barangay_name' => 'Angang']);
        Resident::create([
            'barangay_id' => $otherBarangay->barangay_id,
            'first_name' => 'Jose', 'last_name' => 'Cruz',
            'phone_number' => '09175555555', 'email_address' => 'jose@test.local',
            'password' => Hash::make('password123'), 'status' => 'Inactive',
        ]);

        $this->residentWithStatus('Inactive', '09171111111');

        $filtered = $this->activation(['barangay_id' => $this->barangay->barangay_id]);

        $this->assertSame(1, $filtered['backlog'], 'the other barangay\'s inactive resident must not count here');
    }
}
