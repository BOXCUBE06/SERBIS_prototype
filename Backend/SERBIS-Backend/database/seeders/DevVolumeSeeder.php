<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local-only volume data for the admin panel: ~300 residents over 24 months, five
 * more staff, ~50 text blasts with their recipients, and the info-material PDFs.
 * Requests and loans come from RequestDataSeeder; run all of it with
 *   php artisan serbis:reseed-requests --volume
 *
 * Seeded and re-runnable: residents are keyed on their (unique) phone number and
 * staff on username, so a second run adds nothing; blasts are rebuilt each run.
 * Phones are +63999xxxxxxx and emails @seed.serbis.test, neither of which
 * DemoSeeder or DemoAccountsSeeder uses. Phones are valid Philippine mobiles and
 * some belong to real people, so this refuses to run unless SMS is faked.
 */
class DevVolumeSeeder extends Seeder
{
    public const PASSWORD = 'Passw0rd!123';

    /** Multiplies every count; 1 is 300 residents and ~50 blasts. */
    public float $scale = 1.0;

    private const FIRST = [
        'Maria', 'Juan', 'Jose', 'Ana', 'Rosa', 'Pedro', 'Elena', 'Ramon', 'Teresita', 'Carlos', 'Lourdes', 'Antonio', 'Cristina', 'Eduardo', 'Fe',
        'Rolando', 'Josefina', 'Danilo', 'Marilou', 'Ernesto', 'Luzviminda', 'Romeo', 'Cecilia', 'Alfredo', 'Nenita', 'Rodrigo', 'Gloria', 'Benjamin',
        'Analyn', 'Virgilio', 'Corazon', 'Efren', 'Rosalinda', 'Renato', 'Imelda', 'Arnel', 'Jenny', 'Mark', 'Joy', 'Kristine', 'Jericho', 'Mylene',
    ];

    private const LAST = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Agbayani', 'Pascual', 'Domingo', 'Ramos', 'Bautista', 'Tumaneng', 'Cabacungan', 'Dumlao', 'Aquino',
        'Villanueva', 'Mendoza', 'Castillo', 'Manuel', 'Pagaduan', 'Gaoat', 'Rabago', 'Sibayan', 'Cabatbat', 'Balauag', 'Tagorda', 'Agcaoili', 'Bumanglag',
        'Ballesteros', 'Lazaro', 'Soriano', 'Corpuz', 'Ancheta', 'Guzman', 'Pineda', 'Valdez', 'Racelis',
    ];

    private const SITIOS = ['Centro', 'Riverside', 'Bagong Sikat', 'Pagasa', 'Lagundi', 'Balasa', 'Manggahan', 'Sapa', 'Callang', 'Ugad Proper'];

    private const ORGS = [
        'Elementary School PTA', 'Parish Pastoral Council', 'Youth Council (SK)', 'Farmers Multi-Purpose Cooperative', 'Rural Health Volunteers Association',
        'Tricycle Operators and Drivers Association', 'Senior Citizens Association', 'Women\'s Livelihood Association', 'Irrigators Association',
        'Fishermen\'s Cooperative', 'High School Alumni Association', 'Market Vendors Association', 'Barangay Tanod Brigade', 'Mothers\' Club', 'Credit Cooperative',
    ];

    private const MESSAGES = [
        'MDRRMO Echague: May bagyong paparating. Ihanda ang go-bag at alamin ang pinakamalapit na evacuation center.',
        'MDRRMO Echague: Maghahatid ng relief goods bukas sa barangay hall mula 8AM. Magdala ng valid ID.',
        'MDRRMO Echague: Mataas ang lebel ng ilog. Mag-ingat at huwag tumawid sa tulay kung malakas ang agos.',
        'MDRRMO Echague: Basic first aid seminar sa Biyernes, 9AM sa covered court. Libre at bukas sa lahat.',
        'MDRRMO Echague: Earthquake drill ngayong Huwebes, 10AM. Sundin ang mga tagubilin ng inyong barangay.',
        'MDRRMO Echague: Paalala sa mga nanghiram ng gamit: isauli ang kagamitan sa takdang petsa.',
        'MDRRMO Echague: Walang pasok bukas sa lahat ng antas dahil sa masamang panahon.',
        'MDRRMO Echague: Magkakaroon ng clearing operation sa kalsada ngayong umaga. Maaaring may delay sa biyahe.',
    ];

    private CarbonImmutable $now;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevVolumeSeeder refuses to run unless APP_ENV is local or testing (env: '.app()->environment().').');
        }
        if (! config('serbis.sms_fake')) {
            throw new RuntimeException('DevVolumeSeeder refuses to run unless SERBIS_SMS_FAKE is true: its phone numbers can belong to real people.');
        }
        if (! DB::table('tbl_barangay')->exists()) {
            throw new RuntimeException('No barangays exist. Run BarangaySeeder and EchagueBarangaySeeder first.');
        }

        mt_srand(20261005);
        fake()->seed(20261005);
        $this->now = CarbonImmutable::now();

        $this->staff();
        $this->residents();
        $this->blasts();
        $this->call(InfoMaterialSeeder::class);
    }

    /** +63 99 then seven digits; the 99 block is not used by the other demo seeders. */
    private function phone(int $n): string
    {
        return '+63999'.sprintf('%07d', $n);
    }

    private function staff(): void
    {
        $password = Hash::make(self::PASSWORD);

        // [first, last, username, status, permissions (null: everything), must change password]
        $people = [
            ['Lorna', 'Agbayani', 'lorna.agbayani', 'Active', null, 0],
            ['Jonas', 'Tumaneng', 'jonas.tumaneng', 'Active', ['requests', 'ambulance'], 0],
            ['Evelyn', 'Rabago', 'evelyn.rabago', 'Active', ['borrowings', 'inventory', 'residents'], 0],
            ['Mark', 'Dumlao', 'mark.dumlao', 'Inactive', null, 0],
            ['Grace', 'Santos', 'grace.santos', 'Active', null, 1],
        ];

        foreach ($people as $i => [$first, $last, $username, $status, $permissions, $mustChange]) {
            if (DB::table('tbl_user')->where('username', $username)->exists()) {
                continue;
            }
            $created = $this->now->subDays(mt_rand(300, 700));
            DB::table('tbl_user')->insert([
                'first_name' => $first, 'last_name' => $last, 'username' => $username, 'role' => 'Admin',
                'status' => $status, 'phone_number' => $this->phone(9_000_001 + $i), 'password' => $password,
                'must_change_password' => $mustChange, 'is_super_admin' => 0,
                'permissions' => $permissions === null ? null : json_encode($permissions),
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }
    }

    private function residents(): void
    {
        $total = max(8, (int) round(300 * $this->scale));
        $orgs = max(1, (int) round($total * 0.05));
        $halls = max(1, (int) round($total * 0.067));
        $password = Hash::make(self::PASSWORD);

        // A seeded shuffle, then Zipf: a few big barangays and a long tail of small ones.
        $barangays = DB::table('tbl_barangay')->orderBy('barangay_id')->pluck('barangay_id')->all();
        $this->shuffle($barangays);
        $weights = [];
        foreach ($barangays as $rank => $id) {
            $weights[$id] = 1 / ($rank + 1) ** 0.7;
        }
        $hasHall = DB::table('tbl_residents')->where('account_type', 'barangay')->pluck('barangay_id')->all();
        $hallable = array_values(array_diff($barangays, $hasHall));

        // Oldest first, so resident ids follow registration order; growth is 4% a month.
        $k = log(1.04) / 30;
        $ages = [];
        for ($i = 0; $i < $total; $i++) {
            $u = mt_rand() / mt_getrandmax();
            $ages[] = max(2, (int) round(-log(1 - $u * (1 - exp(-$k * 730))) / $k));
        }
        rsort($ages);

        $rows = [];
        foreach ($ages as $i => $age) {
            $type = $i % max(1, intdiv($total, $halls)) === 3 && $hallable ? 'barangay'
                : ($i % max(1, intdiv($total, $orgs)) === 5 ? 'organization' : 'head_of_family');
            // A barangay account is one per barangay: the biggest barangays still without one.
            $barangayId = $type === 'barangay' ? array_shift($hallable) : $this->weighted($weights);
            $first = $this->pick(self::FIRST);
            $last = $this->pick(self::LAST);
            $created = $this->now->subDays($age)->subSeconds(mt_rand(0, 86_000));
            $hasEmail = $this->chance(55);
            $active = ! $this->chance(12);

            $rows[] = [
                'barangay_id' => $barangayId,
                'street_address' => 'Purok '.mt_rand(1, 7).', Sitio '.$this->pick(self::SITIOS),
                'first_name' => $first,
                'middle_name' => $this->chance(85) ? $this->pick(self::LAST) : null,
                'last_name' => $last,
                'phone_number' => $this->phone(1_000_000 + $i * 3571),
                'password' => $password,
                'photo' => null,
                'status' => $active ? 'Active' : 'Inactive',
                'account_type' => $type,
                'organization_name' => $type === 'organization' ? 'Echague '.$this->pick(self::SITIOS).' '.$this->pick(self::ORGS) : null,
                'sms_opt_in' => $this->chance(85) ? 1 : 0,
                'email_address' => $hasEmail ? strtolower(preg_replace('/[^a-z]/i', '', substr($first, 0, 1).$last)).$i.'@seed.serbis.test' : null,
                'email_verified_at' => $hasEmail && $this->chance(75) ? $created->addHour() : null,
                'phone_verified_at' => $this->chance(92) ? $created->addMinutes(mt_rand(1, 30)) : null,
                'created_at' => $created,
                // Edited some time after sign-up, never in the future.
                'updated_at' => min($this->now, $created->addSeconds(mt_rand(0, 90 * 86_400))),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tbl_residents')->insertOrIgnore($chunk);
        }
    }

    private function blasts(): void
    {
        $count = max(2, (int) round(50 * $this->scale));
        $staff = DB::table('tbl_user')->where('status', 'Active')->pluck('admin_id')->all();

        $old = DB::table('tbl_sms_logs')->where('api_job_id', 'like', 'seed-%')->pluck('sms_log_id');
        DB::table('tbl_recipients')->whereIn('sms_log_id', $old)->delete();
        DB::table('tbl_sms_logs')->whereIn('sms_log_id', $old)->delete();

        // Opted-in, active residents per barangay, oldest first.
        $audience = DB::table('tbl_residents')->where('status', 'Active')->where('sms_opt_in', 1)->where('phone_number', 'like', '+63999%')
            ->orderBy('created_at')->get(['resident_id', 'barangay_id', 'created_at'])->groupBy('barangay_id')
            ->filter(fn ($rows) => $rows->count() >= 2);
        if ($audience->isEmpty() || ! $staff) {
            return;
        }
        $weights = $audience->map(fn ($rows) => $rows->count())->all();

        for ($i = 0; $i < $count; $i++) {
            $barangayId = $this->weighted($weights);
            $sentAt = $this->now->subDays(mt_rand(0, 500))->subMinutes(mt_rand(0, 1400));
            $to = $audience[$barangayId]->filter(fn ($r) => CarbonImmutable::parse($r->created_at)->lessThanOrEqualTo($sentAt));
            if ($to->count() < 2) {
                continue;
            }
            // Queued until a day has passed, then delivered; a few fail outright.
            $status = $sentAt->greaterThan($this->now->subDay()) ? 'Queued' : ($this->chance(5) ? 'Failed' : 'Sent');

            $logId = DB::table('tbl_sms_logs')->insertGetId([
                'sender_id' => $this->pick($staff),
                'target_area_id' => $barangayId,
                'api_job_id' => 'seed-'.($i + 1),
                'message_body' => $this->pick(self::MESSAGES),
                'status' => $status,
                'delivery_checked_at' => $status === 'Sent' ? $sentAt->addHour() : null,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);
            DB::table('tbl_recipients')->insert($to->map(fn ($r) => [
                'sms_log_id' => $logId, 'resident_id' => $r->resident_id, 'status' => $status,
                'created_at' => $sentAt, 'updated_at' => $sentAt,
            ])->values()->all());
        }
    }

    private function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }

    /** A key of $weights, chosen in proportion to its value. */
    private function weighted(array $weights): int|string
    {
        $point = mt_rand() / mt_getrandmax() * array_sum($weights);
        foreach ($weights as $key => $weight) {
            if (($point -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }
}
