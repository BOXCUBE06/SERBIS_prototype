<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSection;
use App\Http\Middleware\IsAdmin;
use App\Models\Barangay;
use App\Models\Resident;
use App\Support\AdminSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesAdmins;
use Tests\TestCase;

/**
 * Every admin route belongs to a section, and a request to one the account was
 * not given answers 403 before the controller runs.
 *
 * The routes below are samples with made-up ids. A caller who is let through
 * reaches the controller and gets whatever it says (404, 422, 200), which is
 * all these tests need: the only thing asserted about them is that it is not
 * this middleware's refusal.
 */
class SectionMiddlewareTest extends TestCase
{
    use MakesAdmins, RefreshDatabase;

    /**
     * method, path, sections that open it. `staff` is the one route family only
     * a super admin can open, since it cannot be handed out.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function routes(): array
    {
        $either = [AdminSections::REQUESTS, AdminSections::AMBULANCE];
        $people = [AdminSections::RESIDENTS, AdminSections::REQUESTS, AdminSections::AMBULANCE, AdminSections::BORROWINGS];

        return [
            'dashboard' => ['GET', '/api/admin/dashboard', [AdminSections::DASHBOARD]],
            'analytics' => ['GET', '/api/admin/analytics', [AdminSections::ANALYTICS]],

            'admin request list' => ['GET', '/api/admin/service-requests', $either],
            'walk-in request' => ['POST', '/api/admin/service-requests', $either],
            'request update' => ['PATCH', '/api/service-requests/1', $either],
            'request delete' => ['DELETE', '/api/service-requests/1', $either],
            'request approve' => ['PATCH', '/api/service-requests/1/approve', $either],
            'request reschedule' => ['PATCH', '/api/service-requests/1/reschedule', $either],
            'shared request list' => ['GET', '/api/service-requests', $either],
            'shared request show' => ['GET', '/api/service-requests/1', $either],
            'request valid id' => ['GET', '/api/service-requests/1/valid-id', $either],
            'request site photo' => ['GET', '/api/service-requests/1/site-photo', $either],
            'request letter' => ['GET', '/api/service-requests/1/letter', $either],
            'request cancel' => ['PATCH', '/api/service-requests/1/cancel', $either],

            'trip list' => ['GET', '/api/conduction-requests', [AdminSections::AMBULANCE]],
            'trip create' => ['POST', '/api/conduction-requests', [AdminSections::AMBULANCE]],
            'trip log' => ['PATCH', '/api/conduction-requests/1/trip-log', [AdminSections::AMBULANCE]],
            'trip print' => ['GET', '/api/conduction-requests/1/print', [AdminSections::AMBULANCE]],

            'borrowing list' => ['GET', '/api/borrowings', [AdminSections::BORROWINGS]],
            'borrowing show' => ['GET', '/api/borrowings/1', [AdminSections::BORROWINGS]],
            'borrowing update' => ['PATCH', '/api/borrowings/1', [AdminSections::BORROWINGS]],
            'borrowing cancel' => ['PATCH', '/api/borrowings/1/cancel', [AdminSections::BORROWINGS]],
            'borrowing photo upload' => ['POST', '/api/borrowings/1/photo', [AdminSections::BORROWINGS]],
            'borrowing photo read' => ['GET', '/api/borrowings/1/photo/release', [AdminSections::BORROWINGS]],
            'borrowing photo delete' => ['DELETE', '/api/borrowings/1/photo/release', [AdminSections::BORROWINGS]],

            'fleet read' => ['GET', '/api/vehicles', [AdminSections::VEHICLES, AdminSections::REQUESTS, AdminSections::AMBULANCE]],
            'fleet write' => ['POST', '/api/vehicles', [AdminSections::VEHICLES]],
            'fleet delete' => ['DELETE', '/api/vehicles/1', [AdminSections::VEHICLES]],
            'unit types read' => ['GET', '/api/service-vehicle-types', [AdminSections::SERVICE_VEHICLES, AdminSections::REQUESTS, AdminSections::AMBULANCE]],
            'unit types write' => ['PUT', '/api/service-vehicle-types/road-clearing', [AdminSections::SERVICE_VEHICLES]],

            'equipment write' => ['POST', '/api/equipments', [AdminSections::INVENTORY]],
            'service write' => ['POST', '/api/services', [AdminSections::SERVICES]],
            'audience read' => ['GET', '/api/service-audience', [AdminSections::SERVICE_AUDIENCE]],
            'audience write' => ['PUT', '/api/service-audience/road-clearing', [AdminSections::SERVICE_AUDIENCE]],

            'resident list' => ['GET', '/api/residents', [AdminSections::RESIDENTS]],
            'resident write' => ['POST', '/api/residents', [AdminSections::RESIDENTS]],
            'resident return history' => ['GET', '/api/residents/1/return-history', [AdminSections::RESIDENTS]],
            'resident photo' => ['GET', '/api/residents/1/photo', $people],
            'barangay write' => ['POST', '/api/barangays', [AdminSections::RESIDENTS]],

            'materials list' => ['GET', '/api/admin/info-materials', [AdminSections::FILES]],
            'materials upload' => ['POST', '/api/admin/info-materials', [AdminSections::FILES]],
            'system log' => ['GET', '/api/logs/system', [AdminSections::LOGS]],
            'sms log' => ['GET', '/api/logs/sms', [AdminSections::LOGS]],

            'blast' => ['POST', '/api/sms/blast', [AdminSections::SMS]],
            'blast code' => ['GET', '/api/sms/blast-code', [AdminSections::SMS]],
            'sms balance' => ['GET', '/api/sms/balance', [AdminSections::SMS]],
            'recipient count' => ['GET', '/api/sms/recipient-count', [AdminSections::SMS]],
            'deliveries' => ['GET', '/api/sms/deliveries', [AdminSections::SMS]],

            'staff list' => ['GET', '/api/admins', [AdminSections::STAFF]],
            'staff create' => ['POST', '/api/admins', [AdminSections::STAFF]],
            'staff reactivate' => ['PATCH', '/api/admins/1/reactivate', [AdminSections::STAFF]],
            'staff reset password' => ['POST', '/api/admins/1/reset-password', [AdminSections::STAFF]],
        ];
    }

    /**
     * The middleware's refusal specifically. ServiceRequestController also
     * answers `section_forbidden` for a request in the other board's section,
     * in its own words; that is covered in ServiceRequestSectionScopeTest and is
     * not what these samples (which send no body) are asking about.
     */
    private function refusedBySection($response): bool
    {
        return $response->getStatusCode() === 403
            && $response->json('code') === 'section_forbidden'
            && str_starts_with((string) $response->json('message'), 'You do not have access to this section.');
    }

    /**
     * @param  list<string>  $sections
     */
    #[DataProvider('routes')]
    public function test_an_account_without_the_section_is_refused(string $method, string $path, array $sections): void
    {
        // Everything assignable except what opens this route, so the only thing
        // missing is the section under test. For the staff routes that is
        // every assignable section: none of them opens it.
        $admin = $this->makeLimitedStaff(array_values(array_diff(AdminSections::ASSIGNABLE, $sections)));
        Sanctum::actingAs($admin);

        $response = $this->json($method, $path);

        $response->assertStatus(403)->assertJsonPath('code', 'section_forbidden');
    }

    /**
     * @param  list<string>  $sections
     */
    #[DataProvider('routes')]
    public function test_an_account_holding_any_one_named_section_is_let_through(string $method, string $path, array $sections): void
    {
        if ($sections === [AdminSections::STAFF]) {
            Sanctum::actingAs($this->makeSuperAdmin());
            $this->assertFalse($this->refusedBySection($this->json($method, $path)), "{$method} {$path} refused a super admin");

            return;
        }

        foreach ($sections as $section) {
            Sanctum::actingAs($this->makeLimitedStaff([$section], "only-{$section}@test.local"));

            $this->assertFalse(
                $this->refusedBySection($this->json($method, $path)),
                "{$method} {$path} refused an account holding only '{$section}'"
            );
        }
    }

    /**
     * @param  list<string>  $sections
     */
    #[DataProvider('routes')]
    public function test_an_account_with_no_permission_list_reaches_everything_but_staff(string $method, string $path, array $sections): void
    {
        // The deploy guarantee: an account that existed before permissions did
        // keeps every section it had. Staff Accounts is the one exception, since
        // it is super-admin-only.
        Sanctum::actingAs($this->makeStaff());

        $refused = $this->refusedBySection($this->json($method, $path));

        $this->assertSame($sections === [AdminSections::STAFF], $refused);
    }

    public function test_an_account_with_an_empty_list_can_still_sign_in_and_read_its_own_profile(): void
    {
        Sanctum::actingAs($this->makeLimitedStaff([]));

        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.email_address', 'limited@test.local');
    }

    public function test_the_open_lookups_stay_open_to_an_account_with_no_sections(): void
    {
        // Catalogues the mobile app needs too. They carry nothing sensitive and
        // every page's dropdowns depend on them.
        Sanctum::actingAs($this->makeLimitedStaff([]));

        $this->getJson('/api/barangays')->assertOk();
        $this->getJson('/api/services')->assertOk();
        $this->getJson('/api/equipments')->assertOk();
        $this->getJson('/api/ambulance-destinations')->assertOk();
    }

    public function test_a_resident_passes_through_the_routes_they_share_with_staff(): void
    {
        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);
        $resident = Resident::create([
            'barangay_id' => $barangay->barangay_id,
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'phone_number' => '+639171234567',
            'email_address' => 'maria@test.local',
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);

        Sanctum::actingAs($resident);

        // Their own rows, scoped by the controller as before: not this middleware's business.
        $this->getJson('/api/service-requests')->assertOk();
        $this->getJson('/api/borrowings')->assertOk();
    }

    public function test_every_admin_route_belongs_to_a_section(): void
    {
        // The guard that keeps this true: a route added to the is.admin group
        // without a section would be open to every admin, including one given
        // none, and nothing else would notice.
        $router = app('router');
        $missing = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = $router->gatherRouteMiddleware($route);

            $isAdmin = count(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, IsAdmin::class))) > 0;
            $hasSection = count(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, EnsureSection::class))) > 0;

            if ($isAdmin && ! $hasSection) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $missing, 'Admin routes with no section: '.implode(', ', $missing));
    }
}
