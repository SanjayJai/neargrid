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
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type')->index();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('category', 32)->index();
            $table->string('deduplication_key', 128)->nullable();
            $table->text('data');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();

            // Database-level atomic notification deduplication per user
            $table->unique(
                ['notifiable_type', 'notifiable_id', 'deduplication_key'],
                'notifications_dedup_unique'
            );

            // Composite indexes for user feed filtering, category tabs, and unread counts
            $table->index(
                ['notifiable_type', 'notifiable_id', 'deleted_at', 'read_at', 'category'],
                'notifications_user_filter_idx'
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'deleted_at', 'created_at'],
                'notifications_user_feed_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
