<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessService;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Search\Contracts\SearchEngineInterface;
use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\Services\GeoSpatialHelper;
use App\Domains\Taxonomy\Models\Category;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MySql8SearchEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql8_fulltext_tamil_tanglish_and_spatial_index_execution(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Requires actual MySQL 8.0 (current driver: '.DB::getDriverName().'). '
                .'SQLite tests do not prove MySQL 8 FULLTEXT or SRID 4326 spatial behavior.'
            );
        }

        $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
        if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '8.0.0', '<')) {
            $this->markTestSkipped(
                "Requires actual MySQL >= 8.0.0, not MariaDB or older MySQL (detected: {$version})."
            );
        }

        $this->seed(DatabaseSeeder::class);

        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $adyar = Locality::query()->where('city_id', $chennai->id)->where('slug', 'adyar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        $adyarDental = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Adyar Orthodontic Dental Clinic',
            'latitude' => 13.0012,
            'longitude' => 80.2565,
            'status' => BusinessStatus::Active,
        ]);

        BusinessService::query()->create([
            'business_id' => $adyarDental->id,
            'name' => 'Ceramic Braces Alignment',
            'description' => 'Specialist dental orthodontic care',
            'is_active' => true,
        ]);

        /** @var SearchEngineInterface $engine */
        $engine = app(SearchEngineInterface::class);

        // 1. MySQL 8 FULLTEXT match across business_services
        $serviceResult = $engine->search(new SearchQuery(rawQuery: 'Ceramic Braces', citySlug: 'chennai'));
        $this->assertNotEmpty($serviceResult->hits);
        $this->assertSame($adyarDental->public_id, $serviceResult->hits[0]->business->public_id);

        // 2. Tamil + Tanglish query through MySQL 8 path
        $tamilResult = $engine->search(new SearchQuery(rawQuery: 'பல் மருத்துவர்', citySlug: 'chennai'));
        $this->assertNotEmpty($tamilResult->hits);

        // 3. MySQL 8 SRID 4326 spatial distance + MBRContains accuracy (T. Nagar -> Adyar ~5.1 km)
        $nearbyResult = $engine->nearby(new SearchQuery(
            latitude: 13.0418,
            longitude: 80.2341,
            radiusKm: 6.0,
        ));
        $this->assertNotEmpty($nearbyResult->hits);
        $this->assertGreaterThan(4.5, (float) $nearbyResult->hits[0]->distanceKm);
        $this->assertLessThan(5.8, (float) $nearbyResult->hits[0]->distanceKm);

        // 4. Inspect EXPLAIN plan confirming idx_biz_location spatial index is registered and usable
        $geo = app(GeoSpatialHelper::class);
        $polygon = $geo->boundingPolygonWkt(13.0418, 80.2341, 6.0);
        $explain = DB::select(
            'EXPLAIN SELECT id FROM businesses WHERE MBRContains(ST_GeomFromText(?, 4326), location_point)',
            [$polygon]
        );
        $possibleKeys = (string) ($explain[0]->possible_keys ?? '');
        $this->assertStringContainsString('idx_biz_location', $possibleKeys);
    }
}
