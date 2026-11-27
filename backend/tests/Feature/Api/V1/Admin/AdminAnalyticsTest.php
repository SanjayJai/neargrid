<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Role;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\EnquiryType;
use App\Domains\Leads\Enums\EnquiryUrgency;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Search\Models\SearchQueryLog;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->superAdmin->roles()->syncWithoutDetaching([$superAdminRole->id]);
    }

    public function test_analytics_rejects_unauthenticated_and_non_super_admin_users(): void
    {
        $this->getJson('/api/v1/admin/analytics')->assertUnauthorized();

        $customer = User::factory()->create(['status' => 'active']);
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $customer->roles()->syncWithoutDetaching([$customerRole->id]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/analytics')->assertForbidden();

        $owner = User::factory()->create(['status' => 'active']);
        $ownerRole = Role::query()->where('name', 'business_owner')->firstOrFail();
        $owner->roles()->syncWithoutDetaching([$ownerRole->id]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/analytics')->assertForbidden();
    }

    public function test_analytics_returns_real_aggregates_period_comparison_safe_activity_and_unavailable_integrations(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $biz = Business::factory()->create([
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
            'is_verified' => true,
        ]);

        Enquiry::query()->create([
            'user_id' => $this->superAdmin->id,
            'target_business_id' => $biz->id,
            'category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'customer_name' => 'Test Customer',
            'phone_e164' => '+919876543210',
            'requirement' => 'Need consultation',
            'urgency' => EnquiryUrgency::Immediate,
            'enquiry_type' => EnquiryType::Direct,
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => 'v1',
            'consent_text' => 'I agree',
            'consent_purpose' => 'lead_dispatch',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
        ]);

        SearchQueryLog::query()->create([
            'normalized_query' => 'dentist',
            'display_query' => 'dentist',
            'city_slug' => $city->slug,
            'locale' => 'en',
            'result_count' => 3,
            'duration_ms' => 42,
            'engine_driver' => 'mysql',
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $this->superAdmin->id,
            'actor_role' => 'super_admin',
            'action' => 'business.verified',
            'auditable_type' => 'Business',
            'auditable_id' => $biz->id,
            'old_values' => ['secret' => 'hidden'],
            'new_values' => ['is_verified' => true],
            'ip_address' => '10.10.10.10',
        ]);

        Sanctum::actingAs($this->superAdmin);
        $res = $this->getJson('/api/v1/admin/analytics?range=7d')
            ->assertOk();
        $cacheControl = (string) $res->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        $res->assertJsonPath('data.period.range', '7d');
        $res->assertJsonPath('data.period.days', 7);
        $this->assertGreaterThanOrEqual(1, $res->json('data.summary.businesses.total'));
        $this->assertGreaterThanOrEqual(1, $res->json('data.summary.enquiries.dispatched'));
        $this->assertSame('dentist', $res->json('data.top_queries.0.query'));
        $this->assertCount(7, $res->json('data.daily_trends'));

        // Safe recent activity strips private audit fields
        $activity = $res->json('data.recent_activity.0');
        $this->assertArrayNotHasKey('old_values', $activity);
        $this->assertArrayNotHasKey('new_values', $activity);
        $this->assertArrayNotHasKey('ip_address', $activity);
        $this->assertArrayNotHasKey('actor_user_id', $activity);

        // Unavailable external/upcoming integrations explicitly distinguished
        $integrations = collect($res->json('data.unavailable_integrations'))->keyBy('key');
        $this->assertSame('not_configured', $integrations['ga4']['status']);
        $this->assertSame('not_configured', $integrations['gsc']['status']);
        $this->assertSame('pending_milestone', $integrations['ad_campaigns']['status']);
    }

    public function test_analytics_validates_custom_date_range_and_bounds(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/admin/analytics?range=invalid')
            ->assertStatus(422);

        $this->getJson('/api/v1/admin/analytics?range=custom&from=2026-11-20&to=2026-11-10')
            ->assertStatus(422);

        $this->getJson('/api/v1/admin/analytics?range=custom&from=2024-01-01&to=2026-11-20')
            ->assertStatus(422);

        $this->getJson('/api/v1/admin/analytics?city_slug=nonexistent-city-slug')
            ->assertStatus(422);

        // Leap-year equal-length window: 2024-02-28..2024-03-01 (3 days) -> prev 2024-02-25..2024-02-27
        $this->getJson('/api/v1/admin/analytics?range=custom&from=2024-02-28&to=2024-03-01')
            ->assertOk()
            ->assertJsonPath('data.period.days', 3)
            ->assertJsonPath('data.period.previous_from', '2024-02-25')
            ->assertJsonPath('data.period.previous_to', '2024-02-27')
            ->assertJsonCount(3, 'data.daily_trends');

        $this->getJson('/api/v1/admin/analytics?range=custom&from=2026-11-01&to=2026-11-15')
            ->assertOk()
            ->assertJsonPath('data.period.from', '2026-11-01')
            ->assertJsonPath('data.period.to', '2026-11-15')
            ->assertJsonPath('data.period.days', 15);
    }

    public function test_half_open_date_boundary_includes_final_day_and_excludes_next_day_midnight(): void
    {
        // Insert search logs directly with boundary timestamps:
        // 1) Final day at 23:59:59.400 -> MUST be included in 2026-11-01..2026-11-15
        //    (Use .400000 because MySQL 8 TIMESTAMP(0) rounds .500000+ up to 00:00:00 next day)
        // 2) Next day at 00:00:00 -> MUST be excluded
        DB::table('search_query_logs')->insert([
            [
                'normalized_query' => 'boundary-in',
                'display_query' => 'boundary-in',
                'city_slug' => 'chennai',
                'locale' => 'en',
                'result_count' => 2,
                'duration_ms' => 20,
                'engine_driver' => 'mysql',
                'created_at' => '2026-11-15 23:59:59.400000',
            ],
            [
                'normalized_query' => 'boundary-out',
                'display_query' => 'boundary-out',
                'city_slug' => 'chennai',
                'locale' => 'en',
                'result_count' => 2,
                'duration_ms' => 20,
                'engine_driver' => 'mysql',
                'created_at' => '2026-11-16 00:00:00.000000',
            ],
        ]);

        Sanctum::actingAs($this->superAdmin);
        $res = $this->getJson('/api/v1/admin/analytics?range=custom&from=2026-11-01&to=2026-11-15')
            ->assertOk();

        $this->assertSame(1, $res->json('data.summary.search.total_searches'));
        $queries = collect($res->json('data.top_queries'))->pluck('query')->all();
        $this->assertContains('boundary-in', $queries);
        $this->assertNotContains('boundary-out', $queries);
    }
}
