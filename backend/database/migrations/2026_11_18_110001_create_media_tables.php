<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_media', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('media_type', 24); // logo, cover, gallery, interior, exterior, product, menu, certificate
            $table->string('upload_status', 20)->default('pending'); // pending, processing, ready, failed
            $table->string('moderation_status', 20)->default('approved'); // pending, approved, rejected

            $table->string('disk', 40);
            $table->string('storage_key', 500);
            $table->string('original_key', 500)->nullable();
            $table->string('webp_full_key', 500)->nullable();
            $table->string('webp_card_key', 500)->nullable();
            $table->string('webp_thumb_key', 500)->nullable();

            $table->string('mime_type', 80);
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('exif_stripped')->default(false);
            $table->string('alt_text', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['business_id', 'moderation_status', 'upload_status', 'media_type', 'sort_order'],
                'idx_biz_media_public_lookup'
            );
            $table->index(['business_id', 'media_type'], 'idx_biz_media_type');
        });

        Schema::create('kyc_documents', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('business_verification_id')->nullable()->constrained('business_verifications')->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();

            $table->string('document_type', 40); // gstin, pan, trade_license, shop_act, utility_bill, incorporation_cert
            $table->string('upload_status', 20)->default('ready'); // ready, failed
            $table->string('disk', 40);
            $table->string('storage_key', 500);
            $table->string('mime_type', 80);
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256_checksum', 64);
            $table->timestamps();

            $table->index(['business_id', 'document_type', 'created_at'], 'idx_kyc_docs_business_type');
            $table->index('business_verification_id', 'idx_kyc_docs_verification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('business_media');
    }
};
