<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Directory;

use App\Domains\Directory\Models\Business;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Media\Models\KycDocument;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OrganizationBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createBusinessFor(User $owner, City $city, Locality $locality, Category $category, string $name): Business
    {
        return Business::query()->forceCreate([
            'public_id' => (string) Str::ulid(),
            'owner_user_id' => $owner->id,
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'address_line_1' => '100 Main Road',
            'pincode' => '632001',
            'latitude' => 12.9165,
            'longitude' => 79.1325,
            'primary_phone_e164' => '+9198400' . random_int(10000, 99999),
            'status' => 'active',
            'is_claimed' => true,
        ]);
    }

    private function createLeadFor(Business $business, Category $category, City $city, Locality $locality, string $req): EnquiryRecipient
    {
        $enquiry = Enquiry::query()->create([
            'target_business_id' => $business->id,
            'category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'customer_name' => 'Test Customer',
            'phone_e164' => '+919840099999',
            'requirement' => $req,
            'urgency' => 'within_week',
            'enquiry_type' => 'direct',
            'status' => 'active',
            'consent_given' => true,
            'consent_version' => 'v1',
            'consent_text' => 'Consent granted',
            'consent_purpose' => 'lead_matching_and_contact',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        return EnquiryRecipient::query()->create([
            'enquiry_id' => $enquiry->id,
            'business_id' => $business->id,
            'lead_status' => 'new',
        ]);
    }

    public function test_company_owned_branches_and_regional_manager_city_scope_enforcement(): void
    {
        $this->seed(DatabaseSeeder::class);

        $hqOwner = User::factory()->create(['status' => 'active']);
        $regionalManager = User::factory()->create(['status' => 'active']);
        $outsider = User::factory()->create(['status' => 'active']);

        $state = State::query()->firstOrFail();
        $cityA = City::query()->firstOrFail();
        $localityA = Locality::query()->where('city_id', $cityA->id)->firstOrFail();

        $cityB = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Other City',
            'slug' => 'other-city',
            'latitude' => 13.0,
            'longitude' => 80.2,
        ]);
        $localityB = Locality::query()->create([
            'city_id' => $cityB->id,
            'name' => 'Other Locality',
            'slug' => 'other-locality',
            'latitude' => 13.0,
            'longitude' => 80.2,
        ]);

        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $branchA = $this->createBusinessFor($hqOwner, $cityA, $localityA, $category, 'Branch City A');
        $branchB = $this->createBusinessFor($hqOwner, $cityB, $localityB, $category, 'Branch City B');

        Sanctum::actingAs($hqOwner);
        $orgRes = $this->postJson('/api/v1/owner/organizations', ['name' => 'NearGrid Clinics'])
            ->assertStatus(201);
        $orgPublicId = $orgRes->json('data.public_id');

        // Regional manager requires at least one city
        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/members", [
            'user_public_id' => $regionalManager->public_id,
            'role' => 'regional_manager',
            'scope_type' => 'assigned_cities',
            'city_public_ids' => [],
        ])->assertStatus(422)->assertJsonPath('error.code', 'REGIONAL_SCOPE_CITIES_REQUIRED');

        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/members", [
            'user_public_id' => $regionalManager->public_id,
            'role' => 'regional_manager',
            'scope_type' => 'assigned_cities',
            'city_public_ids' => [$cityA->public_id],
        ])->assertStatus(200);

        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches", [
            'business_public_id' => $branchA->public_id,
            'branch_type' => 'company_owned',
        ])->assertStatus(200);

        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches", [
            'business_public_id' => $branchB->public_id,
            'branch_type' => 'company_owned',
        ])->assertStatus(200);

        // Regional manager can access City A company-owned branch across business/leads/reviews/kyc, but NOT City B
        Sanctum::actingAs($regionalManager);
        $this->getJson("/api/v1/owner/businesses/{$branchA->public_id}")->assertStatus(200);
        $this->getJson("/api/v1/owner/businesses/{$branchA->public_id}/leads")->assertStatus(200);
        $this->getJson("/api/v1/owner/businesses/{$branchA->public_id}/reviews")->assertStatus(200);
        $this->getJson("/api/v1/owner/businesses/{$branchA->public_id}/kyc-documents")->assertStatus(200);

        $this->getJson("/api/v1/owner/businesses/{$branchB->public_id}")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$branchB->public_id}/leads")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$branchB->public_id}/reviews")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$branchB->public_id}/kyc-documents")->assertStatus(403);

        // Outsider receives 403
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/owner/organizations/{$orgPublicId}")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$branchA->public_id}")->assertStatus(403);
    }

    public function test_franchise_isolation_blocks_hq_and_sibling_franchisees_and_requires_explicit_auditable_opt_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        $hqOwner = User::factory()->create(['status' => 'active']);
        $franchiseeA = User::factory()->create(['status' => 'active']);
        $franchiseeB = User::factory()->create(['status' => 'active']);

        $city = City::query()->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $hqBranch = $this->createBusinessFor($hqOwner, $city, $locality, $category, 'HQ Branch');
        $franchiseA = $this->createBusinessFor($franchiseeA, $city, $locality, $category, 'Franchise A');
        $franchiseB = $this->createBusinessFor($franchiseeB, $city, $locality, $category, 'Franchise B');

        KycDocument::query()->create([
            'business_id' => $franchiseA->id,
            'uploaded_by_user_id' => $franchiseeA->id,
            'document_type' => 'gstin',
            'upload_status' => 'ready',
            'disk' => 'local',
            'storage_key' => 'kyc/franchise-a-gst.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'sha256_checksum' => hash('sha256', 'dummy-kyc-pdf'),
        ]);

        $this->createLeadFor($hqBranch, $category, $city, $locality, 'HQ Lead');
        $this->createLeadFor($franchiseA, $category, $city, $locality, 'Franchise A Secret Lead');

        Sanctum::actingAs($hqOwner);
        $orgPublicId = $this->postJson('/api/v1/owner/organizations', ['name' => 'Brand Corp'])
            ->assertStatus(201)
            ->json('data.public_id');

        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches", [
            'business_public_id' => $hqBranch->public_id,
            'branch_type' => 'headquarters',
        ])->assertStatus(200);

        // Attach franchiseA (must be owned by franchiseeA or super_admin)
        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches", [
            'business_public_id' => $franchiseA->public_id,
            'branch_type' => 'franchise',
        ])->assertStatus(403)->assertJsonPath('error.code', 'BRANCH_OWNERSHIP_REQUIRED');

        // Make HQ owner a super_admin only to test attach, or attach via franchiseeA?
        // Only org members with attachBranch permission can attach, OR attach when franchiseeA attaches? Wait:
        // Business owner owns business, org owner owns org -> attach via service/admin or org_owner owning branch,
        // let's attach via super_admin or attach franchiseA directly in setup to test isolation!
        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        DB::table('user_roles')->insert(['user_id' => $hqOwner->id, 'role_id' => $superAdminRoleId]);

        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches", [
            'business_public_id' => $franchiseA->public_id,
            'branch_type' => 'franchise',
            'franchise_code' => 'FR-001',
            'allow_hq_lead_access' => true, // must be ignored!
        ])->assertStatus(200)->assertJsonPath('data.allow_hq_lead_access', false);

        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches", [
            'business_public_id' => $franchiseB->public_id,
            'branch_type' => 'franchise',
            'franchise_code' => 'FR-002',
        ])->assertStatus(200);

        // Ordinary organization update must NEVER enable allow_hq_lead_access
        $this->putJson("/api/v1/owner/organizations/{$orgPublicId}", [
            'name' => 'Brand Corp Updated',
            'allow_hq_lead_access' => true,
        ])->assertStatus(200);
        $this->assertFalse((bool) $franchiseA->fresh()?->allow_hq_lead_access);

        // Remove super_admin role from hqOwner so we test pure org_owner isolation
        DB::table('user_roles')->where('user_id', $hqOwner->id)->delete();

        // HQ owner sees HQ lead ONLY, and is BLOCKED (403) from Franchise A profile, leads, reviews, KYC
        $orgLeads = $this->getJson("/api/v1/owner/organizations/{$orgPublicId}/leads")->assertStatus(200);
        $this->assertCount(1, $orgLeads->json('data'));
        $this->assertSame('HQ Lead', $orgLeads->json('data.0.enquiry.requirement'));

        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/leads")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/reviews")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/kyc-documents")->assertStatus(403);

        // HQ owner CANNOT unilaterally enable allow_hq_lead_access on Franchise A
        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches/{$franchiseA->public_id}/hq-lead-access", [
            'allow_hq_lead_access' => true,
        ])->assertStatus(403)->assertJsonPath('error.code', 'FRANCHISE_AUTHORIZATION_REQUIRED');

        // Sibling franchisee B is strictly isolated from Franchise A and Org
        Sanctum::actingAs($franchiseeB);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/leads")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/kyc-documents")->assertStatus(403);
        $this->getJson("/api/v1/owner/organizations/{$orgPublicId}/leads")->assertStatus(403);

        // Franchisee A accesses their own business, leads, KYC cleanly, and explicitly authorizes HQ lead access
        Sanctum::actingAs($franchiseeA);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}")->assertStatus(200);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/leads")->assertStatus(200);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/kyc-documents")->assertStatus(200);

        $this->postJson("/api/v1/owner/organizations/{$orgPublicId}/branches/{$franchiseA->public_id}/hq-lead-access", [
            'allow_hq_lead_access' => true,
        ])->assertStatus(200)->assertJsonPath('data.allow_hq_lead_access', true);

        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'organization.franchise.hq_lead_access_updated')->exists()
        );

        $audit = DB::table('audit_logs')
            ->where('action', 'organization.franchise.hq_lead_access_updated')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($franchiseeA->id, (int) $audit->actor_user_id);
        $this->assertSame($franchiseA->id, (int) $audit->auditable_id);
        $this->assertStringContainsString('"allow_hq_lead_access":false', (string) $audit->old_values);
        $this->assertStringContainsString('"allow_hq_lead_access":true', (string) $audit->new_values);

        // Now HQ can view Franchise A leads (but STILL NOT Franchise A KYC or profile edits!)
        Sanctum::actingAs($hqOwner);
        $orgLeadsAfter = $this->getJson("/api/v1/owner/organizations/{$orgPublicId}/leads")->assertStatus(200);
        $this->assertCount(2, $orgLeadsAfter->json('data'));
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/leads")->assertStatus(200);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}/kyc-documents")->assertStatus(403);
        $this->getJson("/api/v1/owner/businesses/{$franchiseA->public_id}")->assertStatus(403);
    }

    public function test_role_escalation_self_promotion_and_cross_branch_write_idor_are_blocked(): void
    {
        $this->seed(DatabaseSeeder::class);

        $orgOwner = User::factory()->create(['status' => 'active']);
        $corporateAdmin = User::factory()->create(['status' => 'active']);
        $regionalManager = User::factory()->create(['status' => 'active']);
        $franchiseeA = User::factory()->create(['status' => 'active']);
        $franchiseeB = User::factory()->create(['status' => 'active']);

        $city = City::query()->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $branchA = $this->createBusinessFor($franchiseeA, $city, $locality, $category, 'Franchise IDOR A');
        $branchB = $this->createBusinessFor($franchiseeB, $city, $locality, $category, 'Franchise IDOR B');

        Sanctum::actingAs($orgOwner);
        $orgAPublicId = $this->postJson('/api/v1/owner/organizations', ['name' => 'Org Alpha'])
            ->assertStatus(201)
            ->json('data.public_id');
        $orgBPublicId = $this->postJson('/api/v1/owner/organizations', ['name' => 'Org Beta'])
            ->assertStatus(201)
            ->json('data.public_id');

        $this->postJson("/api/v1/owner/organizations/{$orgAPublicId}/members", [
            'user_public_id' => $corporateAdmin->public_id,
            'role' => 'corporate_admin',
            'scope_type' => 'company_owned_only',
        ])->assertStatus(200);

        $this->postJson("/api/v1/owner/organizations/{$orgAPublicId}/members", [
            'user_public_id' => $regionalManager->public_id,
            'role' => 'regional_manager',
            'scope_type' => 'assigned_cities',
            'city_public_ids' => [$city->public_id],
        ])->assertStatus(200);

        // Corporate admin cannot self-promote or assign org_owner
        Sanctum::actingAs($corporateAdmin);
        $this->postJson("/api/v1/owner/organizations/{$orgAPublicId}/members", [
            'user_public_id' => $corporateAdmin->public_id,
            'role' => 'org_owner',
            'scope_type' => 'all_branches',
        ])->assertStatus(403)->assertJsonPath('error.code', 'ORG_OWNER_ROLE_REQUIRED');

        // Regional manager cannot assign corporate_admin
        Sanctum::actingAs($regionalManager);
        $this->postJson("/api/v1/owner/organizations/{$orgAPublicId}/members", [
            'user_public_id' => $regionalManager->public_id,
            'role' => 'corporate_admin',
            'scope_type' => 'all_branches',
        ])->assertStatus(403);

        // Franchisee B cannot edit Franchisee A profile or manage Franchisee A staff
        Sanctum::actingAs($franchiseeB);
        $this->putJson("/api/v1/owner/businesses/{$branchA->public_id}", [
            'name' => 'Hacked Branch Name',
        ])->assertStatus(403);
        $this->postJson("/api/v1/owner/businesses/{$branchA->public_id}/members", [
            'user_public_id' => $franchiseeB->public_id,
            'role' => 'manager',
        ])->assertStatus(403);

        // Cross-org branch IDOR: Branch A belongs to neither Org A nor Org B -> 404 on hq-lead-access
        Sanctum::actingAs($franchiseeA);
        $this->postJson("/api/v1/owner/organizations/{$orgBPublicId}/branches/{$branchA->public_id}/hq-lead-access", [
            'allow_hq_lead_access' => true,
        ])->assertStatus(404)->assertJsonPath('error.code', 'NOT_A_FRANCHISE_BRANCH');
    }
}
