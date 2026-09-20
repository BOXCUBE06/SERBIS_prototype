<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\InfoMaterial;
use App\Models\Recipient;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\SmsLog;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Backend audit #10 — pagination on the two log endpoints.
 *
 * Half of this file tests the endpoints that now page. The other half asserts
 * that the endpoints deliberately left alone still return EVERY row, and that
 * half matters more.
 *
 * It does not assert their envelope, because the envelope is not what breaks.
 * These endpoints use three different ones already — a bare array, `{data}`
 * from a plain json(), and `{data}` from ServiceResource::collection — and the
 * mobile client cannot tell them apart: ApiService._decode wraps a bare array
 * as {'data': [...]} and ApiService.listFrom reads data['data'] in every case.
 * Laravel's paginate() puts a list at data['data'] too. So paginating one of
 * these would not empty a mobile screen and would not throw; the app would
 * quietly show the first 25 rows forever, with no error and no way to ask for
 * the rest, on a phone nobody here is holding. Counting rows catches that.
 * Checking for a `data` key would not.
 *
 * Http::preventStrayRequests() is inherited discipline from SmsHistoryTest —
 * SkySMS has no sandbox, so an escaped request is a billed real send.
 */
class ListPaginationTest extends TestCase
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
            'role' => 'Admin',
        ]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $status = 'Active'): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => '09171111111',
            'email_address' => uniqid('r', true).'@test.local',
            'password' => Hash::make('password123'),
            'status' => $status,
        ])->fresh();
    }

    /**
     * Log rows written directly rather than through TracksHistory: the point
     * here is the paging, and driving 30 real panel actions to produce 30 rows
     * would make the test about something else.
     */
    private function systemLogs(int $count, string $action = 'created'): void
    {
        // setUp's User::create writes a log row of its own — User uses
        // TracksHistory. Cleared so the fixture size is exactly $count and the
        // page-boundary assertions below are arithmetic, not arithmetic plus a
        // constant nobody remembers to update.
        SystemLog::query()->delete();

        for ($i = 1; $i <= $count; $i++) {
            SystemLog::create([
                'admin_id' => $this->admin->admin_id,
                'action_type' => $action,
                'auditable_type' => 'App\\Models\\Resident',
                'auditable_id' => $i,
            ]);
        }
    }

    private function smsLogs(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            SmsLog::create([
                'sender_id' => $this->admin->admin_id,
                'target_area_id' => $this->barangay->barangay_id,
                'message_body' => "Advisory number {$i}.",
                'status' => 'Sent',
            ]);
        }
    }

    // ---------------------------------------------------------------
    // The endpoints that now page
    // ---------------------------------------------------------------

    public function test_system_logs_default_to_twenty_five_per_page(): void
    {
        $this->systemLogs(30);

        $this->actingAs($this->admin)->getJson('/api/logs/system')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 30)
            // `success` predates this change and LogsView still reads it.
            ->assertJsonPath('success', true);
    }

    public function test_system_logs_honour_an_explicit_page_size(): void
    {
        $this->systemLogs(30);

        $this->actingAs($this->admin)->getJson('/api/logs/system?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_system_logs_second_page_holds_the_remainder(): void
    {
        $this->systemLogs(30);

        $this->actingAs($this->admin)->getJson('/api/logs/system?per_page=25&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.from', 26)
            ->assertJsonPath('meta.to', 30);
    }

    /**
     * The bug this guards is the one a reader cannot see: every row in the
     * fixture shares a created_at, so ordering by that column alone leaves
     * MySQL free to hand the same row out on both pages. Asserting the two
     * pages are disjoint and together cover everything is the only way to
     * catch it — each page looks perfectly well-formed on its own.
     *
     * HONEST LIMIT, recorded rather than hidden: removing the `log_id`
     * tiebreaker from the controller leaves this test GREEN. The mutation was
     * confirmed applied. On a table this small with no index on created_at,
     * InnoDB's filesort happens to come back in primary-key order, so the
     * ordering is stable in practice even though nothing promises it. The
     * tiebreaker is therefore defence in depth with no test that can currently
     * fail without it — an added index, a larger table or a different MySQL
     * version can each change the plan, and none of those would announce
     * themselves. Do not delete it on the strength of this test passing.
     */
    public function test_paging_never_repeats_or_drops_a_row_when_timestamps_tie(): void
    {
        $this->systemLogs(20);

        $first = $this->actingAs($this->admin)
            ->getJson('/api/logs/system?per_page=10')->assertOk()->json('data');
        $second = $this->actingAs($this->admin)
            ->getJson('/api/logs/system?per_page=10&page=2')->assertOk()->json('data');

        $ids = array_merge(
            array_column($first, 'description'),
            array_column($second, 'description')
        );

        $this->assertCount(20, $ids);
        $this->assertCount(20, array_unique($ids), 'A row was served on more than one page.');
    }

    public function test_an_oversized_page_request_is_capped(): void
    {
        $this->systemLogs(30);

        // Without the ceiling, ?per_page=100000 returns the whole table and
        // undoes the reason the endpoint pages at all.
        $this->actingAs($this->admin)->getJson('/api/logs/system?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_a_nonsense_page_size_falls_back_to_the_default(): void
    {
        $this->systemLogs(30);

        $this->actingAs($this->admin)->getJson('/api/logs/system?per_page=all')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);

        $this->actingAs($this->admin)->getJson('/api/logs/system?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_sms_history_pages_and_reports_its_meta(): void
    {
        $this->smsLogs(30);

        $this->actingAs($this->admin)->getJson('/api/logs/sms?per_page=12')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('success', true);
    }

    public function test_sms_history_second_page_holds_the_remainder(): void
    {
        $this->smsLogs(30);

        $this->actingAs($this->admin)->getJson('/api/logs/sms?per_page=25&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    // ---------------------------------------------------------------
    // Search, which had to move to the server with the paging
    // ---------------------------------------------------------------

    /**
     * The regression this prevents: LogsView's search box was Vuetify's
     * client-side filter over the whole table. Once the endpoint pages, a
     * client-side filter only sees the loaded page, so a row on page 3 becomes
     * unfindable and the screen gives no sign that it is only searching part
     * of the data.
     */
    public function test_system_log_search_reaches_rows_beyond_the_first_page(): void
    {
        $this->systemLogs(30, 'created');

        SystemLog::create([
            'admin_id' => $this->admin->admin_id,
            'action_type' => 'deleted',
            'auditable_type' => 'App\\Models\\Vehicle',
            'auditable_id' => 999,
        ]);

        // Without server-side search this row sits outside the first page of
        // 25 and no amount of typing would surface it.
        $this->actingAs($this->admin)->getJson('/api/logs/system?search=Vehicle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.module', 'Vehicle');
    }

    public function test_system_log_search_matches_the_admin_full_name(): void
    {
        $this->systemLogs(3);

        $this->actingAs($this->admin)->getJson('/api/logs/system?search=MDRRMO Admin')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($this->admin)->getJson('/api/logs/system?search=Nobody')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_sms_history_search_matches_the_message_body(): void
    {
        $this->smsLogs(30);

        $this->actingAs($this->admin)->getJson('/api/logs/sms?search=number 17.')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Advisory number 17.');
    }

    /**
     * A LIKE wildcard typed into the search box must be matched literally.
     * Unescaped, a search for "%" matches every row, which reads as a search
     * that silently did nothing.
     */
    public function test_a_wildcard_in_the_search_term_is_matched_literally(): void
    {
        $this->smsLogs(5);

        foreach ([
            'River at 100% capacity.',
            // The decoy, and the entire point of the test. Unescaped, the term
            // becomes LIKE '%100%%' — the trailing wildcard swallows anything
            // after "100" and this row matches too. Escaped, only the row with
            // a literal per-cent sign comes back. Without a decoy the two
            // behaviours are indistinguishable and the test proves nothing.
            'Road closed at 100 meters.',
        ] as $body) {
            SmsLog::create([
                'sender_id' => $this->admin->admin_id,
                'target_area_id' => $this->barangay->barangay_id,
                'message_body' => $body,
                'status' => 'Sent',
            ]);
        }

        $this->actingAs($this->admin)->getJson('/api/logs/sms?search=100%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'River at 100% capacity.');
    }

    /**
     * A cleared search box must show the whole list again, not an empty one.
     *
     * Two things learned writing this, both worth keeping. First, Laravel's
     * global TrimStrings and ConvertEmptyStringsToNull middleware run before
     * the controller, so `?search=%20%20` arrives as NULL, never as two
     * spaces — a controller-level trim is not what makes this pass and cannot
     * be tested through the HTTP layer.
     *
     * Second, and recorded rather than hidden: removing the controller's
     * empty-search guard entirely leaves this test GREEN, mutation confirmed
     * applied. `message_body` is NOT NULL, so the '%%' the guard prevents
     * matches every row and the result set is identical. The guard is there
     * for query cost, not for behaviour, and no assertion on the response can
     * distinguish the two. Do not delete it on the strength of this passing.
     */
    public function test_an_empty_search_is_not_treated_as_a_filter(): void
    {
        $this->smsLogs(4);

        $this->actingAs($this->admin)->getJson('/api/logs/sms?search=')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);

        // Whitespace is what a cleared Vuetify field can leave behind.
        $this->actingAs($this->admin)->getJson('/api/logs/sms?search=%20%20')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);
    }

    // ---------------------------------------------------------------
    // The endpoints deliberately left unpaginated
    //
    // These assert COMPLETENESS, not envelope shape, and the distinction is
    // the whole point. The three envelopes in use here (bare array, `{data}`
    // from a plain json(), `{data}` from ServiceResource::collection) are not
    // interchangeable to read but they are all indistinguishable from a
    // paginated response to the client that matters:
    //
    // ApiService._decode wraps a bare array as {'data': [...]}, and
    // ApiService.listFrom then reads data['data'] in every case. Laravel's
    // paginate() ALSO puts a list at data['data']. So paginating one of these
    // would not empty the mobile screen and would not throw — the app would
    // quietly show the first 25 rows forever, with no error and no way to ask
    // for the rest. Asserting "is it a bare array" would not have caught that;
    // asserting "did every row come back" does.
    // ---------------------------------------------------------------

    /**
     * Bare array or `{data: [...]}` — whichever this endpoint has always used.
     * The property under test is that nothing was truncated, not which
     * envelope carries it.
     */
    private function itemsOf(array $body): array
    {
        return array_is_list($body) ? $body : ($body['data'] ?? []);
    }

    private function assertReturnsEverything(string $route, int $expected, ?Resident $as = null): void
    {
        $request = $as ? $this->actingAs($as) : $this->actingAs($this->admin);

        $body = $request->getJson($route)->assertOk()->json();

        $this->assertArrayNotHasKey(
            'meta',
            $body,
            "{$route} has started paginating. If that was deliberate, its consumer must page too — see App\\Traits\\PaginatesLists."
        );

        $this->assertCount(
            $expected,
            $this->itemsOf($body),
            "{$route} returned fewer rows than exist; a client reading data['data'] would show a truncated list with no error."
        );
    }

    public function test_services_returns_every_service_not_a_first_page(): void
    {
        $resident = $this->resident();

        for ($i = 1; $i <= 30; $i++) {
            Service::create(['service_name' => "Service {$i}"]);
        }

        $this->assertReturnsEverything('/api/services', 30, $resident);
    }

    public function test_service_requests_returns_every_request_the_resident_filed(): void
    {
        $resident = $this->resident();
        $service = Service::create(['service_name' => 'Ambulance']);

        for ($i = 1; $i <= 30; $i++) {
            ServiceRequest::create([
                'resident_id' => $resident->resident_id,
                'service_id' => $service->service_id,
                'description' => "Incident {$i}",
                'valid_id' => "valid-ids/{$i}.jpg",
                'status' => 'Pending',
            ]);
        }

        $this->assertReturnsEverything('/api/service-requests', 30, $resident);
    }

    public function test_info_materials_returns_everything(): void
    {
        $resident = $this->resident();

        for ($i = 1; $i <= 30; $i++) {
            InfoMaterial::create([
                'uploader_id' => $this->admin->admin_id,
                'title' => "Flood guide {$i}",
                'file_path' => "info-materials/guide-{$i}.pdf",
                'file_type' => 'pdf',
                'file_size' => 1024,
            ]);
        }

        $this->assertReturnsEverything('/api/info-materials', 30, $resident);
    }

    public function test_barangays_returns_everything(): void
    {
        // Public on purpose: the mobile register screen needs the picker
        // before an account exists, so this one is not even authenticated.
        for ($i = 1; $i <= 30; $i++) {
            Barangay::create(['barangay_name' => "Barangay {$i}"]);
        }

        $body = $this->getJson('/api/barangays')->assertOk()->json();

        $this->assertArrayNotHasKey('meta', $body);
        // 30 created here plus San Fabian from setUp.
        $this->assertCount(31, $this->itemsOf($body));
    }

    public function test_advisories_returns_every_blast_the_resident_received(): void
    {
        $resident = $this->resident();

        for ($i = 1; $i <= 30; $i++) {
            $log = SmsLog::create([
                'sender_id' => $this->admin->admin_id,
                'target_area_id' => $this->barangay->barangay_id,
                'message_body' => "Advisory {$i}.",
                'status' => 'Sent',
            ]);

            Recipient::create([
                'sms_log_id' => $log->sms_log_id,
                'resident_id' => $resident->resident_id,
                'status' => 'Sent',
            ]);
        }

        $this->assertReturnsEverything('/api/advisories', 30, $resident);
    }

    public function test_admin_lists_left_alone_return_everything(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Vehicle::create([
                'unit_identifier' => "ABC-{$i}",
                'type' => 'Ambulance',
                'status' => 'Available',
            ]);
        }

        $this->assertReturnsEverything('/api/vehicles', 30);
    }

    public function test_residents_list_left_alone_returns_everything(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->resident();
        }

        $this->assertReturnsEverything('/api/residents', 30);
    }

    /**
     * These two already wrapped in `data` before audit #10. Named explicitly
     * so that paginating one becomes a deliberate act with a red test rather
     * than a drive-by change — their views count and tab over the full array.
     */
    public function test_admin_requests_and_admins_did_not_grow_a_meta_block(): void
    {
        $this->resident();

        foreach (['/api/admin/service-requests', '/api/admins'] as $route) {
            $body = $this->actingAs($this->admin)->getJson($route)->assertOk()->json();

            $this->assertArrayHasKey('data', $body, "{$route} lost its data wrapper.");
            $this->assertArrayNotHasKey('meta', $body, "{$route} has started paginating.");
        }
    }

    // ---------------------------------------------------------------
    // Access control is unchanged by any of the above
    // ---------------------------------------------------------------

    public function test_pagination_parameters_do_not_open_the_logs_to_a_resident(): void
    {
        $resident = $this->resident();

        $this->actingAs($resident)->getJson('/api/logs/system?per_page=10')->assertForbidden();
        $this->actingAs($resident)->getJson('/api/logs/sms?page=2')->assertForbidden();
    }
}
