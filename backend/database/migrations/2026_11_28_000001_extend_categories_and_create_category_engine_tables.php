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
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('node_type', 32)->default('subcategory')->after('parent_id');
            $table->string('lifecycle_status', 24)->default('published')->after('depth');
            $table->boolean('is_system')->default(false)->after('lifecycle_status');
            $table->boolean('is_assignable')->default(true)->after('is_system');
            $table->boolean('has_landing_page')->default(true)->after('is_assignable');
            $table->boolean('show_on_homepage')->default(false)->after('is_featured');
            $table->boolean('is_popular')->default(false)->after('show_on_homepage');
            $table->unsignedBigInteger('published_version_id')->nullable()->after('is_active');
            $table->timestamp('archived_at')->nullable()->after('updated_at');
            $table->softDeletes();

            $table->index(['node_type', 'lifecycle_status', 'is_active'], 'idx_categories_node_lifecycle_active');
            $table->index(['show_on_homepage', 'is_active', 'sort_order'], 'idx_categories_homepage_active_sort');
        });

        // Backfill existing categories without altering existing slugs or parent relationships.
        DB::table('categories')
            ->whereNull('parent_id')
            ->update([
                'node_type' => 'main_category',
                'lifecycle_status' => 'published',
                'is_assignable' => true,
            ]);

        DB::table('categories')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->update(['show_on_homepage' => true]);

        DB::table('categories')
            ->whereNotNull('parent_id')
            ->update([
                'node_type' => 'subcategory',
                'lifecycle_status' => 'published',
                'is_assignable' => true,
            ]);

        Schema::create('category_revisions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 24);
            $table->json('snapshot_json');
            $table->string('change_summary', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['category_id', 'version_number'], 'uniq_cat_revisions_cat_version');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign('published_version_id')
                ->references('id')
                ->on('category_revisions')
                ->nullOnDelete();
        });

        Schema::create('category_collections', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('slug', 140)->unique();
            $table->string('name_en', 140);
            $table->string('name_ta', 140)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ta')->nullable();
            $table->string('icon_name', 80)->nullable();
            $table->string('lifecycle_status', 24)->default('draft');
            $table->boolean('show_on_homepage')->default(false);
            $table->boolean('is_popular')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lifecycle_status', 'is_active', 'sort_order'], 'idx_cat_collections_status_active_sort');
        });

        Schema::create('category_collection_items', function (Blueprint $table): void {
            $table->foreignId('collection_id')->constrained('category_collections')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['collection_id', 'category_id']);
        });

        Schema::create('category_attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('attribute_key', 80);
            $table->string('label_en', 120);
            $table->string('label_ta', 120)->nullable();
            $table->string('data_type', 24);
            $table->json('options_json')->nullable();
            $table->string('unit', 32)->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_required')->default(false);
            $table->boolean('show_on_profile')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'attribute_key'], 'uniq_cat_attr_defs_cat_key');
        });

        Schema::create('category_filter_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('filter_key', 80);
            $table->string('filter_type', 24);
            $table->string('label_en', 120);
            $table->string('label_ta', 120)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'filter_key'], 'uniq_cat_filter_cat_key');
        });

        Schema::create('category_profile_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('section_key', 60);
            $table->string('display_label_en', 120);
            $table->string('display_label_ta', 120)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_required')->default(false);
            $table->boolean('suppress_when_empty')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'section_key'], 'uniq_cat_profile_sec_cat_key');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropForeign(['published_version_id']);
        });

        Schema::dropIfExists('category_profile_sections');
        Schema::dropIfExists('category_filter_configs');
        Schema::dropIfExists('category_attribute_definitions');
        Schema::dropIfExists('category_collection_items');
        Schema::dropIfExists('category_collections');
        Schema::dropIfExists('category_revisions');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex('idx_categories_node_lifecycle_active');
            $table->dropIndex('idx_categories_homepage_active_sort');
            $table->dropColumn([
                'node_type',
                'lifecycle_status',
                'is_system',
                'is_assignable',
                'has_landing_page',
                'show_on_homepage',
                'is_popular',
                'published_version_id',
                'archived_at',
                'deleted_at',
            ]);
        });
    }
};
