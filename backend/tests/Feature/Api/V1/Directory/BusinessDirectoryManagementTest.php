<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Directory;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Governance\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessDirectoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_owner_can_create_and_manage_multiple_businesses_with_audit_logs(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('web-bff', ['customer:read'])->plainTextToken;

        $payload = [
            'name' => 'Adyar Dental Clinic',
            'tagline' => 'Gentle family dentistry',
            'description' => 'Full-service dental practice offering preventive care, digital imaging, and orthodontics.',
            'primary_category_slug' => 'dentists',
            'secondary_category_slugs' => ['hospitals'],
            'city_slug' => 'chennai',
            'locality_slug' => 'adyar',
            'address_line_1' => '18, 2nd Main Road, Gandhi Nagar',
            'pincode' => '600020',
            'primary_phone_e164' => '+919840012345',
            'whatsapp_e164' => '+919840012345',
            'amenity_slugs' => ['air-conditioned', 'parking-available'],
            'payment_method_slugs' => ['upi', 'credit-card'],
            // Privileged fields injected by attacker must be strictly ignored
            'status' => 'active',
            'is_verified' => true,
            'is_trusted' => true,
        ];

        $firstRes = $this->withToken($token)
            ->postJson('/api/v1/owner/businesses', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.is_claimed', true)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.is_trusted', false)
            ->assertJsonPath('data.my_membership.is_owner', true)
            ->assertJsonPath('data.my_membership.role', 'owner');

        $firstPublicId = (string) $firstRes->json('data.public_id');
        $this->assertTrue(Str::isUlid($firstPublicId));
        $this->assertArrayNotHasKey('id', $firstRes->json('data'));
        $this->assertGreaterThanOrEqual(40, $firstRes->json('data.completeness_score'));
        $this->assertTrue($owner->fresh()->hasRole('business_owner'));

        // Create a second business owned by the same user
        $secondRes = $this->withToken($token)
            ->postJson('/api/v1/owner/businesses', array_merge($payload, [
                'name' => 'T Nagar Dental Annex',
                'locality_slug' => 't-nagar',
            ]))
            ->assertCreated();

        $this->assertNotSame($firstPublicId, $secondRes->json('data.public_id'));

        $listRes = $this->withToken($token)
            ->getJson('/api/v1/owner/businesses')
            ->assertOk();
        $this->assertCount(2, $listRes->json('data'));

        // Audit logs recorded for creation
        $this->assertGreaterThanOrEqual(
            2,
            AuditLog::query()->where('action', 'business.created')->where('actor_user_id', $owner->id)->count()
        );
    }

    public function test_hours_split_shifts_services_crud_and_301_slug_redirect(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('web-bff', ['customer:read'])->plainTextToken;

        $created = $this->withToken($token)->postJson('/api/v1/owner/businesses', [
            'name' => 'Marina Spice Kitchen',
            'primary_category_slug' => 'restaurants',
            'city_slug' => 'chennai',
            'locality_slug' => 't-nagar',
            'address_line_1' => '42 North Usman Road',
            'pincode' => '600017',
            'primary_phone_e164' => '+919840011122',
        ])->assertCreated();

        $publicId = (string) $created->json('data.public_id');
        $oldSlug = (string) $created->json('data.slug');

        // Activate business to verify SEO 301 redirect on slug update
        $business = Business::findByPublicId($publicId);
        $this->assertNotNull($business);
        $business->status = BusinessStatus::Active;
        $business->save();

        // Update opening hours with split shift & midnight-crossing shift
        $this->withToken($token)
            ->putJson("/api/v1/owner/businesses/{$publicId}/hours", [
                'hours' => [
                    ['day_of_week' => 1, 'slot_index' => 1, 'open_time' => '09:00', 'close_time' => '13:00'],
                    ['day_of_week' => 1, 'slot_index' => 2, 'open_time' => '18:00', 'close_time' => '02:00'],
                    ['day_of_week' => 0, 'slot_index' => 1, 'is_closed' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data.hours');

        $business->refresh()->load(['hours', 'city']);
        // Monday 10:00 IST -> open
        $this->assertTrue($business->isOpenNow(CarbonImmutable::parse('2026-11-16 10:00:00', 'Asia/Kolkata')));
        // Monday 15:00 IST (between split shifts) -> closed
        $this->assertFalse($business->isOpenNow(CarbonImmutable::parse('2026-11-16 15:00:00', 'Asia/Kolkata')));
        // Tuesday 01:00 IST (midnight rollover from Monday 18:00-02:00) -> open
        $this->assertTrue($business->isOpenNow(CarbonImmutable::parse('2026-11-17 01:00:00', 'Asia/Kolkata')));

        // Services CRUD
        $srv = $this->withToken($token)
            ->postJson("/api/v1/owner/businesses/{$publicId}/services", [
                'name' => 'Banana Leaf Meal',
                'price_min_minor' => 32000,
                'price_max_minor' => 45000,
                'currency' => 'INR',
            ])
            ->assertCreated();
        $servicePublicId = (string) $srv->json('data.public_id');

        $this->withToken($token)
            ->putJson("/api/v1/owner/businesses/{$publicId}/services/{$servicePublicId}", [
                'name' => 'Signature Banana Leaf Meal',
                'price_min_minor' => 35000,
                'price_max_minor' => 48000,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Signature Banana Leaf Meal');

        $this->withToken($token)
            ->deleteJson("/api/v1/owner/businesses/{$publicId}/services/{$servicePublicId}")
            ->assertNoContent();

        // Rename slug -> creates 301 redirect and old public URL responds 301
        $this->withToken($token)
            ->putJson("/api/v1/owner/businesses/{$publicId}", [
                'slug' => 'marina-coastal-kitchen-t-nagar',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'marina-coastal-kitchen-t-nagar');

        $this->assertDatabaseHas('url_redirects', [
            'source_path' => "/chennai/business/{$oldSlug}",
            'target_path' => '/chennai/business/marina-coastal-kitchen-t-nagar',
            'http_status' => 301,
        ]);

        $this->getJson("/api/v1/businesses/chennai/{$oldSlug}")
            ->assertStatus(301)
            ->assertHeader('Location', '/chennai/business/marina-coastal-kitchen-t-nagar');
    }

    public function test_staff_membership_roles_permissions_and_revocation(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $leadAgent = User::factory()->create();
        $manager = User::factory()->create();

        $ownerToken = $owner->createToken('owner-web', ['*'])->plainTextToken;

        $created = $this->withToken($ownerToken)->postJson('/api/v1/owner/businesses', [
            'name' => 'Velachery Auto Hub',
            'primary_category_slug' => 'car-repair-and-service',
            'city_slug' => 'chennai',
            'locality_slug' => 'velachery',
            'address_line_1' => '7 Bypass Road',
            'pincode' => '600042',
            'primary_phone_e164' => '+919840033344',
        ])->assertCreated();

        $bizId = (string) $created->json('data.public_id');

        // Owner adds editor, lead_agent, and manager
        $editorMember = $this->withToken($ownerToken)->postJson("/api/v1/owner/businesses/{$bizId}/members", [
            'user_public_id' => $editor->public_id,
            'role' => 'editor',
        ])->assertCreated();
        $editorMemberId = (string) $editorMember->json('data.public_id');

        $this->withToken($ownerToken)->postJson("/api/v1/owner/businesses/{$bizId}/members", [
            'user_public_id' => $leadAgent->public_id,
            'role' => 'lead_agent',
        ])->assertCreated();

        $this->withToken($ownerToken)->postJson("/api/v1/owner/businesses/{$bizId}/members", [
            'user_public_id' => $manager->public_id,
            'role' => 'manager',
        ])->assertCreated();

        // Cannot assign owner role via staff endpoint
        $this->withToken($ownerToken)->postJson("/api/v1/owner/businesses/{$bizId}/members", [
            'user_public_id' => $editor->public_id,
            'role' => 'owner',
        ])->assertUnprocessable();

        // Editor: CAN edit profile & services, CANNOT submit verification or manage staff
        $editorToken = $editor->createToken('editor-web', ['*'])->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($editorToken)
            ->putJson("/api/v1/owner/businesses/{$bizId}", ['tagline' => 'Updated by editor'])
            ->assertOk();

        $this->withToken($editorToken)
            ->postJson("/api/v1/owner/businesses/{$bizId}/verifications", [
                'document_type' => 'gstin',
                'document_number' => '33ABCDE1234F1Z5',
                'private_file_path' => 'kyc/test.pdf',
            ])
            ->assertForbidden();

        $this->withToken($editorToken)
            ->getJson("/api/v1/owner/businesses/{$bizId}/members")
            ->assertForbidden();

        // LeadAgent: CAN view business, CANNOT edit profile
        $agentToken = $leadAgent->createToken('agent-web', ['*'])->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($agentToken)->getJson("/api/v1/owner/businesses/{$bizId}")->assertOk();
        $this->withToken($agentToken)
            ->putJson("/api/v1/owner/businesses/{$bizId}", ['tagline' => 'Should fail'])
            ->assertForbidden();

        // Manager: CAN submit verification, CANNOT manage staff
        $managerToken = $manager->createToken('manager-web', ['*'])->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($managerToken)
            ->postJson("/api/v1/owner/businesses/{$bizId}/verifications", [
                'document_type' => 'gstin',
                'document_number' => '33ABCDE1234F1Z5',
                'private_file_path' => 'kyc/gstin-doc.pdf',
            ])
            ->assertCreated();
        $this->withToken($managerToken)->getJson("/api/v1/owner/businesses/{$bizId}/members")->assertForbidden();

        // Owner revokes editor -> editor immediately loses access
        auth()->forgetGuards();
        $this->withToken($ownerToken)
            ->patchJson("/api/v1/owner/businesses/{$bizId}/members/{$editorMemberId}", [
                'role' => 'editor',
                'status' => 'revoked',
            ])
            ->assertOk();

        auth()->forgetGuards();
        $this->withToken($editorToken)->getJson("/api/v1/owner/businesses/{$bizId}")->assertForbidden();

        // Owner cannot delete their own owner membership row
        $ownerMembership = BusinessMember::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();
        auth()->forgetGuards();
        $this->withToken($ownerToken)
            ->deleteJson("/api/v1/owner/businesses/{$bizId}/members/{$ownerMembership->public_id}")
            ->assertForbidden();
    }

    public function test_cross_business_idor_is_blocked_independently_of_wildcard_token_abilities(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $tokenA = $ownerA->createToken('owner-a', ['*'])->plainTextToken;
        $tokenB = $ownerB->createToken('owner-b', ['*'])->plainTextToken;

        $bizA = $this->withToken($tokenA)->postJson('/api/v1/owner/businesses', [
            'name' => 'Clinic Alpha',
            'primary_category_slug' => 'dentists',
            'city_slug' => 'chennai',
            'locality_slug' => 'adyar',
            'address_line_1' => '1 Alpha Street',
            'pincode' => '600020',
            'primary_phone_e164' => '+919840055511',
        ])->assertCreated();

        $bizAPublicId = (string) $bizA->json('data.public_id');
        $serviceA = $this->withToken($tokenA)->postJson("/api/v1/owner/businesses/{$bizAPublicId}/services", [
            'name' => 'Alpha Service',
            'price_min_minor' => 10000,
        ])->assertCreated();
        $serviceAPublicId = (string) $serviceA->json('data.public_id');

        $bizB = $this->withToken($tokenB)->postJson('/api/v1/owner/businesses', [
            'name' => 'Clinic Beta',
            'primary_category_slug' => 'dentists',
            'city_slug' => 'chennai',
            'locality_slug' => 'adyar',
            'address_line_1' => '2 Beta Street',
            'pincode' => '600020',
            'primary_phone_e164' => '+919840055522',
        ])->assertCreated();
        $bizBPublicId = (string) $bizB->json('data.public_id');

        // Owner B with wildcard token attempts cross-business IDOR against Business A
        auth()->forgetGuards();
        $this->withToken($tokenB)->getJson("/api/v1/owner/businesses/{$bizAPublicId}")->assertForbidden();
        $this->withToken($tokenB)->putJson("/api/v1/owner/businesses/{$bizAPublicId}", ['name' => 'Hijacked'])->assertForbidden();
        $this->withToken($tokenB)->putJson("/api/v1/owner/businesses/{$bizAPublicId}/hours", [
            'hours' => [['day_of_week' => 1, 'is_closed' => true]],
        ])->assertForbidden();
        $this->withToken($tokenB)->getJson("/api/v1/owner/businesses/{$bizAPublicId}/members")->assertForbidden();
        $this->withToken($tokenB)->getJson("/api/v1/owner/businesses/{$bizAPublicId}/verifications")->assertForbidden();

        // Cross-parent service IDOR: Owner B passes Business A's service ULID under Business B's route
        $this->withToken($tokenB)
            ->deleteJson("/api/v1/owner/businesses/{$bizBPublicId}/services/{$serviceAPublicId}")
            ->assertForbidden();

        $this->assertDatabaseHas('business_services', ['public_id' => $serviceAPublicId]);
        $this->assertSame('Clinic Alpha', Business::findByPublicId($bizAPublicId)?->name);
    }

    public function test_reference_locations_categories_and_attributes_support_english_and_tamil(): void
    {
        $this->getJson('/api/v1/locations/cities?locale=ta')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'chennai', 'name' => 'சென்னை']);

        $this->getJson('/api/v1/locations/cities/chennai/localities?locale=ta')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'adyar', 'name' => 'அடையாறு']);

        $this->getJson('/api/v1/categories?locale=ta')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'dentists', 'name' => 'பல் மருத்துவர்கள்']);

        $this->getJson('/api/v1/attributes')
            ->assertOk()
            ->assertJsonStructure(['data' => ['amenities', 'payment_methods']]);
    }
}
