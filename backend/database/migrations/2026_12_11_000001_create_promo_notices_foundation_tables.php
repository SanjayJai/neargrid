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
        Schema::create('promo_notices', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('kind', 24); // popup, announcement
            $table->string('notice_type', 32); // welcome, promo, exit_intent, login_prompt, location_prompt, owner_prompt, service_notice, maintenance, global_bar
            $table->string('display_format', 24); // modal, slide_in, top_bar, bottom_bar, inline
            $table->string('severity', 24)->default('info'); // info, promo, warning, maintenance
            $table->string('status', 24)->default('draft'); // draft, pending_approval, approved, scheduled, active, paused, expired, archived
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->string('trigger_type', 24)->default('page_load'); // page_load, delay, scroll, exit_intent, login, registration
            $table->unsignedSmallInteger('trigger_delay_seconds')->default(0);
            $table->unsignedTinyInteger('trigger_scroll_percent')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_dismissible')->default(true);
            $table->unsignedSmallInteger('cooldown_hours')->default(24);
            $table->unsignedSmallInteger('max_displays_per_session')->nullable();
            $table->unsignedSmallInteger('max_displays_per_user')->nullable();
            $table->unsignedBigInteger('max_total_impressions')->nullable();
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->unsignedBigInteger('dismissals_count')->default(0);
            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_enabled', 'kind', 'starts_at', 'ends_at', 'priority'], 'idx_promo_notices_delivery');
            $table->index(['notice_type', 'display_format'], 'idx_promo_notices_type');
        });

        Schema::create('promo_notice_locales', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('promo_notice_id')->constrained('promo_notices')->cascadeOnDelete();
            $table->char('locale', 5); // en, ta
            $table->string('badge_text', 40)->nullable();
            $table->string('title', 160);
            $table->string('subtitle', 240)->nullable();
            $table->text('body')->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('secondary_cta_label', 60)->nullable();
            $table->string('destination_url', 500)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('alt_text', 200)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['promo_notice_id', 'locale'], 'uniq_promo_notice_locale');
        });

        Schema::create('promo_notice_targeting_rules', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('promo_notice_id')->constrained('promo_notices')->cascadeOnDelete();
            $table->string('target_type', 32); // category, state, city, locality, domain, device, audience, route_pattern, business
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->unsignedTinyInteger('category_depth')->nullable();
            $table->boolean('include_descendants')->default(true);
            $table->foreignId('state_id')->nullable()->constrained('states')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->cascadeOnDelete();
            $table->foreignId('locality_id')->nullable()->constrained('localities')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('target_value', 120)->nullable();
            $table->boolean('is_exclude')->default(false);
            $table->timestamps();

            $table->index(['promo_notice_id', 'target_type', 'is_exclude'], 'idx_promo_target_notice_type');
            $table->index(['category_id', 'include_descendants'], 'idx_promo_target_category');
            $table->index(['state_id', 'city_id', 'locality_id'], 'idx_promo_target_geo');
            $table->index(['domain_id'], 'idx_promo_target_domain');
            $table->index(['business_id'], 'idx_promo_target_business');
        });

        Schema::create('promo_notice_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_notice_id')->constrained('promo_notices')->cascadeOnDelete();
            $table->foreignId('promo_notice_locale_id')->nullable()->constrained('promo_notice_locales')->nullOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('event_type', 16); // impression, click, dismiss
            $table->char('locale', 5)->nullable();
            $table->string('device_type', 16)->nullable();
            $table->string('audience_type', 16)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->char('user_hash', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['promo_notice_id', 'event_type', 'occurred_at'], 'idx_promo_events_notice_type_time');
            $table->index(['promo_notice_id', 'event_type', 'session_hash', 'occurred_at'], 'idx_promo_events_dedup');
            $table->index(['promo_notice_id', 'event_type', 'user_hash', 'occurred_at'], 'idx_promo_events_user_freq');
            $table->index(['occurred_at'], 'idx_promo_events_time');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE promo_notice_targeting_rules ADD CONSTRAINT chk_promo_targeting_category_depth CHECK (category_depth IS NULL OR (category_depth >= 0 AND category_depth <= 4))');
            DB::statement("ALTER TABLE promo_notice_events ADD CONSTRAINT chk_promo_event_type CHECK (event_type IN ('impression', 'click', 'dismiss'))");
            DB::statement("ALTER TABLE promo_notices ADD CONSTRAINT chk_promo_notice_status CHECK (status IN ('draft', 'pending_approval', 'approved', 'scheduled', 'active', 'paused', 'expired', 'archived'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_notice_events');
        Schema::dropIfExists('promo_notice_targeting_rules');
        Schema::dropIfExists('promo_notice_locales');
        Schema::dropIfExists('promo_notices');
    }
};
