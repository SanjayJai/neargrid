<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->decimal('bayesian_score', 4, 3)->default(3.500)->change();
        });

        DB::table('businesses')->where('review_count', 0)->update(['bayesian_score' => 3.500]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->decimal('bayesian_score', 4, 3)->default(0.000)->change();
        });
    }
};
