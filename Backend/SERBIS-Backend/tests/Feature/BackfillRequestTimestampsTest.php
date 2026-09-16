<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * serbis:backfill-request-timestamps recovers lifecycle timestamps from
 * tbl_system_logs for requests that changed status before the columns
 * existed.
 *
 * It must apply exactly the rules ServiceRequest::stampLifecycle() applies
 * going forward, or history and live data would disagree about what a
 * "first response" is. The from-Pending and Cancelled cases below are the
 * ones where a looser reading would quietly produce different numbers.
 */
class BackfillRequestTimestampsTest extends TestCase
{
    use RefreshDatabase;

    private Resident $resident;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->service = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Clear a blocked road',
        ]);
    }

    /**
     * Creates a request and clears the columns, standing in for a row that
     * predates them. saveQuietly / raw update so the model event under test
     * does not fill in what the backfill is supposed to recover.
     */
    private function legacyRequest(string $status = 'Pending'): ServiceRequest
    {
        $request = ServiceRequest::create([
            'resident_id' => $this->resident->getKey(),
            'service_id' => $this->service->getKey(),
            'description' => 'Blocked road',
            'status' => $status,
        ]);

        DB::table('tbl_service_request')
            ->where('request_id', $request->request_id)
            ->update(['first_responded_at' => null, 'resolved_at' => null]);

        DB::table('tbl_system_logs')->where('auditable_id', $request->request_id)->delete();

        return $request->fresh();
    }

    /**
     * Exactly the shape TracksHistory writes: old_values is the full pre-save
     * snapshot, new_values only the changed attributes.
     */
    private function logTransition(ServiceRequest $request, ?string $from, string $to, string $at): void
    {
        DB::table('tbl_system_logs')->insert([
            'admin_id' => null,
            'resident_id' => null,
            'action_type' => 'updated',
            'auditable_type' => ServiceRequest::class,
            'auditable_id' => $request->request_id,
            'old_values' => json_encode(['request_id' => $request->request_id, 'status' => $from]),
            'new_values' => json_encode(['status' => $to]),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function backfill(array $options = []): void
    {
        $this->artisan('serbis:backfill-request-timestamps', $options)->assertSuccessful();
    }

    public function test_it_recovers_both_timestamps_from_the_log(): void
    {
        $request = $this->legacyRequest('Resolved');

        $this->logTransition($request, 'Pending', 'Responding', '2026-08-12 01:00:00');
        $this->logTransition($request, 'Responding', 'Resolved', '2026-08-12 05:00:00');

        $this->backfill();

        $fresh = $request->fresh();

        $this->assertSame('2026-08-12 01:00:00', $fresh->first_responded_at->toDateTimeString());
        $this->assertSame('2026-08-12 05:00:00', $fresh->resolved_at->toDateTimeString());
    }

    public function test_it_takes_the_earliest_response_not_the_latest(): void
    {
        $request = $this->legacyRequest('Resolved');

        $this->logTransition($request, 'Pending', 'Booked', '2026-08-12 01:00:00');
        $this->logTransition($request, 'Booked', 'Responding', '2026-08-13 09:00:00');

        $this->backfill();

        $this->assertSame('2026-08-12 01:00:00', $request->fresh()->first_responded_at->toDateTimeString());
    }

    public function test_a_transition_that_did_not_start_from_pending_is_not_a_first_response(): void
    {
        $request = $this->legacyRequest('Responding');

        // A scheduled booking leaving for its appointment. Not the office
        // answering a queued request.
        $this->logTransition($request, 'Booked', 'Responding', '2026-08-12 01:00:00');

        $this->backfill();

        $this->assertNull($request->fresh()->first_responded_at);
    }

    public function test_cancelling_backfills_resolved_at_only(): void
    {
        $request = $this->legacyRequest('Cancelled');

        $this->logTransition($request, 'Pending', 'Cancelled', '2026-08-12 03:00:00');

        $this->backfill();

        $fresh = $request->fresh();

        $this->assertNull($fresh->first_responded_at, 'the resident withdrew; nobody answered');
        $this->assertSame('2026-08-12 03:00:00', $fresh->resolved_at->toDateTimeString());
    }

    public function test_a_request_with_no_log_rows_stays_null(): void
    {
        $request = $this->legacyRequest('Resolved');

        $this->backfill();

        $fresh = $request->fresh();

        $this->assertNull($fresh->first_responded_at);
        $this->assertNull($fresh->resolved_at);
    }

    public function test_it_never_overwrites_a_value_that_is_already_set(): void
    {
        $request = $this->legacyRequest('Resolved');

        DB::table('tbl_service_request')
            ->where('request_id', $request->request_id)
            ->update(['first_responded_at' => '2026-08-01 00:00:00']);

        $this->logTransition($request, 'Pending', 'Responding', '2026-08-12 01:00:00');

        $this->backfill();

        $this->assertSame('2026-08-01 00:00:00', $request->fresh()->first_responded_at->toDateTimeString());
    }

    /**
     * The command is documented as safe to re-run, which is the whole reason
     * it is a command rather than a migration.
     */
    public function test_it_is_idempotent(): void
    {
        $request = $this->legacyRequest('Resolved');

        $this->logTransition($request, 'Pending', 'Responding', '2026-08-12 01:00:00');
        $this->logTransition($request, 'Responding', 'Resolved', '2026-08-12 05:00:00');

        $this->backfill();
        $after = $request->fresh();

        $this->backfill();
        $again = $request->fresh();

        $this->assertEquals($after->first_responded_at, $again->first_responded_at);
        $this->assertEquals($after->resolved_at, $again->resolved_at);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $request = $this->legacyRequest('Resolved');

        $this->logTransition($request, 'Pending', 'Responding', '2026-08-12 01:00:00');

        $this->backfill(['--dry-run' => true]);

        $this->assertNull($request->fresh()->first_responded_at);
    }

    /**
     * PurgeRetiredServices deletes requests with raw SQL and does not remove
     * every log row that referenced them, so the log can outlive its request.
     */
    public function test_a_log_row_whose_request_is_gone_is_skipped(): void
    {
        $request = $this->legacyRequest('Resolved');
        $id = $request->request_id;

        $this->logTransition($request, 'Pending', 'Responding', '2026-08-12 01:00:00');

        DB::table('tbl_service_request')->where('request_id', $id)->delete();

        $this->backfill();

        $this->assertDatabaseMissing('tbl_service_request', ['request_id' => $id]);
    }
}
