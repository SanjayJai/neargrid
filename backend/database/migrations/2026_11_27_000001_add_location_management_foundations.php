<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('states', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable()->after('id');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('slug');
        });

        Schema::table('cities', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable()->after('id');
        });

        Schema::table('localities', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable()->after('id');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_popular');
        });

        // Backfill deterministic ULIDs for all existing location rows before enforcing UNIQUE constraints.
        foreach (['states', 'cities', 'localities'] as $tableName) {
            DB::table($tableName)
                ->whereNull('public_id')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)
                            ->where('id', $row->id)
                            ->update(['public_id' => (string) Str::ulid()]);
                    }
                });
        }

        Schema::table('states', function (Blueprint $table): void {
            $table->unique('public_id', 'uniq_states_public_id');
            $table->index(['is_active', 'sort_order'], 'idx_states_active_sort');
        });

        Schema::table('cities', function (Blueprint $table): void {
            $table->unique('public_id', 'uniq_cities_public_id');
        });

        Schema::table('localities', function (Blueprint $table): void {
            $table->unique('public_id', 'uniq_localities_public_id');
            $table->index(['city_id', 'is_active', 'sort_order'], 'idx_localities_city_active_sort');
        });

        Schema::create('state_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('state_id')->constrained('states')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 140);
            $table->timestamps();

            $table->unique(['state_id', 'locale'], 'uniq_state_translations_state_locale');
            $table->index(['locale', 'name'], 'idx_state_translations_locale_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('state_translations');

        Schema::table('localities', function (Blueprint $table): void {
            $table->dropIndex('idx_localities_city_active_sort');
            $table->dropUnique('uniq_localities_public_id');
            $table->dropColumn(['public_id', 'sort_order']);
        });

        Schema::table('cities', function (Blueprint $table): void {
            $table->dropUnique('uniq_cities_public_id');
            $table->dropColumn('public_id');
        });

        Schema::table('states', function (Blueprint $table): void {
            $table->dropIndex('idx_states_active_sort');
            $table->dropUnique('uniq_states_public_id');
            $table->dropColumn(['public_id', 'sort_order']);
        });
    }
};
