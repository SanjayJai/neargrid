<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_erasure_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending'); // pending, approved, rejected
            $table->string('reason', 500)->nullable();
            $table->string('decision_notes', 500)->nullable();
            $table->json('retention_snapshot')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_erasure_status_created');
            $table->index(['user_id', 'status'], 'idx_erasure_user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_erasure_requests');
    }
};
