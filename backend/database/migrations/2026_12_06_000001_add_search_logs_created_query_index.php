<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_query_logs', function (Blueprint $table): void {
            $table->index(['created_at', 'display_query'], 'idx_search_logs_created_query');
        });
    }

    public function down(): void
    {
        Schema::table('search_query_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_search_logs_created_query');
        });
    }
};
