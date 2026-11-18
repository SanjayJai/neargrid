<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_members', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 24); // owner, manager, editor, lead_agent
            $table->json('permissions')->nullable();
            $table->string('status', 20)->default('active'); // active, revoked
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id'], 'uniq_biz_members_business_user');
            $table->index(['user_id', 'status'], 'idx_biz_members_user_status');
        });

        Schema::create('business_verifications', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 40); // gstin, pan, trade_license, shop_act, utility_bill, incorporation_cert
            $table->text('document_number_encrypted');
            $table->string('private_file_path', 500);
            $table->string('status', 24)->default('pending'); // pending, under_review, approved, rejected
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_biz_verif_status_created');
            $table->index(['business_id', 'status'], 'idx_biz_verif_business_status');
        });

        Schema::create('business_claims', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('claimant_user_id')->constrained('users')->restrictOnDelete();
            $table->string('proof_file_path_private', 500);
            $table->text('claim_notes')->nullable();
            $table->string('status', 24)->default('pending'); // pending, approved, rejected
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status'], 'idx_biz_claims_business_status');
            $table->index(['claimant_user_id', 'status'], 'idx_biz_claims_claimant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_claims');
        Schema::dropIfExists('business_verifications');
        Schema::dropIfExists('business_members');
    }
};
