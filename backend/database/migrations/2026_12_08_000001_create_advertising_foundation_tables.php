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
        Schema::create('ad_placements', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('key', 80)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('page_type', 40); // home, search, category, business_profile, city_hub, account, global
            $table->string('route_pattern', 140);
            $table->string('slot_position', 40); // hero, top, inline_1, sidebar_top, etc.
            $table->string('render_format', 32); // banner, card, native_listing, text_link, carousel, strip
            $table->string('device_scope', 20)->default('all'); // all, desktop, mobile, tablet
            $table->string('audience_scope', 24)->default('all'); // all, guest, customer, merchant
            $table->unsignedTinyInteger('max_ads')->default(1);
            $table->unsignedSmallInteger('width_px')->nullable();
            $table->unsignedSmallInteger('height_px')->nullable();
            $table->boolean('supports_category_targeting')->default(true);
            $table->boolean('supports_location_targeting')->default(true);
            $table->boolean('supports_domain_targeting')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('config_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['page_type', 'is_active', 'sort_order'], 'idx_ad_placements_page_active');
            $table->index(['render_format', 'is_active'], 'idx_ad_placements_format_active');
        });

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('campaign_type', 32)->default('sponsored_banner');
            $table->string('status', 24)->default('draft'); // draft, pending_review, active, paused, completed, rejected, archived
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('daily_impression_cap')->nullable();
            $table->unsignedInteger('total_impression_cap')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at', 'ends_at', 'priority'], 'idx_ad_campaigns_status_window');
            $table->index(['organization_id', 'status'], 'idx_ad_campaigns_org_status');
        });

        Schema::create('ad_creatives', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('title', 140);
            $table->string('subtitle', 240)->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->string('destination_url', 500);
            $table->string('image_url', 500)->nullable();
            $table->string('mobile_image_url', 500)->nullable();
            $table->string('alt_text', 200)->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('status', 24)->default('active'); // active, paused, rejected
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ad_campaign_id', 'status', 'locale'], 'idx_ad_creatives_campaign_status');
        });

        Schema::create('ad_campaign_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('ad_placement_id')->constrained('ad_placements')->cascadeOnDelete();
            $table->unsignedSmallInteger('weight')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['ad_campaign_id', 'ad_placement_id'], 'uniq_ad_campaign_placement');
            $table->index(['ad_placement_id', 'is_active'], 'idx_ad_cp_placement_active');
        });

        Schema::create('ad_campaign_businesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['ad_campaign_id', 'business_id'], 'uniq_ad_campaign_business');
            $table->index(['business_id'], 'idx_ad_cb_business');
        });

        Schema::create('ad_targeting_rules', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('target_type', 32); // category, state, city, locality, domain, device, audience, route_pattern
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->unsignedTinyInteger('category_depth')->nullable();
            $table->boolean('include_descendants')->default(true);
            $table->foreignId('state_id')->nullable()->constrained('states')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->cascadeOnDelete();
            $table->foreignId('locality_id')->nullable()->constrained('localities')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $table->string('target_value', 120)->nullable();
            $table->boolean('is_exclude')->default(false);
            $table->timestamps();

            $table->index(['ad_campaign_id', 'target_type', 'is_exclude'], 'idx_ad_target_campaign_type');
            $table->index(['category_id', 'include_descendants'], 'idx_ad_target_category');
            $table->index(['state_id', 'city_id', 'locality_id'], 'idx_ad_target_geo');
            $table->index(['domain_id'], 'idx_ad_target_domain');
        });

        Schema::create('ad_event_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('ad_creative_id')->nullable()->constrained('ad_creatives')->nullOnDelete();
            $table->foreignId('ad_placement_id')->nullable()->constrained('ad_placements')->nullOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('event_type', 16); // impression, click
            $table->string('device_type', 16)->nullable();
            $table->string('audience_type', 16)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['ad_campaign_id', 'event_type', 'occurred_at'], 'idx_ad_events_campaign_type_time');
            $table->index(['ad_placement_id', 'event_type', 'occurred_at'], 'idx_ad_events_placement_time');
            $table->index(['occurred_at'], 'idx_ad_events_time');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ad_targeting_rules ADD CONSTRAINT chk_ad_targeting_category_depth CHECK (category_depth IS NULL OR (category_depth >= 0 AND category_depth <= 4))');
            DB::statement("ALTER TABLE ad_event_logs ADD CONSTRAINT chk_ad_event_type CHECK (event_type IN ('impression', 'click'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_event_logs');
        Schema::dropIfExists('ad_targeting_rules');
        Schema::dropIfExists('ad_campaign_businesses');
        Schema::dropIfExists('ad_campaign_placements');
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_placements');
    }
};
