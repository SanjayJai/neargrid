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
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name', 100);
            $table->string('phone_e164', 20);
            $table->unsignedTinyInteger('rating'); // 1 to 5
            $table->string('title', 150)->nullable();
            $table->text('content')->nullable();

            // Verified customer badge
            $table->boolean('is_verified_interaction')->default(false);
            $table->string('interaction_type', 32)->nullable(); // 'enquiry', 'contact_reveal', 'none'
            $table->unsignedBigInteger('interaction_id')->nullable();

            // Status & phone verification
            $table->string('status', 24)->default('published'); // 'pending_verification', 'published', 'flagged', 'hidden', 'rejected'
            $table->foreignId('otp_challenge_id')->nullable()->constrained('otp_challenges')->nullOnDelete();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('ip_address_hash', 64);

            // Business Owner Reply
            $table->text('owner_reply_text')->nullable();
            $table->timestamp('owner_replied_at')->nullable();
            $table->foreignId('owner_reply_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Moderation
            $table->string('moderation_status', 24)->default('approved'); // 'pending', 'approved', 'flagged', 'hidden', 'rejected'
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status', 'created_at'], 'idx_reviews_biz_status_date');
            $table->index(['user_id', 'business_id', 'created_at'], 'idx_reviews_user_biz_date');
            $table->index(['phone_e164', 'business_id', 'created_at'], 'idx_reviews_phone_biz_date');
            $table->index(['status', 'rating'], 'idx_reviews_status_rating');
        });

        Schema::create('review_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_ip_hash', 64);
            $table->string('reason', 50); // 'spam', 'offensive', 'fake', 'conflict_of_interest', 'other'
            $table->text('details')->nullable();
            $table->string('status', 24)->default('pending'); // 'pending', 'investigated', 'dismissed', 'action_taken'
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_taken', 50)->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'status'], 'idx_review_reports_review_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_reports');
        Schema::dropIfExists('reviews');
    }
};
