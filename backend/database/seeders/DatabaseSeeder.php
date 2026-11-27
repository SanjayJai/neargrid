<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Reference\AdPlacementsSeeder;
use Database\Seeders\Reference\AttributesSeeder;
use Database\Seeders\Reference\CategoriesSeeder;
use Database\Seeders\Reference\LocationsSeeder;
use Database\Seeders\Reference\RolesAndPermissionsSeeder;
use Database\Seeders\Reference\SearchSynonymsSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds idempotent production-safe reference data only.
     * For local synthetic test accounts, run DevSampleSeeder explicitly.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            LocationsSeeder::class,
            CategoriesSeeder::class,
            AttributesSeeder::class,
            SearchSynonymsSeeder::class,
            AdPlacementsSeeder::class,
        ]);
    }
}
