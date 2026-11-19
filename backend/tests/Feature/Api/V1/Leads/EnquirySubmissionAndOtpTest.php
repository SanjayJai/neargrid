<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Leads;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\Role;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\EnquiryType;
use App\Domains\Leads\Models\ContactReveal;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fakes\FakeOtpSender;
use Tests\TestCase;

final class EnquirySubmissionAndOtpTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Locality $locality;

    private Category $category;

    private Business $business;

    private FakeOtpSender $fakeOtpSender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->fakeOtpSender = new FakeOtpSender();
        $this->app->instance(OtpSender::class, $this->fakeOtpSender);

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->locality = Locality::query()->where('city_id', $this->city->id)->where('slug', 't-nagar')->firstOrFail();
        $this->category = Category::query()->where('slug', 'dentists')->firstOrFail();

        RateLimiter::clear('otp:req:ip:1h:'.hash('sha256', '127.0.0.1'));
        RateLimiter::clear('enquiry_ip:'.hash('sha256', '127.0.0.1'.config('app.key')));

        $owner = User::factory()->create();
        $this->business = new Business([
            'primary_category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'name' => 'Pearl Dental Care',
            'slug' => 'pearl-dental-care-t-nagar',
            'address_line_1' => '22 North Usman Road',
            'pincode' => '600017',
            'latitude' => 13.0418,
            'longitude' => 80.2341,
            'primary_phone_e164' => '+919840111222',
        ]);
        $this->business->owner_user_id = $owner->id;
        $this->business->status = BusinessStatus::Active;
        $this->business->is_claimed = true;
        $this->business->is_verified = true;
        $this->business->verified_at = now();
        $this->business->is_trusted = true;
        $this->business->completeness_score = 85;
        $this->business->save();
    }

    public function test_authenticated_customer_submits_direct_enquiry_with_affirmative_consent(): void
    {
        $customer = User::factory()->create([
            'phone_e164' => '+919840001111',
            'phone_verified_at' => now(),
        ]);
        $token = $customer->createToken('test-token', ['customer:enquiry'])->plainTextToken;

        $payload = [
            'customer_name' => 'Kavitha Raman',
            'phone_e164' => '+919840001111',
            'email' => 'kavitha@example.test',
            'requirement' => 'Looking for complete smile makeover consultation and cost estimates.',
            'urgency' => 'immediate',
            'category_slug' => 'dentists',
            'city_slug' => 'chennai',
            'locality_slug' => 't-nagar',
            'target_business_slug' => 'pearl-dental-care-t-nagar',
            'consent_given' => true,
            'consent_version' => '2026.1',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/enquiries', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.customer_name', 'Kavitha Raman')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.enquiry_type', 'direct')
            ->assertJsonPath('data.consent.given', true)
            ->assertJsonPath('data.consent.version', '2026.1')
            ->assertJsonPath('data.target_business.name', 'Pearl Dental Care');

        $this->assertDatabaseHas('enquiries', [
            'customer_name' => 'Kavitha Raman',
            'phone_e164' => '+919840001111',
            'status' => 'active',
            'enquiry_type' => 'direct',
            'target_business_id' => $this->business->id,
            'consent_given' => 1,
            'consent_version' => '2026.1',
        ]);

        $this->assertDatabaseHas('enquiry_recipients', [
            'business_id' => $this->business->id,
            'lead_status' => 'new',
            'delivery_status' => 'delivered',
        ]);
    }

    public function test_unauthenticated_guest_enquiry_triggers_otp_challenge_and_activates_upon_verification(): void
    {
        $payload = [
            'customer_name' => 'Guest Ramesh',
            'phone_e164' => '+919840002222',
            'requirement' => 'Need emergency toothache relief and root canal treatment.',
            'urgency' => 'immediate',
            'category_slug' => 'dentists',
            'city_slug' => 'chennai',
            'locality_slug' => 't-nagar',
            'target_business_slug' => 'pearl-dental-care-t-nagar',
            'consent_given' => true,
            'consent_version' => '2026.1',
        ];

        // 1. Submit as guest -> 202 Accepted with OTP challenge
        $response = $this->postJson('/api/v1/enquiries', $payload);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_otp')
            ->assertJsonPath('data.requires_otp', true);

        $enquiryUlid = $response->json('data.public_id');
        $enquiry = Enquiry::query()->where('public_id', $enquiryUlid)->firstOrFail();

        $this->assertEquals(EnquiryStatus::PendingOtp, $enquiry->status);
        $this->assertNotNull($enquiry->otp_challenge_id);
        $this->assertDatabaseMissing('enquiry_recipients', ['enquiry_id' => $enquiry->id]);

        // Retrieve challenge and verify OTP
        $challenge = OtpChallenge::query()->where('challenge_id', $enquiry->otp_challenge_id)->firstOrFail();

        // Verify with wrong OTP fails
        $wrongVerify = $this->postJson("/api/v1/enquiries/{$enquiryUlid}/verify-otp", [
            'otp' => '000000',
        ]);
        $wrongVerify->assertStatus(422);

        // Verify with correct sent OTP from fakeOtpSender
        $sentOtp = $this->fakeOtpSender->latestOtpFor($payload['phone_e164']);
        $this->assertNotNull($sentOtp);

        $validVerify = $this->postJson("/api/v1/enquiries/{$enquiryUlid}/verify-otp", [
            'otp' => $sentOtp,
            'device_name' => 'Automated Test Browser',
        ]);

        $validVerify->assertStatus(200)
            ->assertJsonPath('data.enquiry.status', 'active')
            ->assertJsonPath('data.enquiry.customer_name', 'Guest Ramesh')
            ->assertJsonStructure([
                'data' => [
                    'enquiry',
                    'auth' => ['token_type', 'access_token', 'session_id', 'user'],
                ],
            ]);

        // Enquiry is now active and dispatched to merchant
        $enquiry->refresh();
        $this->assertEquals(EnquiryStatus::Active, $enquiry->status);
        $this->assertNotNull($enquiry->phone_verified_at);
        $this->assertDatabaseHas('enquiry_recipients', [
            'enquiry_id' => $enquiry->id,
            'business_id' => $this->business->id,
            'lead_status' => 'new',
        ]);
    }

    public function test_non_consented_payload_is_rejected(): void
    {
        $payload = [
            'customer_name' => 'Unauthorized Seeker',
            'phone_e164' => '+919840003333',
            'requirement' => 'General dental cleaning inquiry.',
            'category_slug' => 'dentists',
            'city_slug' => 'chennai',
            'consent_given' => false, // Non-consented!
            'consent_version' => '2026.1',
        ];

        $response = $this->postJson('/api/v1/enquiries', $payload);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['consent_given']]]);
    }

    public function test_contact_reveal_records_interaction_and_rate_limits(): void
    {
        $response = $this->postJson("/api/v1/businesses/{$this->business->public_id}/contact-reveal", [
            'channel' => 'phone',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.revealed', true);

        $this->assertDatabaseHas('contact_reveals', [
            'business_id' => $this->business->id,
            'channel' => 'phone',
        ]);
    }

    public function test_super_admin_can_monitor_and_cancel_enquiry_with_audit_log(): void
    {
        $superAdmin = User::factory()->create();
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $superAdmin->roles()->sync([$adminRole->id]);

        $adminToken = $superAdmin->createToken('admin-test')->plainTextToken;

        // Seed an active enquiry
        $enquiry = Enquiry::query()->create([
            'category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'target_business_id' => $this->business->id,
            'customer_name' => 'Abusive Spammer',
            'phone_e164' => '+919999988888',
            'requirement' => 'Spam spam spam commercial advertisements 12345.',
            'urgency' => 'immediate',
            'enquiry_type' => 'direct',
            'status' => 'active',
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Direct consent',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ]);

        // 1. Admin Index
        $indexRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/v1/admin/enquiries');
        $indexRes->assertStatus(200)
            ->assertJsonFragment(['customer_name' => 'Abusive Spammer']);

        // 2. Admin Show
        $showRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson("/api/v1/admin/enquiries/{$enquiry->public_id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.customer_name', 'Abusive Spammer');

        // 3. Admin Cancel
        $cancelRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson("/api/v1/admin/enquiries/{$enquiry->public_id}/cancel", [
                'reason' => 'Identified as automated spam lead.',
            ]);

        $cancelRes->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Identified as automated spam lead.',
            'cancelled_by_user_id' => $superAdmin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'enquiry.cancel',
            'auditable_type' => Enquiry::class,
            'auditable_id' => $enquiry->id,
            'actor_user_id' => $superAdmin->id,
        ]);
    }

    public function test_non_admin_is_forbidden_from_admin_enquiry_endpoints(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('customer-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/enquiries');

        $response->assertStatus(403);
    }
}
