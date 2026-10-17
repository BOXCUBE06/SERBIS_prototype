<?php

namespace Tests\Unit;

use App\Support\AdminSections;
use PHPUnit\Framework\TestCase;

class AdminSectionsTest extends TestCase
{
    public function test_keys_are_unique(): void
    {
        $this->assertSame(AdminSections::ALL, array_values(array_unique(AdminSections::ALL)));
    }

    public function test_assignable_is_all_but_staff(): void
    {
        $this->assertSame([AdminSections::STAFF], array_values(array_diff(AdminSections::ALL, AdminSections::ASSIGNABLE)));
    }

    public function test_assignable_sections_exist(): void
    {
        $this->assertSame([], array_diff(AdminSections::ASSIGNABLE, AdminSections::ALL));
    }

    public function test_is_assignable(): void
    {
        $this->assertTrue(AdminSections::isAssignable(AdminSections::SMS));
        $this->assertFalse(AdminSections::isAssignable(AdminSections::STAFF));
        $this->assertFalse(AdminSections::isAssignable('nonsense'));
    }

    public function test_ambulance_code_routes_to_ambulance_everything_else_to_requests(): void
    {
        $this->assertSame(AdminSections::AMBULANCE, AdminSections::forServiceCode(AdminSections::AMBULANCE_SERVICE_CODE));
        $this->assertSame(AdminSections::REQUESTS, AdminSections::forServiceCode('relief-goods'));
        $this->assertSame(AdminSections::REQUESTS, AdminSections::forServiceCode(null));
    }
}
