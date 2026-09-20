<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use App\Rules\PhoneAvailable;
use App\Support\PhoneBackfill;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The phone number is a resident's login: stored one way (+639XXXXXXXXX), held
 * by one account, and read back as 09… wherever a person sees it.
 */
class PhoneCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $phone, ?string $email = null): Resident
    {
        return Resident::create([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone_number' => $phone,
            'email_address' => $email ?? uniqid('r').'@test.local',
            'password' => Hash::make('Password123'),
            'status' => 'Active',
        ]);
    }

    // ---- storage ---------------------------------------------------------

    public function test_every_accepted_spelling_is_stored_as_e164(): void
    {
        foreach (['09171234567', '639171234568', '+639171234569'] as $typed) {
            $resident = $this->resident($typed);

            $this->assertMatchesRegularExpression('/^\+639\d{9}$/', $resident->fresh()->phone_number);
        }

        $this->assertSame('+639171234567', Resident::where('phone_number', '+639171234567')->firstOrFail()->phone_number);
    }

    public function test_a_value_that_is_not_a_number_is_stored_as_given_not_blanked(): void
    {
        // The API refuses such a value; the model does not hide a bug by
        // rewriting it.
        $this->assertSame('not-a-phone', $this->resident('not-a-phone')->fresh()->phone_number);
    }

    public function test_display_form_is_the_national_one(): void
    {
        $this->assertSame('09171234567', PhoneNumber::display('+639171234567'));
        $this->assertSame('09171234567', PhoneNumber::display('09171234567'));
        $this->assertSame('', PhoneNumber::display(''));
        $this->assertSame('not-a-phone', PhoneNumber::display('not-a-phone'));
    }

    // ---- uniqueness ------------------------------------------------------

    public function test_the_database_refuses_two_accounts_with_one_number_in_any_spelling(): void
    {
        $this->resident('09171234567');

        $this->expectException(QueryException::class);

        $this->resident('+639171234567');
    }

    public function test_the_index_and_the_new_columns_exist(): void
    {
        $indexes = collect(Schema::getIndexes('tbl_residents'));

        $this->assertTrue($indexes->contains(fn ($i) => $i['unique'] && $i['columns'] === ['phone_number']));
        $this->assertTrue(Schema::hasColumn('tbl_residents', 'phone_verified_at'));

        $email = collect(Schema::getColumns('tbl_residents'))->firstWhere('name', 'email_address');
        $this->assertTrue($email['nullable'], 'email_address must be nullable — the column stays, the requirement goes.');
        // Its unique index survives: MySQL allows any number of NULLs under it.
        $this->assertTrue($indexes->contains(fn ($i) => $i['unique'] && $i['columns'] === ['email_address']));
    }

    public function test_many_accounts_may_have_no_email(): void
    {
        foreach (['09171111111', '09172222222', '09173333333'] as $phone) {
            DB::table('tbl_residents')->insert([
                'barangay_id' => $this->barangay->barangay_id,
                'first_name' => 'No',
                'last_name' => 'Email',
                'phone_number' => PhoneNumber::normalize($phone),
                'email_address' => null,
                'password' => 'x',
                'status' => 'Active',
                'account_type' => 'head_of_family',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, Resident::whereNull('email_address')->count());
    }

    // ---- the validation rule ---------------------------------------------

    public function test_the_rule_refuses_a_taken_number_whatever_its_spelling(): void
    {
        $this->resident('09171234567');

        foreach (['09171234567', '639171234567', '+639171234567'] as $typed) {
            $this->assertTrue(
                Validator::make(['p' => $typed], ['p' => [new PhoneAvailable]])->fails(),
                $typed,
            );
        }

        $this->assertFalse(Validator::make(['p' => '09179999999'], ['p' => [new PhoneAvailable]])->fails());
    }

    public function test_the_rule_lets_an_account_keep_its_own_number_and_uses_the_given_message(): void
    {
        $mine = $this->resident('09171234567');
        $this->resident('09179999999');

        $this->assertFalse(Validator::make(['p' => '09171234567'], ['p' => [new PhoneAvailable($mine->resident_id)]])->fails());

        $other = Validator::make(['p' => '09179999999'], ['p' => [new PhoneAvailable($mine->resident_id, 'Pick another.')]]);
        $this->assertTrue($other->fails());
        $this->assertSame('Pick another.', $other->errors()->first('p'));
    }

    public function test_the_admin_form_names_the_conflict_plainly(): void
    {
        $admin = User::create([
            'first_name' => 'MDRRMO', 'last_name' => 'Admin', 'email_address' => 'admin@serbis.com',
            'password' => Hash::make('Password123'), 'role' => 'Admin', 'status' => 'Active',
        ]);
        $this->resident('09171234567');

        $this->actingAs($admin)->postJson('/api/residents', [
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Barangay', 'last_name' => 'Hall',
            'phone_number' => '+639171234567',
            'email_address' => 'hall@test.local',
            'password' => 'Password123',
            'status' => 'Active',
        ])->assertStatus(422)
            ->assertJsonPath('errors.phone_number.0', 'This number is already used by another account. Give this account a different number.');
    }

    // ---- the migration's rules -------------------------------------------

    public function test_the_backfill_plans_only_what_needs_changing(): void
    {
        $plan = PhoneBackfill::plan([1 => '09171234567', 2 => '+639171234568', 3 => '639171234569']);

        $this->assertSame([1 => '+639171234567', 3 => '+639171234569'], $plan['updates']);
        $this->assertSame([], $plan['invalid']);
        $this->assertSame([], $plan['duplicates']);
    }

    public function test_the_backfill_finds_duplicates_across_spellings_and_reports_invalid_rows(): void
    {
        $plan = PhoneBackfill::plan([
            1 => '09171234567',
            2 => '+639171234567',
            3 => '',
            4 => '0288888888',
            5 => 'not-a-phone',
            6 => '09179999999',
        ]);

        $this->assertSame(['+639171234567' => [1, 2]], $plan['duplicates']);
        $this->assertSame([3 => '', 4 => '0288888888', 5 => 'not-a-phone'], $plan['invalid']);
    }

    public function test_the_refusal_names_resident_ids_and_never_a_number(): void
    {
        $message = PhoneBackfill::describe(PhoneBackfill::plan([
            10 => '09171234567',
            11 => '+639171234567',
            12 => 'not-a-phone',
        ]));

        $this->assertStringContainsString('10, 11', $message);
        $this->assertStringContainsString('12', $message);
        $this->assertStringContainsString('Nothing was changed', $message);
        $this->assertStringNotContainsString('9171234567', $message);
        $this->assertStringNotContainsString('not-a-phone', $message);
    }
}
