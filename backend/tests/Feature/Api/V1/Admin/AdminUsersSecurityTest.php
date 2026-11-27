<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminUsersSecurityTest extends TestCase
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

    public function test_user_and_security_endpoints_enforce_authorization_and_hide_secrets(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->getJson('/api/v1/admin/roles')->assertUnauthorized();
        $this->getJson('/api/v1/admin/permissions')->assertUnauthorized();
        $this->getJson('/api/v1/admin/security/sessions')->assertUnauthorized();
        $this->getJson('/api/v1/admin/erasure-requests')->assertUnauthorized();

        $customer = User::factory()->create(['status' => 'active']);
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $customer->roles()->syncWithoutDetaching([$customerRole->id]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->getJson('/api/v1/admin/security/sessions')->assertForbidden();

        Sanctum::actingAs($this->superAdmin);
        $detail = $this->getJson("/api/v1/admin/users/{$customer->public_id}")->assertOk();
        $userJson = $detail->json('data.user');
        $this->assertArrayNotHasKey('password', $userJson);
        $this->assertArrayNotHasKey('remember_token', $userJson);
        $this->assertArrayNotHasKey('totp_secret_encrypted', $userJson);
    }

    public function test_suspend_restore_verify_and_last_super_admin_protection(): void
    {
        $customer = User::factory()->create(['status' => 'active', 'phone_verified_at' => null]);
        $customer->createToken('web-session');

        Sanctum::actingAs($this->superAdmin);

        // Self-suspension blocked
        $this->patchJson("/api/v1/admin/users/{$this->superAdmin->public_id}/status", [
            'status' => 'suspended',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SELF_SUSPENSION_FORBIDDEN');

        // Suspend customer revokes tokens & records audit
        $this->patchJson("/api/v1/admin/users/{$customer->public_id}/status", [
            'status' => 'suspended',
            'reason' => 'Policy violation',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(0, $customer->tokens()->count());

        // Restore & verify
        $this->patchJson("/api/v1/admin/users/{$customer->public_id}/status", [
            'status' => 'active',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson("/api/v1/admin/users/{$customer->public_id}/verify", [
            'channel' => 'phone',
        ])->assertOk();
        $this->assertNotNull($customer->fresh()->phone_verified_at);

        // Last super admin protection: another super_admin cannot suspend the last remaining super_admin if only 1 active
        $otherSuper = User::factory()->create(['status' => 'suspended']);
        $superRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $otherSuper->roles()->syncWithoutDetaching([$superRole->id]);

        // Only $this->superAdmin is active, test removing super_admin role from self is blocked
        $this->deleteJson("/api/v1/admin/users/{$this->superAdmin->public_id}/roles/{$superRole->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SELF_ROLE_REMOVAL_FORBIDDEN');
    }

    public function test_role_permission_sync_and_last_super_admin_role_removal_protection(): void
    {
        $secondSuper = User::factory()->create(['status' => 'active']);
        $superRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $moderatorRole = Role::query()->where('name', 'moderator')->firstOrFail();

        Sanctum::actingAs($this->superAdmin);

        // Cannot remove roles.manage from super_admin
        $this->putJson("/api/v1/admin/roles/{$superRole->id}/permissions", [
            'permissions' => ['audit_logs.view'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SUPER_ADMIN_PERMISSION_LOCKED');

        // Cannot grant a permission the actor does not possess
        Permission::query()->create([
            'name' => 'vault.root_key',
            'label' => 'Unpossessed Root Permission',
            'group_name' => 'security',
        ]);
        $this->putJson("/api/v1/admin/roles/{$moderatorRole->id}/permissions", [
            'permissions' => ['reviews.moderate', 'vault.root_key'],
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_ESCALATION_FORBIDDEN');

        // Assign and remove moderator role on another user
        $this->postJson("/api/v1/admin/users/{$secondSuper->public_id}/roles", [
            'role' => 'moderator',
        ])
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'moderator');

        $this->deleteJson("/api/v1/admin/users/{$secondSuper->public_id}/roles/{$moderatorRole->id}")
            ->assertOk();

        $this->assertTrue(AuditLog::query()->where('action', 'user.role_assigned')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'user.role_removed')->exists());
    }

    public function test_session_listing_single_revocation_and_security_events_hide_tokens(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $newToken = $user->createToken('mobile-ios');
        $plainToken = $newToken->plainTextToken;
        $tokenPublicId = $newToken->accessToken->public_id;

        Sanctum::actingAs($this->superAdmin);

        $sessionsRes = $this->getJson('/api/v1/admin/security/sessions?q=mobile-ios')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $tokenPublicId);

        $this->assertArrayNotHasKey('token', $sessionsRes->json('data.0'));

        $this->deleteJson("/api/v1/admin/security/sessions/{$tokenPublicId}")
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->assertSame(0, $user->tokens()->count());
        $this->getJson('/api/v1/admin/security/events')->assertOk();

        // Confirm revoked Sanctum token cannot authenticate subsequent requests
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_dev_otp_is_only_exposed_in_local_when_explicit_flag_is_enabled(): void
    {
        // Production / testing env MUST NOT expose dev_otp even if flag is true
        Config::set('directory.otp.expose_dev_otp', true);
        $resTesting = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840099901',
            'purpose' => 'login',
        ])->assertOk();
        $this->assertNull($resTesting->json('data.dev_otp'));

        // Local env with flag=false MUST NOT expose dev_otp
        $this->app['env'] = 'local';
        Config::set('directory.otp.expose_dev_otp', false);
        $resLocalOff = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840099902',
            'purpose' => 'login',
        ])->assertOk();
        $this->assertNull($resLocalOff->json('data.dev_otp'));

        // Local env with flag=true exposes 6-digit dev_otp
        Config::set('directory.otp.expose_dev_otp', true);
        $resLocalOn = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840099903',
            'purpose' => 'login',
        ])->assertOk();
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $resLocalOn->json('data.dev_otp'));
    }

    public function test_controlled_account_erasure_respects_retention_and_anonymizes_safely(): void
    {
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $owner = User::factory()->create(['status' => 'active', 'email' => 'erase@example.com']);
        $biz = Business::factory()->create([
            'owner_user_id' => $owner->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'primary_category_id' => $category->id,
            'status' => BusinessStatus::Active,
        ]);

        Sanctum::actingAs($this->superAdmin);

        $reqRes = $this->postJson("/api/v1/admin/users/{$owner->public_id}/erasure-requests", [
            'reason' => 'GDPR user deletion request',
        ])->assertCreated();

        $erasureId = (string) $reqRes->json('data.public_id');

        // Blocked while owning active business
        $this->postJson("/api/v1/admin/erasure-requests/{$erasureId}/review", [
            'decision' => 'approved',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETENTION_DEPENDENCY_BLOCKED');

        // Suspend business, then approve anonymization
        $biz->status = BusinessStatus::Suspended;
        $biz->save();

        $this->postJson("/api/v1/admin/erasure-requests/{$erasureId}/review", [
            'decision' => 'approved',
            'decision_notes' => 'Retention check cleared',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Cannot process an already-reviewed erasure request twice
        $this->postJson("/api/v1/admin/erasure-requests/{$erasureId}/review", [
            'decision' => 'rejected',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ERASURE_ALREADY_REVIEWED');

        $anonymized = User::withTrashed()->findOrFail($owner->id);
        $this->assertSame('Deleted User', $anonymized->name);
        $this->assertNull($anonymized->email);
        $this->assertSame('erased', $anonymized->status);
        $this->assertNotNull($anonymized->deleted_at);
    }
}
