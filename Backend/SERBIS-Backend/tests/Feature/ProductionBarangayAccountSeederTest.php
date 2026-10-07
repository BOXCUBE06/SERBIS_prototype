<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use Database\Seeders\ProductionBarangayAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/** Runs on every production boot under `set -e`: a throw stops the boot. */
class ProductionBarangayAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = [
        'BARANGAY_SEED_PASSWORD' => 'BrgyPass123!',
        'BARANGAY_SEED_PHONE_SAN_FABIAN' => '09170000001',
        'BARANGAY_SEED_PHONE_PAG_ASA' => '09170000002',
        'BARANGAY_SEED_PHONE_SAN_ANTONIO_UGAD' => '09170000003',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $key) {
            unset($_SERVER[$key]);
        }

        parent::tearDown();
    }

    private function accounts()
    {
        return Resident::where('account_type', Resident::TYPE_BARANGAY)->with('barangay')->get();
    }

    public function test_it_creates_three_accounts_linked_to_their_barangays(): void
    {
        $this->seed(ProductionBarangayAccountSeeder::class);

        $accounts = $this->accounts()->keyBy(fn ($a) => $a->barangay->barangay_name);
        $this->assertSame(['Pag-asa', 'San Antonio Ugad', 'San Fabian'], $accounts->keys()->sort()->values()->all());
        $this->assertSame('+639170000002', $accounts['Pag-asa']->phone_number);
        $this->assertSame('Active', $accounts['San Fabian']->status);
        $this->assertNotNull($accounts['San Fabian']->phone_verified_at);
        $this->assertTrue(Hash::check('BrgyPass123!', $accounts['San Antonio Ugad']->password));
    }

    public function test_a_second_run_leaves_edited_accounts_untouched(): void
    {
        $this->seed(ProductionBarangayAccountSeeder::class);
        $fabian = $this->accounts()->first(fn ($a) => $a->barangay->barangay_name === 'San Fabian');
        $fabian->forceFill(['phone_number' => '09179999999', 'password' => Hash::make('Changed123!'), 'first_name' => 'Kapitan'])->save();
        $_SERVER['BARANGAY_SEED_PASSWORD'] = 'Different123!';

        $this->seed(ProductionBarangayAccountSeeder::class);

        $this->assertSame(3, $this->accounts()->count());
        $fabian->refresh();
        $this->assertSame('+639179999999', $fabian->phone_number);
        $this->assertSame('Kapitan', $fabian->first_name);
        $this->assertTrue(Hash::check('Changed123!', $fabian->password));
    }

    public function test_the_password_is_required_only_while_an_account_is_missing(): void
    {
        $this->seed(ProductionBarangayAccountSeeder::class);
        unset($_SERVER['BARANGAY_SEED_PASSWORD']);

        $this->seed(ProductionBarangayAccountSeeder::class); // all exist: no throw

        $this->accounts()->first()->delete();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BARANGAY_SEED_PASSWORD');
        $this->seed(ProductionBarangayAccountSeeder::class);
    }

    public function test_a_missing_barangay_throws(): void
    {
        Barangay::where('barangay_name', 'Pag-asa')->update(['barangay_name' => 'Pagasa']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'Pag-asa'");
        $this->seed(ProductionBarangayAccountSeeder::class);
    }
}
