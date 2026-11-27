<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->unsignedBigInteger('impressions_today')->default(0)->after('total_impression_cap');
            $table->date('impressions_today_date')->nullable()->after('impressions_today');
            $table->unsignedBigInteger('total_impressions')->default(0)->after('impressions_today_date');
            $table->unsignedBigInteger('total_clicks')->default(0)->after('total_impressions');

            $table->index(['status', 'starts_at', 'ends_at', 'priority'], 'idx_ad_campaigns_delivery_window');
        });

        Schema::table('ad_event_logs', function (Blueprint $table): void {
            $table->index(['ad_campaign_id', 'event_type', 'session_hash', 'occurred_at'], 'idx_ad_events_dedup_window');
        });
    }

    public function down(): void
    {
        Schema::table('ad_event_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_ad_events_dedup_window');
        });

        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->dropIndex('idx_ad_campaigns_delivery_window');
            $table->dropColumn([
                'impressions_today',
                'impressions_today_date',
                'total_impressions',
                'total_clicks',
            ]);
        });
    }
};
