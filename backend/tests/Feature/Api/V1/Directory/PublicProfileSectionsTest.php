<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Directory;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PublicProfileSectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $owner;

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

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->locality = Locality::query()->where('city_id', $this->city->id)->firstOrFail();

        $this->clinicCategory = Category::query()->create([
            'name' => 'Clinics',
            'slug' => 'clinics-pub-test',
            'node_type' => 'subcategory',
            'depth' => 1,
            'lifecycle_status' => 'published',
            'is_assignable' => true,
            'is_active' => true,
        ]);

        $this->diagnosticCategory = Category::query()->create([
            'name' => 'Diagnostics',
            'slug' => 'diagnostics-pub-test',
            'node_type' => 'subcategory',
            'depth' => 1,
            'lifecycle_status' => 'published',
            'is_assignable' => true,
            'is_active' => true,
        ]);

        $this->restaurantCategory = Category::query()->create([
            'name' => 'Restaurants',
            'slug' => 'restaurants-pub-test',
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
            'description' => 'Trusted multi-speciality clinic.',
        ]);
        $this->business->categories()->sync([
            $this->clinicCategory->id => ['is_primary' => true],
            $this->diagnosticCategory->id => ['is_primary' => false],
        ]);
    }

    public function test_default_storefront_preserves_all_fields_with_empty_sections_when_unconfigured(): void
    {
        $res = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->business->slug}")
            ->assertOk();

        $res->assertJsonStructure([
            'data' => [
                'public_id', 'name', 'slug', 'description', 'primary_category',
                'categories', 'address', 'contact', 'hours', 'services',
                'amenities', 'payment_methods', 'faqs', 'media',
                'profile_sections', 'attributes', 'seo',
            ],
        ]);
        $res->assertJsonPath('data.profile_sections', []);
        $res->assertJsonPath('data.attributes', []);
    }

    public function test_public_profile_resolves_ordered_localized_sections_suppresses_empty_and_hides_private_or_orphaned(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->putJson("/api/v1/admin/categories/{$this->clinicCategory->public_id}/schemas", [
            'profile_sections' => [
                [
                    'section_key' => 'clinic_specs',
                    'display_label_en' => 'Clinic Specifications',
                    'display_label_ta' => 'மருத்துவமனை விவரங்கள்',
                    'is_enabled' => true,
                    'suppress_when_empty' => true,
                    'sort_order' => 1,
                ],
                [
                    'section_key' => 'empty_suppressed',
                    'display_label_en' => 'Should Be Hidden',
                    'is_enabled' => true,
                    'suppress_when_empty' => true,
                    'sort_order' => 2,
                ],
                [
                    'section_key' => 'disabled_section',
                    'display_label_en' => 'Disabled Section',
                    'is_enabled' => false,
                    'suppress_when_empty' => false,
                    'sort_order' => 3,
                ],
                [
                    'section_key' => 'overview',
                    'display_label_en' => 'Clinic Overview',
                    'display_label_ta' => 'மருத்துவமனை அறிமுகம்',
                    'is_enabled' => true,
                    'suppress_when_empty' => true,
                    'sort_order' => 4,
                ],
            ],
            'attributes' => [
                [
                    'attribute_key' => 'emergency_24x7',
                    'section_key' => 'clinic_specs',
                    'label_en' => '24x7 Emergency',
                    'label_ta' => '24x7 அவசர சிகிச்சை',
                    'data_type' => 'boolean',
                    'show_on_profile' => true,
                    'sort_order' => 1,
                ],
                [
                    'attribute_key' => 'internal_reg_note',
                    'section_key' => 'clinic_specs',
                    'label_en' => 'Internal Registration Note',
                    'data_type' => 'string',
                    'show_on_profile' => false,
                    'sort_order' => 2,
                ],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/admin/categories/{$this->diagnosticCategory->public_id}/schemas", [
            'attributes' => [
                [
                    'attribute_key' => 'home_sample_collection',
                    'label_en' => 'Home Sample Collection',
                    'data_type' => 'boolean',
                    'show_on_profile' => true,
                ],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/admin/categories/{$this->restaurantCategory->public_id}/schemas", [
            'attributes' => [
                [
                    'attribute_key' => 'seating_capacity',
                    'label_en' => 'Seating Capacity',
                    'data_type' => 'number',
                    'show_on_profile' => true,
                ],
            ],
        ])->assertOk();

        // Owner saves primary + secondary attributes (including show_on_profile=false)
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/owner/businesses/{$this->business->public_id}/attributes", [
            'attributes' => [
                ['attribute_key' => 'emergency_24x7', 'value' => true],
                ['attribute_key' => 'internal_reg_note', 'value' => 'PRIVATE-CODE-123'],
                ['attribute_key' => 'home_sample_collection', 'value' => true],
            ],
        ])->assertOk();

        // Public fetch in Tamil (?locale=ta)
        $this->app['auth']->forgetGuards();
        $res = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->business->slug}?locale=ta")
            ->assertOk();

        $sections = $res->json('data.profile_sections');
        $this->assertCount(2, $sections);
        $this->assertSame('clinic_specs', $sections[0]['section_key']);
        $this->assertSame('மருத்துவமனை விவரங்கள்', $sections[0]['label']);
        $this->assertSame('overview', $sections[1]['section_key']);
        $this->assertSame('மருத்துவமனை அறிமுகம்', $sections[1]['label']);

        // Only show_on_profile=true attributes exposed, internal DB IDs stripped
        $publicAttrs = collect($res->json('data.attributes'))->keyBy('attribute_key');
        $this->assertTrue($publicAttrs->has('emergency_24x7'));
        $this->assertTrue($publicAttrs->has('home_sample_collection'));
        $this->assertFalse($publicAttrs->has('internal_reg_note'));
        $this->assertSame('24x7 அவசர சிகிச்சை', $publicAttrs['emergency_24x7']['label']);
        $this->assertArrayNotHasKey('id', $publicAttrs['emergency_24x7']);
        $this->assertArrayNotHasKey('business_id', $publicAttrs['emergency_24x7']);

        // Now switch business category to restaurant: old clinic/diagnostic values become orphaned and must NOT appear publicly
        $this->business->primary_category_id = $this->restaurantCategory->id;
        $this->business->save();
        $this->business->categories()->sync([$this->restaurantCategory->id => ['is_primary' => true]]);

        $afterSwitch = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->business->slug}")
            ->assertOk();
        $this->assertSame([], $afterSwitch->json('data.attributes'));
        $this->assertSame([], $afterSwitch->json('data.profile_sections'));
    }
}
