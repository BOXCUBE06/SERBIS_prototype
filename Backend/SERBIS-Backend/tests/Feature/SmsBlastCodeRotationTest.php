<?php

namespace Tests\Feature;

use App\Models\SmsBlastCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MDRRMO feedback, 2026-09-19: sending a text blast is gated on a code shared
 * between the two staff who know it, not on the caller's own account
 * password. That gate sits on top of the Text Blast section: the section lets
 * an admin open the page, the code lets them send. This covers the rotation
 * and status endpoints; SmsBlastLoggingTest covers the send-time gate itself.
 */
class SmsBlastCodeRotationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);
    }

    public function test_status_reports_unconfigured_when_no_code_has_ever_been_set(): void
    {
        $this->actingAs($this->admin)->getJson('/api/sms/blast-code')
            ->assertOk()
            ->assertJson(['configured' => false]);
    }

    public function test_status_never_returns_the_code_or_its_hash(): void
    {
        SmsBlastCode::create([
            'code_hash' => Hash::make('123456'),
            'updated_by' => $this->admin->admin_id,
        ]);

        $body = $this->actingAs($this->admin)->getJson('/api/sms/blast-code')
            ->assertOk()
            ->assertJson([
                'configured' => true,
                'updated_by' => 'MDRRMO Admin (admin)',
            ])
            ->json();

        $this->assertArrayNotHasKey('code', $body);
        $this->assertArrayNotHasKey('code_hash', $body);
    }

    public function test_rotation_requires_the_current_code(): void
    {
        SmsBlastCode::create(['code_hash' => Hash::make('123456')]);

        $this->actingAs($this->admin)->postJson('/api/sms/blast-code', [
            'current_code' => 'wrong',
            'new_code' => '654321',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_code']);

        $this->assertTrue(Hash::check('123456', SmsBlastCode::firstOrFail()->code_hash));
    }

    public function test_rotation_with_the_correct_current_code_sets_a_new_one(): void
    {
        SmsBlastCode::create(['code_hash' => Hash::make('123456')]);

        $second = User::create([
            'first_name' => 'Second',
            'last_name' => 'Admin',
            'email_address' => 'second@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
        ]);

        $this->actingAs($second)->postJson('/api/sms/blast-code', [
            'current_code' => '123456',
            'new_code' => '654321',
        ])->assertOk();

        $blastCode = SmsBlastCode::firstOrFail();

        $this->assertTrue(Hash::check('654321', $blastCode->code_hash));
        $this->assertFalse(Hash::check('123456', $blastCode->code_hash));
        $this->assertSame($second->admin_id, $blastCode->updated_by);
    }

    public function test_rotation_records_who_changed_it_and_when(): void
    {
        SmsBlastCode::create(['code_hash' => Hash::make('123456')]);

        $this->actingAs($this->admin)->postJson('/api/sms/blast-code', [
            'current_code' => '123456',
            'new_code' => '654321',
        ])->assertOk();

        $this->actingAs($this->admin)->getJson('/api/sms/blast-code')
            ->assertOk()
            ->assertJson([
                'configured' => true,
                'updated_by' => 'MDRRMO Admin (admin)',
            ]);
    }

    public function test_rotation_can_set_the_first_code_when_none_was_ever_seeded(): void
    {
        // No SmsBlastCode row at all — the seed step never ran. Rotation
        // cannot require a "current" code that has never existed.
        $this->actingAs($this->admin)->postJson('/api/sms/blast-code', [
            'current_code' => 'anything',
            'new_code' => '111111',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_code']);

        $this->assertSame(0, SmsBlastCode::count());
    }

    public function test_the_new_code_must_be_exactly_six_digits(): void
    {
        SmsBlastCode::create(['code_hash' => Hash::make('123456')]);

        $this->actingAs($this->admin)->postJson('/api/sms/blast-code', [
            'current_code' => '123456',
            'new_code' => '12345',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['new_code']);

        $this->actingAs($this->admin)->postJson('/api/sms/blast-code', [
            'current_code' => '123456',
            'new_code' => 'abcdef',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['new_code']);

        $this->assertTrue(Hash::check('123456', SmsBlastCode::firstOrFail()->code_hash));
    }

    public function test_rotation_shares_the_same_rate_limit_as_sending(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        SmsBlastCode::create(['code_hash' => Hash::make('123456')]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($this->admin)->postJson('/api/sms/blast-code', [
                'current_code' => "wrong-{$attempt}",
                'new_code' => '654321',
            ])->assertStatus(422);
        }

        // Sixth attempt, correct code this time — still refused, by the limiter.
        $this->actingAs($this->admin)->postJson('/api/sms/blast-code', [
            'current_code' => '123456',
            'new_code' => '654321',
        ])->assertStatus(429);

        $this->assertTrue(Hash::check('123456', SmsBlastCode::firstOrFail()->code_hash));
    }
}
