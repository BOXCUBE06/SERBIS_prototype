<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/service-requests — which rows an admin token gets back.
 *
 * The route sits under auth:sanctum with no is.admin guard, so both kinds of
 * caller reach the same method and index() branches on the user internally.
 * That branch was broken for the whole life of the endpoint: it compared
 * `$user->role === 'admin'` while AdminController writes 'Admin', so an admin
 * fell through to the resident arm and ran `where('resident_id', $user->getKey())`
 * — filtering the resident table by an admin_id.
 *
 * Nothing caught it. Every fixture created admins as lowercase 'admin', which
 * made the comparison true under test and false in production, and no test
 * called this route as an admin at all. Correcting the fixtures alone did not
 * help: with the broken comparison restored and the casing fixed, the suite
 * still passed 35/35, because the branch had no coverage either way.
 *
 * The setup below is what makes this test bite. It gives one resident the same
 * primary key as the admin, which is the collision that turned a broken branch
 * into one resident's requests being served to staff rather than an empty list.
 *
 * That collision is forced, not inherited. RefreshDatabase wraps each test in a
 * transaction and MariaDB does not roll back auto-increment counters, so the two
 * tables drift apart as soon as any other test class has run: this file passed
 * alone and failed in the full suite with admin_id 135 against resident_id 118.
 * Relying on both sequences starting at 1 is only true of a class run in
 * isolation.
 */
class ServiceRequestAdminScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Resident $collidingResident;

    private Resident $otherResident;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->admin = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => 'Admin',
            'email_address' => 'ana@test.local',
            'password' => Hash::make('password123'),
        ]);

        // Key assigned by hand so it matches the admin's. resident_id is the
        // primary key and not fillable, so it cannot come from create().
        $this->collidingResident = new Resident([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone_number' => '09171111111',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
        $this->collidingResident->resident_id = $this->admin->getKey();
        $this->collidingResident->save();

        $this->otherResident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'phone_number' => '09172222222',
            'email_address' => 'jose@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        $this->service = Service::create([
            'service_name' => 'Road Clearing',
            'description' => 'Debris and obstacles',
        ]);

        // Deliberately lopsided. The colliding resident owns the smaller share,
        // so the broken branch returns a strict subset rather than everything.
        $this->requestsFor($this->collidingResident, 2);
        $this->requestsFor($this->otherResident, 3);
    }

    private function requestsFor(Resident $resident, int $count): void
    {
        foreach (range(1, $count) as $n) {
            ServiceRequest::create([
                'resident_id' => $resident->getKey(),
                'service_id' => $this->service->getKey(),
                'description' => "Blocked road {$n}",
                'status' => 'Pending',
            ]);
        }
    }

    public function test_an_admin_receives_every_service_request_not_one_resident_s(): void
    {
        // The collision the bug depended on. Asserted rather than assumed: if a
        // future change gives these tables separate id ranges, this test would
        // quietly stop covering the thing it was written for.
        $this->assertSame(
            $this->admin->getKey(),
            $this->collidingResident->getKey(),
            'admin_id must collide with a resident_id for this test to exercise the bug',
        );
        $this->assertNotSame(
            $this->admin->getKey(),
            $this->otherResident->getKey(),
            'the second resident must NOT collide, or both branches return the same rows',
        );

        // Derived, never hardcoded, so seeded or fixture changes cannot make
        // this assertion stale.
        $seededTotal = ServiceRequest::count();
        $collidingResidentTotal = ServiceRequest::where(
            'resident_id',
            $this->collidingResident->getKey(),
        )->count();

        $this->assertGreaterThan(
            $collidingResidentTotal,
            $seededTotal,
            'the fixture must spread requests across residents, or both branches return the same rows',
        );

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/service-requests')->assertOk();

        // index() returns a bare array, unlike adminIndex() which wraps in data.
        $rows = $response->json();

        $this->assertCount($seededTotal, $rows);
        $this->assertNotCount($collidingResidentTotal, $rows);

        // Every resident is represented, which a resident-scoped query cannot do.
        $this->assertEqualsCanonicalizing(
            [$this->collidingResident->getKey(), $this->otherResident->getKey()],
            array_values(array_unique(array_column($rows, 'resident_id'))),
        );
    }

    public function test_a_resident_still_receives_only_their_own_requests(): void
    {
        // The other half of the branch. Widening the admin arm must not widen
        // this one — the fix would be worse than the bug.
        Sanctum::actingAs($this->collidingResident);

        $rows = $this->getJson('/api/service-requests')->assertOk()->json();

        $this->assertCount(
            ServiceRequest::where('resident_id', $this->collidingResident->getKey())->count(),
            $rows,
        );

        foreach ($rows as $row) {
            $this->assertSame($this->collidingResident->getKey(), $row['resident_id']);
        }
    }
}
