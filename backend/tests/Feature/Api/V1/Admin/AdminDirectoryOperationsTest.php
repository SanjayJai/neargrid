<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessClaim;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Directory\Models\Organization;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminDirectoryOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->superAdmin->roles()->syncWithoutDetaching([$role->id]);
    }

    public function test_new_admin_directory_endpoints_reject_unauthenticated_and_non_super_admin_users(): void
    {
        $this->getJson('/api/v1/admin/businesses')->assertUnauthorized();
        $this->getJson('/api/v1/admin/organizations')->assertUnauthorized();
        $this->getJson('/api/v1/admin/claims')->assertUnauthorized();
        $this->getJson('/api/v1/admin/seo/metadata')->assertUnauthorized();
        $this->getJson('/api/v1/admin/seo/redirects')->assertUnauthorized();

        $customer = User::factory()->create(['status' => 'active']);
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $customer->roles()->syncWithoutDetaching([$customerRole->id]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/businesses')->assertForbidden();
        $this->getJson('/api/v1/admin/organizations')->assertForbidden();
        $this->getJson('/api/v1/admin/claims')->assertForbidden();
        $this->getJson('/api/v1/admin/seo/metadata')->assertForbidden();
        $this->getJson('/api/v1/admin/seo/redirects')->assertForbidden();
    }

    public function test_super_admin_can_filter_businesses_update_status_and_toggle_badges_with_audit(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $biz = Business::factory()->create([
            'name' => 'Apex Dental Studio',
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::PendingReview,
            'is_verified' => false,
            'is_trusted' => false,
        ]);

        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/admin/businesses?q=Apex&status=pending_review')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $biz->public_id);

        $this->patchJson("/api/v1/admin/businesses/{$biz->public_id}/status", [
            'status' => 'active',
            'reason' => 'Approved by admin review',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // Cannot bypass KYC workflow by granting is_verified=true without approved BusinessVerification
        $this->patchJson("/api/v1/admin/businesses/{$biz->public_id}/badges", [
            'is_verified' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'KYC_APPROVAL_REQUIRED');

        BusinessVerification::query()->create([
            'business_id' => $biz->id,
            'submitted_by_user_id' => $this->superAdmin->id,
            'document_type' => VerificationDocumentType::Gstin,
            'document_number_encrypted' => encrypt('33AABCU9603R1ZM'),
            'private_file_path' => 'private/kyc/gst.pdf',
            'status' => 'approved',
        ]);

        $this->patchJson("/api/v1/admin/businesses/{$biz->public_id}/badges", [
            'is_verified' => true,
            'is_trusted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.is_trusted', true);

        $this->assertTrue(AuditLog::query()->where('action', 'business.admin_status_updated')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'business.admin_badges_updated')->exists());
    }

    public function test_super_admin_can_manage_organizations_branches_franchises_and_cross_org_isolation(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $owner = User::factory()->create(['status' => 'active']);
        $manager = User::factory()->create(['status' => 'active']);

        $branch = Business::factory()->create([
            'name' => 'Franchise Outlet 01',
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
        ]);

        Sanctum::actingAs($this->superAdmin);

        $createRes = $this->postJson('/api/v1/admin/organizations', [
            'name' => 'Metro Health Group',
            'owner_public_id' => $owner->public_id,
        ])->assertCreated();

        $orgPublicId = (string) $createRes->json('data.public_id');

        $otherOrgRes = $this->postJson('/api/v1/admin/organizations', [
            'name' => 'Other Group',
            'owner_public_id' => $owner->public_id,
        ])->assertCreated();
        $otherOrgPublicId = (string) $otherOrgRes->json('data.public_id');

        $this->postJson("/api/v1/admin/organizations/{$orgPublicId}/branches", [
            'business_public_id' => $branch->public_id,
            'branch_type' => 'franchise',
            'franchise_code' => 'FR-001',
        ])
            ->assertOk()
            ->assertJsonPath('data.branch_type', 'franchise')
            ->assertJsonPath('data.allow_hq_lead_access', false);

        // Cross-org isolation: cannot toggle HQ lead access via another organization
        $this->patchJson("/api/v1/admin/organizations/{$otherOrgPublicId}/branches/{$branch->public_id}/hq-lead-access", [
            'allow_hq_lead_access' => true,
        ])->assertNotFound();

        $this->patchJson("/api/v1/admin/organizations/{$orgPublicId}/branches/{$branch->public_id}/hq-lead-access", [
            'allow_hq_lead_access' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.allow_hq_lead_access', true);

        $this->postJson("/api/v1/admin/organizations/{$orgPublicId}/members", [
            'user_public_id' => $manager->public_id,
            'role' => 'regional_manager',
            'scope_type' => 'assigned_cities',
            'city_public_ids' => [$city->public_id],
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'regional_manager')
            ->assertJsonPath('data.scope_type', 'assigned_cities');

        $this->getJson("/api/v1/admin/organizations/{$orgPublicId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonCount(2, 'data.members');
    }

    public function test_super_admin_can_review_claims_without_exposing_private_proof_path(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();
        $claimant = User::factory()->create(['status' => 'active']);

        $biz = Business::factory()->create([
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
            'is_claimed' => false,
            'is_verified' => false,
        ]);

        $claim = BusinessClaim::query()->create([
            'business_id' => $biz->id,
            'claimant_user_id' => $claimant->id,
            'proof_file_path_private' => 'private/claims/secret-doc.pdf',
            'claim_notes' => 'Owner registration proof',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->superAdmin);

        $listRes = $this->getJson('/api/v1/admin/claims?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $claim->public_id);

        $this->assertArrayNotHasKey('proof_file_path_private', $listRes->json('data.0'));

        $this->postJson("/api/v1/admin/claims/{$claim->public_id}/review", [
            'decision' => 'approved',
            'review_notes' => 'Ownership verified',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.business.is_claimed', true);

        $biz->refresh();
        $this->assertSame($claimant->id, $biz->owner_user_id);
        $this->assertFalse($biz->is_verified);
    }

    public function test_super_admin_can_list_seo_metadata_and_manage_redirects(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/admin/seo/metadata')->assertOk();

        // Reject external open redirects and self-loops with 422 (not 500)
        $this->postJson('/api/v1/admin/seo/redirects', [
            'source_path' => '/old-clinic-path',
            'target_path' => 'https://evil.example/phish',
            'http_status' => 301,
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/seo/redirects', [
            'source_path' => '/same-path',
            'target_path' => '/same-path',
            'http_status' => 301,
        ])->assertStatus(422);

        $createRes = $this->postJson('/api/v1/admin/seo/redirects', [
            'source_path' => '/old-clinic-path',
            'target_path' => '/chennai/dentists',
            'http_status' => 301,
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_path', '/old-clinic-path')
            ->assertJsonPath('data.target_path', '/chennai/dentists');

        $redirectId = (int) $createRes->json('data.id');

        $this->getJson('/api/v1/admin/seo/redirects?q=old-clinic-path')
            ->assertOk()
            ->assertJsonPath('data.0.id', $redirectId);

        $this->patchJson("/api/v1/admin/seo/redirects/{$redirectId}", [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/admin/seo/redirects/{$redirectId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }
}
