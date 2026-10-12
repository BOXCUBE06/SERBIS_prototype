<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Recipient;
use App\Models\Resident;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/sms/deliveries/{smsLog}/recipients: who one barangay's message went
 * to. The panel reads it a page at a time when a barangay is opened, so these
 * tests pin the filter, the search, that the number is never returned whole,
 * and the paging.
 */
class SmsDeliveryRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SmsLog $log;

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

        $barangay = Barangay::create(['barangay_name' => 'San Fabian']);

        $this->log = SmsLog::create([
            'sender_id' => $this->admin->admin_id,
            'target_area_id' => $barangay->barangay_id,
            'message_body' => 'Test blast',
            'status' => Recipient::QUEUED,
        ]);

        foreach ([
            ['Maria', 'Santos', '+639171234821', Recipient::SENT],
            ['Jose', 'Dela Cruz', '+639281231190', Recipient::SENT],
            ['Ana', 'Bautista', '+639351237742', Recipient::FAILED],
            ['Ramon', 'Villanueva', '+639191233306', Recipient::PENDING],
            ['Liza', 'Agbayani', '+639061235518', Recipient::QUEUED],
        ] as [$first, $last, $phone, $status]) {
            $resident = Resident::create([
                'barangay_id' => $barangay->barangay_id,
                'first_name' => $first,
                'last_name' => $last,
                'phone_number' => $phone,
                'password' => Hash::make('password123'),
                'status' => 'Active',
            ]);

            Recipient::create([
                'sms_log_id' => $this->log->sms_log_id,
                'resident_id' => $resident->resident_id,
                'status' => $status,
            ]);
        }
    }

    private function recipients(array $query = [], ?SmsLog $log = null)
    {
        $log ??= $this->log;

        return $this->actingAs($this->admin)->getJson("/api/sms/deliveries/{$log->sms_log_id}/recipients?".http_build_query($query));
    }

    public function test_it_lists_every_recipient_of_the_barangay_with_status_and_times(): void
    {
        $response = $this->recipients()->assertOk();

        $response->assertJsonPath('meta.total', 5)
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['data' => [['recipient_id', 'name', 'phone', 'status', 'queued_at', 'updated_at']], 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        // Ordered by surname.
        $this->assertSame(
            ['Liza Agbayani', 'Ana Bautista', 'Jose Dela Cruz', 'Maria Santos', 'Ramon Villanueva'],
            array_column($response->json('data'), 'name'),
        );
    }

    public function test_the_number_is_masked_to_its_first_and_last_four_digits(): void
    {
        $response = $this->recipients(['search' => 'Maria'])->assertOk();

        $this->assertSame('0917 ••• 4821', $response->json('data.0.phone'));
        // The whole number appears nowhere in the payload, in either form.
        $this->assertStringNotContainsString('09171234821', $response->getContent());
        $this->assertStringNotContainsString('639171234821', $response->getContent());
    }

    public function test_it_filters_by_status(): void
    {
        $response = $this->recipients(['status' => Recipient::FAILED])->assertOk();

        $response->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Ana Bautista')->assertJsonPath('data.0.status', 'Failed');

        $this->assertSame(2, $this->recipients(['status' => Recipient::SENT])->json('meta.total'));
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->recipients(['status' => 'Delivered'])->assertUnprocessable();
    }

    public function test_it_searches_by_name(): void
    {
        $this->assertSame(['Jose Dela Cruz'], array_column($this->recipients(['search' => 'dela cru'])->json('data'), 'name'));
        $this->assertSame(['Ramon Villanueva'], array_column($this->recipients(['search' => 'Ramon'])->json('data'), 'name'));
        $this->assertSame([], $this->recipients(['search' => 'Nobody'])->json('data'));
    }

    public function test_it_searches_by_number_in_any_local_form(): void
    {
        $this->assertSame(['Ana Bautista'], array_column($this->recipients(['search' => '09351237742'])->json('data'), 'name'));
        $this->assertSame(['Ana Bautista'], array_column($this->recipients(['search' => '+639351237742'])->json('data'), 'name'));
        $this->assertSame(['Ana Bautista'], array_column($this->recipients(['search' => '7742'])->json('data'), 'name'));
    }

    public function test_search_and_status_combine(): void
    {
        $this->assertSame(1, $this->recipients(['search' => 'Ana', 'status' => Recipient::FAILED])->json('meta.total'));
        $this->assertSame(0, $this->recipients(['search' => 'Ana', 'status' => Recipient::SENT])->json('meta.total'));
    }

    public function test_it_pages(): void
    {
        $first = $this->recipients(['per_page' => 2])->assertOk();

        $first->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(2, 'data');

        $third = $this->recipients(['per_page' => 2, 'page' => 3])->assertOk();

        $third->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 3);

        // No page repeats a row of another.
        $ids = array_merge(
            array_column($first->json('data'), 'recipient_id'),
            array_column($this->recipients(['per_page' => 2, 'page' => 2])->json('data'), 'recipient_id'),
            array_column($third->json('data'), 'recipient_id'),
        );
        $this->assertCount(5, array_unique($ids));
    }

    public function test_it_defaults_to_25_a_page_and_caps_the_page_size(): void
    {
        $this->recipients()->assertJsonPath('meta.per_page', 25);
        $this->recipients(['per_page' => 5000])->assertJsonPath('meta.per_page', 100);
    }

    public function test_it_only_returns_the_recipients_of_that_barangay(): void
    {
        $other = SmsLog::create([
            'sender_id' => $this->admin->admin_id,
            'target_area_id' => Barangay::create(['barangay_name' => 'Angoluan'])->barangay_id,
            'message_body' => 'Test blast',
            'status' => Recipient::QUEUED,
        ]);

        $this->recipients([], $other)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_a_resident_cannot_read_it(): void
    {
        $resident = Resident::first();

        $this->actingAs($resident)->getJson("/api/sms/deliveries/{$this->log->sms_log_id}/recipients")->assertForbidden();
    }
}
