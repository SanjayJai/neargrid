<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Directory;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Exceptions\DirectoryException;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessClaim;
use App\Domains\Directory\Models\BusinessHour;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Directory\Services\BusinessVerificationAndClaimService;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicProfileVerificationClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_publication_rules_and_public_api_resource_privacy(): void
    {
        $owner = User::factory()->create([
            'name' => 'Secret Owner Name',
            'email' => 'private.owner@example.com',
            'phone_e164' => '+919840099887',
        ]);
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->where('slug', 'adyar')->firstOrFail();
        $category = Category::query()->where('slug', 'dentists')->firstOrFail();

        $business = Business::factory()->create([
            'owner_user_id' => $owner->id,
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'name' => 'Marina Dental Clinic',
            'slug' => 'marina-dental-clinic',
            'primary_phone_e164' => '+919840077665',
            'whatsapp_e164' => '+919840077665',
            'email' => 'desk@marinadental.test',
            'status' => BusinessStatus::PendingReview,
        ]);

        // Unverified / pending_review / draft / suspended / rejected listings MUST NOT be publicly visible
        foreach ([
            BusinessStatus::Draft,
            BusinessStatus::PendingReview,
            BusinessStatus::Suspended,
            BusinessStatus::Rejected,
        ] as $hiddenStatus) {
            $business->status = $hiddenStatus;
            $business->save();

            $this->getJson('/api/v1/businesses/chennai/marina-dental-clinic')
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        // Active listing is visible and strictly strips private owner & sensitive data
        $business->status = BusinessStatus::Active;
        $business->save();

        $res = $this->getJson('/api/v1/businesses/chennai/marina-dental-clinic?locale=ta')
            ->assertOk()
            ->assertJsonPath('data.public_id', $business->public_id)
            ->assertJsonPath('data.name', 'Marina Dental Clinic')
            ->assertJsonPath('data.primary_category.name', 'பல் மருத்துவர்கள்')
            ->assertJsonPath('data.address.city.name', 'சென்னை')
            ->assertJsonPath('data.contact.phone_masked', '+9198400XXXXX')
            ->assertJsonPath('data.contact.has_whatsapp', true);

        $rawBody = (string) $res->getContent();
        $this->assertStringNotContainsString('Secret Owner Name', $rawBody);
        $this->assertStringNotContainsString('private.owner@example.com', $rawBody);
        $this->assertStringNotContainsString('+919840099887', $rawBody);
        $this->assertStringNotContainsString('+919840077665', $rawBody);
        $this->assertStringNotContainsString('desk@marinadental.test', $rawBody);
        $this->assertArrayNotHasKey('id', $res->json('data'));
        $this->assertArrayNotHasKey('owner_user_id', $res->json('data'));
        $this->assertArrayNotHasKey('members', $res->json('data'));
        $this->assertArrayNotHasKey('verifications', $res->json('data'));
    }

    public function test_kyc_verification_encryption_and_admin_only_approval_with_audit_log(): void
    {
        $owner = User::factory()->create();
        $verifier = User::factory()->create();
        $kycRole = Role::query()->where('name', 'kyc_verifier')->firstOrFail();
        $verifier->roles()->attach($kycRole->id);

        $ownerToken = $owner->createToken('owner', ['*'])->plainTextToken;
        $created = $this->withToken($ownerToken)->postJson('/api/v1/owner/businesses', [
            'name' => 'Adyar Health Diagnostics',
            'primary_category_slug' => 'diagnostic-labs',
            'city_slug' => 'chennai',
            'locality_slug' => 'adyar',
            'address_line_1' => '10 Lattice Bridge Road',
            'pincode' => '600020',
            'primary_phone_e164' => '+919840066677',
        ])->assertCreated();

        $bizId = (string) $created->json('data.public_id');
        $docNum = '33AABCU9603R1ZM';

        $subRes = $this->withToken($ownerToken)
            ->postJson("/api/v1/owner/businesses/{$bizId}/verifications", [
                'document_type' => 'gstin',
                'document_number' => $docNum,
                'private_file_path' => 'private-kyc/2026/doc-1.pdf',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        // Plaintext document number and private S3 path must NOT appear in API response or raw DB column
        $this->assertStringNotContainsString($docNum, (string) $subRes->getContent());
        $this->assertStringNotContainsString('private-kyc/2026/doc-1.pdf', (string) $subRes->getContent());

        $rawDbRow = DB::table('business_verifications')
            ->where('public_id', $subRes->json('data.public_id'))
            ->first();
        $this->assertNotSame($docNum, $rawDbRow->document_number_encrypted);

        // Duplicate pending submission for same document type blocked
        $this->withToken($ownerToken)
            ->postJson("/api/v1/owner/businesses/{$bizId}/verifications", [
                'document_type' => 'gstin',
                'document_number' => $docNum,
                'private_file_path' => 'private-kyc/2026/doc-2.pdf',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERIFICATION_ALREADY_PENDING');

        $verification = BusinessVerification::findByPublicId((string) $subRes->json('data.public_id'));
        $this->assertNotNull($verification);

        $service = app(BusinessVerificationAndClaimService::class);

        // Owner CANNOT self-approve KYC verification
        $this->assertThrows(
            fn () => $service->reviewVerification($owner, $verification, 'approved'),
            DirectoryException::class
        );

        // Authorized KYC verifier approves -> sets is_verified=true & activates pending_review business
        $service->reviewVerification($verifier, $verification, 'approved');

        $business = Business::findByPublicId($bizId);
        $this->assertNotNull($business);
        $this->assertTrue($business->is_verified);
        $this->assertNotNull($business->verified_at);
        $this->assertSame(BusinessStatus::Active, $business->status);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $verifier->id,
            'action' => 'business.verification_approved',
            'auditable_id' => $business->id,
        ]);

        // Suspending via admin publication service hides listing immediately
        $service->changePublicationStatus($verifier, $business, BusinessStatus::Suspended, 'Policy review');
        $this->getJson("/api/v1/businesses/chennai/{$business->slug}")->assertNotFound();
    }

    public function test_claim_workflow_is_separate_from_kyc_verification_and_transfers_ownership(): void
    {
        $claimantA = User::factory()->create();
        $claimantB = User::factory()->create();
        $verifier = User::factory()->create();
        $verifier->roles()->attach(Role::query()->where('name', 'kyc_verifier')->firstOrFail()->id);

        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->where('slug', 'adyar')->firstOrFail();
        $category = Category::query()->where('slug', 'plumbers')->firstOrFail();

        $unclaimed = Business::factory()->unclaimed()->create([
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'status' => BusinessStatus::Active,
            'is_verified' => false,
        ]);

        $tokenA = $claimantA->createToken('claimant-a', ['customer:read'])->plainTextToken;
        $tokenB = $claimantB->createToken('claimant-b', ['customer:read'])->plainTextToken;

        $claimARes = $this->withToken($tokenA)
            ->postJson("/api/v1/businesses/{$unclaimed->public_id}/claims", [
                'proof_file_path_private' => 'claims/proof-a.pdf',
                'claim_notes' => 'Proprietor electricity bill attached.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertStringNotContainsString('claims/proof-a.pdf', (string) $claimARes->getContent());

        auth()->forgetGuards();
        $claimBRes = $this->withToken($tokenB)
            ->postJson("/api/v1/businesses/{$unclaimed->public_id}/claims", [
                'proof_file_path_private' => 'claims/proof-b.pdf',
            ])
            ->assertCreated();

        $claimA = BusinessClaim::findByPublicId((string) $claimARes->json('data.public_id'));
        $claimB = BusinessClaim::findByPublicId((string) $claimBRes->json('data.public_id'));
        $this->assertNotNull($claimA);
        $this->assertNotNull($claimB);

        // Admin approves Claimant A -> transfers ownership, keeps is_verified=false, rejects competing claim B
        app(BusinessVerificationAndClaimService::class)->reviewClaim($verifier, $claimA, 'approved', 'Verified ownership');

        $unclaimed->refresh();
        $this->assertSame($claimantA->id, $unclaimed->owner_user_id);
        $this->assertTrue($unclaimed->is_claimed);
        $this->assertFalse($unclaimed->is_verified, 'Claim approval must remain separate from KYC verification.');
        $this->assertSame('rejected', $claimB->fresh()->status);

        // Claiming an already-claimed business is now forbidden
        $outsider = User::factory()->create();
        $outsiderToken = $outsider->createToken('outsider', ['*'])->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($outsiderToken)
            ->postJson("/api/v1/businesses/{$unclaimed->public_id}/claims", [
                'proof_file_path_private' => 'claims/proof-c.pdf',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $verifier->id,
            'action' => 'business.claim_approved',
            'auditable_id' => $unclaimed->id,
        ]);
    }

    public function test_directory_foreign_keys_and_unique_constraints(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->where('slug', 'adyar')->firstOrFail();
        $category = Category::query()->where('slug', 'dentists')->firstOrFail();

        $biz = Business::factory()->create([
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'slug' => 'unique-clinic-slug',
        ]);

        // Duplicate (city_id, slug) prevented
        $this->assertThrows(
            fn () => Business::factory()->create([
                'primary_category_id' => $category->id,
                'city_id' => $city->id,
                'locality_id' => $locality->id,
                'slug' => 'unique-clinic-slug',
            ]),
            QueryException::class
        );

        // Duplicate (business_id, day_of_week, slot_index) prevented
        BusinessHour::query()->create(['business_id' => $biz->id, 'day_of_week' => 1, 'slot_index' => 1, 'open_time' => '09:00:00', 'close_time' => '18:00:00']);
        $this->assertThrows(
            fn () => BusinessHour::query()->create(['business_id' => $biz->id, 'day_of_week' => 1, 'slot_index' => 1, 'open_time' => '10:00:00', 'close_time' => '19:00:00']),
            QueryException::class
        );

        // Duplicate (business_id, user_id) in business_members prevented
        $staff = User::factory()->create();
        BusinessMember::query()->create(['business_id' => $biz->id, 'user_id' => $staff->id, 'role' => 'editor']);
        $this->assertThrows(
            fn () => BusinessMember::query()->create(['business_id' => $biz->id, 'user_id' => $staff->id, 'role' => 'manager']),
            QueryException::class
        );

        // RESTRICT: cannot force-delete locality or category referenced by a business
        $this->assertThrows(fn () => $locality->delete(), QueryException::class);
        $this->assertThrows(fn () => $category->forceDelete(), QueryException::class);

        // CASCADE: force-deleting a business cleans up hours & staff memberships
        $biz->forceDelete();
        $this->assertDatabaseMissing('business_hours', ['business_id' => $biz->id]);
        $this->assertDatabaseMissing('business_members', ['business_id' => $biz->id]);
    }
}
