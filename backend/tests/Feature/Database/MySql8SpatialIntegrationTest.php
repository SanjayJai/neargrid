<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MySql8SpatialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql8_srid_4326_spatial_distance_and_fulltext_indexes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Requires a live MySQL 8.0 connection (current driver: '.DB::getDriverName().'). '
                .'Start MySQL 8.0 via docker-compose.yml to execute SRID 4326 spatial and FULLTEXT integration tests.'
            );
        }

        $this->seed(DatabaseSeeder::class);

        $tNagar = Locality::query()->where('slug', 't-nagar')->firstOrFail();
        $adyar = Locality::query()->where('slug', 'adyar')->firstOrFail();

        $distanceMeters = (float) DB::selectOne(
            'SELECT ST_Distance_Sphere(
                (SELECT center_point FROM localities WHERE id = ?),
                (SELECT center_point FROM localities WHERE id = ?)
            ) AS dist_m',
            [$tNagar->id, $adyar->id]
        )->dist_m;

        // Known geodesic distance between T. Nagar and Adyar in Chennai is ~5.1 km.
        $distanceKm = $distanceMeters / 1000.0;
        $this->assertGreaterThan(4.5, $distanceKm);
        $this->assertLessThan(5.8, $distanceKm);

        // Verify FULLTEXT index on category_translations matches Tamil & English keywords.
        $tamilMatch = DB::select(
            'SELECT category_id FROM category_translations WHERE MATCH(name, synonyms) AGAINST (? IN NATURAL LANGUAGE MODE)',
            ['பல் மருத்துவர்']
        );
        $this->assertNotEmpty($tamilMatch);

        // Verify City & Locality SRID 4326 center_point persistence on insert and update.
        $city = City::factory()->create([
            'state_id' => $tNagar->city->state_id,
            'latitude' => 12.9165167,
            'longitude' => 79.1324986,
        ]);

        $citySrid = (int) DB::selectOne('SELECT ST_SRID(center_point) AS srid FROM cities WHERE id = ?', [$city->id])->srid;
        $this->assertSame(4326, $citySrid);

        $city->update(['latitude' => 12.9500000, 'longitude' => 79.1500000]);
        $updatedLat = (float) DB::selectOne('SELECT ST_Latitude(center_point) AS lat FROM cities WHERE id = ?', [$city->id])->lat;
        $this->assertEqualsWithDelta(12.95, $updatedLat, 0.001);

        $locality = Locality::factory()->create([
            'city_id' => $city->id,
            'latitude' => 12.9698000,
            'longitude' => 79.1459000,
        ]);
        $locSrid = (int) DB::selectOne('SELECT ST_SRID(center_point) AS srid FROM localities WHERE id = ?', [$locality->id])->srid;
        $this->assertSame(4326, $locSrid);
    }
}
