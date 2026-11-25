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
        Schema::create('seo_metadata', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            // Nullable domain_id intentionally has no FK until M-A2 creates the domains table.
            $table->unsignedBigInteger('domain_id')->nullable()->index();
            // Non-null scope_key ('global' or 'domain:<id>') guarantees uniqueness even when domain_id is NULL.
            $table->string('scope_key', 64)->default('global');
            $table->string('locale', 10);
            $table->string('meta_title', 180)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('canonical_override_url', 500)->nullable();
            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->json('robots_directives')->nullable();
            $table->string('og_title', 180)->nullable();
            $table->string('og_description', 320)->nullable();
            $table->string('og_image_url', 500)->nullable();
            $table->string('og_image_alt', 200)->nullable();
            $table->string('schema_type', 80)->nullable();
            $table->json('schema_overrides_json')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'scope_key', 'locale'], 'uniq_seo_meta_entity_scope_locale');
            $table->index(['entity_type', 'entity_id', 'locale'], 'idx_seo_meta_entity_locale');
        });

        Schema::create('seo_revisions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('seo_metadata_id')->constrained('seo_metadata')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot_json');
            $table->string('change_summary', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['seo_metadata_id', 'version_number'], 'uniq_seo_revisions_meta_version');
        });

        Schema::table('seo_metadata', function (Blueprint $table): void {
            $table->foreign('published_revision_id')
                ->references('id')
                ->on('seo_revisions')
                ->nullOnDelete();
        });

        Schema::table('url_redirects', function (Blueprint $table): void {
            $table->string('entity_type', 100)->nullable()->after('http_status');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            $table->boolean('is_active')->default(true)->after('entity_id');

            $table->index(['entity_type', 'entity_id'], 'idx_url_redirects_entity');
        });

        DB::table('url_redirects')->whereNull('is_active')->update(['is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('url_redirects', function (Blueprint $table): void {
            $table->dropIndex('idx_url_redirects_entity');
            $table->dropColumn(['entity_type', 'entity_id', 'is_active']);
        });

        Schema::table('seo_metadata', function (Blueprint $table): void {
            $table->dropForeign(['published_revision_id']);
        });

        Schema::dropIfExists('seo_revisions');
        Schema::dropIfExists('seo_metadata');
    }
};
