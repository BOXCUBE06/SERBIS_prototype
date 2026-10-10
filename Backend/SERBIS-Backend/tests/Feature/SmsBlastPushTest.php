<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\SmsBlastCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\FakesFcm;
use Tests\TestCase;

/** A text blast is also pushed, to exactly the people the advisories feed shows it to. */
class SmsBlastPushTest extends TestCase
{
    use FakesFcm;
    use RefreshDatabase;

    private const SMS_HOST = 'skysms.skyio.site/*';

    private const FCM_HOST = 'fcm.googleapis.com/*';

    private const CODE = '123456';

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->configureFcm();

        $this->admin = User::create([
            'first_name' => 'MDRRMO',
            'last_name' => 'Admin',
            'email_address' => 'admin@test.local',
            'password' => Hash::make('password123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);

        SmsBlastCode::create(['code_hash' => Hash::make(self::CODE), 'updated_by' => $this->admin->admin_id]);

        $this->barangay = Barangay::create(['barangay_name' => 'San Fabian']);
    }

    private function resident(string $phone, bool $optIn = true): Resident
    {
        return Resident::forceCreate([
            'barangay_id' => $this->barangay->barangay_id,
            'first_name' => 'Resident',
            'last_name' => $phone,
            'phone_number' => $phone,
            'email_address' => $phone.'@test.local',
            'password' => 'x',
            'status' => 'Active',
            'account_type' => Resident::TYPE_HEAD_OF_FAMILY,
            'sms_opt_in' => $optIn,
        ]);
    }

    private function blast()
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/sms/blast', [
                'message' => 'Heavy rain expected today.',
                'code' => self::CODE,
                'barangays' => [$this->barangay->barangay_id],
            ]);
    }

    /** @return list<string> the device tokens FCM was asked to push to */
    private function pushedTokens(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))
            ->map(fn ($pair) => $pair[0]['message']['token'])
            ->values()
            ->all();
    }

    public function test_a_queued_blast_is_pushed_to_recipients_only(): void
    {
        Http::fake([
            self::SMS_HOST => Http::response(['success' => true, 'batch_id' => 'b-1'], 200),
            self::FCM_HOST => $this->fcmAccepts(),
        ]);
        $this->deviceFor($this->resident('09170000001'), 'recipient-device');
        // Opted out of SMS: not texted, not in their feed, so not pushed either.
        $this->deviceFor($this->resident('09170000002', optIn: false), 'opted-out-device');

        $this->blast()->assertOk();

        $this->assertSame(['recipient-device'], $this->pushedTokens());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com')
            && $request['message']['notification'] === ['title' => 'MDRRMO advisory', 'body' => 'Heavy rain expected today.']);
    }

    public function test_a_failed_blast_is_not_pushed(): void
    {
        Http::fake([
            self::SMS_HOST => Http::response(['message' => 'Insufficient credits'], 402),
            self::FCM_HOST => $this->fcmAccepts(),
        ]);
        $this->deviceFor($this->resident('09170000001'));

        $this->blast()->assertStatus(402);

        $this->assertSame([], $this->pushedTokens());
    }
}
