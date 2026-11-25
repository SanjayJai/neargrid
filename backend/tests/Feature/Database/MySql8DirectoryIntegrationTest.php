<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domains\Directory\Models\Business;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TruncatesMysqlDatabase;
use Tests\TestCase;

final class MySql8DirectoryIntegrationTest extends TestCase
{
    use TruncatesMysqlDatabase;

    public function test_mysql8_business_location_point_srid_4326_and_fulltext_index(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Requires actual MySQL 8.0 (current driver: '.DB::getDriverName().'). '
                .'Start MySQL 8.0 via docker-compose.yml to verify businesses.location_point SRID 4326 and FULLTEXT indexes.'
            );
        }

        $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
        if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '8.0.0', '<')) {
            $this->markTestSkipped(
                "Requires actual MySQL >= 8.0.0, not MariaDB or older MySQL (detected: {$version})."
            );
        }

        $this->seed(DatabaseSeeder::class);

        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $city->id)->where('slug', 't-nagar')->firstOrFail();
        $adyar = Locality::query()->where('city_id', $city->id)->where('slug', 'adyar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        $biz = Business::factory()->create([
            'primary_category_id' => $dentists->id,
            'city_id' => $city->id,
            'locality_id' => $adyar->id,
            'name' => 'Adyar Dental Care',
            'latitude' => $adyar->latitude,
            'longitude' => $adyar->longitude,
        ]);

        $distMeters = (float) DB::selectOne(
            'SELECT ST_Distance_Sphere(
                (SELECT location_point FROM businesses WHERE id = ?),
                (SELECT center_point FROM localities WHERE id = ?)
            ) AS dist_m',
            [$biz->id, $tNagar->id]
        )->dist_m;

        $distKm = $distMeters / 1000.0;
        $this->assertGreaterThan(4.5, $distKm);
        $this->assertLessThan(5.8, $distKm);

        $ftMatch = DB::select(
            'SELECT id FROM businesses WHERE MATCH(name, tagline, description, search_keywords) AGAINST (? IN NATURAL LANGUAGE MODE)',
            ['Dental']
        );
        $this->assertNotEmpty($ftMatch);
    }
}
