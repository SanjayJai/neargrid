<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

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
    }
}
