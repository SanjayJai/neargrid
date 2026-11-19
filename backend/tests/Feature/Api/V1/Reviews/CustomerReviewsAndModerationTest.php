<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Reviews;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\Role;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Reviews\Enums\ReviewModerationStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\Review;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeOtpSender;
use Tests\TestCase;

final class CustomerReviewsAndModerationTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;
    private Business $businessB;
    private User $ownerA;
    private User $ownerB;
    private User $leadAgentA;
    private User $editorA;
    private User $superAdmin;
    private Category $category;
    private City $city;
    private FakeOtpSender $fakeOtpSender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->fakeOtpSender = new FakeOtpSender();
        $this->app->instance(OtpSender::class, $this->fakeOtpSender);

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->category = Category::query()->where('slug', 'dentists')->firstOrFail();
        $locality = Locality::query()->where('city_id', $this->city->id)->firstOrFail();

        $this->ownerA = User::factory()->create();
        $this->businessA = new Business([
            'primary_category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $locality->id,
            'name' => 'Apex Dental Studio',
            'slug' => 'apex-dental-studio',
            'address_line_1' => '10 Usman Road',
            'pincode' => '600017',
            'latitude' => 13.0418,
            'longitude' => 80.2341,
            'primary_phone_e164' => '+919840111222',
        ]);
        $this->businessA->owner_user_id = $this->ownerA->id;
        $this->businessA->status = BusinessStatus::Active;
        $this->businessA->is_claimed = true;
        $this->businessA->is_verified = true;
        $this->businessA->save();

        $this->ownerB = User::factory()->create();
        $this->businessB = new Business([
            'primary_category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $locality->id,
            'name' => 'Bright Smile Clinic',
            'slug' => 'bright-smile-clinic',
            'address_line_1' => '45 Anna Salai',
            'pincode' => '600002',
            'latitude' => 13.0604,
            'longitude' => 80.2496,
            'primary_phone_e164' => '+919840333444',
        ]);
        $this->businessB->owner_user_id = $this->ownerB->id;
        $this->businessB->status = BusinessStatus::Active;
        $this->businessB->is_claimed = true;
        $this->businessB->is_verified = true;
        $this->businessB->save();

        // Staff for Business A
        $this->leadAgentA = User::factory()->create(['name' => 'Agent Priya']);
        BusinessMember::query()->create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->leadAgentA->id,
            'role' => BusinessMemberRole::LeadAgent,
            'status' => 'active',
            'permissions' => BusinessMemberRole::LeadAgent->defaultCapabilities(),
        ]);

        $this->editorA = User::factory()->create(['name' => 'Editor Vikram']);
        BusinessMember::query()->create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->editorA->id,
            'role' => BusinessMemberRole::Editor,
            'status' => 'active',
            'permissions' => BusinessMemberRole::Editor->defaultCapabilities(),
        ]);

        // Super Admin
        $this->superAdmin = User::factory()->create();
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->superAdmin->roles()->attach($superAdminRole);
    }

    public function test_authenticated_customer_submits_review_and_bayesian_rating_is_calculated(): void
    {
        $customer = User::factory()->create(['phone_e164' => '+919840999111']);
        $token = $customer->createToken('customer-token')->plainTextToken;

        // Submit a 5-star review
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
                'customer_name' => 'Ananya Sharma',
                'phone_e164' => '+919840999111',
                'rating' => 5,
                'title' => 'Pain-free root canal treatment!',
                'content' => 'Dr. Apex was very polite and thorough. Clinic is spotless.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.requires_otp', false)
            ->assertJsonPath('data.review.rating', 5)
            ->assertJsonPath('data.review.title', 'Pain-free root canal treatment!')
            ->assertJsonPath('data.review.is_verified_interaction', false);

        // Verify database state and Bayesian score
        $this->businessA->refresh();
        $this->assertEquals(1, $this->businessA->review_count);
        $this->assertEquals(5.00, (float) $this->businessA->rating_avg);

        // Bayesian score: prior_mean = 3.5, prior_weight = 10, count = 1, avg = 5.0
        // (1 * 5.0 + 10 * 3.5) / 11 = 40 / 11 = 3.636
        $expectedBayes = round((1 * 5.0 + 10 * 3.5) / 11, 3);
        $this->assertEquals($expectedBayes, (float) $this->businessA->bayesian_score);

        // Public reviews list includes the published review and rating breakdown
        $listRes = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews");
        $listRes->assertStatus(200)
            ->assertJsonPath('meta.rating_avg', 5)
            ->assertJsonPath('meta.review_count', 1)
            ->assertJsonPath('meta.breakdown.5', 1)
            ->assertJsonPath('meta.breakdown.4', 0)
            ->assertJsonCount(1, 'data');
    }

    public function test_guest_review_requires_otp_verification_before_publishing(): void
    {
        $guestPhone = '+919840888222';

        $submitRes = $this->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
            'customer_name' => 'Guest Ramesh',
            'phone_e164' => $guestPhone,
            'rating' => 4,
            'title' => 'Good experience',
            'content' => 'Doctor took time to explain the procedure.',
        ]);

        $submitRes->assertStatus(201)
            ->assertJsonPath('data.requires_otp', true);

        $this->assertNotNull($submitRes->json('data.challenge_id'));
        $reviewUlid = $submitRes->json('data.review.public_id');

        // Review must NOT be publicly visible yet
        $this->businessA->refresh();
        $this->assertEquals(0, $this->businessA->review_count);

        $listBefore = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews");
        $listBefore->assertStatus(200)->assertJsonCount(0, 'data');

        // Retrieve OTP from fake sender
        $lastOtp = $this->fakeOtpSender->latestOtpFor($guestPhone);
        $this->assertNotNull($lastOtp);

        // Verify with invalid OTP -> 422
        $invalidOtpRes = $this->postJson("/api/v1/reviews/{$reviewUlid}/verify-otp", [
            'code' => '000000',
        ]);
        $invalidOtpRes->assertStatus(422);

        // Verify with valid OTP -> 200
        $verifyRes = $this->postJson("/api/v1/reviews/{$reviewUlid}/verify-otp", [
            'code' => $lastOtp,
        ]);
        $verifyRes->assertStatus(200)
            ->assertJsonPath('data.rating', 4);

        // Now review is published and rating recalculated
        $this->businessA->refresh();
        $this->assertEquals(1, $this->businessA->review_count);
        $this->assertEquals(4.00, (float) $this->businessA->rating_avg);

        $listAfter = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews");
        $listAfter->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_verified_interaction_is_detected_from_previous_enquiry(): void
    {
        $customer = User::factory()->create(['phone_e164' => '+919840777333']);
        $token = $customer->createToken('customer-token')->plainTextToken;

        // Create verified enquiry targeting Business A
        Enquiry::query()->create([
            'user_id' => $customer->id,
            'category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'target_business_id' => $this->businessA->id,
            'customer_name' => 'Verified Customer Karthik',
            'phone_e164' => '+919840777333',
            'requirement' => 'Need dental crown replacement.',
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Direct consent',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
                'customer_name' => 'Karthik',
                'phone_e164' => '+919840777333',
                'rating' => 5,
                'content' => 'Crown was fitted perfectly. Excellent work!',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.review.is_verified_interaction', true);
    }

    public function test_anti_shill_rule_blocks_business_owner_and_staff_from_reviewing_own_business(): void
    {
        // Owner A attempts to review Business A
        $ownerToken = $this->ownerA->createToken('owner-token')->plainTextToken;
        $ownerRes = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
                'customer_name' => 'Owner Self Review',
                'phone_e164' => '+919840111222',
                'rating' => 5,
                'content' => 'Best clinic in town!',
            ]);
        $ownerRes->assertStatus(422)
            ->assertJsonPath('error.code', 'CANNOT_REVIEW_OWN_BUSINESS');

        $this->app['auth']->forgetGuards();

        // Staff member (Agent Priya) attempts to review Business A
        $agentToken = $this->leadAgentA->createToken('agent-token')->plainTextToken;
        $agentRes = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
                'customer_name' => 'Agent Priya',
                'phone_e164' => '+919840999555',
                'rating' => 5,
                'content' => 'Great staff and service!',
            ]);
        $agentRes->assertStatus(422)
            ->assertJsonPath('error.code', 'CANNOT_REVIEW_OWN_BUSINESS');
    }

    public function test_one_review_per_business_30_day_duplicate_protection(): void
    {
        $customer = User::factory()->create(['phone_e164' => '+919840666444']);
        $token = $customer->createToken('customer-token')->plainTextToken;

        // 1. First review succeeds
        $firstRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
                'customer_name' => 'Deepa Sundar',
                'phone_e164' => '+919840666444',
                'rating' => 5,
                'content' => 'First visit was great.',
            ]);
        $firstRes->assertStatus(201);

        $this->app['auth']->forgetGuards();

        // 2. Second review from same authenticated user within 30 days is blocked
        $secondRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
                'customer_name' => 'Deepa Sundar',
                'phone_e164' => '+919840666444',
                'rating' => 4,
                'content' => 'Trying to submit a second review.',
            ]);
        $secondRes->assertStatus(422)
            ->assertJsonPath('error.code', 'DUPLICATE_REVIEW_30_DAYS');

        $this->app['auth']->forgetGuards();

        // 3. Guest submitting with the same phone number is also blocked
        $guestRes = $this->postJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews", [
            'customer_name' => 'Deepa Duplicate Phone',
            'phone_e164' => '+919840666444',
            'rating' => 1,
            'content' => 'Guest attempt with same phone number.',
        ]);
        $guestRes->assertStatus(422)
            ->assertJsonPath('error.code', 'DUPLICATE_REVIEW_30_DAYS');
    }

    public function test_business_owner_can_reply_edit_reply_and_delete_reply_with_permissions(): void
    {
        // Create an approved review for Business A
        $review = Review::query()->create([
            'business_id' => $this->businessA->id,
            'customer_name' => 'Manoj K',
            'phone_e164' => '+919840555333',
            'rating' => 5,
            'title' => 'Super fast scaling',
            'content' => 'Cleaning was done in 30 mins.',
            'status' => ReviewStatus::Published,
            'moderation_status' => ReviewModerationStatus::Approved,
            'phone_verified_at' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        $ownerToken = $this->ownerA->createToken('owner-token')->plainTextToken;
        $agentToken = $this->leadAgentA->createToken('agent-token')->plainTextToken;
        $editorToken = $this->editorA->createToken('editor-token')->plainTextToken;
        $ownerBToken = $this->ownerB->createToken('owner-b-token')->plainTextToken;

        // 1. Staff with reviews.reply (Lead Agent) can reply
        $agentReplyRes = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson("/api/v1/owner/businesses/{$this->businessA->public_id}/reviews/{$review->public_id}/reply", [
                'reply_text' => 'Thank you Manoj! We look forward to seeing you for your annual checkup.',
            ]);
        $agentReplyRes->assertStatus(200)
            ->assertJsonPath('data.owner_reply.text', 'Thank you Manoj! We look forward to seeing you for your annual checkup.')
            ->assertJsonPath('data.owner_reply.replied_by.public_id', $this->leadAgentA->public_id);

        $this->app['auth']->forgetGuards();

        // 2. Editor without reviews.reply is forbidden
        $editorRes = $this->withHeader('Authorization', "Bearer {$editorToken}")
            ->postJson("/api/v1/owner/businesses/{$this->businessA->public_id}/reviews/{$review->public_id}/reply", [
                'reply_text' => 'Editor unauthorized reply',
            ]);
        $editorRes->assertStatus(403);

        $this->app['auth']->forgetGuards();

        // 3. Cross-business IDOR: Owner B cannot reply to Business A's review
        $idorRes = $this->withHeader('Authorization', "Bearer {$ownerBToken}")
            ->postJson("/api/v1/owner/businesses/{$this->businessB->public_id}/reviews/{$review->public_id}/reply", [
                'reply_text' => 'Malicious reply from competitor',
            ]);
        $idorRes->assertStatus(404)
            ->assertJsonPath('error.code', 'REVIEW_NOT_FOUND');

        $this->app['auth']->forgetGuards();

        // 4. Owner A can update reply
        $updateReplyRes = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/v1/owner/businesses/{$this->businessA->public_id}/reviews/{$review->public_id}/reply", [
                'reply_text' => 'Updated response by Business Owner: Thank you Manoj!',
            ]);
        $updateReplyRes->assertStatus(200)
            ->assertJsonPath('data.owner_reply.text', 'Updated response by Business Owner: Thank you Manoj!')
            ->assertJsonPath('data.owner_reply.replied_by.public_id', $this->ownerA->public_id);

        // 5. Public review endpoint shows owner reply
        $publicRes = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}/reviews");
        $publicRes->assertStatus(200)
            ->assertJsonPath('data.0.owner_reply.text', 'Updated response by Business Owner: Thank you Manoj!');

        $this->app['auth']->forgetGuards();

        // 6. Owner A deletes reply
        $deleteReplyRes = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->deleteJson("/api/v1/owner/businesses/{$this->businessA->public_id}/reviews/{$review->public_id}/reply");
        $deleteReplyRes->assertStatus(200);

        $review->refresh();
        $this->assertFalse($review->hasOwnerReply());
    }

    public function test_super_admin_can_moderate_reviews_and_audit_log_is_recorded(): void
    {
        $review = Review::query()->create([
            'business_id' => $this->businessA->id,
            'customer_name' => 'Spam Bot',
            'phone_e164' => '+919840000000',
            'rating' => 1,
            'title' => 'Buy cheap crypto now at shady-link.in',
            'content' => 'Spam content advertisement',
            'status' => ReviewStatus::Published,
            'moderation_status' => ReviewModerationStatus::Approved,
            'phone_verified_at' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        // Customer reports review
        $reportRes = $this->postJson("/api/v1/reviews/{$review->public_id}/report", [
            'reason' => 'spam',
            'details' => 'Promotes crypto scam website.',
        ]);
        $reportRes->assertStatus(200);

        $adminToken = $this->superAdmin->createToken('admin-token')->plainTextToken;

        // Admin lists reviews with flagged/all filters
        $adminListRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/v1/admin/reviews?rating=1');
        $adminListRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Admin moderates review to "rejected"
        $modRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson("/api/v1/admin/reviews/{$review->public_id}/moderation", [
                'status' => 'rejected',
                'reason' => 'Confirmed spam advertisement violation of community terms.',
            ]);

        $modRes->assertStatus(200)
            ->assertJsonPath('data.moderation_status', 'rejected')
            ->assertJsonPath('data.status', 'rejected');

        $review->refresh();
        $this->assertEquals(ReviewModerationStatus::Rejected, $review->moderation_status);
        $this->assertEquals(ReviewStatus::Rejected, $review->status);

        // Audit log verified
        $auditLog = AuditLog::query()
            ->where('action', 'review.moderated')
            ->where('auditable_id', $review->id)
            ->latest()
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($this->superAdmin->id, $auditLog->actor_user_id);
        $this->assertEquals('rejected', $auditLog->new_values['moderation_status']);
    }

    public function test_non_admin_is_forbidden_from_admin_review_endpoints(): void
    {
        $review = Review::query()->create([
            'business_id' => $this->businessA->id,
            'customer_name' => 'Regular Review',
            'phone_e164' => '+919840111333',
            'rating' => 4,
            'status' => ReviewStatus::Published,
            'moderation_status' => ReviewModerationStatus::Approved,
            'phone_verified_at' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        $customer = User::factory()->create();
        $token = $customer->createToken('customer-token')->plainTextToken;

        $indexRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/reviews');
        $indexRes->assertStatus(403);

        $showRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/reviews/{$review->public_id}");
        $showRes->assertStatus(403);

        $modRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/admin/reviews/{$review->public_id}/moderation", [
                'status' => 'rejected',
            ]);
        $modRes->assertStatus(403);
    }
}
