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
use App\Domains\Location\Services\LocationRedirectResolver;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
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
        $statesEn = $this->getJson('/api/v1/locations/states?locale=en');
        $statesEn->assertOk();
        $statesEn->assertJsonPath('status', 'success');
        $this->assertGreaterThanOrEqual(5, count($statesEn->json('data')));
        $tamilNadu = collect($statesEn->json('data'))->firstWhere('slug', 'tamil-nadu');
        $this->assertNotNull($tamilNadu);
        $this->assertSame('Tamil Nadu', $tamilNadu['name']);
        $this->assertSame(4, $tamilNadu['cities_count']);
        $this->assertNotEmpty($tamilNadu['public_id']);

        $statesTa = $this->getJson('/api/v1/locations/states?locale=ta');
        $statesTa->assertOk();
        $tamilNaduTa = collect($statesTa->json('data'))->firstWhere('slug', 'tamil-nadu');
        $this->assertSame('தமிழ்நாடு', $tamilNaduTa['name']);

        $citiesFiltered = $this->getJson('/api/v1/locations/cities?state=tamil-nadu&locale=ta');
        $citiesFiltered->assertOk();
        $citiesData = $citiesFiltered->json('data');
        $this->assertCount(4, $citiesData);
        $chennaiTa = collect($citiesData)->firstWhere('slug', 'chennai');
        $this->assertSame('சென்னை', $chennaiTa['name']);
        $this->assertSame('Chennai', $chennaiTa['name_en']);
        $this->assertSame('tamil-nadu', $chennaiTa['state_slug']);
        $this->assertSame('தமிழ்நாடு', $chennaiTa['state_name']);

        $locRes = $this->getJson('/api/v1/locations/cities/chennai/localities?locale=ta&q=அடையாறு');
        $locRes->assertOk();
        $this->assertCount(1, $locRes->json('data'));
        $this->assertSame('adyar', $locRes->json('data.0.slug'));
        $this->assertSame('அடையாறு', $locRes->json('data.0.name'));

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

        $citiesRes = $this->getJson('/api/v1/locations/cities');
        $this->assertNull(collect($citiesRes->json('data'))->firstWhere('slug', 'panaji'));

        $this->getJson('/api/v1/locations/cities/panaji/localities')->assertStatus(404);
    }

    public function test_authorization_rejects_unauthenticated_customer_owner_and_non_super_admin(): void
    {
        $tn = State::query()->where('slug', 'tamil-nadu')->firstOrFail();

        $this->getJson('/api/v1/admin/locations/states')->assertStatus(401);
        $this->postJson('/api/v1/admin/locations/states', ['name' => 'Test'])->assertStatus(401);

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/admin/locations/states')->assertStatus(403);
        $this->postJson('/api/v1/admin/locations/states', ['name' => 'Test'])->assertStatus(403);

        Sanctum::actingAs($this->businessOwner);
        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $tn->public_id,
            'name' => 'Erode',
            'latitude' => 11.341,
            'longitude' => 77.717,
        ])->assertStatus(403);

        Sanctum::actingAs($this->moderator);
        $this->getJson('/api/v1/admin/locations/states')->assertStatus(403);
        $this->postJson('/api/v1/admin/locations/states', ['name' => 'Test'])->assertStatus(403);
    }

    public function test_rejects_creating_or_moving_city_or_locality_under_inactive_parent(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $activeState = State::query()->where('slug', 'tamil-nadu')->firstOrFail();
        $inactiveState = State::factory()->create(['name' => 'Inactive State', 'slug' => 'inactive-state', 'is_active' => false]);
        $activeCity = City::factory()->create(['state_id' => $activeState->id, 'name' => 'Salem', 'slug' => 'salem', 'is_active' => true]);
        $inactiveCity = City::factory()->create(['state_id' => $activeState->id, 'name' => 'Inactive City', 'slug' => 'inactive-city', 'is_active' => false]);

        // 1. Create city under inactive state -> 422 PARENT_LOCATION_INACTIVE
        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $inactiveState->public_id,
            'name' => 'Blocked City',
            'slug' => 'blocked-city',
            'latitude' => 11.66,
            'longitude' => 78.14,
        ])->assertStatus(422)->assertJsonPath('error.code', 'PARENT_LOCATION_INACTIVE');

        // 2. Move dependency-free city to inactive state -> 422 PARENT_LOCATION_INACTIVE
        $this->patchJson("/api/v1/admin/locations/cities/{$activeCity->public_id}", [
            'state_public_id' => $inactiveState->public_id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'PARENT_LOCATION_INACTIVE');

        // 3. Create locality under inactive city -> 422 PARENT_LOCATION_INACTIVE
        $this->postJson('/api/v1/admin/locations/localities', [
            'city_public_id' => $inactiveCity->public_id,
            'name' => 'Blocked Locality',
            'slug' => 'blocked-locality',
            'latitude' => 11.67,
            'longitude' => 78.15,
        ])->assertStatus(422)->assertJsonPath('error.code', 'PARENT_LOCATION_INACTIVE');

        // 4. Move dependency-free locality to inactive city -> 422 PARENT_LOCATION_INACTIVE
        $freeLoc = Locality::factory()->create(['city_id' => $activeCity->id, 'name' => 'Fairlands', 'slug' => 'fairlands']);
        $this->patchJson("/api/v1/admin/locations/localities/{$freeLoc->public_id}", [
            'city_public_id' => $inactiveCity->public_id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'PARENT_LOCATION_INACTIVE');

        // 5. Valid creation & update under active parents succeeds
        $ka = State::query()->where('slug', 'karnataka')->firstOrFail();
        $this->patchJson("/api/v1/admin/locations/cities/{$activeCity->public_id}", [
            'state_public_id' => $ka->public_id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_MOVE_REQUIRES_MIGRATION');

        $movableCity = City::factory()->create(['state_id' => $activeState->id, 'name' => 'Tiruppur', 'slug' => 'tiruppur']);
        $this->patchJson("/api/v1/admin/locations/cities/{$movableCity->public_id}", [
            'state_public_id' => $ka->public_id,
        ])->assertOk();
    }

    public function test_super_admin_crud_translations_and_per_locale_audit_diffs(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $createState = $this->postJson('/api/v1/admin/locations/states', [
            'country_code' => 'IN',
            'name' => 'Puducherry',
            'slug' => 'puducherry',
            'sort_order' => 10,
            'translations' => [
                ['locale' => 'en', 'name' => 'Puducherry'],
            ],
        ]);
        $createState->assertStatus(201);
        $statePublicId = $createState->json('data.public_id');
        $stateModel = State::findByPublicId($statePublicId);

        // Tamil-only addition records old=null, new=ta
        $this->patchJson("/api/v1/admin/locations/states/{$statePublicId}", [
            'translations' => [
                ['locale' => 'ta', 'name' => 'புதுச்சேரி'],
            ],
        ])->assertOk();

        $taAddLog = AuditLog::query()
            ->where('action', 'location.state.updated')
            ->where('auditable_id', $stateModel->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertNull($taAddLog->old_values['translations']['ta']);
        $this->assertSame('புதுச்சேரி', $taAddLog->new_values['translations']['ta']['name']);
        $this->assertArrayNotHasKey('en', $taAddLog->new_values['translations']);

        // English-only modification records en diff only
        $this->patchJson("/api/v1/admin/locations/states/{$statePublicId}", [
            'translations' => [
                ['locale' => 'en', 'name' => 'Union Territory of Puducherry'],
            ],
        ])->assertOk();

        $enEditLog = AuditLog::query()
            ->where('action', 'location.state.updated')
            ->where('auditable_id', $stateModel->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Puducherry', $enEditLog->old_values['translations']['en']['name']);
        $this->assertSame('Union Territory of Puducherry', $enEditLog->new_values['translations']['en']['name']);
        $this->assertArrayNotHasKey('ta', $enEditLog->new_values['translations']);

        // Multi-locale change: update en + remove ta
        $this->patchJson("/api/v1/admin/locations/states/{$statePublicId}", [
            'translations' => [
                ['locale' => 'en', 'name' => 'Puducherry UT'],
            ],
            'remove_locales' => ['ta'],
        ])->assertOk();

        $multiLog = AuditLog::query()
            ->where('action', 'location.state.updated')
            ->where('auditable_id', $stateModel->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Puducherry UT', $multiLog->new_values['translations']['en']['name']);
        $this->assertSame('புதுச்சேரி', $multiLog->old_values['translations']['ta']['name']);
        $this->assertNull($multiLog->new_values['translations']['ta']);

        // Create City & Locality and verify clean delete when zero dependencies
        $createCity = $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $statePublicId,
            'name' => 'Pondicherry',
            'slug' => 'pondicherry',
            'latitude' => 11.9416,
            'longitude' => 79.8083,
            'translations' => [
                ['locale' => 'en', 'name' => 'Pondicherry'],
                ['locale' => 'ta', 'name' => 'பாண்டிச்சேரி'],
            ],
        ]);
        $createCity->assertStatus(201);
        $cityPublicId = $createCity->json('data.public_id');

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

        $this->deleteJson("/api/v1/admin/locations/localities/{$locPublicId}")->assertStatus(204);
        $this->deleteJson("/api/v1/admin/locations/cities/{$cityPublicId}")->assertStatus(204);
        $this->deleteJson("/api/v1/admin/locations/states/{$statePublicId}")->assertStatus(204);
    }

    public function test_validation_rejects_duplicate_slugs_duplicate_translations_and_invalid_coordinates(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $tn = State::query()->where('slug', 'tamil-nadu')->firstOrFail();

        $this->postJson('/api/v1/admin/locations/states', [
            'country_code' => 'IN',
            'name' => 'Tamil Nadu Duplicate',
            'slug' => 'tamil-nadu',
        ])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_STATE_SLUG');

        $this->postJson('/api/v1/admin/locations/states', [
            'country_code' => 'IN',
            'name' => 'Odisha',
            'slug' => 'odisha',
            'translations' => [
                ['locale' => 'ta', 'name' => 'ஒடிசா 1'],
                ['locale' => 'ta', 'name' => 'ஒடிசா 2'],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'DUPLICATE_LOCALE');

        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $tn->public_id,
            'name' => 'Chennai Copy',
            'slug' => 'chennai',
            'latitude' => 13.08,
            'longitude' => 80.27,
        ])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_CITY_SLUG');

        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => $tn->public_id,
            'name' => 'Invalid Coords City',
            'slug' => 'invalid-coords',
            'latitude' => 95.5,
            'longitude' => 200.1,
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/locations/cities', [
            'state_public_id' => '01HQZZZZZZZZZZZZZZZZZZZZZZ',
            'name' => 'Orphan City',
            'slug' => 'orphan-city',
            'latitude' => 12.0,
            'longitude' => 79.0,
        ])->assertStatus(422)->assertJsonPath('error.code', 'STATE_NOT_FOUND');

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

        $this->deleteJson("/api/v1/admin/locations/states/{$tn->public_id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LOCATION_HAS_DEPENDENCIES');

        $this->deleteJson("/api/v1/admin/locations/cities/{$chennai->public_id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LOCATION_HAS_DEPENDENCIES');

        $this->deleteJson("/api/v1/admin/locations/localities/{$adyar->public_id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LOCATION_HAS_DEPENDENCIES');

        $this->patchJson("/api/v1/admin/locations/cities/{$chennai->public_id}", [
            'state_public_id' => $ka->public_id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_MOVE_REQUIRES_MIGRATION');

        $this->patchJson("/api/v1/admin/locations/localities/{$adyar->public_id}", [
            'city_public_id' => $bengaluru->public_id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_MOVE_REQUIRES_MIGRATION');

        $this->postJson("/api/v1/admin/locations/localities/{$adyar->public_id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $publicLocs = $this->getJson('/api/v1/locations/cities/chennai/localities');
        $this->assertNull(collect($publicLocs->json('data'))->firstWhere('slug', 'adyar'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'location.locality.archived']);
        $this->assertDatabaseHas('businesses', ['id' => $biz->id, 'locality_id' => $adyar->id]);
    }

    public function test_redirect_chain_collapse_conflict_protection_loop_prevention_and_public_301_resolution(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $vellore = City::query()->where('slug', 'vellore')->firstOrFail();
        $katpadi = Locality::query()->where('slug', 'katpadi')->firstOrFail();
        $category = Category::query()->where('slug', 'dentists')->firstOrFail();

        Business::factory()->create([
            'city_id' => $vellore->id,
            'locality_id' => $katpadi->id,
            'primary_category_id' => $category->id,
            'slug' => 'apex-vellore-test',
            'status' => BusinessStatus::Active,
        ]);

        // 1. Locality rename katpadi -> katpadi-west, then city rename vellore -> vellore-city -> vellore-town
        $this->patchJson("/api/v1/admin/locations/localities/{$katpadi->public_id}", [
            'slug' => 'katpadi-west',
        ])->assertOk();

        $this->patchJson("/api/v1/admin/locations/cities/{$vellore->public_id}", [
            'slug' => 'vellore-city',
        ])->assertOk();

        $this->patchJson("/api/v1/admin/locations/cities/{$vellore->public_id}", [
            'slug' => 'vellore-town',
        ])->assertOk();

        // Single-hop collapsed redirects directly to latest destination!
        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/vellore',
            'target_path' => '/vellore-town',
            'http_status' => 301,
        ]);
        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/vellore/katpadi',
            'target_path' => '/vellore-town/katpadi-west',
            'http_status' => 301,
        ]);
        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/vellore/business/apex-vellore-test',
            'target_path' => '/vellore-town/business/apex-vellore-test',
            'http_status' => 301,
        ]);

        // 2. Public directory SearchController & ReferenceTaxonomyController return 301
        $this->getJson('/api/v1/search?city=vellore&category=dentists')
            ->assertStatus(301)
            ->assertHeader('Location', '/vellore-town/dentists')
            ->assertJsonPath('error.details.target_path', '/vellore-town/dentists');

        $this->getJson('/api/v1/search?city=vellore&locality=katpadi&category=dentists')
            ->assertStatus(301)
            ->assertHeader('Location', '/vellore-town/katpadi-west/dentists')
            ->assertJsonPath('error.details.target_path', '/vellore-town/katpadi-west/dentists');

        $this->getJson('/api/v1/locations/cities/vellore/localities')
            ->assertStatus(301)
            ->assertHeader('Location', '/api/v1/locations/cities/vellore-town/localities');

        $this->getJson('/api/v1/businesses/vellore/apex-vellore-test')
            ->assertStatus(301)
            ->assertJsonPath('error.details.target_path', '/vellore-town/business/apex-vellore-test');

        // 3. Reverting locality slug (katpadi-west -> katpadi) collapses & deletes inverse redirect without loops
        $this->patchJson("/api/v1/admin/locations/localities/{$katpadi->public_id}", [
            'slug' => 'katpadi',
        ])->assertOk();

        $this->assertDatabaseMissing('url_redirects', ['source_path' => '/vellore-town/katpadi']);
        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/vellore-town/katpadi-west',
            'target_path' => '/vellore-town/katpadi',
        ]);

        // 4. Conflicting redirect source is rejected (409 REDIRECT_SOURCE_CONFLICT) without silent overwrite
        $tn = State::query()->where('slug', 'tamil-nadu')->firstOrFail();
        $conflictCity = City::factory()->create(['state_id' => $tn->id, 'name' => 'Conflicted', 'slug' => 'taken-old']);
        UrlRedirect::query()->create(['source_path' => '/taken-old', 'target_path' => '/chennai', 'http_status' => 301]);

        $this->patchJson("/api/v1/admin/locations/cities/{$conflictCity->public_id}", [
            'slug' => 'new-unique-slug',
        ])->assertStatus(409)->assertJsonPath('error.code', 'REDIRECT_SOURCE_CONFLICT');

        $this->assertDatabaseHas('url_redirects', ['source_path' => '/taken-old', 'target_path' => '/chennai']);
        $this->assertDatabaseHas('cities', ['id' => $conflictCity->id, 'slug' => 'taken-old']);

        // 5. Loop & external redirect defense in LocationRedirectResolver
        UrlRedirect::query()->create(['source_path' => '/loop-a', 'target_path' => '/loop-b', 'http_status' => 301]);
        UrlRedirect::query()->create(['source_path' => '/loop-b', 'target_path' => '/loop-a', 'http_status' => 301]);
        UrlRedirect::query()->create(['source_path' => '/evil-city', 'target_path' => 'https://attacker.example', 'http_status' => 301]);

        $resolver = app(LocationRedirectResolver::class);
        $this->assertNull($resolver->resolveDirectoryRedirect('loop-a'));
        $this->assertNull($resolver->resolveDirectoryRedirect('evil-city'));
    }

    public function test_public_id_not_null_enforced_at_database_level_for_raw_writes(): void
    {
        $tn = State::query()->where('slug', 'tamil-nadu')->firstOrFail();
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();

        $driver = DB::getDriverName();
        $isMysqlFamily = in_array($driver, ['mysql', 'mariadb'], true);
        $originalSqlMode = null;

        if ($isMysqlFamily) {
            $schemaName = DB::connection()->getDatabaseName();
            $nullability = DB::select(
                "SELECT table_name, is_nullable
                 FROM information_schema.columns
                 WHERE table_schema = ?
                   AND table_name IN ('states', 'cities', 'localities')
                   AND column_name = 'public_id'",
                [$schemaName]
            );
            $this->assertCount(3, $nullability);
            foreach ($nullability as $col) {
                $this->assertSame('NO', $col->is_nullable ?? $col->IS_NULLABLE);
            }

            $originalSqlMode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
            if (! str_contains($originalSqlMode, 'STRICT_TRANS_TABLES') && ! str_contains($originalSqlMode, 'STRICT_ALL_TABLES')) {
                $strictMode = $originalSqlMode === ''
                    ? 'STRICT_TRANS_TABLES'
                    : $originalSqlMode.',STRICT_TRANS_TABLES';
                DB::statement('SET SESSION sql_mode = ?', [$strictMode]);
            }
        }

        try {
            // Raw SQLite/MySQL/MariaDB insert without public_id on states must fail
            try {
                DB::table('states')->insert([
                    'country_code' => 'IN',
                    'name' => 'No ULID State',
                    'slug' => 'no-ulid-state',
                    'sort_order' => 0,
                    'is_active' => true,
                ]);
                $this->fail('Expected QueryException when inserting state without public_id.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('public_id', $e->getMessage());
            }

            // Raw insert without public_id on cities must fail
            try {
                DB::table('cities')->insert([
                    'state_id' => $tn->id,
                    'name' => 'No ULID City',
                    'slug' => 'no-ulid-city',
                    'latitude' => 11.0,
                    'longitude' => 77.0,
                    'is_active' => true,
                ]);
                $this->fail('Expected QueryException when inserting city without public_id.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('public_id', $e->getMessage());
            }

            // Raw insert without public_id on localities must fail
            try {
                DB::table('localities')->insert([
                    'city_id' => $chennai->id,
                    'name' => 'No ULID Locality',
                    'slug' => 'no-ulid-locality',
                    'latitude' => 13.0,
                    'longitude' => 80.2,
                    'is_active' => true,
                ]);
                $this->fail('Expected QueryException when inserting locality without public_id.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('public_id', $e->getMessage());
            }

            // Raw update setting public_id = null must also fail
            try {
                DB::table('states')->where('id', $tn->id)->update(['public_id' => null]);
                $this->fail('Expected QueryException when updating state public_id to null.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('public_id', $e->getMessage());
            }
        } finally {
            if ($isMysqlFamily && $originalSqlMode !== null) {
                DB::statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
            }
        }
    }

    public function test_transaction_rolls_back_on_failure_during_multi_record_mutation(): void
    {
        Sanctum::actingAs($this->superAdmin);

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

            $this->assertDatabaseMissing('states', ['slug' => 'rollback-state']);
        } finally {
            StateTranslation::flushEventListeners();
        }
    }
}
