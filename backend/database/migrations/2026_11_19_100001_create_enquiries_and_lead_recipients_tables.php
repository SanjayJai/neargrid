<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('locality_id')->nullable()->constrained('localities')->nullOnDelete();

            $table->string('customer_name', 120);
            $table->string('phone_e164', 20);
            $table->string('email', 191)->nullable();
            $table->text('requirement');
            $table->string('urgency', 30)->default('flexible');
            $table->string('enquiry_type', 20)->default('direct');
            $table->string('status', 30)->default('pending_otp');

            // Explicit DPDP affirmative consent metadata
            $table->boolean('consent_given')->default(false);
            $table->string('consent_version', 20);
            $table->text('consent_text');
            $table->string('consent_purpose', 100)->default('lead_matching_and_contact');
            $table->timestamp('consent_timestamp');

            $table->char('ip_address_hash', 64);
            $table->char('otp_challenge_id', 36)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            // Admin cancellation & abuse metadata
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['phone_e164', 'created_at']);
            $table->index(['status', 'enquiry_type']);
            $table->index(['city_id', 'category_id']);
            $table->index('target_business_id');
        });

        Schema::create('enquiry_recipients', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('enquiry_id')->constrained('enquiries')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('lead_status', 30)->default('new');
            $table->string('delivery_channel', 30)->default('in_app');
            $table->string('delivery_status', 30)->default('delivered');
            $table->timestamp('delivery_timestamp')->useCurrent();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->text('merchant_notes')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['enquiry_id', 'business_id'], 'uq_enquiry_business');
            $table->index(['business_id', 'lead_status', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('contact_reveals', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel', 30)->default('phone');
            $table->char('ip_address_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'created_at']);
            $table->index(['ip_address_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_reveals');
        Schema::dropIfExists('enquiry_recipients');
        Schema::dropIfExists('enquiries');
    }
};
