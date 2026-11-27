<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Advertising;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdCreative;
use App\Domains\Advertising\Models\AdEventLog;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Advertising\Models\AdTargetingRule;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdDeliveryAndTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_delivery_enforces_lifecycle_targeting_locale_caps_and_signed_tracking(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $parentCategory = Category::query()->where('depth', 0)->firstOrFail();
        $childCategory = Category::query()->where('parent_id', $parentCategory->id)->first() ?? $parentCategory;
        $user = User::factory()->create(['status' => 'active']);

        $placement = AdPlacement::query()->where('key', 'home.featured_carousel')->firstOrFail();

        // Ineligible draft campaign must never appear
        $draft = AdCampaign::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Draft Campaign',
            'campaign_type' => 'sponsored_banner',
            'status' => 'draft',
            'priority' => 999,
        ]);
        $draft->placements()->attach($placement->id, ['weight' => 100, 'is_active' => true]);
        AdCreative::query()->create([
            'ad_campaign_id' => $draft->id,
            'title' => 'Should Not Appear',
            'destination_url' => '/chennai',
            'locale' => 'en',
            'status' => 'active',
        ]);

        // Active campaign with daily cap = 1, category descendant targeting, and EN/TA creatives
        $active = AdCampaign::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Live Home Carousel',
            'campaign_type' => 'sponsored_banner',
            'status' => 'active',
            'priority' => 200,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'daily_impression_cap' => 1,
            'total_impression_cap' => 5,
        ]);
        $active->placements()->attach($placement->id, ['weight' => 100, 'is_active' => true]);
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $active->id,
            'target_type' => 'category',
            'category_id' => $parentCategory->id,
            'include_descendants' => true,
            'is_exclude' => false,
        ]);
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $active->id,
            'target_type' => 'city',
            'city_id' => $city->id,
            'is_exclude' => false,
        ]);

        AdCreative::query()->create([
            'ad_campaign_id' => $active->id,
            'title' => 'English Promo',
            'destination_url' => '/chennai',
            'locale' => 'en',
            'status' => 'active',
        ]);
        AdCreative::query()->create([
            'ad_campaign_id' => $active->id,
            'title' => 'தமிழ் விளம்பரம்',
            'destination_url' => '/chennai',
            'locale' => 'ta',
            'status' => 'active',
        ]);

        // Request in Tamil for child category in Chennai
        $res = $this->getJson('/api/v1/public/ads/deliver?'.http_build_query([
            'page_type' => 'home',
            'placement_keys' => ['home.featured_carousel'],
            'locale' => 'ta',
            'device' => 'desktop',
            'city_slug' => $city->slug,
            'locality_slug' => $locality->slug,
            'category_slug' => $childCategory->slug,
        ]))->assertOk();

        $placements = $res->json('data.placements');
        $ads = is_array($placements) ? ($placements['home.featured_carousel']['ads'] ?? []) : [];
        $this->assertCount(1, $ads);
        $this->assertSame('தமிழ் விளம்பரம்', $ads[0]['title']);
        $token = (string) $ads[0]['tracking_token'];
        $this->assertNotEmpty($token);

        // Tampered token rejected
        $this->postJson('/api/v1/public/ads/impression', [
            'token' => $token.'tamper',
        ])->assertStatus(422);

        // Bot traffic filtered without incrementing caps
        $this->withHeader('User-Agent', 'Googlebot/2.1')->postJson('/api/v1/public/ads/impression', [
            'token' => $token,
            'session_id' => 'sess-bot',
        ])->assertOk()->assertJsonPath('data.recorded', false);

        // Valid browser impression recorded
        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson('/api/v1/public/ads/impression', [
            'token' => $token,
            'session_id' => 'sess-1',
        ])->assertOk()->assertJsonPath('data.recorded', true);

        // Duplicate impression in window suppressed
        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson('/api/v1/public/ads/impression', [
            'token' => $token,
            'session_id' => 'sess-1',
        ])->assertOk()->assertJsonPath('data.recorded', false)->assertJsonPath('data.reason', 'duplicate');

        // Click recorded
        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson('/api/v1/public/ads/click', [
            'token' => $token,
            'session_id' => 'sess-1',
        ])->assertOk()->assertJsonPath('data.recorded', true);

        // Daily cap (1) is now exhausted -> delivery returns empty
        $afterCap = $this->getJson('/api/v1/public/ads/deliver?'.http_build_query([
            'page_type' => 'home',
            'placement_keys' => ['home.featured_carousel'],
            'locale' => 'ta',
            'city_slug' => $city->slug,
            'category_slug' => $childCategory->slug,
        ]))->assertOk();

        $this->assertEmpty($afterCap->json('data.placements'));

        // Privacy check: raw IP/session not stored
        $log = AdEventLog::query()->firstOrFail();
        $this->assertSame(64, strlen((string) $log->ip_hash));
        $this->assertSame(64, strlen((string) $log->session_hash));
    }

    public function test_featured_listing_campaign_integrates_with_search_page_1_without_duplication(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();
        $owner = User::factory()->create(['status' => 'active']);

        $org = Organization::query()->create([
            'name' => 'Sponsored Org',
            'slug' => 'sponsored-org',
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);

        $business = Business::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Apex Dental Studio',
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
            'completeness_score' => 85,
            'rating_avg' => 4.8,
            'review_count' => 12,
        ]);

        $campaign = AdCampaign::query()->create([
            'organization_id' => $org->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Apex Search Featured',
            'campaign_type' => 'featured_listing',
            'status' => 'active',
            'priority' => 500,
        ]);
        $campaign->businesses()->attach($business->id, ['is_primary' => true]);

        $res = $this->getJson('/api/v1/search?'.http_build_query([
            'city' => $city->slug,
            'category' => $category->slug,
        ]))->assertOk();

        $hits = $res->json('data');
        $sponsored = array_values(array_filter($hits, static fn (array $h): bool => ($h['is_sponsored'] ?? false) === true));
        $this->assertNotEmpty($sponsored);
        $this->assertSame($business->public_id, $sponsored[0]['public_id']);

        // Deduplicated: business appears only once on Page 1
        $matching = array_values(array_filter($hits, static fn (array $h): bool => $h['public_id'] === $business->public_id));
        $this->assertCount(1, $matching);
    }
}
