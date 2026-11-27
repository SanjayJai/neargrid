<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Promotions;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Models\Domain;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Promotions\Models\PromoNotice;
use App\Domains\Promotions\Models\PromoNoticeEvent;
use App\Domains\Promotions\Models\PromoNoticeLocale;
use App\Domains\Promotions\Models\PromoNoticeTargetingRule;
use App\Domains\Promotions\Services\PromoNoticeConflictDetector;
use App\Domains\Promotions\Services\PromoNoticeTrackingService;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class PromoNoticeDeliveryAndTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_delivery_enforces_lifecycle_maintenance_priority_targeting_locale_and_frequency_limits(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $parentCategory = Category::query()->where('depth', 0)->firstOrFail();
        $childCategory = Category::query()->where('parent_id', $parentCategory->id)->first() ?? $parentCategory;
        $user = User::factory()->create(['status' => 'active']);

        // Ineligible draft popup must never be delivered
        $draft = PromoNotice::query()->create([
            'kind' => 'popup',
            'notice_type' => 'welcome',
            'display_format' => 'modal',
            'status' => 'draft',
            'priority' => 999,
        ]);
        PromoNoticeLocale::query()->create([
            'promo_notice_id' => $draft->id,
            'locale' => 'en',
            'title' => 'Draft Popup',
        ]);

        // Maintenance announcement (lower priority number, but maintenance severity wins ordering)
        $maint = PromoNotice::query()->create([
            'kind' => 'announcement',
            'notice_type' => 'maintenance',
            'display_format' => 'top_bar',
            'severity' => 'maintenance',
            'status' => 'active',
            'is_enabled' => true,
            'is_dismissible' => false,
            'priority' => 10,
        ]);
        PromoNoticeLocale::query()->create([
            'promo_notice_id' => $maint->id,
            'locale' => 'en',
            'title' => 'Scheduled Maintenance Tonight',
        ]);

        // Targeted promo popup with session cap = 1, cooldown = 24h, EN + TA
        $popup = PromoNotice::query()->create([
            'kind' => 'popup',
            'notice_type' => 'promo',
            'display_format' => 'modal',
            'severity' => 'promo',
            'status' => 'active',
            'is_enabled' => true,
            'is_dismissible' => true,
            'cooldown_hours' => 24,
            'max_displays_per_session' => 1,
            'priority' => 500,
            'starts_at' => CarbonImmutable::now('UTC')->subHour(),
            'ends_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);
        PromoNoticeLocale::query()->create([
            'promo_notice_id' => $popup->id,
            'locale' => 'en',
            'title' => 'Chennai Offer',
            'destination_url' => '/chennai',
        ]);
        PromoNoticeLocale::query()->create([
            'promo_notice_id' => $popup->id,
            'locale' => 'ta',
            'title' => 'சென்னை சிறப்பு சலுகை',
            'destination_url' => '/chennai',
        ]);
        PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $popup->id,
            'target_type' => 'category',
            'category_id' => $parentCategory->id,
            'include_descendants' => true,
            'is_exclude' => false,
        ]);
        PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $popup->id,
            'target_type' => 'route_pattern',
            'target_value' => '/search*',
            'is_exclude' => false,
        ]);

        // Deliver on /search in Tamil
        $res = $this->getJson('/api/v1/public/notices/deliver?'.http_build_query([
            'route_path' => '/search',
            'locale' => 'ta',
            'device' => 'desktop',
            'city_slug' => $city->slug,
            'locality_slug' => $locality->slug,
            'category_slug' => $childCategory->slug,
            'session_id' => 'promo-sess-1',
        ]))->assertOk();

        $this->assertCount(1, $res->json('data.announcements'));
        $this->assertSame('Scheduled Maintenance Tonight', $res->json('data.announcements.0.title'));
        $this->assertSame('சென்னை சிறப்பு சலுகை', $res->json('data.popup.title'));

        $maintToken = (string) $res->json('data.announcements.0.tracking_token');
        $popupToken = (string) $res->json('data.popup.tracking_token');

        // Non-dismissible maintenance bar rejects dismiss event
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/notices/event', [
                'token' => $maintToken,
                'event_type' => 'dismiss',
                'session_id' => 'promo-sess-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.recorded', false)
            ->assertJsonPath('data.reason', 'not_dismissible');

        // Record impression for popup -> session cap (1) is now reached
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/notices/event', [
                'token' => $popupToken,
                'event_type' => 'impression',
                'session_id' => 'promo-sess-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        // Next deliver for same session suppresses the popup
        $afterCap = $this->getJson('/api/v1/public/notices/deliver?'.http_build_query([
            'route_path' => '/search',
            'locale' => 'ta',
            'device' => 'desktop',
            'city_slug' => $city->slug,
            'category_slug' => $childCategory->slug,
            'session_id' => 'promo-sess-1',
        ]))->assertOk();

        $this->assertNull($afterCap->json('data.popup'));

        // Different session still sees popup; dismissing it triggers cooldown
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/notices/event', [
                'token' => $popupToken,
                'event_type' => 'dismiss',
                'session_id' => 'promo-sess-2',
            ])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        $afterDismiss = $this->getJson('/api/v1/public/notices/deliver?'.http_build_query([
            'route_path' => '/search',
            'locale' => 'ta',
            'device' => 'desktop',
            'city_slug' => $city->slug,
            'category_slug' => $childCategory->slug,
            'session_id' => 'promo-sess-2',
        ]))->assertOk();
        $this->assertNull($afterDismiss->json('data.popup'));
        $this->assertNotNull($user);
    }

    public function test_token_security_bot_filtering_redis_fallback_domain_isolation_and_conflicts(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();
        $customer = User::factory()->create(['status' => 'active']);

        $domain = Domain::query()->create([
            'hostname' => 'portal.neargrid.test',
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

        $n1 = PromoNotice::query()->create([
            'kind' => 'popup',
            'notice_type' => 'login_prompt',
            'display_format' => 'modal',
            'status' => 'active',
            'is_enabled' => true,
            'priority' => 100,
        ]);
        PromoNoticeLocale::query()->create([
            'promo_notice_id' => $n1->id,
            'locale' => 'en',
            'title' => 'Sign in to save',
            'destination_url' => '/login',
        ]);
        PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $n1->id,
            'target_type' => 'domain',
            'domain_id' => $domain->id,
            'is_exclude' => false,
        ]);
        PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $n1->id,
            'target_type' => 'audience',
            'target_value' => 'guest',
            'is_exclude' => false,
        ]);
        PromoNoticeTargetingRule::query()->create([
            'promo_notice_id' => $n1->id,
            'target_type' => 'business',
            'business_id' => $business->id,
            'is_exclude' => false,
        ]);

        // Spoofed host must NOT deliver domain-targeted notice
        $this->getJson('/api/v1/public/notices/deliver?'.http_build_query([
            'host' => 'spoofed.evil.com',
            'business_public_id' => $business->public_id,
        ]))->assertOk()->assertJsonPath('data.popup', null);

        // Authenticated customer must NOT see guest-targeted notice
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/public/notices/deliver?'.http_build_query([
            'host' => 'portal.neargrid.test',
            'business_public_id' => $business->public_id,
        ]))->assertOk()->assertJsonPath('data.popup', null);

        // Guest on verified host sees it
        $this->app['auth']->forgetGuards();
        $okRes = $this->getJson('/api/v1/public/notices/deliver?'.http_build_query([
            'host' => 'portal.neargrid.test',
            'business_public_id' => $business->public_id,
        ]))->assertOk();
        $token = (string) $okRes->json('data.popup.tracking_token');
        $this->assertNotEmpty($token);

        // Tampered token rejected & expired token rejected & altered nid rejected
        $this->postJson('/api/v1/public/notices/event', [
            'token' => $token.'bad',
            'event_type' => 'click',
        ])->assertStatus(422);

        $expiredPayload = base64_encode((string) json_encode(['nid' => $n1->public_id, 'exp' => time() - 60]));
        $expiredSig = hash_hmac('sha256', $expiredPayload, (string) config('app.key'));
        $this->postJson('/api/v1/public/notices/event', [
            'token' => "{$expiredPayload}.{$expiredSig}",
            'event_type' => 'impression',
        ])->assertStatus(422);

        [, $validSig] = explode('.', $token);
        $alteredPayload = rtrim(strtr(base64_encode((string) json_encode(['nid' => '01J00000000000000000000000', 'exp' => time() + 3600])), '+/', '-_'), '=');
        $this->postJson('/api/v1/public/notices/event', [
            'token' => "{$alteredPayload}.{$validSig}",
            'event_type' => 'impression',
        ])->assertStatus(422);

        // Bot traffic filtered
        $this->withHeader('User-Agent', 'Googlebot/2.1')->postJson('/api/v1/public/notices/event', [
            'token' => $token,
            'event_type' => 'impression',
            'session_id' => 'bot-1',
        ])->assertOk()->assertJsonPath('data.recorded', false);

        // Redis outage fallback to DB dedup
        Cache::shouldReceive('has')->andThrow(new RuntimeException('Redis down'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('Redis down'));

        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson('/api/v1/public/notices/event', [
            'token' => $token,
            'event_type' => 'click',
            'session_id' => 'redis-fb',
        ])->assertOk()->assertJsonPath('data.recorded', true)->assertJsonPath('data.destination_url', '/login');

        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson('/api/v1/public/notices/event', [
            'token' => $token,
            'event_type' => 'click',
            'session_id' => 'redis-fb',
        ])->assertOk()->assertJsonPath('data.recorded', false)->assertJsonPath('data.reason', 'duplicate');

        $log = PromoNoticeEvent::query()->firstOrFail();
        $this->assertSame(64, strlen((string) $log->ip_hash));
        $this->assertSame(64, strlen((string) $log->session_hash));

        // Conflict detector flags overlapping active popup on same format & scope
        $n2 = PromoNotice::query()->create([
            'kind' => 'popup',
            'notice_type' => 'promo',
            'display_format' => 'modal',
            'status' => 'active',
            'is_enabled' => true,
            'priority' => 90,
        ]);
        $conflicts = app(PromoNoticeConflictDetector::class)->detectConflicts();
        $this->assertNotEmpty($conflicts);
        $this->assertNotNull($n2->id);

        // M5 global.top_announcement_link vs M6 top_bar announcement conflict detection
        $topAdPlacement = AdPlacement::query()->where('key', 'global.top_announcement_link')->firstOrFail();
        $adCampaign = AdCampaign::query()->create([
            'created_by_user_id' => $customer->id,
            'name' => 'Global Top Ad',
            'campaign_type' => 'house_promo',
            'status' => 'active',
        ]);
        $adCampaign->placements()->attach($topAdPlacement->id, ['weight' => 100, 'is_active' => true]);

        $topBarNotice = PromoNotice::query()->create([
            'kind' => 'announcement',
            'notice_type' => 'global_bar',
            'display_format' => 'top_bar',
            'status' => 'active',
            'is_enabled' => true,
        ]);

        $allConflicts = app(PromoNoticeConflictDetector::class)->detectConflicts();
        $m5Conflict = collect($allConflicts)->firstWhere('source', 'm5_ad_placement');
        $this->assertNotNull($m5Conflict);
        $this->assertSame('global.top_announcement_link', $m5Conflict['placement_key']);
        $this->assertNotNull($topBarNotice->id);
        $this->assertTrue(class_exists(PromoNoticeTrackingService::class));
    }
}
