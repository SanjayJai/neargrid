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
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('primary_category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('locality_id')->constrained('localities')->restrictOnDelete();

            $table->string('name', 180);
            $table->string('slug', 220);
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->text('search_keywords')->nullable();
            $table->unsignedSmallInteger('year_established')->nullable();

            $table->string('address_line_1', 255);
            $table->string('address_line_2', 255)->nullable();
            $table->string('landmark', 180)->nullable();
            $table->string('pincode', 12);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->string('primary_phone_e164', 20);
            $table->string('secondary_phone_e164', 20)->nullable();
            $table->string('whatsapp_e164', 20)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('website_url', 255)->nullable();

            $table->string('status', 24)->default('pending_review');
            $table->boolean('is_claimed')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('trusted_at')->nullable();

            $table->decimal('rating_avg', 3, 2)->default(0.00);
            $table->decimal('bayesian_score', 4, 3)->default(0.000);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedSmallInteger('photo_count')->default(0);
            $table->unsignedTinyInteger('completeness_score')->default(0);
            $table->unsignedBigInteger('view_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['city_id', 'slug'], 'uniq_businesses_city_slug');
            $table->index(['status', 'city_id', 'primary_category_id', 'bayesian_score'], 'idx_biz_serp');
            $table->index(['status', 'city_id', 'locality_id', 'is_verified'], 'idx_biz_locality');
            $table->index(['owner_user_id', 'status'], 'idx_biz_owner_status');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE businesses ADD COLUMN location_point POINT NOT NULL SRID 4326 AFTER longitude');
            DB::statement('ALTER TABLE businesses ADD SPATIAL INDEX idx_biz_location (location_point)');
            DB::statement('ALTER TABLE businesses ADD FULLTEXT INDEX idx_biz_fulltext (name, tagline, description, search_keywords)');
        }

        Schema::create('business_categories', function (Blueprint $table): void {
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->primary(['business_id', 'category_id']);
            $table->index(['category_id', 'business_id'], 'idx_biz_cat_category');
        });

        Schema::create('business_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sun .. 6 = Sat
            $table->unsignedTinyInteger('slot_index')->default(1); // 1 or 2 for split shifts
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->boolean('is_24_hours')->default(false);
            $table->boolean('is_closed')->default(false);

            $table->unique(['business_id', 'day_of_week', 'slot_index'], 'uniq_biz_hours_day_slot');
            $table->index(['day_of_week', 'is_closed', 'open_time', 'close_time'], 'idx_biz_hours_open_now');
        });

        Schema::create('business_services', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('price_min_minor')->nullable();
            $table->unsignedInteger('price_max_minor')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->string('price_unit', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active', 'sort_order'], 'idx_biz_services_active_sort');
        });

        Schema::create('business_amenities', function (Blueprint $table): void {
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained('amenities')->restrictOnDelete();

            $table->primary(['business_id', 'amenity_id']);
            $table->index(['amenity_id', 'business_id'], 'idx_biz_amenity_reverse');
        });

        Schema::create('business_payment_methods', function (Blueprint $table): void {
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();

            $table->primary(['business_id', 'payment_method_id']);
        });

        Schema::create('business_faqs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('question', 300);
            $table->text('answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active', 'sort_order'], 'idx_biz_faqs_active_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_faqs');
        Schema::dropIfExists('business_payment_methods');
        Schema::dropIfExists('business_amenities');
        Schema::dropIfExists('business_services');
        Schema::dropIfExists('business_hours');
        Schema::dropIfExists('business_categories');
        Schema::dropIfExists('businesses');
    }
};
