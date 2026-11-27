<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Models\Domain;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Promotions\Models\PromoNotice;
use App\Domains\Promotions\Models\PromoNoticeEvent;
use App\Domains\Promotions\Models\PromoNoticeLocale;
use App\Domains\Promotions\Models\PromoNoticeTargetingRule;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class PromoNoticeFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_promo_notice_tables_relationships_scopes_and_constraints(): void
    {
        foreach ([
            'promo_notices',
            'promo_notice_locales',
            'promo_notice_targeting_rules',
            'promo_notice_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing expected table: {$table}");
        }

        $admin = User::factory()->create(['status' => 'active']);
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $domain = Domain::query()->create([
            'hostname' => 'promo-test.neargrid.test',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'visibility_mode' => 'public',
            'default_locale' => 'en',
            'default_city_id' => $city->id,
        ]);

        $business = Business::factory()->create([
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
        ]);

        $campaign = AdCampaign::query()->create([
            'created_by_user_id' => $admin->id,
            'name' => 'Linked Campaign',
            'campaign_type' => 'house_promo',
            'status' => 'active',
        ]);

        $notice = PromoNotice::query()->create([
            'kind' => 'popup',
            'notice_type' => 'welcome',
            'display_format' => 'modal',
            'severity' => 'promo',
            'status' => 'active',
            'is_enabled' => true,
            'priority' => 200,
            'trigger_type' => 'delay',
            'trigger_delay_seconds' => 3,
            'starts_at' => CarbonImmutable::now('UTC')->subHour(),
            'ends_at' => CarbonImmutable::now('UTC')->addDay(),
            'ad_campaign_id' => $campaign->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $this->assertSame(26, strlen($notice->public_id));
        $this->assertSame(1, PromoNotice::query()->liveAt(CarbonImmutable::now('UTC'))->ofKind('popup')->count());

        $enLocale = PromoNoticeLocale::query()->create([
            'promo_notice_id' => $notice->id,
            'locale' => 'en',
            'badge_text' => 'New',
            'title' => 'Welcome to NearGrid Chennai',
            'body' => 'Discover verified local services.',
            'cta_label' => 'Explore',
            'destination_url' => '/chennai',
        ]);

        PromoNoticeLocale::query()->create([
            'promo_notice_id' => $notice->id,
            'locale' => 'ta',
            'title' => 'நியர்கிரிட் சென்னைக்கு நல்வரவு',
            'destination_url' => '/chennai',
        ]);

        // Duplicate locale for same notice must fail unique constraint
        $this->expectException(QueryException::class);
        PromoNoticeLocale::query()->create([
            'promo_notice_id' => $notice->id,
            'locale' => 'en',
            'title' => 'Duplicate EN',
        ]);
    }

    public function test_promo_notice_validation_targeting_events_and_foreign_keys(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $state = State::query()->firstOrFail();
        $otherState = State::query()->create(['country_code' => 'IN', 'name' => 'Other State', 'slug' => 'other-state', 'is_active' => true]);
        $city = City::query()->where('state_id', $state->id)->firstOrFail();
        $category = Category::query()->where('depth', '<=', 4)->firstOrFail();

        $notice = PromoNotice::query()->create([
            'kind' => 'announcement',
            'notice_type' => 'maintenance',
            'display_format' => 'top_bar',
            'severity' => 'maintenance',
            'status' => 'active',
            'trigger_type' => 'page_load',
            'created_by_user_id' => $admin->id,
        ]);

        // Invalid status
        $this->assertThrows(fn () => PromoNotice::query()->create([
            'kind' => 'popup',
            'notice_type' => 'welcome',
            'display_format' => 'modal',
            'status' => 'invalid_state',
        ]), InvalidArgumentException::class);

        // Invalid schedule
        $this->assertThrows(fn () => PromoNotice::query()->create([
            'kind' => 'popup',
            'notice_type' => 'welcome',
            'display_format' => 'modal',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
            'ends_at' => CarbonImmutable::now('UTC'),
        ]), InvalidArgumentException::class);

        // Unsafe markup & URL rejected
        $this->assertThrows(fn () => PromoNoticeLocale::query()->create([
            'promo_notice_id' => $notice->id,
            'locale' => 'en',
            'title' => '<script>alert(1)</script>',
        ]), InvalidArgumentException::class);

        $this->assertThrows(fn () => PromoNoticeLocale::query()->create([
            'promo_notice_id' => $notice->id,
            'locale' => 'en',
            'title' => 'Safe Title',
            'destination_url' => 'javascript:alert(1)',
        ]), InvalidArgumentException::class);

        // Invalid targeting depth (> 4) & geo mismatch
        $badCat = Category::query()->create([
            'name' => 'Too Deep',
            'slug' => 'too-deep-cat',
            'depth' => 5,
            'is_active' => true,
        ]);

        $this->assertThrows(fn () => PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $notice->id,
            'target_type' => 'category',
            'category_id' => $badCat->id,
        ]), InvalidArgumentException::class);

        $this->assertThrows(fn () => PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $notice->id,
            'target_type' => 'city',
            'state_id' => $otherState->id,
            'city_id' => $city->id,
        ]), InvalidArgumentException::class);

        $this->assertThrows(fn () => PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $notice->id,
            'target_type' => 'category',
            'category_id' => $category->id,
            'city_id' => $city->id,
        ]), InvalidArgumentException::class);

        // Valid targeting rule & event pruning
        $rule = PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $notice->id,
            'target_type' => 'category',
            'category_id' => $category->id,
            'include_descendants' => true,
        ]);
        $this->assertSame((int) $category->depth, $rule->category_depth);

        $this->assertThrows(fn () => PromoNoticeEvent::query()->create([
            'promo_notice_id' => $notice->id,
            'event_type' => 'invalid_event',
        ]), InvalidArgumentException::class);

        // DB-level CHECK constraint on MariaDB/MySQL
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertThrows(fn () => DB::table('promo_notice_events')->insert([
                'promo_notice_id' => $notice->id,
                'event_type' => 'invalid_raw',
                'occurred_at' => now(),
            ]), QueryException::class);
        }

        PromoNoticeEvent::query()->create([
            'promo_notice_id' => $notice->id,
            'event_type' => 'dismiss',
            'occurred_at' => CarbonImmutable::now('UTC')->subDays(100),
        ]);
        PromoNoticeEvent::query()->create([
            'promo_notice_id' => $notice->id,
            'event_type' => 'impression',
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);

        $this->assertSame(1, PromoNoticeEvent::pruneOlderThan(CarbonImmutable::now('UTC')->subDays(90)));
        $this->assertSame(1, PromoNoticeEvent::query()->count());

        // Force delete cascades to targeting rules & events
        $notice->forceDelete();
        $this->assertSame(0, PromoNoticeTargetingRule::query()->count());
        $this->assertSame(0, PromoNoticeEvent::query()->count());
    }
}
