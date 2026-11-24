<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Jobs\DispatchRevalidationWebhookJob;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Media\Models\KycDocument;
use App\Domains\Reviews\Enums\ReviewModerationStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\Review;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AdminGovernanceAndModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $kycVerifier;

    private User $moderator;

    private User $regularCustomer;

    private Business $business;

    private City $city;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $kycRole = Role::query()->where('name', 'kyc_verifier')->firstOrFail();
        $modRole = Role::query()->where('name', 'moderator')->firstOrFail();

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->roles()->sync([$superAdminRole->id]);

        $this->kycVerifier = User::factory()->create();
        $this->kycVerifier->roles()->sync([$kycRole->id]);

        $this->moderator = User::factory()->create();
        $this->moderator->roles()->sync([$modRole->id]);

        $this->regularCustomer = User::factory()->create();

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->category = Category::query()->where('slug', 'dentists')->firstOrFail();
        $locality = Locality::query()->where('city_id', $this->city->id)->firstOrFail();

        $owner = User::factory()->create();
        $this->business = new Business([
            'primary_category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $locality->id,
            'name' => 'Metro Smile Dental Care',
            'slug' => 'metro-smile-dental-care-t-nagar',
            'address_line_1' => '100 Mount Road',
            'pincode' => '600017',
            'latitude' => 13.0418,
            'longitude' => 80.2341,
            'primary_phone_e164' => '+919840112233',
        ]);
        $this->business->owner_user_id = $owner->id;
        $this->business->status = BusinessStatus::PendingReview;
        $this->business->is_verified = false;
        $this->business->is_trusted = false;
        $this->business->save();
    }

    public function test_unauthenticated_and_non_admin_users_are_forbidden_from_all_admin_endpoints(): void
    {
        // 1. Unauthenticated -> 401
        $this->getJson('/api/v1/admin/verifications')->assertUnauthorized();
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
        $this->getJson('/api/v1/admin/enquiries')->assertUnauthorized();
        $this->getJson('/api/v1/admin/reviews')->assertUnauthorized();

        // 2. Regular customer -> 403
        $customerToken = $this->regularCustomer->createToken('cust-token')->plainTextToken;
        $authHeaders = ['Authorization' => "Bearer {$customerToken}"];

        $this->withHeaders($authHeaders)->getJson('/api/v1/admin/verifications')->assertForbidden();
        $this->withHeaders($authHeaders)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->withHeaders($authHeaders)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->withHeaders($authHeaders)->getJson('/api/v1/admin/enquiries')->assertForbidden();
        $this->withHeaders($authHeaders)->getJson('/api/v1/admin/reviews')->assertForbidden();
    }

    public function test_kyc_verifier_can_list_verifications_and_issue_actor_bound_signed_urls(): void
    {
        $verification = BusinessVerification::query()->create([
            'business_id' => $this->business->id,
            'submitted_by_user_id' => $this->business->owner_user_id,
            'document_type' => VerificationDocumentType::Gstin,
            'document_number_encrypted' => '33AAAAA0000A1Z5',
            'private_file_path' => 'kyc/test-doc.pdf',
            'status' => 'pending',
        ]);

        $kycDoc = KycDocument::query()->create([
            'business_id' => $this->business->id,
            'uploaded_by_user_id' => $this->business->owner_user_id,
            'document_type' => VerificationDocumentType::Gstin,
            'disk' => 'kyc_private',
            'storage_key' => 'kyc/test-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'sha256_checksum' => hash('sha256', 'dummy'),
            'upload_status' => 'ready',
        ]);

        $token = $this->kycVerifier->createToken('kyc-token')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        // List verifications
        $listRes = $this->withHeaders($headers)->getJson('/api/v1/admin/verifications');
        $listRes->assertOk()
            ->assertJsonPath('data.0.public_id', $verification->public_id)
            ->assertJsonPath('data.0.status', 'pending');

        // Generate signed URL
        $signedRes = $this->withHeaders($headers)->getJson(
            "/api/v1/admin/businesses/{$this->business->public_id}/kyc-documents/{$kycDoc->public_id}/signed-url"
        );
        $signedRes->assertOk()
            ->assertJsonPath('data.expires_in_seconds', 60);

        $downloadUrl = (string) $signedRes->json('data.download_url');
        $this->assertStringContainsString('signature=', $downloadUrl);

        Storage::fake('kyc_private');
        Storage::disk('kyc_private')->put('kyc/test-doc.pdf', 'secret test kyc content');

        // KYC verifier can successfully download using actor-bound signed link
        $downloadRes = $this->withHeaders($headers)->get($downloadUrl);
        $downloadRes->assertOk();
        $this->assertSame('secret test kyc content', $downloadRes->getContent());

        // A different user cannot download using this actor-bound link
        $this->flushHeaders();
        auth()->forgetGuards();
        $otherToken = $this->regularCustomer->createToken('other-cust')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$otherToken}")->get($downloadUrl)->assertForbidden();
    }

    public function test_kyc_verifier_approves_verification_and_awards_verified_and_trust_badges(): void
    {
        Queue::fake([DispatchRevalidationWebhookJob::class]);

        $verification = BusinessVerification::query()->create([
            'business_id' => $this->business->id,
            'submitted_by_user_id' => $this->business->owner_user_id,
            'document_type' => VerificationDocumentType::Gstin,
            'document_number_encrypted' => '33AAAAA0000A1Z5',
            'private_file_path' => 'kyc/test-doc.pdf',
            'status' => 'pending',
        ]);

        $token = $this->kycVerifier->createToken('kyc-token')->plainTextToken;

        $reviewRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/verifications/{$verification->public_id}/review", [
                'decision' => 'approved',
                'grant_trust_badge' => true,
            ]);

        $reviewRes->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->business->refresh();
        $this->assertTrue($this->business->is_verified);
        $this->assertTrue($this->business->is_trusted);
        $this->assertSame(BusinessStatus::Active, $this->business->status);

        // Immutable audit log created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'business.verification_approved',
            'actor_user_id' => $this->kycVerifier->id,
        ]);

        // Revalidation job dispatched for the business storefront path
        Queue::assertPushed(DispatchRevalidationWebhookJob::class, function (DispatchRevalidationWebhookJob $job) {
            return in_array("/{$this->city->slug}/business/{$this->business->slug}", $job->paths, true);
        });
    }

    public function test_super_admin_can_manage_user_status_and_revoke_active_sessions(): void
    {
        $targetUser = User::factory()->create(['status' => 'active']);
        $token1 = $targetUser->createToken('token-1')->plainTextToken;
        $token2 = $targetUser->createToken('token-2')->plainTextToken;
        $this->assertCount(2, $targetUser->tokens);

        $adminToken = $this->superAdmin->createToken('admin-token')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$adminToken}"];

        // 1. Suspend user -> automatically deletes all personal access tokens
        $suspendRes = $this->withHeaders($headers)
            ->patchJson("/api/v1/admin/users/{$targetUser->public_id}/status", [
                'status' => 'suspended',
                'reason' => 'Repeated fraudulent enquiry abuse.',
            ]);

        $suspendRes->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $targetUser->refresh();
        $this->assertSame('suspended', $targetUser->status);
        $this->assertCount(0, $targetUser->tokens);

        // 2. Reactivate user and revoke sessions
        $activateRes = $this->withHeaders($headers)
            ->patchJson("/api/v1/admin/users/{$targetUser->public_id}/status", [
                'status' => 'active',
            ]);
        $activateRes->assertOk()->assertJsonPath('data.status', 'active');

        // Create new token then test revoke-sessions endpoint
        $targetUser->createToken('new-token');
        $targetUser->refresh();
        $this->assertCount(1, $targetUser->tokens);

        $revokeRes = $this->withHeaders($headers)
            ->postJson("/api/v1/admin/users/{$targetUser->public_id}/revoke-sessions");

        $revokeRes->assertOk();
        $this->assertCount(0, $targetUser->fresh()->tokens);

        // Verify audit logs were recorded for both events
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.status_updated',
            'actor_user_id' => $this->superAdmin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.sessions_revoked',
            'actor_user_id' => $this->superAdmin->id,
        ]);
    }

    public function test_super_admin_can_filter_immutable_audit_logs(): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $this->superAdmin->id,
            'actor_role' => 'super_admin',
            'action' => 'test.governance_action',
            'auditable_type' => User::class,
            'auditable_id' => $this->superAdmin->id,
            'old_values' => ['foo' => 'bar'],
            'new_values' => ['foo' => 'baz'],
            'ip_address' => '127.0.0.1',
        ]);

        $adminToken = $this->superAdmin->createToken('admin-token')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/v1/admin/audit-logs?action=test.governance_action');

        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'test.governance_action')
            ->assertJsonPath('data.0.actor_role', 'super_admin');
    }

    public function test_revalidation_webhook_job_computes_hmac_and_delivers_payload(): void
    {
        $testSecret = 'shared_revalidation_secret_for_tests';
        config([
            'directory.revalidation.secret' => $testSecret,
            'directory.revalidation.frontend_url' => 'http://localhost:3000',
        ]);

        Http::fake([
            'http://localhost:3000/api/revalidate' => Http::response(['revalidated' => true], 200),
        ]);

        $paths = ['/chennai/business/metro-smile-dental-care-t-nagar', '/chennai'];
        $job = new DispatchRevalidationWebhookJob($paths, 'test_revalidation');
        $job->handle();

        Http::assertSent(function (Request $request) use ($testSecret, $paths) {
            $hasHeader = $request->hasHeader('X-Webhook-Signature');
            if (! $hasHeader) {
                return false;
            }

            $sigHeader = $request->header('X-Webhook-Signature')[0];
            preg_match('/^t=(\d+),v1=([a-f0-9]+)$/', $sigHeader, $matches);
            if (count($matches) !== 3) {
                return false;
            }

            $ts = $matches[1];
            $hash = $matches[2];
            $body = $request->body();

            $expectedHash = hash_hmac('sha256', "{$ts}.{$body}", $testSecret);

            return hash_equals($expectedHash, $hash)
                && $request->data()['paths'] === $paths;
        });
    }

    public function test_moderator_role_can_view_and_moderate_reviews(): void
    {
        $review = Review::query()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->regularCustomer->id,
            'customer_name' => 'Anita Raman',
            'phone_e164' => '+919840112233',
            'rating' => 1,
            'title' => 'Flagged Content',
            'content' => 'Review containing unverified promotional claims.',
            'status' => ReviewStatus::Published,
            'moderation_status' => ReviewModerationStatus::Pending,
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        $token = $this->moderator->createToken('mod-token')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        // 1. Moderator can list admin reviews
        $listRes = $this->withHeaders($headers)->getJson('/api/v1/admin/reviews');
        $listRes->assertOk()
            ->assertJsonPath('data.0.public_id', $review->public_id);

        // 2. Moderator can moderate review
        $modRes = $this->withHeaders($headers)
            ->patchJson("/api/v1/admin/reviews/{$review->public_id}/moderation", [
                'status' => 'hidden',
                'reason' => 'Violation of platform content standards.',
            ]);

        $modRes->assertOk()
            ->assertJsonPath('data.moderation_status', 'hidden')
            ->assertJsonPath('data.status', 'hidden');

        $review->refresh();
        $this->assertSame(ReviewModerationStatus::Hidden, $review->moderation_status);
        $this->assertSame(ReviewStatus::Hidden, $review->status);
    }

    public function test_services_media_and_directory_changes_dispatch_revalidation_webhook(): void
    {
        Queue::fake([DispatchRevalidationWebhookJob::class]);

        $owner = User::query()->findOrFail($this->business->owner_user_id);
        $ownerToken = $owner->createToken('owner-token')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$ownerToken}"];

        // 1. Owner creates service -> dispatches revalidation
        $this->withHeaders($headers)->postJson("/api/v1/owner/businesses/{$this->business->public_id}/services", [
            'name' => 'Root Canal Treatment',
            'description' => 'Comprehensive endodontic treatment',
            'price_min_minor' => 250000,
            'currency' => 'INR',
        ])->assertCreated();

        Queue::assertPushed(DispatchRevalidationWebhookJob::class, function (DispatchRevalidationWebhookJob $job) {
            return in_array("/{$this->city->slug}/business/{$this->business->slug}", $job->paths, true)
                && $job->reason === 'business_service_created';
        });

        // 2. Profile update -> dispatches revalidation for storefront AND directory listings
        $this->withHeaders($headers)->putJson("/api/v1/owner/businesses/{$this->business->public_id}", [
            'name' => 'Metro Smile Super Dental Care',
            'address_line_1' => '102 Mount Road',
            'pincode' => '600017',
        ])->assertOk();

        Queue::assertPushed(DispatchRevalidationWebhookJob::class, function (DispatchRevalidationWebhookJob $job) {
            return in_array("/{$this->city->slug}/business/{$this->business->slug}", $job->paths, true)
                && in_array("/{$this->city->slug}", $job->paths, true)
                && in_array("/{$this->city->slug}/{$this->category->slug}", $job->paths, true)
                && $job->reason === 'business_profile_updated';
        });
    }
}
