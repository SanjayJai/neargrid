<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminAdPlacementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $superRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->superAdmin->roles()->syncWithoutDetaching([$superRole->id]);
    }

    public function test_placement_management_requires_super_admin_and_detects_conflicts(): void
    {
        $this->getJson('/api/v1/admin/advertising/placements')->assertUnauthorized();

        $customer = User::factory()->create(['status' => 'active']);
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $customer->roles()->syncWithoutDetaching([$customerRole->id]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/advertising/placements')->assertForbidden();

        Sanctum::actingAs($this->superAdmin);

        $listRes = $this->getJson('/api/v1/admin/advertising/placements?per_page=50')->assertOk();
        $this->assertGreaterThanOrEqual(32, count($listRes->json('data')));

        $desktopTop = AdPlacement::query()->where('key', 'search.top_leaderboard')->firstOrFail();
        $mobileTop = AdPlacement::query()->where('key', 'search.mobile_top_banner')->firstOrFail();

        // Update mobileTop to device_scope='all' so it conflicts with desktopTop on search:top
        $patchRes = $this->patchJson("/api/v1/admin/advertising/placements/{$mobileTop->public_id}", [
            'device_scope' => 'all',
            'max_ads' => 2,
        ])->assertOk();

        $conflicts = $patchRes->json('data.conflicts');
        $this->assertNotEmpty($conflicts);
        $this->assertSame('search', $conflicts[0]['page_type']);
        $this->assertSame('top', $conflicts[0]['slot_position']);
        $this->assertTrue(AuditLog::query()->where('action', 'ad_placement.updated')->exists());

        // Also test targeting-rules sync on campaign
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $campaign = AdCampaign::query()->create([
            'created_by_user_id' => $this->superAdmin->id,
            'name' => 'Targeted Campaign',
            'campaign_type' => 'sponsored_banner',
            'status' => 'draft',
        ]);

        $this->putJson("/api/v1/admin/advertising/campaigns/{$campaign->public_id}/targeting-rules", [
            'rules' => [
                ['target_type' => 'city', 'city_public_id' => $city->public_id, 'is_exclude' => false],
                ['target_type' => 'device', 'target_value' => 'desktop', 'is_exclude' => false],
            ],
        ])->assertOk()->assertJsonPath('data.rule_count', 2);

        // Suppress unused variable lint
        $this->assertNotNull($desktopTop->public_id);
    }
}
