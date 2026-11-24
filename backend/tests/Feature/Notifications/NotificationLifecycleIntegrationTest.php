<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Services\AuthTokenService;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\LeadStatus;
use App\Domains\Leads\Jobs\DispatchEnquiryLeadsJob;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Domains\Leads\Services\EnquiryManagementService;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Notifications\Notifications\AccountSecurityNotification;
use App\Domains\Notifications\Notifications\EnquiryCancelledNotification;
use App\Domains\Notifications\Notifications\EnquiryMatchedNotification;
use App\Domains\Notifications\Notifications\EnquirySubmittedNotification;
use App\Domains\Notifications\Notifications\LeadStatusUpdatedNotification;
use App\Domains\Notifications\Notifications\ReviewReplyReceivedNotification;
use App\Domains\Reviews\Enums\ReviewModerationStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\Review;
use App\Domains\Reviews\Services\ReviewManagementService;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeOtpSender;
use Tests\TestCase;

final class NotificationLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Locality $locality;

    private Category $category;

    private Business $business;

    private User $businessOwner;

    private FakeOtpSender $fakeOtpSender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->fakeOtpSender = new FakeOtpSender;
        $this->app->instance(OtpSender::class, $this->fakeOtpSender);

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->locality = Locality::query()->where('city_id', $this->city->id)->where('slug', 't-nagar')->firstOrFail();
        $this->category = Category::query()->where('slug', 'dentists')->firstOrFail();

        RateLimiter::clear('otp:req:ip:1h:'.hash('sha256', '127.0.0.1'));
        RateLimiter::clear('enquiry_ip:'.hash('sha256', '127.0.0.1'.config('app.key')));

        $this->businessOwner = User::factory()->create(['status' => 'active', 'phone_e164' => '+919840111222']);
        $ownerRole = Role::query()->where('name', 'business_owner')->first();
        if ($ownerRole !== null) {
            $this->businessOwner->roles()->attach($ownerRole->id);
        }

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
        $this->business->owner_user_id = $this->businessOwner->id;
        $this->business->status = BusinessStatus::Active;
        $this->business->is_claimed = true;
        $this->business->is_verified = true;
        $this->business->verified_at = now();
        $this->business->is_trusted = true;
        $this->business->completeness_score = 90;
        $this->business->save();

        BusinessMember::query()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->businessOwner->id,
            'role' => BusinessMemberRole::Owner,
            'status' => 'active',
            'permissions' => BusinessMemberRole::Owner->defaultCapabilities(),
        ]);
    }

    public function test_authenticated_customer_enquiry_submission_dispatches_notification(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840001111',
            'phone_verified_at' => now(),
        ]);
        $customerRole = Role::query()->where('name', 'customer')->first();
        if ($customerRole !== null) {
            $customer->roles()->attach($customerRole->id);
        }

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

        $response->assertStatus(201);

        Notification::assertSentTo(
            $customer,
            EnquirySubmittedNotification::class,
            function (EnquirySubmittedNotification $notification) {
                return $notification->categoryName === 'Dentists'
                    && $notification->targetBusinessName === 'Pearl Dental Care'
                    && str_starts_with($notification->deduplicationKey($this->businessOwner), 'enquiry:submitted:');
            }
        );
    }

    public function test_guest_enquiry_submission_does_not_notify_while_pending_otp(): void
    {
        Notification::fake();

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

        $response = $this->postJson('/api/v1/enquiries', $payload);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_otp');

        Notification::assertNothingSent();
    }

    public function test_guest_enquiry_otp_verification_dispatches_notification_to_new_user(): void
    {
        Notification::fake();

        $payload = [
            'customer_name' => 'Guest Ramesh',
            'phone_e164' => '+919840003333',
            'requirement' => 'Need emergency toothache relief and root canal treatment.',
            'urgency' => 'immediate',
            'category_slug' => 'dentists',
            'city_slug' => 'chennai',
            'locality_slug' => 't-nagar',
            'target_business_slug' => 'pearl-dental-care-t-nagar',
            'consent_given' => true,
            'consent_version' => '2026.1',
        ];

        $response = $this->postJson('/api/v1/enquiries', $payload);
        $response->assertStatus(202);

        $enquiryUlid = $response->json('data.public_id');
        $sentOtp = $this->fakeOtpSender->latestOtpFor($payload['phone_e164']);
        $this->assertNotNull($sentOtp);

        $verifyResponse = $this->postJson("/api/v1/enquiries/{$enquiryUlid}/verify-otp", [
            'otp' => $sentOtp,
            'device_name' => 'Automated Test Browser',
        ]);

        $verifyResponse->assertStatus(200);

        $verifiedUser = User::query()->where('phone_e164', $payload['phone_e164'])->firstOrFail();

        Notification::assertSentTo(
            $verifiedUser,
            EnquirySubmittedNotification::class,
            function (EnquirySubmittedNotification $notification) {
                return $notification->categoryName === 'Dentists'
                    && $notification->targetBusinessName === 'Pearl Dental Care';
            }
        );
    }

    public function test_enquiry_matching_dispatches_notification_when_recipients_exist(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840004444',
        ]);

        $enquiry = Enquiry::query()->create([
            'user_id' => $customer->id,
            'category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'customer_name' => 'Broadcast User',
            'phone_e164' => '+919840004444',
            'requirement' => 'General broadcast inquiry for root canal',
            'urgency' => 'within_week',
            'enquiry_type' => 'broadcast',
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Broadcast consent',
            'consent_purpose' => 'lead_matching_and_contact',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ]);

        // Run the dispatch job synchronously
        (new DispatchEnquiryLeadsJob($enquiry->id))->handle();

        Notification::assertSentTo(
            $customer,
            EnquiryMatchedNotification::class,
            function (EnquiryMatchedNotification $notification) use ($enquiry) {
                return $notification->enquiryId === $enquiry->id
                    && $notification->matchedCount >= 1
                    && str_contains($notification->localityOrCity, 'Nagar');
            }
        );
    }

    public function test_enquiry_matching_does_not_notify_when_zero_businesses_matched(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840005555',
        ]);

        // Create an empty locality with no businesses
        $emptyLocality = Locality::query()->create([
            'city_id' => $this->city->id,
            'name' => 'Empty Suburb',
            'slug' => 'empty-suburb',
            'pincode' => '600099',
            'latitude' => 13.1111,
            'longitude' => 80.1111,
        ]);

        // Create an unrepresented category
        $emptyCategory = Category::query()->create([
            'name' => 'Astronomers',
            'slug' => 'astronomers',
            'is_active' => true,
        ]);

        $enquiry = Enquiry::query()->create([
            'user_id' => $customer->id,
            'category_id' => $emptyCategory->id,
            'city_id' => $this->city->id,
            'locality_id' => $emptyLocality->id,
            'customer_name' => 'Zero Match User',
            'phone_e164' => '+919840005555',
            'requirement' => 'Nobody can service this requirement',
            'urgency' => 'immediate',
            'enquiry_type' => 'broadcast',
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Broadcast consent',
            'consent_purpose' => 'lead_matching_and_contact',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ]);

        (new DispatchEnquiryLeadsJob($enquiry->id))->handle();

        Notification::assertNotSentTo($customer, EnquiryMatchedNotification::class);
    }

    public function test_business_owner_updating_lead_status_notifies_customer_without_leaking_notes(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840006666',
        ]);

        $enquiry = Enquiry::query()->create([
            'user_id' => $customer->id,
            'category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'customer_name' => 'Priya Kumar',
            'phone_e164' => '+919840006666',
            'requirement' => 'Need consultation for veneers',
            'urgency' => 'immediate',
            'enquiry_type' => 'direct',
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Direct consent',
            'consent_purpose' => 'lead_matching_and_contact',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ]);

        $recipient = EnquiryRecipient::query()->create([
            'enquiry_id' => $enquiry->id,
            'business_id' => $this->business->id,
            'lead_status' => LeadStatus::New,
            'delivery_channel' => 'in_app',
            'delivery_status' => 'delivered',
            'delivery_timestamp' => now(),
            'is_read' => false,
        ]);

        $ownerToken = $this->businessOwner->createToken('owner-token', ['owner:leads:manage'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->patchJson("/api/v1/owner/businesses/{$this->business->public_id}/leads/{$recipient->public_id}", [
                'lead_status' => 'contacted',
                'merchant_notes' => 'Customer called, budget is 25,000 INR. Follow-up tomorrow.',
            ]);

        $response->assertStatus(200);

        Notification::assertSentTo(
            $customer,
            LeadStatusUpdatedNotification::class,
            function (LeadStatusUpdatedNotification $notification) use ($enquiry) {
                $array = $notification->toArray($enquiry->user);

                // Safe customer-facing fields present
                $hasSafeFields = $notification->newStatus === 'contacted'
                    && $notification->businessName === 'Pearl Dental Care'
                    && $notification->enquiryUlid === $enquiry->public_id
                    && $array['category'] === 'enquiry';

                // Private merchant notes MUST NOT leak
                $noNotesLeak = ! isset($array['metadata']['merchant_notes'])
                    && ! str_contains(json_encode($array), 'budget is 25,000 INR');

                return $hasSafeFields && $noNotesLeak;
            }
        );
    }

    public function test_business_owner_updating_merchant_notes_only_does_not_notify_customer(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840007777',
        ]);

        $enquiry = Enquiry::query()->create([
            'user_id' => $customer->id,
            'category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'customer_name' => 'Vijay Anand',
            'phone_e164' => '+919840007777',
            'requirement' => 'Teeth whitening enquiry',
            'urgency' => 'immediate',
            'enquiry_type' => 'direct',
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Direct consent',
            'consent_purpose' => 'lead_matching_and_contact',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ]);

        $recipient = EnquiryRecipient::query()->create([
            'enquiry_id' => $enquiry->id,
            'business_id' => $this->business->id,
            'lead_status' => LeadStatus::Contacted,
            'delivery_channel' => 'in_app',
            'delivery_status' => 'delivered',
            'delivery_timestamp' => now(),
            'is_read' => true,
        ]);

        $ownerToken = $this->businessOwner->createToken('owner-token', ['owner:leads:manage'])->plainTextToken;

        // Update ONLY notes without changing lead_status
        $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->patchJson("/api/v1/owner/businesses/{$this->business->public_id}/leads/{$recipient->public_id}", [
                'merchant_notes' => 'Updated internal CRM notes. Customer requested price breakdown.',
            ]);

        $response->assertStatus(200);

        Notification::assertNotSentTo($customer, LeadStatusUpdatedNotification::class);
    }

    public function test_admin_cancelling_enquiry_notifies_customer_with_sanitized_reason(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840008888',
        ]);

        $superAdmin = User::factory()->create(['status' => 'active', 'phone_e164' => '+919840009999']);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $superAdmin->roles()->attach($adminRole->id);

        $enquiry = Enquiry::query()->create([
            'user_id' => $customer->id,
            'category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'customer_name' => 'Anand Kumar',
            'phone_e164' => '+919840008888',
            'requirement' => 'Dental implants quote',
            'urgency' => 'immediate',
            'enquiry_type' => 'direct',
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Direct consent',
            'consent_purpose' => 'lead_matching_and_contact',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ]);

        $enquiryService = $this->app->make(EnquiryManagementService::class);
        $enquiryService->cancelEnquiryByAdmin(
            $enquiry,
            $superAdmin,
            'Contact phone number was fake and unreachable by merchant'
        );

        Notification::assertSentTo(
            $customer,
            EnquiryCancelledNotification::class,
            function (EnquiryCancelledNotification $notification) use ($enquiry) {
                return $notification->enquiryId === $enquiry->id
                    && $notification->safeReason === 'This enquiry was closed due to incomplete or unreachable contact details.';
            }
        );
    }

    public function test_business_owner_initial_reply_to_review_notifies_registered_customer(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840010001',
        ]);

        $review = Review::query()->create([
            'business_id' => $this->business->id,
            'user_id' => $customer->id,
            'customer_name' => 'Devi Prasad',
            'phone_e164' => '+919840010001',
            'rating' => 5,
            'content' => 'Excellent service and gentle dentistry! Highly recommend Dr. Pearl.',
            'status' => ReviewStatus::Published,
            'moderation_status' => ReviewModerationStatus::Approved,
            'is_verified_interaction' => true,
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        $reviewService = $this->app->make(ReviewManagementService::class);
        $reviewService->addOrUpdateOwnerReply(
            $review,
            $this->business,
            'Thank you so much Devi for your kind feedback! Looking forward to your next visit.',
            $this->businessOwner
        );

        Notification::assertSentTo(
            $customer,
            ReviewReplyReceivedNotification::class,
            function (ReviewReplyReceivedNotification $notification) use ($review) {
                return $notification->reviewId === $review->id
                    && $notification->businessName === 'Pearl Dental Care'
                    && $notification->replyVersion === 'initial'
                    && str_contains($notification->replyText, 'Thank you so much Devi');
            }
        );
    }

    public function test_business_owner_editing_existing_reply_does_not_trigger_additional_notification(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840010002',
        ]);

        $review = Review::query()->create([
            'business_id' => $this->business->id,
            'user_id' => $customer->id,
            'customer_name' => 'Radha M',
            'phone_e164' => '+919840010002',
            'rating' => 4,
            'content' => 'Good treatment, slightly long wait time.',
            'status' => ReviewStatus::Published,
            'moderation_status' => ReviewModerationStatus::Approved,
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'owner_reply_text' => 'Initial response: Thank you for your feedback.',
            'owner_replied_at' => now()->subDay(),
            'owner_reply_user_id' => $this->businessOwner->id,
        ]);

        $reviewService = $this->app->make(ReviewManagementService::class);
        $reviewService->addOrUpdateOwnerReply(
            $review,
            $this->business,
            'Updated response: We have resolved the wait time issues.',
            $this->businessOwner
        );

        Notification::assertNotSentTo($customer, ReviewReplyReceivedNotification::class);
    }

    public function test_business_owner_replying_to_guest_review_does_not_fail_and_does_not_notify(): void
    {
        Notification::fake();

        $review = Review::query()->create([
            'business_id' => $this->business->id,
            'user_id' => null, // Guest reviewer
            'customer_name' => 'Anonymous Visitor',
            'phone_e164' => '+919840010003',
            'rating' => 5,
            'content' => 'Great clinic and very neat facility.',
            'status' => ReviewStatus::Published,
            'moderation_status' => ReviewModerationStatus::Approved,
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        $reviewService = $this->app->make(ReviewManagementService::class);
        $updatedReview = $reviewService->addOrUpdateOwnerReply(
            $review,
            $this->business,
            'Thank you for visiting us!',
            $this->businessOwner
        );

        $this->assertSame('Thank you for visiting us!', $updatedReview->owner_reply_text);
        Notification::assertNothingSent();
    }

    public function test_new_device_registration_triggers_security_notification(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840010004',
        ]);

        $authTokenService = $this->app->make(AuthTokenService::class);

        // 1. Initial device creation -> should notify
        $device1 = $authTokenService->upsertDevice(
            user: $customer,
            platform: 'android',
            deviceIdentifier: 'device-xyz-123',
            fcmToken: 'token-abc',
            appVersion: '1.0.0'
        );

        $this->assertTrue($device1->wasRecentlyCreated);

        Notification::assertSentTo(
            $customer,
            AccountSecurityNotification::class,
            function (AccountSecurityNotification $notification) use ($device1) {
                return $notification->eventType === 'new_device'
                    && $notification->uniqueEventId === (string) $device1->public_id
                    && $notification->extraMetadata['platform'] === 'android';
            }
        );

        // 2. Repeat call with same device identifier -> updates record, wasRecentlyCreated is false -> no notification
        Notification::fake();

        $device2 = $authTokenService->upsertDevice(
            user: $customer,
            platform: 'android',
            deviceIdentifier: 'device-xyz-123',
            fcmToken: 'token-abc-refreshed',
            appVersion: '1.0.1'
        );

        $this->assertFalse($device2->wasRecentlyCreated);
        Notification::assertNothingSent();
    }

    public function test_admin_revoking_sessions_triggers_security_notification(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840010005',
        ]);

        $superAdmin = User::factory()->create(['status' => 'active', 'phone_e164' => '+919840010006']);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $superAdmin->roles()->attach($adminRole->id);

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson("/api/v1/admin/users/{$customer->public_id}/revoke-sessions");
        $response->assertStatus(200);

        Notification::assertSentTo(
            $customer,
            AccountSecurityNotification::class,
            function (AccountSecurityNotification $notification) {
                return $notification->eventType === 'session_revocation'
                    && str_starts_with($notification->uniqueEventId, 'revoke_')
                    && $notification->title === 'Active Sessions Revoked';
            }
        );
    }

    public function test_admin_suspending_and_reactivating_user_triggers_security_notifications(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840010007',
        ]);

        $superAdmin = User::factory()->create(['status' => 'active', 'phone_e164' => '+919840010008']);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $superAdmin->roles()->attach($adminRole->id);

        Sanctum::actingAs($superAdmin);

        // 1. Suspend user
        $suspendResponse = $this->patchJson("/api/v1/admin/users/{$customer->public_id}/status", [
            'status' => 'suspended',
            'reason' => 'Violation of platform terms',
        ]);
        $suspendResponse->assertStatus(200);

        Notification::assertSentTo(
            $customer,
            AccountSecurityNotification::class,
            function (AccountSecurityNotification $notification) {
                return $notification->eventType === 'status_updated'
                    && $notification->title === 'Account Suspended'
                    && $notification->extraMetadata['status'] === 'suspended';
            }
        );

        // 2. Reactivate user
        Notification::fake();

        $reactivateResponse = $this->patchJson("/api/v1/admin/users/{$customer->public_id}/status", [
            'status' => 'active',
            'reason' => 'User appeal verified',
        ]);
        $reactivateResponse->assertStatus(200);

        Notification::assertSentTo(
            $customer,
            AccountSecurityNotification::class,
            function (AccountSecurityNotification $notification) {
                return $notification->eventType === 'status_updated'
                    && $notification->title === 'Account Reactivated'
                    && $notification->extraMetadata['status'] === 'active';
            }
        );
    }

    public function test_voluntary_logout_and_logout_all_do_not_trigger_security_notification(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919840010009',
        ]);
        $customerRole = Role::query()->where('name', 'customer')->first();
        if ($customerRole !== null) {
            $customer->roles()->attach($customerRole->id);
        }

        $tokenA = $customer->createToken('token-a', ['customer:read']);
        $tokenB = $customer->createToken('token-b', ['customer:read']);

        // Voluntary single logout
        $this->withToken($tokenA->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // Voluntary logout-all
        auth()->forgetGuards();
        $this->withToken($tokenB->plainTextToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertNoContent();

        Notification::assertNothingSent();
    }
}
