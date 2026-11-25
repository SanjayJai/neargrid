<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domains\Taxonomy\Models\Category;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CategoryAndSeoSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_engine_and_global_seo_tables_columns_and_backfills(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expectedTables = [
            'categories',
            'category_revisions',
            'category_collections',
            'category_collection_items',
            'category_attribute_definitions',
            'category_filter_configs',
            'category_profile_sections',
            'seo_metadata',
            'seo_revisions',
            'url_redirects',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table '{$table}' to exist.");
        }

        // Verify categories extensions and backfill values on seeded categories
        $root = Category::query()->whereNull('parent_id')->firstOrFail();
        $child = Category::query()->whereNotNull('parent_id')->firstOrFail();

        $this->assertSame('main_category', $root->node_type);
        $this->assertSame('published', $root->lifecycle_status);
        $this->assertTrue((bool) $root->is_assignable);
        $this->assertSame('subcategory', $child->node_type);
        $this->assertSame('published', $child->lifecycle_status);

        // Verify url_redirects extension columns
        $this->assertTrue(Schema::hasColumns('url_redirects', ['entity_type', 'entity_id', 'is_active']));
    }

    public function test_seo_metadata_scope_key_prevents_duplicate_global_rows_and_revisions_link_cleanly(): void
    {
        $seoId = DB::table('seo_metadata')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'entity_type' => 'category',
            'entity_id' => 1,
            'domain_id' => null,
            'scope_key' => 'global',
            'locale' => 'en',
            'meta_title' => 'Restaurants in Vellore',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revId = DB::table('seo_revisions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'seo_metadata_id' => $seoId,
            'version_number' => 1,
            'snapshot_json' => json_encode(['meta_title' => 'Restaurants in Vellore']),
            'created_at' => now(),
        ]);

        DB::table('seo_metadata')->where('id', $seoId)->update(['published_revision_id' => $revId]);

        $this->expectException(QueryException::class);

        DB::table('seo_metadata')->insert([
            'public_id' => (string) Str::ulid(),
            'entity_type' => 'category',
            'entity_id' => 1,
            'domain_id' => null,
            'scope_key' => 'global',
            'locale' => 'en',
            'meta_title' => 'Duplicate Global Row',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
