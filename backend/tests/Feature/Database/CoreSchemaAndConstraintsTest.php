<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\CityTranslation;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryTranslation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Development\DevSampleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class CoreSchemaAndConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ulid_generation_and_internal_id_hiding_on_public_models(): void
    {
        $user = User::factory()->otpOnly()->create();
        $challenge = OtpChallenge::factory()->create(['phone_e164' => $user->phone_e164]);
        $category = Category::factory()->create();

        $this->assertTrue(Str::isUlid($user->public_id));
        $this->assertTrue(Str::isUlid($challenge->challenge_id));
        $this->assertTrue(Str::isUlid($category->public_id));

        $this->assertSame($user->id, User::findByPublicId($user->public_id)?->id);
        $this->assertSame($challenge->id, OtpChallenge::findByPublicId($challenge->challenge_id)?->id);
        $this->assertSame($category->id, Category::findByPublicId($category->public_id)?->id);

        // Internal database IDs and secrets must never leak via default serialization.
        $userArray = $user->toArray();
        $this->assertArrayHasKey('public_id', $userArray);
        $this->assertArrayNotHasKey('id', $userArray);
        $this->assertArrayNotHasKey('password', $userArray);
        $this->assertArrayNotHasKey('totp_secret_encrypted', $userArray);

        $challengeArray = $challenge->toArray();
        $this->assertArrayHasKey('challenge_id', $challengeArray);
        $this->assertArrayNotHasKey('id', $challengeArray);
        $this->assertArrayNotHasKey('code_hash', $challengeArray);

        $categoryArray = $category->toArray();
        $this->assertArrayHasKey('public_id', $categoryArray);
        $this->assertArrayNotHasKey('id', $categoryArray);
        $this->assertArrayNotHasKey('parent_id', $categoryArray);
    }

    public function test_sanctum_personal_access_tokens_and_otp_challenge_helpers(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('flutter-android', ['customer:read', 'customer:enquiry']);

        $this->assertStringStartsWith((string) config('sanctum.token_prefix', 'lbd_'), explode('|', $newToken->plainTextToken)[1]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'flutter-android',
        ]);

        $activeOtp = OtpChallenge::factory()->create();
        $expiredOtp = OtpChallenge::factory()->expired()->create();
        $verifiedOtp = OtpChallenge::factory()->verified()->create();

        $this->assertFalse($activeOtp->isExpired());
        $this->assertFalse($activeOtp->isVerified());
        $this->assertTrue($expiredOtp->isExpired());
        $this->assertTrue($verifiedOtp->isVerified());
    }

    public function test_rbac_relationships_and_restrict_delete_policy(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $role = Role::factory()->create(['name' => 'business_owner', 'label' => 'Business Owner']);
        $permission = Permission::factory()->create(['name' => 'leads.manage_own']);

        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['assigned_by_user_id' => $admin->id]);

        $this->assertTrue($user->hasRole('business_owner'));
        $this->assertTrue($user->hasPermission('leads.manage_own'));
        $this->assertFalse($user->hasPermission('kyc.review'));

        // ON DELETE RESTRICT: deleting a role assigned to users must fail.
        $this->expectException(QueryException::class);
        $role->delete();
    }

    public function test_user_force_delete_cascades_devices_and_role_pivots(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => 'customer']);
        $user->roles()->attach($role->id);
        $device = UserDevice::factory()->create(['user_id' => $user->id]);

        $user->forceDelete();

        $this->assertDatabaseMissing('user_devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('user_roles', ['user_id' => $user->id, 'role_id' => $role->id]);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_geography_foreign_key_restrict_and_cascade_policies(): void
    {
        $state = State::factory()->create();
        $city = City::factory()->create(['state_id' => $state->id]);
        $locality = Locality::factory()->create(['city_id' => $city->id]);

        CityTranslation::query()->create([
            'city_id' => $city->id,
            'locale' => 'ta',
            'name' => 'சென்னை',
        ]);

        // Deleting a state with cities must fail (RESTRICT).
        try {
            $state->delete();
            $this->fail('Expected QueryException when deleting a state that has cities.');
        } catch (QueryException) {
            $this->assertDatabaseHas('states', ['id' => $state->id]);
        }

        // Deleting a city with localities must fail (RESTRICT).
        try {
            $city->delete();
            $this->fail('Expected QueryException when deleting a city that has localities.');
        } catch (QueryException) {
            $this->assertDatabaseHas('cities', ['id' => $city->id]);
        }

        // Removing the locality allows city deletion and cascades city_translations.
        $locality->delete();
        $city->delete();
        $this->assertDatabaseMissing('city_translations', ['city_id' => $city->id]);
    }

    public function test_category_hierarchy_restrict_and_translation_cascade(): void
    {
        $parent = Category::factory()->create(['name' => 'Healthcare', 'slug' => 'healthcare']);
        $child = Category::factory()->childOf($parent)->create(['name' => 'Dentists', 'slug' => 'dentists']);

        CategoryTranslation::query()->create([
            'category_id' => $child->id,
            'locale' => 'ta',
            'name' => 'பல் மருத்துவர்கள்',
        ]);

        $this->assertSame($parent->id, $child->parent?->id);
        $this->assertCount(1, $parent->children);

        // Deleting parent while children exist must fail (RESTRICT).
        try {
            $parent->delete();
            $this->fail('Expected QueryException when deleting a parent category with subcategories.');
        } catch (QueryException) {
            $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        }

        // Deleting leaf child cascades category_translations.
        $child->delete();
        $this->assertDatabaseMissing('category_translations', ['category_id' => $child->id]);
    }

    public function test_unique_constraints_prevent_duplicate_users_cities_localities_and_categories(): void
    {
        // Duplicate phone_e164
        User::factory()->create(['phone_e164' => '+919820012345']);
        $this->assertThrows(
            fn () => User::factory()->create(['phone_e164' => '+919820012345']),
            QueryException::class
        );

        // Duplicate city slug and duplicate city name within same state
        $state = State::factory()->create();
        $city = City::factory()->create(['state_id' => $state->id, 'name' => 'Chennai', 'slug' => 'chennai']);

        $this->assertThrows(
            fn () => City::factory()->create(['state_id' => $state->id, 'name' => 'Chennai Duplicate', 'slug' => 'chennai']),
            QueryException::class
        );
        $this->assertThrows(
            fn () => City::factory()->create(['state_id' => $state->id, 'name' => 'Chennai', 'slug' => 'chennai-2']),
            QueryException::class
        );

        // Duplicate locality slug and name within same city
        Locality::factory()->create(['city_id' => $city->id, 'name' => 'Adyar', 'slug' => 'adyar']);
        $this->assertThrows(
            fn () => Locality::factory()->create(['city_id' => $city->id, 'name' => 'Adyar Two', 'slug' => 'adyar']),
            QueryException::class
        );
        $this->assertThrows(
            fn () => Locality::factory()->create(['city_id' => $city->id, 'name' => 'Adyar', 'slug' => 'adyar-2']),
            QueryException::class
        );

        // Duplicate category slug and subcategory name under same parent
        $parent = Category::factory()->create(['slug' => 'home-services']);
        Category::factory()->childOf($parent)->create(['name' => 'Plumbers', 'slug' => 'plumbers']);

        $this->assertThrows(
            fn () => Category::factory()->childOf($parent)->create(['name' => 'Plumbers Two', 'slug' => 'plumbers']),
            QueryException::class
        );
        $this->assertThrows(
            fn () => Category::factory()->childOf($parent)->create(['name' => 'Plumbers', 'slug' => 'plumbers-2']),
            QueryException::class
        );

        // Duplicate user device identifier per user
        $device = UserDevice::factory()->create();
        $this->assertThrows(
            fn () => UserDevice::factory()->create([
                'user_id' => $device->user_id,
                'device_identifier' => $device->device_identifier,
            ]),
            QueryException::class
        );
    }

    public function test_reference_seeders_are_idempotent_and_support_english_and_tamil_retrieval(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roleCount = Role::query()->count();
        $permissionCount = Permission::query()->count();
        $cityCount = City::query()->count();
        $localityCount = Locality::query()->count();
        $categoryCount = Category::query()->count();

        // Re-running reference seeders must remain strictly idempotent.
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($roleCount, Role::query()->count());
        $this->assertSame($permissionCount, Permission::query()->count());
        $this->assertSame($cityCount, City::query()->count());
        $this->assertSame($localityCount, Locality::query()->count());
        $this->assertSame($categoryCount, Category::query()->count());
        $this->assertSame(0, User::query()->count(), 'Reference seeders must not seed synthetic users.');

        // Verify English + Tamil category retrieval & fallback
        $dentists = Category::query()
            ->withLocale('ta')
            ->where('slug', 'dentists')
            ->firstOrFail();

        $this->assertSame('Dentists', $dentists->translatedName('en'));
        $this->assertSame('பல் மருத்துவர்கள்', $dentists->translatedName('ta'));
        $this->assertSame('Dentists', $dentists->translatedName('fr')); // Fallback to English
        $this->assertStringContainsString('பல் மருத்துவர்', (string) $dentists->translatedSynonyms('ta'));
        $this->assertStringContainsString('pal maruthuvar', (string) $dentists->translatedSynonyms('en'));
        $this->assertSame('Healthcare', $dentists->parent?->translatedName('en'));
        $this->assertSame('மருத்துவம் மற்றும் சுகாதாரம்', $dentists->parent?->translatedName('ta'));

        // Verify English + Tamil city & locality retrieval
        $chennai = City::query()->withLocale('ta')->where('slug', 'chennai')->firstOrFail();
        $this->assertSame('Chennai', $chennai->translatedName('en'));
        $this->assertSame('சென்னை', $chennai->translatedName('ta'));

        $adyar = Locality::query()->withLocale('ta')->where('slug', 'adyar')->firstOrFail();
        $this->assertSame('Adyar', $adyar->translatedName('en'));
        $this->assertSame('அடையாறு', $adyar->translatedName('ta'));
    }

    public function test_dev_sample_seeder_runs_outside_production_and_blocks_production(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DevSampleSeeder::class);

        $owner = User::query()->where('email', 'dev.owner@localbiz.test')->first();
        $admin = User::query()->where('email', 'dev.admin@localbiz.test')->first();

        $this->assertNotNull($owner);
        $this->assertNotNull($admin);
        $this->assertTrue($owner->hasRole('business_owner'));
        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertTrue($admin->hasPermission('kyc.review'));

        // Guard against accidental execution in production.
        $originalEnv = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            $this->expectException(RuntimeException::class);
            (new DevSampleSeeder)->run();
        } finally {
            app()->detectEnvironment(static fn () => $originalEnv);
        }
    }
}
