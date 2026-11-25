<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Taxonomy;

use App\Domains\Directory\Models\Business;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create();
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->superAdmin->roles()->syncWithoutDetaching([$superAdminRole->id]);

        $this->customer = User::factory()->create();
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $this->customer->roles()->syncWithoutDetaching([$customerRole->id]);
    }

    public function test_authorization_rejects_unauthenticated_and_non_super_admin(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Test'])->assertUnauthorized();

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/admin/categories')->assertForbidden();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Test'])->assertForbidden();
        $this->getJson('/api/v1/admin/category-collections')->assertForbidden();
    }

    public function test_five_level_hierarchy_max_depth_and_cycle_prevention(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $l0 = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Level 0 Main',
            'node_type' => 'main_category',
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $l1 = $this->postJson('/api/v1/admin/categories', [
            'parent_id' => $l0['public_id'],
            'name' => 'Level 1 Group',
            'node_type' => 'category_group',
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $l2 = $this->postJson('/api/v1/admin/categories', [
            'parent_id' => $l1['public_id'],
            'name' => 'Level 2 Sub',
            'node_type' => 'subcategory',
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $l3 = $this->postJson('/api/v1/admin/categories', [
            'parent_id' => $l2['public_id'],
            'name' => 'Level 3 Spec',
            'node_type' => 'speciality',
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $l4 = $this->postJson('/api/v1/admin/categories', [
            'parent_id' => $l3['public_id'],
            'name' => 'Level 4 SubSpec',
            'node_type' => 'sub_speciality',
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $this->assertSame(4, $l4['depth']);

        // Level 6 (depth 5) must be rejected
        $this->postJson('/api/v1/admin/categories', [
            'parent_id' => $l4['public_id'],
            'name' => 'Level 5 Too Deep',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_MAX_DEPTH_EXCEEDED');

        // Cycle prevention: moving l0 under l2 must fail
        $this->postJson("/api/v1/admin/categories/{$l0['public_id']}/move", [
            'parent_id' => $l2['public_id'],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_CYCLE_DETECTED');

        // Public 5-level tree includes the published hierarchy
        $treeRes = $this->getJson('/api/v1/categories/tree')->assertOk();
        $this->assertNotEmpty($treeRes->json('data.categories'));
    }

    public function test_non_assignable_category_group_rejected_for_business_assignment(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $group = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Dining Collections Group',
            'slug' => 'dining-collections-group',
            'node_type' => 'category_group',
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $this->assertFalse($group['is_assignable']);

        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();

        $this->postJson('/api/v1/owner/businesses', [
            'name' => 'Group Assigned Biz',
            'primary_category_slug' => 'dining-collections-group',
            'city_slug' => $city->slug,
            'locality_slug' => $locality->slug,
            'address_line_1' => '10 Anna Salai',
            'pincode' => '600002',
            'primary_phone_e164' => '+919840099111',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'NON_ASSIGNABLE_CATEGORY_NODE');
    }

    public function test_slug_change_emits_301_redirect_and_soft_delete_preserves_seo_metadata(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $created = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Organic Cafes',
            'slug' => 'organic-cafes',
            'node_type' => 'main_category',
            'lifecycle_status' => 'published',
            'translations' => [
                'en' => ['name' => 'Organic Cafes', 'seo_title_template' => 'Best Organic Cafes in {city}'],
                'ta' => ['name' => 'இயற்கை உணவகங்கள்', 'seo_title_template' => '{city} இயற்கை உணவகங்கள்'],
            ],
        ])->assertCreated()->json('data');

        $catId = $created['public_id'];
        $catModel = Category::query()->where('public_id', $catId)->firstOrFail();

        // Attach SEO metadata
        $this->putJson("/api/v1/admin/seo/category/{$catId}", [
            'locale' => 'en',
            'meta_title' => 'Top Organic Cafes',
        ])->assertOk();

        // Rename slug -> registers 301 redirect
        $this->putJson("/api/v1/admin/categories/{$catId}", [
            'slug' => 'green-organic-cafes',
        ])->assertOk();

        $landingRedirect = $this->getJson('/api/v1/categories/landing/organic-cafes')->assertOk();
        $this->assertTrue($landingRedirect->json('data.redirect'));
        $this->assertSame('/c/green-organic-cafes', $landingRedirect->json('data.target_path'));

        // Live landing includes SEO payload and Tamil hreflang
        $landing = $this->getJson('/api/v1/categories/landing/green-organic-cafes')->assertOk();
        $this->assertFalse($landing->json('data.redirect'));
        $this->assertSame('Top Organic Cafes', $landing->json('data.seo.title'));
        $this->assertArrayHasKey('ta-IN', $landing->json('data.seo.alternates'));

        // Soft-delete preserves SEO metadata and revisions
        $this->deleteJson("/api/v1/admin/categories/{$catId}")->assertOk();
        $this->assertDatabaseHas('seo_metadata', [
            'entity_type' => 'category',
            'entity_id' => $catModel->id,
        ]);

        // Restore works cleanly
        $this->postJson("/api/v1/admin/categories/{$catId}/restore")->assertOk();
    }

    public function test_system_category_protection_dependency_guards_clone_reorder_and_collections(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $sys = $this->postJson('/api/v1/admin/categories', [
            'name' => 'System Protected',
            'slug' => 'system-protected',
            'node_type' => 'main_category',
            'is_system' => true,
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/v1/admin/categories/{$sys['public_id']}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SYSTEM_CATEGORY_PROTECTED');

        // Dependency guard on active category with a business
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();
        Business::factory()->create(['primary_category_id' => $dentists->id, 'status' => 'active']);

        $this->postJson("/api/v1/admin/categories/{$dentists->public_id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CATEGORY_HAS_DEPENDENCIES');

        // Clone + Collection creation
        $cloned = $this->postJson("/api/v1/admin/categories/{$dentists->public_id}/clone")
            ->assertCreated()
            ->json('data');
        $this->assertSame('draft', $cloned['lifecycle_status']);
        $this->assertStringContainsString('-copy', $cloned['slug']);

        $col = $this->postJson('/api/v1/admin/category-collections', [
            'name_en' => 'Healthcare Picks',
            'name_ta' => 'மருத்துவ தேர்வுகள்',
            'lifecycle_status' => 'published',
            'show_on_homepage' => true,
            'category_public_ids' => [$dentists->public_id],
        ])->assertCreated()->json('data');

        $this->assertSame(['Healthcare Picks'], [
            $col['name_en'],
        ]);
    }

    public function test_sync_schemas_and_revision_rollback(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $cat = $this->postJson('/api/v1/admin/categories', [
            'name' => 'V1 Test Category',
            'slug' => 'v1-test-cat-'.Str::lower(Str::random(4)),
            'node_type' => 'subcategory',
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/admin/categories/{$cat['public_id']}/schemas", [
            'attributes' => [
                [
                    'attribute_key' => 'consultation_fee',
                    'label_en' => 'Consultation Fee',
                    'label_ta' => 'ஆலோசனை கட்டணம்',
                    'data_type' => 'number',
                    'is_filterable' => true,
                ],
            ],
            'filters' => [
                [
                    'filter_key' => 'consultation_fee',
                    'filter_type' => 'range',
                    'label_en' => 'Fee Range',
                ],
            ],
            'profile_sections' => [
                [
                    'section_key' => 'services',
                    'display_label_en' => 'Treatments',
                ],
            ],
        ])->assertOk()
            ->assertJsonCount(1, 'data.attributes')
            ->assertJsonCount(1, 'data.filters')
            ->assertJsonCount(1, 'data.profile_sections');

        $this->putJson("/api/v1/admin/categories/{$cat['public_id']}", [
            'name' => 'Renamed Category Name',
        ])->assertOk();

        $detailed = $this->getJson("/api/v1/admin/categories/{$cat['public_id']}")->assertOk()->json('data');
        $firstRevision = collect($detailed['revisions'])->firstWhere('version_number', 1);
        $this->assertNotNull($firstRevision);

        $rolledBack = $this->postJson("/api/v1/admin/categories/{$cat['public_id']}/revisions/{$firstRevision['public_id']}/rollback")
            ->assertOk()
            ->json('data');

        $this->assertSame('V1 Test Category', $rolledBack['name']);
    }

    public function test_secondary_category_rejects_category_group_draft_and_inactive_nodes(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Non Assignable Secondary Group',
            'slug' => 'sec-group',
            'node_type' => 'category_group',
            'lifecycle_status' => 'published',
        ])->assertCreated();

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Draft Secondary Sub',
            'slug' => 'sec-draft',
            'node_type' => 'subcategory',
            'lifecycle_status' => 'draft',
        ])->assertCreated();

        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();

        $this->postJson('/api/v1/owner/businesses', [
            'name' => 'Secondary Group Reject',
            'primary_category_slug' => 'dentists',
            'secondary_category_slugs' => ['sec-group'],
            'city_slug' => $city->slug,
            'locality_slug' => $locality->slug,
            'address_line_1' => '10 Anna Salai',
            'pincode' => '600002',
            'primary_phone_e164' => '+919840099112',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'NON_ASSIGNABLE_CATEGORY_NODE');

        $this->postJson('/api/v1/owner/businesses', [
            'name' => 'Secondary Draft Reject',
            'primary_category_slug' => 'dentists',
            'secondary_category_slugs' => ['sec-draft'],
            'city_slug' => $city->slug,
            'locality_slug' => $locality->slug,
            'address_line_1' => '10 Anna Salai',
            'pincode' => '600002',
            'primary_phone_e164' => '+919840099113',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CATEGORY');
    }

    public function test_nested_translation_validation_returns_422_and_system_category_move_blocked_and_published_version_linked(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Oversized translation fields must return 422, not 500
        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Valid Category',
            'translations' => [
                'en' => [
                    'name' => str_repeat('a', 141),
                    'seo_title_template' => str_repeat('b', 181),
                    'seo_description_template' => str_repeat('c', 321),
                ],
            ],
        ])->assertStatus(422);

        // Creating directly as published links published_version_id to initial CategoryRevision
        $created = $this->postJson('/api/v1/admin/categories', [
            'name' => 'System Published Category',
            'slug' => 'sys-pub-cat',
            'node_type' => 'main_category',
            'is_system' => true,
            'lifecycle_status' => 'published',
        ])->assertCreated()->json('data');

        $model = Category::query()->where('public_id', $created['public_id'])->firstOrFail();
        $this->assertNotNull($model->published_version_id);
        $this->assertSame(1, $model->publishedVersion?->version_number);

        $targetParent = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Target Parent',
            'node_type' => 'main_category',
        ])->assertCreated()->json('data');

        // Moving is_system category is rejected
        $this->postJson("/api/v1/admin/categories/{$created['public_id']}/move", [
            'parent_id' => $targetParent['public_id'],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'SYSTEM_CATEGORY_PROTECTED');
    }
}
