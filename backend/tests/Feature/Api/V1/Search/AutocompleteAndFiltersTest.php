<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Search;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessService;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class AutocompleteAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['search.autocomplete.cache_store' => 'array']);
    }

    public function test_autocomplete_returns_grouped_active_suggestions_caches_and_excludes_unpublished(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        $active = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Dental Excellence T Nagar',
            'status' => BusinessStatus::Active,
            'is_verified' => true,
        ]);
        BusinessService::query()->create([
            'business_id' => $active->id,
            'name' => 'Dental Implants',
            'is_active' => true,
        ]);

        // Unpublished business + service MUST NOT appear in autocomplete or cache
        $draft = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Dental Secret Draft Clinic',
            'status' => BusinessStatus::Draft,
        ]);
        BusinessService::query()->create([
            'business_id' => $draft->id,
            'name' => 'Dental Secret Draft Service',
            'is_active' => true,
        ]);

        // Below minimum length -> empty debounce-friendly response
        $this->getJson('/api/v1/search/suggestions?city=chennai&q=d')
            ->assertOk()
            ->assertJsonPath('meta.cache.source', 'skipped_min_length')
            ->assertJsonCount(0, 'data.businesses');

        // First call populates cache
        $first = $this->getJson('/api/v1/search/suggestions?city=chennai&q=dent')
            ->assertOk()
            ->assertJsonPath('meta.cache.hit', false)
            ->assertHeader('X-Robots-Tag', 'noindex, follow');

        $this->assertNotEmpty($first->json('data.categories'));
        $this->assertSame(['Dental Implants'], array_column($first->json('data.services'), 'name'));
        $this->assertSame([$active->public_id], array_column($first->json('data.businesses'), 'public_id'));
        $this->assertStringNotContainsString('Dental Secret Draft', (string) $first->getContent());

        // Second identical call serves directly from cache
        $second = $this->getJson('/api/v1/search/suggestions?city=chennai&q=dent')
            ->assertOk()
            ->assertJsonPath('meta.cache.hit', true);
        $this->assertSame($first->json('data'), $second->json('data'));

        // Tamil suggestion query works seamlessly
        $tamil = $this->getJson('/api/v1/search/suggestions?city=chennai&locale=ta&q='.urlencode('பிளம்பர்'))
            ->assertOk();
        $this->assertNotEmpty($tamil->json('data.categories'));
    }

    public function test_autocomplete_gracefully_falls_back_to_database_when_redis_is_unavailable(): void
    {
        config(['search.autocomplete.cache_store' => 'redis']);

        $brokenStore = Mockery::mock(Repository::class);
        $brokenStore->shouldReceive('get')->once()->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

        Cache::shouldReceive('store')->with('redis')->andReturn($brokenStore);

        $res = $this->getJson('/api/v1/search/suggestions?city=chennai&q=dent');
        $res->assertOk()
            ->assertJsonPath('meta.cache.hit', false)
            ->assertJsonPath('meta.cache.source', 'database_fallback');

        $this->assertNotEmpty($res->json('data.categories'));
    }

    public function test_autocomplete_rate_limiting_protects_against_excessive_requests(): void
    {
        config(['search.autocomplete.rate_limit_per_minute' => 10]);

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/search/suggestions?q=dent')->assertOk();
        }

        $this->getJson('/api/v1/search/suggestions?q=dent')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_search_filters_endpoint_returns_localized_facets_and_active_counts(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'status' => BusinessStatus::Active,
            'is_verified' => true,
        ]);

        // Suspended listing must not inflate active counts
        Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'status' => BusinessStatus::Suspended,
            'is_verified' => true,
        ]);

        $res = $this->getJson('/api/v1/search/filters?city=chennai&category=dentists&locale=ta');
        $res->assertOk()
            ->assertJsonPath('meta.counts.total_active', 1)
            ->assertJsonPath('meta.counts.verified_active', 1)
            ->assertJsonStructure([
                'data' => [
                    'cities',
                    'localities',
                    'categories',
                    'amenities',
                    'payment_methods',
                    'rating_options',
                    'sort_options',
                    'radius' => ['min_km', 'max_km', 'default_km'],
                ],
            ]);
    }
}
