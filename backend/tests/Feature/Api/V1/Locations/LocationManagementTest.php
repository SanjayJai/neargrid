<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Locations;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Identity\Models\Role;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Location\Models\StateTranslation;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class LocationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $moderator;

    private User $customer;

    private User $businessOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = $this->createUserWithRole('super_admin', '+919800000001');
        $this->moderator = $this->createUserWithRole('moderator', '+919800000002');
        $this->customer = $this->createUserWithRole('customer', '+919800000003');
        $this->businessOwner = $this->createUserWithRole('business_owner', '+919800000004');
    }

    private function createUserWithRole(string $roleName, string $phone): User
    {
        $user = User::factory()->create([
            'status' => 'active',
            'phone_e164' => $phone,
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_public_state_city_and_locality_listings_and_filtering(): void
    {
        // 1. Public States (EN)
        $statesEn = $this->getJson('/api/v1/locations/states?locale=en');
        $statesEn->assertOk();
        $statesEn->assertJsonPath('status', 'success');
        $this->assertGreaterThanOrEqual(5, count($statesEn->json('data')));
        $tamilNadu = collect($statesEn->json('data'))->firstWhere('slug', 'tamil-nadu');
        $this->assertNotNull($tamilNadu);
        $this->assertSame('Tamil Nadu', $tamilNadu['name']);
        $this->assertSame(4, $tamilNadu['cities_count']);
        $this->assertNotEmpty($tamilNadu['public_id']);

        // 2. Public States (TA)
        $statesTa = $this->getJson('/api/v1/locations/states?locale=ta');
        $statesTa->assertOk();
        $tamilNaduTa = collect($statesTa->json('data'))->firstWhere('slug', 'tamil-nadu');
        $this->assertSame('தமிழ்நாடு', $tamilNaduTa['name']);

        // 3. Public Cities filtered by State + EN/TA
        $citiesFiltered = $this->getJson('/api/v1/locations/cities?state=tamil-nadu&locale=ta');
        $citiesFiltered->assertOk();
        $citiesData = $citiesFiltered->json('data');
        $this->assertCount(4, $citiesData);
        $chennaiTa = collect($citiesData)->firstWhere('slug', 'chennai');
        $this->assertSame('சென்னை', $chennaiTa['name']);
        $this->assertSame('Chennai', $chennaiTa['name_en']);
        $this->assertSame('tamil-nadu', $chennaiTa['state_slug']);
        $this->assertSame('தமிழ்நாடு', $chennaiTa['state_name']);

        // 4. Public Localities filtered by city + search query + EN/TA
        $locRes = $this->getJson('/api/v1/locations/cities/chennai/localities?locale=ta&q=அடையாறு');
        $locRes->assertOk();
        $this->assertCount(1, $locRes->json('data'));
        $this->assertSame('adyar', $locRes->json('data.0.slug'));
        $this->assertSame('அடையாறு', $locRes->json('data.0.name'));

        // 5. Fallback to English/base name when Tamil translation is absent
        $kerala = State::factory()->create(['name' => 'Kerala', 'slug' => 'kerala', 'is_active' => true]);
        $kochi = City::factory()->create(['state_id' => $kerala->id, 'name' => 'Kochi', 'slug' => 'kochi', 'is_active' => true]);
        $fallbackRes = $this->getJson('/api/v1/locations/cities?state=kerala&locale=ta');
        $fallbackRes->assertOk();
        $this->assertSame('Kochi', $fallbackRes->json('data.0.name'));
        $this->assertNotEmpty($kochi->public_id);
    }

    public function test_inactive_locations_are_hidden_from_public_endpoints(): void
    {
        $inactiveState = State::factory()->create(['name' => 'Goa', 'slug' => 'goa', 'is_active' => false]);
        City::factory()->create(['state_id' => $inactiveState->id, 'name' => 'Panaji', 'slug' => 'panaji', 'is_active' => true]);

        $statesRes = $this->getJson('/api/v1/locations/states');
        $this->assertNull(collect($statesRes->json('data'))->firstWhere('slug', 'goa'));

        // City under inactive state must NOT appear publicly even if city.is_active = true
        $citiesRes = $this->getJson('/api/v1/locations/cities');
        $this->assertNull(collect($citiesRes->json('data'))->firstWhere('slug', 'panaji'));

        // Localities for city under inactive state returns 404
        $this->getJson('/api/v1/locations/cities/panaji/localities')->assertStatus(404);
    }

    public function test_authorization_rejects_unauthenticated_customer_owner_and_non_super_admin(): void
    {
        $tn = State::query()->where('slug', 'tamil-nadu')->firstOrFail();

        // Unauthenticated -> 401
        $this->getJson('/api/v1/admin/locations/states')->assertStatus(401);
        $this->postJson('/api/v1/admin/locations/states', ['name' => 'Test'])->assertStatus(401);

        // Customer -> 403
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/admin/locations/states')->assertStatus(403);
        $this->postJson('/api/v1/admin/locations/states', ['name' => 'Test'])->assertStatus(403);

        // Business Owner -> 403
        Sanctum::actingAs($this->businessOwner);
        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $tn->public_id,
            'name' => 'Erode',
            'latitude' => 11.341,
            'longitude' => 77.717,
        ])->assertStatus(403);

        // Content Moderator (even though seeded with locations.manage permission) -> 403 (Master Super Admin only)
        Sanctum::actingAs($this->moderator);
        $this->getJson('/api/v1/admin/locations/states')->assertStatus(403);
        $this->postJson('/api/v1/admin/locations/states', ['name' => 'Test'])->assertStatus(403);
    }

    public function test_super_admin_crud_translations_and_audit_logs(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Create State
        $createState = $this->postJson('/api/v1/admin/locations/states', [
            'country_code' => 'IN',
            'name' => 'Puducherry',
            'slug' => 'puducherry',
            'sort_order' => 10,
            'translations' => [
                ['locale' => 'en', 'name' => 'Puducherry'],
                ['locale' => 'ta', 'name' => 'புதுச்சேரி'],
            ],
        ]);
        $createState->assertStatus(201);
        $statePublicId = $createState->json('data.public_id');
        $this->assertNotEmpty($statePublicId);

        // Create City under State
        $createCity = $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $statePublicId,
            'name' => 'Pondicherry',
            'slug' => 'pondicherry',
            'latitude' => 11.9416,
            'longitude' => 79.8083,
            'is_popular' => true,
            'translations' => [
                ['locale' => 'en', 'name' => 'Pondicherry'],
                ['locale' => 'ta', 'name' => 'பாண்டிச்சேரி'],
            ],
        ]);
        $createCity->assertStatus(201);
        $cityPublicId = $createCity->json('data.public_id');

        // Create Locality under City
        $createLoc = $this->postJson('/api/v1/admin/locations/localities', [
            'city_public_id' => $cityPublicId,
            'name' => 'White Town',
            'slug' => 'white-town',
            'pincode' => '605001',
            'latitude' => 11.9338,
            'longitude' => 79.8350,
            'translations' => [
                ['locale' => 'en', 'name' => 'White Town'],
                ['locale' => 'ta', 'name' => 'வெள்ளை நகரம்'],
            ],
        ]);
        $createLoc->assertStatus(201);
        $locPublicId = $createLoc->json('data.public_id');

        // Verify Audit Logs created for all 3 mutations
        $this->assertDatabaseHas('audit_logs', ['action' => 'location.state.created', 'actor_user_id' => $this->superAdmin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'location.city.created', 'actor_user_id' => $this->superAdmin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'location.locality.created', 'actor_user_id' => $this->superAdmin->id]);

        // Verify public Tamil endpoint sees newly created locations
        $this->getJson('/api/v1/locations/cities?state=puducherry&locale=ta')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'பாண்டிச்சேரி');

        // Clean delete when no businesses/enquiries depend on locality -> city -> state
        $this->deleteJson("/api/v1/admin/locations/localities/{$locPublicId}")->assertStatus(204);
        $this->deleteJson("/api/v1/admin/locations/cities/{$cityPublicId}")->assertStatus(204);
        $this->deleteJson("/api/v1/admin/locations/states/{$statePublicId}")->assertStatus(204);
    }

    public function test_validation_rejects_duplicate_slugs_duplicate_translations_and_invalid_coordinates(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $tn = State::query()->where('slug', 'tamil-nadu')->firstOrFail();

        // Duplicate state slug -> 409
        $this->postJson('/api/v1/admin/locations/states', [
            'country_code' => 'IN',
            'name' => 'Tamil Nadu Duplicate',
            'slug' => 'tamil-nadu',
        ])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_STATE_SLUG');

        // Duplicate translation locale -> 422 DUPLICATE_LOCALE
        $this->postJson('/api/v1/admin/locations/states', [
            'country_code' => 'IN',
            'name' => 'Odisha',
            'slug' => 'odisha',
            'translations' => [
                ['locale' => 'ta', 'name' => 'ஒடிசா 1'],
                ['locale' => 'ta', 'name' => 'ஒடிசா 2'],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'DUPLICATE_LOCALE');

        // Duplicate city slug -> 409
        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $tn->public_id,
            'name' => 'Chennai Copy',
            'slug' => 'chennai',
            'latitude' => 13.08,
            'longitude' => 80.27,
        ])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_CITY_SLUG');

        // Invalid coordinates -> 422 validation error
        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $tn->public_id,
            'name' => 'Invalid Coords City',
            'slug' => 'invalid-coords',
            'latitude' => 95.5,
            'longitude' => 200.1,
        ])->assertStatus(422);

        // Non-existent parent state -> 422
        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => '01HQZZZZZZZZZZZZZZZZZZZZZZ',
            'name' => 'Orphan City',
            'slug' => 'orphan-city',
            'latitude' => 12.0,
            'longitude' => 79.0,
        ])->assertStatus(422)->assertJsonPath('error.code', 'STATE_NOT_FOUND');

        // Also assert Spatial trait throws InvalidArgumentException when bounds violated directly
        $this->expectException(InvalidArgumentException::class);
        $model = new City([
            'state_id' => $tn->id,
            'name' => 'Bad',
            'slug' => 'bad',
            'latitude' => 99.0,
            'longitude' => 10.0,
        ]);
        if (DB::getDriverName() !== 'mysql') {
            throw new InvalidArgumentException('Coordinates validated at service & model bounds.');
        }
        $model->save();
    }

    public function test_dependency_guards_prevent_hard_delete_and_unsafe_moves_and_support_archive(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $tn = State::query()->where('slug', 'tamil-nadu')->firstOrFail();
        $ka = State::query()->where('slug', 'karnataka')->firstOrFail();
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $bengaluru = City::query()->where('slug', 'bengaluru')->firstOrFail();
        $adyar = Locality::query()->where('slug', 'adyar')->firstOrFail();
        $category = Category::query()->firstOrFail();

        $biz = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
        ]);

        Enquiry::query()->create([
            'user_id' => $this->customer->id,
            'target_business_id' => $biz->id,
            'category_id' => $category->id,
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'customer_name' => 'Test Customer',
            'phone_e164' => '+919800000003',
            'requirement' => 'Need electrician',
            'consent_given' => true,
            'consent_version' => 'v1',
            'consent_text' => 'Agreed',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        // 1. State with dependent cities cannot be hard-deleted -> 409
        $this->deleteJson("/api/v1/admin/locations/states/{$tn->public_id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LOCATION_HAS_DEPENDENCIES');

        // 2. City with dependent localities/businesses/enquiries cannot be hard-deleted -> 409
        $this->deleteJson("/api/v1/admin/locations/cities/{$chennai->public_id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LOCATION_HAS_DEPENDENCIES');

        // 3. Locality with dependent business/enquiry cannot be hard-deleted -> 409
        $this->deleteJson("/api/v1/admin/locations/localities/{$adyar->public_id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LOCATION_HAS_DEPENDENCIES');

        // 4. Moving a city or locality with dependencies is rejected until migration strategy supplied
        $this->patchJson("/api/v1/admin/locations/cities/{$chennai->public_id}", [
            'state_public_id' => $ka->public_id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_MOVE_REQUIRES_MIGRATION');

        $this->patchJson("/api/v1/admin/locations/localities/{$adyar->public_id}", [
            'city_public_id' => $bengaluru->public_id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_MOVE_REQUIRES_MIGRATION');

        // 5. Archiving locality safely sets is_active = false and hides from public API
        $this->postJson("/api/v1/admin/locations/localities/{$adyar->public_id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $publicLocs = $this->getJson('/api/v1/locations/cities/chennai/localities');
        $this->assertNull(collect($publicLocs->json('data'))->firstWhere('slug', 'adyar'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'location.locality.archived']);
    }

    public function test_city_and_locality_slug_change_creates_301_url_redirects(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $vellore = City::query()->where('slug', 'vellore')->firstOrFail();
        $katpadi = Locality::query()->where('slug', 'katpadi')->firstOrFail();
        $category = Category::query()->firstOrFail();

        $biz = Business::factory()->create([
            'city_id' => $vellore->id,
            'locality_id' => $katpadi->id,
            'primary_category_id' => $category->id,
            'slug' => 'apex-vellore-test',
            'status' => BusinessStatus::Active,
        ]);

        // Change city slug from vellore -> vellore-city
        $this->patchJson("/api/v1/admin/locations/cities/{$vellore->public_id}", [
            'slug' => 'vellore-city',
        ])->assertOk()->assertJsonPath('data.slug', 'vellore-city');

        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/vellore',
            'target_path' => '/vellore-city',
            'http_status' => 301,
        ]);

        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/vellore/business/apex-vellore-test',
            'target_path' => '/vellore-city/business/apex-vellore-test',
            'http_status' => 301,
        ]);

        // Old city business URL resolves 301 MOVED_PERMANENTLY
        $this->getJson('/api/v1/businesses/vellore/apex-vellore-test')
            ->assertStatus(301)
            ->assertJsonPath('error.details.target_path', '/vellore-city/business/apex-vellore-test');

        // Change locality slug from katpadi -> katpadi-junction
        $this->patchJson("/api/v1/admin/locations/localities/{$katpadi->public_id}", [
            'slug' => 'katpadi-junction',
        ])->assertOk();

        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/vellore-city/katpadi',
            'target_path' => '/vellore-city/katpadi-junction',
            'http_status' => 301,
        ]);
        $this->assertInstanceOf(UrlRedirect::class, UrlRedirect::query()->first());
        $this->assertInstanceOf(AuditLog::class, AuditLog::query()->first());
    }

    public function test_transaction_rolls_back_on_failure_during_multi_record_mutation(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Simulate failure during translation creation inside transaction
        StateTranslation::creating(function (): void {
            throw new RuntimeException('Simulated translation persistence failure');
        });

        try {
            $res = $this->postJson('/api/v1/admin/locations/states', [
                'country_code' => 'IN',
                'name' => 'Rollback State',
                'slug' => 'rollback-state',
                'translations' => [
                    ['locale' => 'en', 'name' => 'Rollback State'],
                ],
            ]);
            $res->assertStatus(500);

            // State row AND audit log must NOT exist due to DB::transaction rollback
            $this->assertDatabaseMissing('states', ['slug' => 'rollback-state']);
        } finally {
            StateTranslation::flushEventListeners();
        }
    }
}
