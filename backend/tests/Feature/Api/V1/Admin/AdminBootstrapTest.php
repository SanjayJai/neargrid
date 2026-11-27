<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_bootstrap_rejects_unauthenticated_and_non_super_admin_accounts(): void
    {
        $this->getJson('/api/v1/admin/bootstrap')
            ->assertUnauthorized();

        $customer = User::factory()->create(['status' => 'active']);
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $customer->roles()->syncWithoutDetaching([$customerRole->id]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/bootstrap')
            ->assertForbidden();

        $owner = User::factory()->create(['status' => 'active']);
        $ownerRole = Role::query()->where('name', 'business_owner')->firstOrFail();
        $owner->roles()->syncWithoutDetaching([$ownerRole->id]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/bootstrap')
            ->assertForbidden();
    }

    public function test_admin_bootstrap_returns_counts_and_actor_for_super_admin_without_secrets(): void
    {
        $superAdmin = User::factory()->create([
            'status' => 'active',
            'name' => 'Master Admin',
        ]);
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $superAdmin->roles()->syncWithoutDetaching([$superAdminRole->id]);

        AuditLog::query()->create([
            'actor_user_id' => $superAdmin->id,
            'actor_role' => 'super_admin',
            'action' => 'domain.activated',
            'auditable_type' => 'Domain',
            'auditable_id' => 1,
            'old_values' => ['secret_token' => 'should-not-leak'],
            'new_values' => ['status' => 'active'],
            'ip_address' => '10.0.0.5',
        ]);

        Sanctum::actingAs($superAdmin);
        $res = $this->getJson('/api/v1/admin/bootstrap')
            ->assertOk();

        $res->assertJsonStructure([
            'data' => [
                'actor' => ['public_id', 'name', 'email', 'roles', 'permissions'],
                'module_counts' => [
                    'businesses_total',
                    'pending_verifications',
                    'pending_reviews',
                    'open_enquiries',
                    'domains_total',
                    'categories_total',
                    'users_total',
                ],
                'recent_activity' => [
                    ['id', 'actor_role', 'action', 'auditable_type', 'auditable_id', 'created_at'],
                ],
                'capabilities' => ['navigation_search', 'record_search'],
            ],
        ]);

        $firstActivity = $res->json('data.recent_activity.0');
        $this->assertArrayNotHasKey('old_values', $firstActivity);
        $this->assertArrayNotHasKey('new_values', $firstActivity);
        $this->assertArrayNotHasKey('ip_address', $firstActivity);
        $this->assertArrayNotHasKey('actor_user_id', $firstActivity);

        $raw = json_encode($res->json());
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('token', $raw);
        $this->assertStringNotContainsString('10.0.0.5', $raw);
    }
}
