<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdCreative;
use App\Domains\Advertising\Models\AdEventLog;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Advertising\Models\AdTargetingRule;
use App\Domains\Advertising\Services\AdPlacementRegistry;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Domains\Models\Domain;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Reference\AdPlacementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class AdFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_advertising_tables_and_30_plus_placements_are_seeded_idempotently_preserving_customizations(): void
    {
        foreach ([
            'ad_placements',
            'ad_campaigns',
            'ad_creatives',
            'ad_campaign_placements',
            'ad_campaign_businesses',
            'ad_targeting_rules',
            'ad_event_logs',
            'sponsored_placements',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing expected table: {$table}");
        }

        $countBefore = AdPlacement::query()->count();
        $this->assertGreaterThanOrEqual(30, $countBefore);

        // Customize a placement as an admin would, then re-run seeder
        $hero = AdPlacement::query()->where('key', 'home.hero_banner')->firstOrFail();
        $hero->is_active = false;
        $hero->max_ads = 3;
        $hero->save();

        $this->seed(AdPlacementsSeeder::class);
        $this->assertSame($countBefore, AdPlacement::query()->count());

        $hero->refresh();
        $this->assertFalse($hero->is_active);
        $this->assertSame(3, $hero->max_ads);

        $registry = new AdPlacementRegistry();
        $mobileSearchSlots = $registry->activeForContext('search', 'mobile', 'customer');
        $this->assertTrue($mobileSearchSlots->contains('key', 'search.mobile_top_banner'));
        $this->assertFalse($mobileSearchSlots->contains('key', 'search.top_leaderboard'));
        $this->assertTrue($mobileSearchSlots->contains('key', 'global.top_announcement_link'));
    }

    public function test_campaign_creatives_targeting_validation_policy_isolation_and_event_pruning(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $otherOwner = User::factory()->create(['status' => 'active']);
        $superAdmin = User::factory()->create(['status' => 'active']);
        $superRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $superAdmin->roles()->syncWithoutDetaching([$superRole->id]);

        $org = Organization::query()->create([
            'name' => 'Apex Healthcare Group',
            'slug' => 'apex-healthcare-group',
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);

        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->id)->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $domain = Domain::query()->create([
            'hostname' => 'chennai.neargrid.test',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'default_city_id' => $city->id,
            'organization_id' => $org->id,
            'created_by_user_id' => $owner->id,
        ]);

        $business = Business::factory()->create([
            'owner_user_id' => $owner->id,
            'organization_id' => $org->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
        ]);

        $campaign = AdCampaign::query()->create([
            'organization_id' => $org->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Chennai Dental Spotlight',
            'campaign_type' => 'category_spotlight',
            'status' => 'active',
            'priority' => 50,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(14),
            'daily_impression_cap' => 5000,
        ]);

        // Cross-organization policy isolation
        $this->assertTrue(Gate::forUser($owner)->allows('manage', $campaign));
        $this->assertFalse(Gate::forUser($otherOwner)->allows('view', $campaign));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('manage', $campaign));

        // Unsafe creative URL and unsupported locale rejected
        try {
            AdCreative::query()->create([
                'ad_campaign_id' => $campaign->id,
                'title' => 'Unsafe',
                'destination_url' => 'javascript:alert(1)',
                'locale' => 'en',
            ]);
            $this->fail('Expected InvalidArgumentException for javascript: URL');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            AdCreative::query()->create([
                'ad_campaign_id' => $campaign->id,
                'title' => 'Protocol Relative',
                'destination_url' => '//evil.example/phish',
                'locale' => 'en',
            ]);
            $this->fail('Expected InvalidArgumentException for protocol-relative URL');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $creative = AdCreative::query()->create([
            'ad_campaign_id' => $campaign->id,
            'title' => '24x7 Dental Care in Chennai',
            'subtitle' => 'Book instant consultation',
            'cta_label' => 'Book Now',
            'destination_url' => '/chennai/business/'.$business->slug,
            'locale' => 'en',
            'status' => 'active',
        ]);

        $placement = AdPlacement::query()->where('key', 'category.speciality_spotlight')->firstOrFail();
        $campaign->placements()->attach($placement->id, ['weight' => 120, 'is_active' => true]);
        $campaign->businesses()->attach($business->id, ['is_primary' => true]);

        // Invalid targeting rule combinations rejected
        try {
            AdTargetingRule::query()->create([
                'ad_campaign_id' => $campaign->id,
                'target_type' => 'category',
                'category_id' => $category->id,
                'city_id' => $city->id, // forbidden on category rule
            ]);
            $this->fail('Expected InvalidArgumentException for mixed category + city FK');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            AdTargetingRule::query()->create([
                'ad_campaign_id' => $campaign->id,
                'target_type' => 'category',
                'category_id' => $category->id,
                'category_depth' => 99,
            ]);
            $this->fail('Expected InvalidArgumentException for mismatched category_depth');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        // Valid targeting rules
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $campaign->id,
            'target_type' => 'category',
            'category_id' => $category->id,
            'category_depth' => $category->depth ?? 0,
            'include_descendants' => true,
        ]);
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $campaign->id,
            'target_type' => 'locality',
            'state_id' => $state->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
        ]);
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $campaign->id,
            'target_type' => 'domain',
            'domain_id' => $domain->id,
        ]);
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $campaign->id,
            'target_type' => 'device',
            'target_value' => 'mobile',
        ]);
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $campaign->id,
            'target_type' => 'audience',
            'target_value' => 'customer',
        ]);

        // Event logging privacy & retention pruning
        $oldEvent = AdEventLog::query()->create([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
            'ad_placement_id' => $placement->id,
            'event_type' => 'impression',
            'ip_hash' => hash('sha256', '127.0.0.1'),
            'session_hash' => hash('sha256', 'sess_old'),
            'occurred_at' => now()->subDays(120),
        ]);
        $recentEvent = AdEventLog::query()->create([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
            'ad_placement_id' => $placement->id,
            'domain_id' => $domain->id,
            'city_id' => $city->id,
            'category_id' => $category->id,
            'event_type' => 'click',
            'device_type' => 'mobile',
            'audience_type' => 'customer',
            'ip_hash' => hash('sha256', '127.0.0.1'),
            'session_hash' => hash('sha256', 'sess_recent'),
            'occurred_at' => now(),
        ]);

        $this->assertArrayNotHasKey('ip_hash', $recentEvent->toArray());
        $this->assertArrayNotHasKey('session_hash', $recentEvent->toArray());

        $pruned = AdEventLog::pruneOlderThan(now()->subDays(90));
        $this->assertSame(1, $pruned);
        $this->assertDatabaseMissing('ad_event_logs', ['id' => $oldEvent->id]);
        $this->assertDatabaseHas('ad_event_logs', ['id' => $recentEvent->id]);
    }
}
