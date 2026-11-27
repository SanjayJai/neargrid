<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Domains\Advertising\Services\AdPlacementRegistry;
use Illuminate\Database\Seeder;

class AdPlacementsSeeder extends Seeder
{
    public function run(): void
    {
        AdPlacementRegistry::syncDefaults();
    }
}
