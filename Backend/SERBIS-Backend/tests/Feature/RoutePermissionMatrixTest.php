<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Walks the real route table. A new /api route must be put in one of the two
 * lists below on purpose, or give itself an auth + admin + section gate.
 * No database: only the route definitions are read.
 */
class RoutePermissionMatrixTest extends TestCase
{
    /** Reachable with no token. Anything added here is a decision to expose it. */
    private const PUBLIC = [
        'POST api/admin/login',
        'POST api/admin/login/verify',
        'POST api/admin/login/resend',
        'POST api/resident/login',
        'POST api/resident/login/verify',
        'POST api/resident/login/resend',
        'POST api/register',
        'POST api/resident/verify-phone',
        'POST api/resident/verify-phone/resend',
        'POST api/resident/password/forgot',
        'POST api/resident/password/verify',
        'POST api/resident/password/reset',
        'POST api/resident/verify-email',
        'POST api/resident/verify-email/resend',
        'GET api/barangays',
        'GET api/hotlines',
    ];

    /** Any signed-in account (resident or staff). Catalogues and the caller's own profile. */
    private const SIGNED_IN = [
        'POST api/logout',
        'GET api/me',
        'PATCH api/me',
        'POST api/admin/change-password',
        'POST api/me/phone',
        'POST api/me/phone/resend',
        'POST api/me/phone/verify',
        'POST api/me/photo',
        'DELETE api/me/photo',
        'GET api/equipments',
        'GET api/services',
        'GET api/ambulance-destinations',
        'GET api/advisories',
        'GET api/ambulance-availability',
        'GET api/info-materials',
        'POST api/device-tokens',
        'DELETE api/device-tokens',
    ];

    /** @return array<string, list<string>> "METHOD uri" => middleware */
    private function apiRoutes(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $found["{$method} {$route->uri()}"] = $route->gatherMiddleware();
            }
        }

        return $found;
    }

    private function sectionOf(array $middleware): ?string
    {
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'section:')) {
                return $m;
            }
        }

        return null;
    }

    public function test_the_route_table_is_not_empty(): void
    {
        $this->assertGreaterThan(100, count($this->apiRoutes()));
    }

    public function test_every_route_outside_the_public_list_needs_a_token(): void
    {
        $open = [];

        foreach ($this->apiRoutes() as $key => $middleware) {
            if (! in_array($key, self::PUBLIC, true) && ! in_array('auth:sanctum', $middleware, true)) {
                $open[] = $key;
            }
        }

        $this->assertSame([], $open, 'Unauthenticated routes missing from PUBLIC');
    }

    public function test_every_public_entry_still_exists_and_is_really_open(): void
    {
        $routes = $this->apiRoutes();

        foreach (self::PUBLIC as $key) {
            $this->assertArrayHasKey($key, $routes, "Stale PUBLIC entry: {$key}");
            $this->assertNotContains('auth:sanctum', $routes[$key], "{$key} is listed public but is gated");
        }
    }

    public function test_every_admin_route_has_a_section_gate(): void
    {
        $ungated = [];

        foreach ($this->apiRoutes() as $key => $middleware) {
            if (in_array('is.admin', $middleware, true) && $this->sectionOf($middleware) === null) {
                $ungated[] = $key;
            }
        }

        $this->assertSame([], $ungated, 'is.admin routes with no section: middleware');
    }

    public function test_every_authenticated_route_is_admin_gated_or_listed_as_signed_in(): void
    {
        $unclassified = [];

        foreach ($this->apiRoutes() as $key => $middleware) {
            $gated = in_array('is.admin', $middleware, true) || $this->sectionOf($middleware) !== null;

            if (in_array('auth:sanctum', $middleware, true) && ! $gated && ! in_array($key, self::SIGNED_IN, true)) {
                $unclassified[] = $key;
            }
        }

        $this->assertSame([], $unclassified, 'Authenticated routes with no admin/section gate and not in SIGNED_IN');
    }

    public function test_staff_account_routes_need_the_staff_section(): void
    {
        $wrong = [];

        foreach ($this->apiRoutes() as $key => $middleware) {
            if (str_contains($key, ' api/admins') && $this->sectionOf($middleware) !== 'section:staff') {
                $wrong[] = $key;
            }
        }

        $this->assertSame([], $wrong);
    }

    public function test_every_signed_in_entry_still_exists(): void
    {
        $routes = $this->apiRoutes();

        foreach (self::SIGNED_IN as $key) {
            $this->assertArrayHasKey($key, $routes, "Stale SIGNED_IN entry: {$key}");
        }
    }
}
