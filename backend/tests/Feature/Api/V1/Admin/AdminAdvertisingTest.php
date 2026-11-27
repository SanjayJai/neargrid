<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminAdvertisingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $superRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->superAdmin->roles()->syncWithoutDetaching([$superRole->id]);
    }

    public function test_advertising_admin_endpoints_reject_unauthenticated_and_non_super_admin_users(): void
    {
        $this->getJson('/api/v1/admin/advertising/campaigns')->assertUnauthorized();
        $this->getJson('/api/v1/admin/advertising/creatives')->assertUnauthorized();

        $customer = User::factory()->create(['status' => 'active']);
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $customer->roles()->syncWithoutDetaching([$customerRole->id]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/advertising/campaigns')->assertForbidden();
        $this->getJson('/api/v1/admin/advertising/creatives')->assertForbidden();

        // Non-super-admin with audit_logs.view or support role must also be forbidden
        $auditor = User::factory()->create(['status' => 'active']);
        $supportRole = Role::query()->where('name', 'support')->first();
        if ($supportRole !== null) {
            $auditor->roles()->syncWithoutDetaching([$supportRole->id]);
        }
        $auditPerm = Permission::query()->where('name', 'audit_logs.view')->first();
        if ($auditPerm !== null && $supportRole !== null) {
            $supportRole->permissions()->syncWithoutDetaching([$auditPerm->id]);
        }

        Sanctum::actingAs($auditor);
        $this->getJson('/api/v1/admin/advertising/campaigns')->assertForbidden();
        $this->postJson('/api/v1/admin/advertising/campaigns', [
            'name' => 'Unauthorized',
            'campaign_type' => 'house_promo',
        ])->assertForbidden();
        $this->postJson('/api/v1/admin/advertising/creatives', [
            'ad_campaign_public_id' => '01J00000000000000000000000',
            'title' => 'Unauthorized',
            'destination_url' => '/chennai',
            'locale' => 'en',
        ])->assertForbidden();
    }

    public function test_campaign_lifecycle_approval_rejection_placement_and_org_business_isolation(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();
        $owner = User::factory()->create(['status' => 'active']);

        $orgA = Organization::query()->create([
            'name' => 'Org Alpha',
            'slug' => 'org-alpha',
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);
        $orgB = Organization::query()->create([
            'name' => 'Org Beta',
            'slug' => 'org-beta',
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);

        $bizA = Business::factory()->create([
            'organization_id' => $orgA->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
        ]);
        $bizB = Business::factory()->create([
            'organization_id' => $orgB->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
        ]);

        Sanctum::actingAs($this->superAdmin);

        $createRes = $this->postJson('/api/v1/admin/advertising/campaigns', [
            'name' => 'Alpha Launch Banner',
            'description' => 'City-wide launch campaign',
            'organization_public_id' => $orgA->public_id,
            'campaign_type' => 'sponsored_banner',
            'status' => 'pending_approval',
            'priority' => 80,
        ])->assertCreated();

        $campaignId = (string) $createRes->json('data.public_id');

        // Cannot directly activate unapproved campaign via status patch
        $this->patchJson("/api/v1/admin/advertising/campaigns/{$campaignId}/status", [
            'status' => 'active',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_LIFECYCLE_TRANSITION');

        // Rejection requires mandatory reason
        $this->postJson("/api/v1/admin/advertising/campaigns/{$campaignId}/reject", [
            'rejection_reason' => 'no',
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/advertising/campaigns/{$campaignId}/reject", [
            'rejection_reason' => 'Missing creative disclosure',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // Approve transitions rejected/pending campaign to active
        $this->postJson("/api/v1/admin/advertising/campaigns/{$campaignId}/approve", [
            'activate_immediately' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // Cross-org business isolation
        $this->putJson("/api/v1/admin/advertising/campaigns/{$campaignId}/businesses", [
            'business_public_ids' => [$bizB->public_id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORGANIZATION_ISOLATION_VIOLATION');

        $this->putJson("/api/v1/admin/advertising/campaigns/{$campaignId}/businesses", [
            'business_public_ids' => [$bizA->public_id],
        ])
            ->assertOk()
            ->assertJsonPath('data.business_count', 1);

        $placement = AdPlacement::query()->firstOrFail();
        $this->putJson("/api/v1/admin/advertising/campaigns/{$campaignId}/placements", [
            'placements' => [
                ['placement_public_id' => $placement->public_id, 'weight' => 150, 'is_active' => true],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.placement_count', 1);

        $this->assertTrue(AuditLog::query()->where('action', 'ad_campaign.created')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'ad_campaign.approved')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'ad_campaign.rejected')->exists());
    }

    public function test_creatives_localization_url_validation_and_image_signature_security(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->superAdmin);

        $campaignRes = $this->postJson('/api/v1/admin/advertising/campaigns', [
            'name' => 'Bilingual Promo',
            'campaign_type' => 'house_promo',
        ])->assertCreated();
        $campaignId = (string) $campaignRes->json('data.public_id');

        // Reject unsafe destination URL
        $this->postJson('/api/v1/admin/advertising/creatives', [
            'ad_campaign_public_id' => $campaignId,
            'title' => 'Bad Ad',
            'destination_url' => 'javascript:alert(1)',
            'locale' => 'en',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CREATIVE_DATA');

        // Reject spoofed PHP file uploaded as .png
        $spoofed = UploadedFile::fake()->createWithContent('evil.png', '<?php echo "hack"; ?>');
        $this->postJson('/api/v1/admin/advertising/creatives/upload-image', [
            'image' => $spoofed,
        ])->assertStatus(422);

        // Accept valid PNG image
        $validPng = UploadedFile::fake()->image('banner.png', 600, 200);
        $uploadRes = $this->postJson('/api/v1/admin/advertising/creatives/upload-image', [
            'image' => $validPng,
            'campaign_public_id' => $campaignId,
        ])->assertCreated();

        $imageUrl = (string) $uploadRes->json('data.image_url');

        // Create English & Tamil creatives
        $enCreativeRes = $this->postJson('/api/v1/admin/advertising/creatives', [
            'ad_campaign_public_id' => $campaignId,
            'title' => 'Top Clinics in Chennai',
            'cta_label' => 'Explore',
            'destination_url' => '/chennai',
            'image_url' => $imageUrl,
            'locale' => 'en',
        ])->assertCreated();

        $enCreativeId = (string) $enCreativeRes->json('data.public_id');

        $this->postJson('/api/v1/admin/advertising/creatives', [
            'ad_campaign_public_id' => $campaignId,
            'title' => 'சென்னை சிறந்த மருத்துவமனைகள்',
            'cta_label' => 'பார்க்கவும்',
            'destination_url' => '/chennai',
            'locale' => 'ta',
        ])->assertCreated();

        $this->getJson("/api/v1/admin/advertising/creatives?campaign_public_id={$campaignId}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Edit creative and reject unsafe URL on update
        $this->patchJson("/api/v1/admin/advertising/creatives/{$enCreativeId}", [
            'destination_url' => 'javascript:alert(2)',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CREATIVE_DATA');

        $this->patchJson("/api/v1/admin/advertising/creatives/{$enCreativeId}", [
            'title' => 'Top Clinics Updated',
            'status' => 'paused',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Top Clinics Updated')
            ->assertJsonPath('data.status', 'paused');

        // Archive creative and confirm soft-delete
        $this->deleteJson("/api/v1/admin/advertising/creatives/{$enCreativeId}")
            ->assertOk()
            ->assertJsonPath('data.archived', true);

        $this->patchJson("/api/v1/admin/advertising/creatives/{$enCreativeId}", [
            'title' => 'Should Not Update',
        ])->assertNotFound();

        $this->assertTrue(AuditLog::query()->where('action', 'ad_creative.updated')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'ad_creative.archived')->exists());

        // Reject protocol-relative destination URL
        $this->postJson('/api/v1/admin/advertising/creatives', [
            'ad_campaign_public_id' => $campaignId,
            'title' => 'Protocol Relative',
            'destination_url' => '//evil.example/phish',
            'locale' => 'en',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CREATIVE_DATA');

        // Reject path traversal in campaign_public_id on upload
        $this->postJson('/api/v1/admin/advertising/creatives/upload-image', [
            'image' => $validPng,
            'campaign_public_id' => '../../etc/passwd_1234567890',
        ])->assertStatus(422);
    }

    public function test_campaign_schedule_caps_and_invalid_lifecycle_transitions_are_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Reject ends_at <= starts_at
        $this->postJson('/api/v1/admin/advertising/campaigns', [
            'name' => 'Bad Schedule',
            'campaign_type' => 'house_promo',
            'starts_at' => '2026-12-10T10:00:00Z',
            'ends_at' => '2026-12-09T10:00:00Z',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_SCHEDULE');

        // Reject total_impression_cap < daily_impression_cap
        $this->postJson('/api/v1/admin/advertising/campaigns', [
            'name' => 'Bad Caps',
            'campaign_type' => 'house_promo',
            'daily_impression_cap' => 5000,
            'total_impression_cap' => 1000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_IMPRESSION_CAP');

        // Allow ends_at without starts_at
        $created = $this->postJson('/api/v1/admin/advertising/campaigns', [
            'name' => 'Open Start Campaign',
            'campaign_type' => 'house_promo',
            'ends_at' => '2026-12-31T23:59:59Z',
        ])->assertCreated();

        $campaignId = (string) $created->json('data.public_id');

        // Archive then verify cannot transition out of archived
        $this->deleteJson("/api/v1/admin/advertising/campaigns/{$campaignId}")->assertOk();
        $this->patchJson("/api/v1/admin/advertising/campaigns/{$campaignId}/status", [
            'status' => 'active',
        ])->assertNotFound(); // Soft-deleted
    }
}
