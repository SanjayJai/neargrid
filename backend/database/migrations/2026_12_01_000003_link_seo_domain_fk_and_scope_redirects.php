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
        // Guard against orphan domain_id values before attaching FK.
        DB::table('seo_metadata')
            ->whereNotNull('domain_id')
            ->whereNotIn('domain_id', DB::table('domains')->select('id'))
            ->update([
                'domain_id' => null,
                'scope_key' => 'global',
            ]);

        Schema::table('seo_metadata', function (Blueprint $table): void {
            $table->foreign('domain_id', 'fk_seo_metadata_domain')
                ->references('id')
                ->on('domains')
                ->nullOnDelete();
        });

        Schema::table('url_redirects', function (Blueprint $table): void {
            $table->foreignId('domain_id')
                ->nullable()
                ->after('id')
                ->constrained('domains')
                ->cascadeOnDelete();
            $table->string('scope_key', 64)
                ->default('global')
                ->after('domain_id');
        });

        DB::table('url_redirects')
            ->whereNull('scope_key')
            ->orWhere('scope_key', '')
            ->update(['scope_key' => 'global']);

        Schema::table('url_redirects', function (Blueprint $table): void {
            $table->dropUnique(['source_path']);
            $table->unique(['scope_key', 'source_path'], 'uniq_url_redirects_scope_source');
        });
    }

    public function down(): void
    {
        Schema::table('url_redirects', function (Blueprint $table): void {
            $table->dropUnique('uniq_url_redirects_scope_source');
            $table->unique('source_path');
            $table->dropForeign(['domain_id']);
            $table->dropColumn(['domain_id', 'scope_key']);
        });

        Schema::table('seo_metadata', function (Blueprint $table): void {
            $table->dropForeign('fk_seo_metadata_domain');
        });
    }
};
