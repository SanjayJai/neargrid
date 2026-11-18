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
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('schema_org_type', 80)->default('LocalBusiness');
            $table->string('icon_name', 80)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['parent_id', 'name'], 'uniq_categories_parent_name');
            $table->index(['parent_id', 'is_active', 'sort_order'], 'idx_categories_parent_active_sort');
            $table->index(['is_featured', 'is_active', 'sort_order'], 'idx_categories_featured_active_sort');
        });

        Schema::create('category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 140);
            $table->text('description')->nullable();
            $table->text('synonyms')->nullable();
            $table->string('seo_title_template', 180)->nullable();
            $table->string('seo_description_template', 320)->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'locale'], 'uniq_category_translations_category_locale');
            $table->index(['locale', 'name'], 'idx_category_translations_locale_name');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE category_translations ADD FULLTEXT INDEX idx_cat_trans_fulltext (name, synonyms)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translations');

        if (Schema::hasTable('categories')) {
            DB::table('categories')->whereNotNull('parent_id')->update(['parent_id' => null]);
        }

        Schema::dropIfExists('categories');
    }
};
