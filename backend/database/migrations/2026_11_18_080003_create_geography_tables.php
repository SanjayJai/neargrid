<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->char('country_code', 2)->default('IN');
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_code', 'slug'], 'uniq_states_country_slug');
            $table->unique(['country_code', 'name'], 'uniq_states_country_name');
            $table->index(['country_code', 'is_active']);
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->boolean('is_popular')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['state_id', 'name'], 'uniq_cities_state_name');
            $table->index(['is_active', 'is_popular', 'sort_order'], 'idx_cities_active_popular_sort');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE cities ADD COLUMN center_point POINT NOT NULL SRID 4326 AFTER longitude');
            DB::statement('ALTER TABLE cities ADD SPATIAL INDEX idx_cities_center_point (center_point)');
        }

        Schema::create('city_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();

            $table->unique(['city_id', 'locale'], 'uniq_city_translations_city_locale');
            $table->index(['locale', 'name'], 'idx_city_translations_locale_name');
        });

        Schema::create('localities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('pincode', 12)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'slug'], 'uniq_localities_city_slug');
            $table->unique(['city_id', 'name'], 'uniq_localities_city_name');
            $table->index(['city_id', 'pincode'], 'idx_localities_city_pincode');
            $table->index(['city_id', 'is_active', 'is_popular'], 'idx_localities_city_active_popular');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE localities ADD COLUMN center_point POINT NOT NULL SRID 4326 AFTER longitude');
            DB::statement('ALTER TABLE localities ADD SPATIAL INDEX idx_localities_center_point (center_point)');
        }

        Schema::create('locality_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('locality_id')->constrained('localities')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 140);
            $table->timestamps();

            $table->unique(['locality_id', 'locale'], 'uniq_locality_translations_locality_locale');
            $table->index(['locale', 'name'], 'idx_locality_translations_locale_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locality_translations');
        Schema::dropIfExists('localities');
        Schema::dropIfExists('city_translations');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
    }
};
