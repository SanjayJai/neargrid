<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saved_businesses', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Composite primary key enforces database-level uniqueness per customer-business pair
            $table->primary(['user_id', 'business_id']);

            // Composite index for fast chronological customer pagination
            $table->index(['user_id', 'created_at'], 'saved_businesses_user_created_idx');

            // Index for business lookups and cascading deletion
            $table->index('business_id', 'saved_businesses_business_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_businesses');
    }
};
