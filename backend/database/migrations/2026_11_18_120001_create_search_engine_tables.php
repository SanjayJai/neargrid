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
        Schema::create('search_synonyms', function (Blueprint $table): void {
            $table->id();
            $table->string('term', 120);
            $table->string('normalized_term', 120);
            $table->string('canonical_term', 120);
            $table->string('locale', 10)->default('en'); // en, ta, ta-Latn
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->decimal('weight', 3, 2)->default(1.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['normalized_term', 'canonical_term', 'locale'], 'uniq_search_synonyms_term_canon_locale');
            $table->index(['is_active', 'normalized_term'], 'idx_search_synonyms_active_norm');
            $table->index(['is_active', 'canonical_term'], 'idx_search_synonyms_active_canon');
        });

        Schema::create('sponsored_placements', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('locality_id')->nullable()->constrained('localities')->restrictOnDelete();
            $table->string('status', 24)->default('active'); // active, paused, exhausted, expired, rejected
            $table->unsignedInteger('bid_minor')->default(0);
            $table->unsignedBigInteger('budget_minor')->default(0);
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(
                ['status', 'city_id', 'category_id', 'starts_at', 'ends_at'],
                'idx_sponsored_active_lookup'
            );
            $table->index(['business_id', 'status'], 'idx_sponsored_business_status');
        });

        Schema::create('search_query_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_query', 160)->nullable();
            $table->string('display_query', 160)->nullable();
            $table->string('city_slug', 100)->nullable();
            $table->string('category_slug', 140)->nullable();
            $table->string('locality_slug', 140)->nullable();
            $table->string('locale', 10)->default('en');
            $table->boolean('has_geo')->default(false);
            $table->string('sort_by', 24)->default('relevance');
            $table->unsignedInteger('result_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('engine_driver', 24)->default('mysql');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['city_slug', 'created_at'], 'idx_search_logs_city_created');
            $table->index(['duration_ms', 'created_at'], 'idx_search_logs_duration_created');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->index(['status', 'city_id', 'name'], 'idx_businesses_search_active_city_name');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE business_services ADD FULLTEXT INDEX idx_biz_services_fulltext (name, description)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('business_services')) {
            DB::statement('ALTER TABLE business_services DROP INDEX idx_biz_services_fulltext');
        }

        if (Schema::hasTable('businesses')) {
            Schema::table('businesses', function (Blueprint $table): void {
                $table->dropIndex('idx_businesses_search_active_city_name');
            });
        }

        Schema::dropIfExists('search_query_logs');
        Schema::dropIfExists('sponsored_placements');
        Schema::dropIfExists('search_synonyms');
    }
};
