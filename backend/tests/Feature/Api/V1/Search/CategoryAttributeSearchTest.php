<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Search;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CategoryAttributeSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $owner;

    private City $city;

    private Locality $locality;

    private Category $clinicCategory;

    private Category $restaurantCategory;

    private Business $clinicA;

    private Business $clinicB;

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

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->locality = Locality::query()->where('city_id', $this->city->id)->firstOrFail();

        $this->clinicCategory = Category::query()->create([
            'name' => 'Clinics Search Test',
            'slug' => 'clinics-search-test',
            'node_type' => 'subcategory',
            'depth' => 1,
            'lifecycle_status' => 'published',
            'is_assignable' => true,
            'is_active' => true,
        ]);

        $this->restaurantCategory = Category::query()->create([
            'name' => 'Restaurants Search Test',
            'slug' => 'restaurants-search-test',
            'node_type' => 'subcategory',
            'depth' => 1,
            'lifecycle_status' => 'published',
            'is_assignable' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->superAdmin);
        $this->putJson("/api/v1/admin/categories/{$this->clinicCategory->public_id}/schemas", [
            'attributes' => [
                [
                    'attribute_key' => 'emergency_24x7',
                    'label_en' => '24x7 Emergency',
                    'label_ta' => '24x7 அவசர சிகிச்சை',
                    'data_type' => 'boolean',
                    'is_filterable' => true,
                ],
                [
                    'attribute_key' => 'hospital_tier',
                    'label_en' => 'Hospital Tier',
                    'data_type' => 'enum',
                    'options_json' => ['choices' => ['clinic', 'multi_speciality']],
                    'is_filterable' => true,
                ],
                [
                    'attribute_key' => 'specialities',
                    'label_en' => 'Specialities',
                    'data_type' => 'multi_enum',
                    'options_json' => ['choices' => ['cardio', 'cardiology', 'orthopedics']],
                    'is_filterable' => true,
                ],
                [
                    'attribute_key' => 'bed_count',
                    'label_en' => 'Bed Count',
                    'data_type' => 'number',
                    'unit' => 'beds',
                    'options_json' => ['min' => 1, 'max' => 1000],
                    'is_filterable' => true,
                ],
                [
                    'attribute_key' => 'internal_code',
                    'label_en' => 'Internal Code',
                    'data_type' => 'string',
                    'is_filterable' => false,
                ],
            ],
            'filters' => [
                [
                    'filter_key' => 'emergency_24x7',
                    'filter_type' => 'toggle',
                    'label_en' => '24x7 Emergency',
                    'label_ta' => '24x7 அவசர சிகிச்சை',
                    'is_enabled' => true,
                    'sort_order' => 1,
                ],
                [
                    'filter_key' => 'hospital_tier',
                    'filter_type' => 'select',
                    'label_en' => 'Hospital Tier',
                    'is_enabled' => true,
                    'sort_order' => 2,
                ],
                [
                    'filter_key' => 'specialities',
                    'filter_type' => 'checkbox',
                    'label_en' => 'Specialities',
                    'is_enabled' => true,
                    'sort_order' => 3,
                ],
                [
                    'filter_key' => 'bed_count',
                    'filter_type' => 'range',
                    'label_en' => 'Bed Count',
                    'is_enabled' => true,
                    'sort_order' => 4,
                ],
                [
                    'filter_key' => 'internal_code',
                    'filter_type' => 'select',
                    'label_en' => 'Internal Code',
                    'is_enabled' => true,
                    'sort_order' => 5,
                ],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/admin/categories/{$this->restaurantCategory->public_id}/schemas", [
            'attributes' => [
                [
                    'attribute_key' => 'pure_veg',
                    'label_en' => 'Pure Veg',
                    'data_type' => 'boolean',
                    'is_filterable' => true,
                ],
            ],
        ])->assertOk();

        $this->clinicA = Business::factory()->create([
            'owner_user_id' => $this->owner->id,
            'primary_category_id' => $this->clinicCategory->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'status' => BusinessStatus::Active,
            'name' => 'Alpha Care Clinic',
        ]);
        $this->clinicA->categories()->sync([$this->clinicCategory->id => ['is_primary' => true]]);

        $this->clinicB = Business::factory()->create([
            'owner_user_id' => $this->owner->id,
            'primary_category_id' => $this->clinicCategory->id,
            'city_id' => $this->city->id,
            'locality_id' => $this->locality->id,
            'status' => BusinessStatus::Active,
            'name' => 'Beta Ortho Clinic',
        ]);
        $this->clinicB->categories()->sync([$this->clinicCategory->id => ['is_primary' => true]]);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/owner/businesses/{$this->clinicA->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'emergency_24x7', 'value' => true],
                ['attribute_key' => 'hospital_tier', 'value' => 'multi_speciality'],
                ['attribute_key' => 'specialities', 'value' => ['cardiology']],
                ['attribute_key' => 'bed_count', 'value' => 45],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/owner/businesses/{$this->clinicB->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'emergency_24x7', 'value' => false],
                ['attribute_key' => 'hospital_tier', 'value' => 'clinic'],
                ['attribute_key' => 'specialities', 'value' => ['cardio', 'orthopedics']],
                ['attribute_key' => 'bed_count', 'value' => 12],
            ],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
    }

    public function test_filter_discovery_returns_enabled_filterable_category_filters_with_localization(): void
    {
        $res = $this->getJson("/api/v1/search/filters?city={$this->city->slug}&category={$this->clinicCategory->slug}&locale=ta")
            ->assertOk();

        $filters = collect($res->json('data.category_filters'))->keyBy('filter_key');
        $this->assertCount(4, $filters);
        $this->assertTrue($filters->has('emergency_24x7'));
        $this->assertSame('24x7 அவசர சிகிச்சை', $filters['emergency_24x7']['label']);
        $this->assertFalse($filters->has('internal_code'));
    }

    public function test_boolean_select_exact_multi_select_and_range_filters(): void
    {
        // Boolean true -> only Alpha Care Clinic
        $boolRes = $this->getJson("/api/v1/search?city={$this->city->slug}&category={$this->clinicCategory->slug}&attr[emergency_24x7]=1")
            ->assertOk();
        $this->assertSame([$this->clinicA->public_id], collect($boolRes->json('data'))->pluck('public_id')->all());

        // Enum select -> only Alpha Care Clinic
        $enumRes = $this->getJson("/api/v1/search?city={$this->city->slug}&category={$this->clinicCategory->slug}&attr[hospital_tier]=multi_speciality")
            ->assertOk();
        $this->assertSame([$this->clinicA->public_id], collect($enumRes->json('data'))->pluck('public_id')->all());

        // Exact multi_select membership: 'cardio' must match Beta (['cardio','orthopedics']) and NOT Alpha (['cardiology'])
        $multiRes = $this->getJson("/api/v1/search?city={$this->city->slug}&category={$this->clinicCategory->slug}&attr[specialities][]=cardio")
            ->assertOk();
        $this->assertSame([$this->clinicB->public_id], collect($multiRes->json('data'))->pluck('public_id')->all());

        // Numeric range: min=20, max=60 -> only Alpha (45)
        $rangeRes = $this->getJson("/api/v1/search?city={$this->city->slug}&category={$this->clinicCategory->slug}&attr[bed_count][min]=20&attr[bed_count][max]=60")
            ->assertOk();
        $this->assertSame([$this->clinicA->public_id], collect($rangeRes->json('data'))->pluck('public_id')->all());
    }

    public function test_validation_rejects_unknown_non_filterable_cross_category_inverted_range_and_over_eight_filters(): void
    {
        // Missing category
        $this->getJson("/api/v1/search?city={$this->city->slug}&attr[emergency_24x7]=1")
            ->assertStatus(422);

        // Non-filterable attribute
        $this->getJson("/api/v1/search?city={$this->city->slug}&category={$this->clinicCategory->slug}&attr[internal_code]=ABC")
            ->assertStatus(422);

        // Cross-category attribute ('pure_veg' belongs to restaurantCategory, not clinicCategory)
        $this->getJson("/api/v1/search?city={$this->city->slug}&category={$this->clinicCategory->slug}&attr[pure_veg]=1")
            ->assertStatus(422);

        // Inverted range (min > max)
        $this->getJson("/api/v1/search?city={$this->city->slug}&category={$this->clinicCategory->slug}&attr[bed_count][min]=100&attr[bed_count][max]=20")
            ->assertStatus(422);

        // More than 8 simultaneous filters
        $nine = http_build_query([
            'city' => $this->city->slug,
            'category' => $this->clinicCategory->slug,
            'attr' => [
                'f1' => '1', 'f2' => '1', 'f3' => '1', 'f4' => '1',
                'f5' => '1', 'f6' => '1', 'f7' => '1', 'f8' => '1', 'f9' => '1',
            ],
        ]);
        $this->getJson("/api/v1/search?{$nine}")
            ->assertStatus(422);
    }

    public function test_mysql_query_plan_uses_composite_index_for_attribute_exists_subquery(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('EXPLAIN index verification runs on MySQL/MariaDB.');
        }

        $def = CategoryAttributeDefinition::query()
            ->where('category_id', $this->clinicCategory->id)
            ->where('attribute_key', 'emergency_24x7')
            ->firstOrFail();

        $plan = DB::select(
            'EXPLAIN SELECT businesses.id FROM businesses WHERE businesses.status = ? AND EXISTS (SELECT 1 FROM business_attribute_values AS bav WHERE bav.business_id = businesses.id AND bav.attribute_definition_id = ? AND bav.value_boolean = ?)',
            ['active', $def->id, 1]
        );

        $this->assertNotEmpty($plan);
        $bavRow = collect($plan)->first(static fn ($row): bool => ($row->table ?? null) === 'bav');
        $this->assertNotNull($bavRow);
        $this->assertNotNull($bavRow->key ?? null);
    }
}
