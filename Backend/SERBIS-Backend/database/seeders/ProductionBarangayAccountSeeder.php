<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Resident;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The three barangay accounts MDRRMO starts with. A barangay account is a
 * tbl_residents row with account_type 'barangay', one per barangay (the rule
 * ResidentController enforces); it signs in like any resident, by phone number
 * and a texted code. There is no username.
 *
 * Matched by barangay: a barangay that already has its account is skipped and
 * never updated, so a number or password changed in the panel survives a
 * redeploy. Nothing that signs anyone in is committed: the shared password is
 * BARANGAY_SEED_PASSWORD and each number is BARANGAY_SEED_PHONE_<KEY>, both
 * required only while that account is missing.
 */
class ProductionBarangayAccountSeeder extends Seeder
{
    /** barangay_name, exactly as in tbl_barangay => env key suffix */
    public const ACCOUNTS = [
        'San Fabian' => 'SAN_FABIAN',
        'Pag-asa' => 'PAG_ASA',
        'San Antonio Ugad' => 'SAN_ANTONIO_UGAD',
    ];

    public function run(): void
    {
        $missing = [];

        foreach (self::ACCOUNTS as $name => $key) {
            $barangay = Barangay::where('barangay_name', $name)->first()
                ?? throw new RuntimeException("ProductionBarangayAccountSeeder: barangay '{$name}' is not in tbl_barangay.");

            $exists = Resident::where('account_type', Resident::TYPE_BARANGAY)
                ->where('barangay_id', $barangay->barangay_id)
                ->exists();

            if (! $exists) {
                $missing[$key] = $barangay;
            }
        }

        if ($missing === []) {
            $this->command?->info('ProductionBarangayAccountSeeder skipped: all three barangay accounts exist; left untouched.');

            return;
        }

        $password = env('BARANGAY_SEED_PASSWORD');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'ProductionBarangayAccountSeeder: a barangay account is missing and BARANGAY_SEED_PASSWORD is not set. '
                .'Set it on the host and redeploy; it can be unset once the accounts exist.'
            );
        }

        foreach ($missing as $key => $barangay) {
            $phone = PhoneNumber::normalize((string) env("BARANGAY_SEED_PHONE_{$key}"));

            if ($phone === '') {
                throw new RuntimeException("ProductionBarangayAccountSeeder: BARANGAY_SEED_PHONE_{$key} is not set to a mobile number like 09171234567.");
            }
            if (Resident::where('phone_number', $phone)->exists()) {
                throw new RuntimeException("ProductionBarangayAccountSeeder: BARANGAY_SEED_PHONE_{$key} is already another account's login.");
            }

            $account = new Resident([
                'barangay_id' => $barangay->barangay_id,
                'first_name' => 'Barangay',
                'last_name' => $barangay->barangay_name,
                'phone_number' => $phone,
                'password' => Hash::make($password),
                'status' => 'Active',
            ]);
            // Not fillable on purpose; set here as ResidentController does. The
            // number is vouched for by whoever set the env var, as an admin-made account is.
            $account->forceFill([
                'account_type' => Resident::TYPE_BARANGAY,
                'phone_verified_at' => now(),
            ])->save();

            $this->command?->info("ProductionBarangayAccountSeeder: created the account for {$barangay->barangay_name}.");
        }
    }
}
