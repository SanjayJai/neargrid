<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Directory;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessAttributeValue;
use App\Domains\Directory\Models\Organization;
use App\Domains\Directory\Models\OrganizationMember;
use App\Domains\Directory\Services\AttributeTypeRegistry;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Search\DTOs\FilterQuery;
use App\Domains\Search\Services\FilterOptionsService;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class BusinessAttributesTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $owner;

    private User $otherOwner;

    private City $city;

    private Locality $locality;

    private Category $clinicCategory;

    private Category $diagnosticCategory;

    private Category $restaurantCategory;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create();
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->superAdmin->roles()->syncWithoutDetaching([$superAdminRole->id]);

        $this->owner = User::factory()->create();
        $ownerRole = Role::query()->where('name', 'business_owner')->firstOrFail();
        $this->owner->roles()->syncWithoutDetaching([$ownerRole->id]);

        $this->otherOwner = User::factory()->create();
        $this->otherOwner->roles()->syncWithoutDetaching([$ownerRole->id]);

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->locality = Locality::query()->where('city_id', $this->city->id)->firstOrFail();

        $this->clinicCategory = Category::query()->create([
            'name' => 'Clinics',
            'slug' => 'clinics-attr-test',
            'node_type' => 'subcategory',
            'depth' => 1,
            'lifecycle_status' => 'published',
            'is_assignable' => true,
            'is_active' => true,
        ]);

        $this->diagnosticCategory = Category::query()->create([
            'name' => 'Diagnostics',
            'slug' => 'diagnostics-attr-test',
            'node_type' => 'subcategory',
            'depth' => 1,
            'lifecycle_status' => 'published',
            'is_assignable' => true,
            'is_active' => true,
        ]);

        $this->restaurantCategory = Category::query()->create([
            'name' => 'Restaurants',
            'slug' => 'restaurants-attr-test',
            'node_type' => 'subcategory',
            'depth' => 1,
            'lifecycle_status' => 'published',
            'is_assignable' => true,
            'is_active' => true,
        ]);

        $this->business = Business::factory()->create([
            'owner_user_id' => $this->owner->id,
            'primary_category_id' => $this->clinicCategory->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'status' => BusinessStatus::Active,
        ]);
        $this->business->categories()->sync([
            $this->clinicCategory->id => ['is_primary' => true],
            $this->diagnosticCategory->id => ['is_primary' => false],
        ]);
    }

    public function test_admin_sync_schemas_validates_section_key_preserves_values_and_invalidates_filter_cache(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Invalid section_key reference must fail with 422
        $this->putJson("/api/v1/admin/categories/{$this->clinicCategory->public_id}/schemas", [
            'profile_sections' => [
                ['section_key' => 'clinic_specs', 'display_label_en' => 'Clinic Specifications'],
            ],
            'attributes' => [
                [
                    'attribute_key' => 'emergency_24x7',
                    'section_key' => 'non_existent_section',
                    'label_en' => '24x7 Emergency',
                    'data_type' => 'boolean',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_SECTION_KEY_REFERENCE');

        // Prime filter cache for clinicCategory
        $filterService = app(FilterOptionsService::class);
        $filterService->getFilters(new FilterQuery(locale: 'en', citySlug: 'chennai', categorySlug: $this->clinicCategory->slug));
        $storeName = (string) config('search.autocomplete.cache_store', config('cache.default', 'array'));
        $versionBefore = (int) Cache::store($storeName)->get("search:filters:cat_ver:{$this->clinicCategory->id}", 1);

        // Valid schema sync
        $this->putJson("/api/v1/admin/categories/{$this->clinicCategory->public_id}/schemas", [
            'profile_sections' => [
                ['section_key' => 'clinic_specs', 'display_label_en' => 'Clinic Specifications', 'sort_order' => 1],
            ],
            'attributes' => [
                [
                    'attribute_key' => 'emergency_24x7',
                    'section_key' => 'clinic_specs',
                    'label_en' => '24x7 Emergency',
                    'data_type' => 'boolean',
                    'is_filterable' => true,
                ],
                [
                    'attribute_key' => 'consultation_fee',
                    'section_key' => 'clinic_specs',
                    'label_en' => 'Consultation Fee',
                    'data_type' => 'number',
                    'unit' => 'INR',
                    'options_json' => ['min' => 0, 'max' => 10000],
                ],
            ],
        ])->assertOk();

        $versionAfter = (int) Cache::store($storeName)->get("search:filters:cat_ver:{$this->clinicCategory->id}", 1);
        $this->assertGreaterThan($versionBefore, $versionAfter);

        // Save an owner value
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'emergency_24x7', 'value' => true],
                ['attribute_key' => 'consultation_fee', 'value' => 500],
            ],
        ])->assertOk();

        // Re-sync schemas removing consultation_fee: value must be preserved privately (soft-deactivated), not cascade-deleted
        Sanctum::actingAs($this->superAdmin);
        $this->putJson("/api/v1/admin/categories/{$this->clinicCategory->public_id}/schemas", [
            'profile_sections' => [
                ['section_key' => 'clinic_specs', 'display_label_en' => 'Clinic Specifications'],
            ],
            'attributes' => [
                [
                    'attribute_key' => 'emergency_24x7',
                    'section_key' => 'clinic_specs',
                    'label_en' => '24x7 Emergency (Updated)',
                    'data_type' => 'boolean',
                ],
            ],
        ])->assertOk();

        $this->assertSame(2, BusinessAttributeValue::query()->where('business_id', $this->business->id)->count());
        $deactivatedDef = CategoryAttributeDefinition::query()
            ->where('category_id', $this->clinicCategory->id)
            ->where('attribute_key', 'consultation_fee')
            ->firstOrFail();
        $this->assertFalse($deactivatedDef->is_active);
    }

    public function test_typed_value_consistency_and_multi_enum_json_array_storage(): void
    {
        $this->seedClinicAndDiagnosticSchemas();

        Sanctum::actingAs($this->owner);
        $res = $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'registration_no', 'value' => 'TN-MED-2026'],
                ['attribute_key' => 'bed_count', 'value' => 24],
                ['attribute_key' => 'emergency_24x7', 'value' => true],
                ['attribute_key' => 'hospital_tier', 'value' => 'multi_speciality'],
                ['attribute_key' => 'specialities', 'value' => ['cardiology', 'orthopedics', 'cardiology']],
                // Secondary category attribute must also be allowed
                ['attribute_key' => 'home_sample_collection', 'value' => true],
            ],
        ])->assertOk();

        $res->assertJsonPath('data.business_public_id', $this->business->public_id);

        // Verify strict single-typed-column invariant in DB
        $rows = DB::table('business_attribute_values')
            ->join('category_attribute_definitions', 'category_attribute_definitions.id', '=', 'business_attribute_values.attribute_definition_id')
            ->where('business_attribute_values.business_id', $this->business->id)
            ->select([
                'category_attribute_definitions.attribute_key',
                'business_attribute_values.value_string',
                'business_attribute_values.value_number',
                'business_attribute_values.value_boolean',
                'business_attribute_values.value_json',
            ])
            ->get()
            ->keyBy('attribute_key');

        // string
        $this->assertSame('TN-MED-2026', $rows['registration_no']->value_string);
        $this->assertNull($rows['registration_no']->value_number);
        $this->assertNull($rows['registration_no']->value_boolean);
        $this->assertNull($rows['registration_no']->value_json);

        // number
        $this->assertNull($rows['bed_count']->value_string);
        $this->assertSame(24.0, (float) $rows['bed_count']->value_number);
        $this->assertNull($rows['bed_count']->value_boolean);
        $this->assertNull($rows['bed_count']->value_json);

        // boolean
        $this->assertNull($rows['emergency_24x7']->value_string);
        $this->assertNull($rows['emergency_24x7']->value_number);
        $this->assertSame(1, (int) $rows['emergency_24x7']->value_boolean);
        $this->assertNull($rows['emergency_24x7']->value_json);

        // enum
        $this->assertSame('multi_speciality', $rows['hospital_tier']->value_string);
        $this->assertNull($rows['hospital_tier']->value_number);
        $this->assertNull($rows['hospital_tier']->value_boolean);
        $this->assertNull($rows['hospital_tier']->value_json);

        // multi_enum (one row, JSON array deduplicated)
        $this->assertNull($rows['specialities']->value_string);
        $this->assertNull($rows['specialities']->value_number);
        $this->assertNull($rows['specialities']->value_boolean);
        $this->assertSame(['cardiology', 'orthopedics'], json_decode((string) $rows['specialities']->value_json, true));

        // Audit log recorded
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'business.attributes_updated')
                ->where('auditable_id', $this->business->id)
                ->exists()
        );
    }

    public function test_validation_rejects_mismatched_types_disallowed_categories_and_inverted_ranges(): void
    {
        $this->seedClinicAndDiagnosticSchemas();

        // Restaurant attribute belongs to an unattached category
        CategoryAttributeDefinition::query()->create([
            'category_id' => $this->restaurantCategory->id,
            'attribute_key' => 'seating_capacity',
            'label_en' => 'Seating Capacity',
            'data_type' => 'number',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->owner);

        // Unattached category attribute rejected
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'seating_capacity', 'value' => 50],
            ],
        ])->assertStatus(422);

        // Wrong type for boolean
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'emergency_24x7', 'value' => 'yes'],
            ],
        ])->assertStatus(422);

        // Out-of-bounds number
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'bed_count', 'value' => 50000],
            ],
        ])->assertStatus(422);

        // Invalid enum choice
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'hospital_tier', 'value' => 'invalid_tier'],
            ],
        ])->assertStatus(422);

        // Range bounds validation
        $registry = app(AttributeTypeRegistry::class);
        $this->expectException(ValidationException::class);
        $registry->validateRangeFilterBounds(500, 100);
    }

    public function test_partial_saves_and_explicit_required_publish_validation(): void
    {
        $this->seedClinicAndDiagnosticSchemas();
        Sanctum::actingAs($this->owner);

        // Partial save of optional attribute succeeds even though required 'registration_no' is missing
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'emergency_24x7', 'value' => true],
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_complete_for_publish', false)
            ->assertJsonPath('data.missing_required.0.attribute_key', 'registration_no');

        // Explicit publish validation fails with 422
        $this->postJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes/validate")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['required_attributes.registration_no']]]);

        // Save required attribute with validate_required=true succeeds and preserves previous partial value
        $res = $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'validate_required' => true,
            'attributes' => [
                ['attribute_key' => 'registration_no', 'value' => 'TN-REG-99'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_complete_for_publish', true);

        $attrs = collect($res->json('data.attributes'))->keyBy('attribute_key');
        $this->assertTrue($attrs['emergency_24x7']['value']);
        $this->assertSame('TN-REG-99', $attrs['registration_no']['value']);

        // Clearing optional attribute via value: null
        $cleared = $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'emergency_24x7', 'value' => null],
            ],
        ])->assertOk();

        $attrsAfterClear = collect($cleared->json('data.attributes'))->keyBy('attribute_key');
        $this->assertNull($attrsAfterClear['emergency_24x7']['value']);
    }

    public function test_orphaned_values_preserved_after_category_change_and_explicitly_clearable(): void
    {
        $this->seedClinicAndDiagnosticSchemas();
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'registration_no', 'value' => 'TN-OLD-1'],
                ['attribute_key' => 'bed_count', 'value' => 12],
            ],
        ])->assertOk();

        // Change business primary category to Restaurant (and remove clinic/diagnostic)
        $this->business->primary_category_id = $this->restaurantCategory->id;
        $this->business->save();
        $this->business->categories()->sync([$this->restaurantCategory->id => ['is_primary' => true]]);

        $getRes = $this->getJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes")
            ->assertOk();

        $orphans = $getRes->json('data.orphaned_values');
        $this->assertCount(2, $orphans);
        $this->assertSame('category_changed', $orphans[0]['reason']);

        // Clear one orphan by public_id
        $firstOrphanId = $orphans[0]['public_id'];
        $this->deleteJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes/orphans", [
            'value_public_ids' => [$firstOrphanId],
        ])->assertOk()
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonCount(1, 'data.attributes.orphaned_values');

        // Clear remaining orphans
        $this->deleteJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes/orphans")
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonCount(0, 'data.attributes.orphaned_values');
    }

    public function test_authorization_enforces_owner_and_organization_branch_isolation(): void
    {
        $this->seedClinicAndDiagnosticSchemas();

        // Unauthenticated -> 401 (clear superAdmin guard set by seedClinicAndDiagnosticSchemas)
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes")
            ->assertUnauthorized();

        // Other owner -> 403
        Sanctum::actingAs($this->otherOwner);
        $this->getJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes")
            ->assertForbidden();
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [['attribute_key' => 'emergency_24x7', 'value' => true]],
        ])->assertForbidden();

        // Organization corporate_admin authorized for company_owned branch via M-A2 OrganizationAccessPolicy
        $org = Organization::query()->create([
            'name' => 'Care Group',
            'slug' => 'care-group-attr-test',
            'owner_user_id' => $this->owner->id,
            'organization_type' => 'multi_branch',
            'status' => 'active',
        ]);
        $this->business->organization_id = $org->id;
        $this->business->branch_type = 'company_owned';
        $this->business->save();

        $corporateAdmin = User::factory()->create(['status' => 'active']);
        OrganizationMember::query()->create([
            'organization_id' => $org->id,
            'user_id' => $corporateAdmin->id,
            'role' => 'corporate_admin',
            'scope_type' => 'all_branches',
            'status' => 'active',
        ]);

        Sanctum::actingAs($corporateAdmin);
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [['attribute_key' => 'emergency_24x7', 'value' => false]],
        ])->assertOk();

        // Franchise branch blocks corporate_admin profile/attribute edits
        $this->business->branch_type = 'franchise';
        $this->business->save();
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [['attribute_key' => 'emergency_24x7', 'value' => true]],
        ])->assertForbidden();
    }

    private function seedClinicAndDiagnosticSchemas(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/v1/admin/categories/{$this->clinicCategory->public_id}/schemas", [
            'profile_sections' => [
                ['section_key' => 'clinic_specs', 'display_label_en' => 'Clinic Specifications', 'sort_order' => 1],
            ],
            'attributes' => [
                [
                    'attribute_key' => 'registration_no',
                    'section_key' => 'clinic_specs',
                    'label_en' => 'Medical Registration No',
                    'data_type' => 'string',
                    'is_required' => true,
                    'sort_order' => 1,
                ],
                [
                    'attribute_key' => 'bed_count',
                    'section_key' => 'clinic_specs',
                    'label_en' => 'Bed Count',
                    'data_type' => 'number',
                    'options_json' => ['min' => 1, 'max' => 2000, 'integer_only' => true],
                    'sort_order' => 2,
                ],
                [
                    'attribute_key' => 'emergency_24x7',
                    'section_key' => 'clinic_specs',
                    'label_en' => '24x7 Emergency',
                    'data_type' => 'boolean',
                    'is_filterable' => true,
                    'sort_order' => 3,
                ],
                [
                    'attribute_key' => 'hospital_tier',
                    'section_key' => 'clinic_specs',
                    'label_en' => 'Hospital Tier',
                    'data_type' => 'enum',
                    'options_json' => ['choices' => ['clinic', 'multi_speciality', 'super_speciality']],
                    'sort_order' => 4,
                ],
                [
                    'attribute_key' => 'specialities',
                    'section_key' => 'clinic_specs',
                    'label_en' => 'Specialities',
                    'data_type' => 'multi_enum',
                    'options_json' => ['choices' => ['cardiology', 'orthopedics', 'pediatrics']],
                    'sort_order' => 5,
                ],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/admin/categories/{$this->diagnosticCategory->public_id}/schemas", [
            'attributes' => [
                [
                    'attribute_key' => 'home_sample_collection',
                    'label_en' => 'Home Sample Collection',
                    'data_type' => 'boolean',
                    'is_filterable' => true,
                ],
            ],
        ])->assertOk();
    }
}
