<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\UserDevice;
use App\Models\User;
use Database\Seeders\Reference\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SessionDeviceAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_token_expiration_logout_logout_all_and_suspended_account_block(): void
    {
        $user = User::factory()->create();
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $user->roles()->attach($customerRole->id);

        // 1. Expired Bearer token is rejected with 401
        $expired = $user->createToken('expired-client', ['customer:read'], now()->subMinute());
        $this->withToken($expired->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // 2. Valid token works on /me and /me PATCH (ignoring privileged field injection)
        $tokenA = $user->createToken('web-bff', ['customer:read'], now()->addDays(7));
        $tokenB = $user->createToken('flutter-ios', ['customer:read'], now()->addDays(7));

        $this->withToken($tokenA->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.public_id', $user->public_id)
            ->assertJsonPath('data.roles', ['customer'])
            ->assertJsonMissingPath('data.id');

        $this->withToken($tokenA->plainTextToken)
            ->patchJson('/api/v1/auth/me', [
                'name' => 'Priya Raman',
                'roles' => ['super_admin'],
                'status' => 'banned',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Priya Raman')
            ->assertJsonPath('data.roles', ['customer']);

        $this->assertSame('active', $user->fresh()->status);

        // 3. Logout revokes only tokenA; tokenB stays valid
        $this->withToken($tokenA->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenA->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenB->accessToken->id]);

        auth()->forgetGuards();
        $this->withToken($tokenA->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // 4. Logout-all revokes all remaining tokens
        $tokenC = $user->createToken('tablet', ['customer:read'], now()->addDays(7));
        auth()->forgetGuards();
        $this->withToken($tokenB->plainTextToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenC->accessToken->id]);

        // 5. Suspended account is immediately blocked even with an unexpired token
        $activeToken = $user->createToken('still-valid', ['customer:read'], now()->addDays(7));
        $user->update(['status' => 'suspended']);

        auth()->forgetGuards();
        $this->withToken($activeToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    public function test_device_registration_and_device_specific_token_revocation(): void
    {
        $user = User::factory()->create();
        $webToken = $user->createToken('web-bff', ['customer:read'], now()->addDays(7));
        $androidToken = $user->createToken('flutter-android', ['customer:read'], now()->addDays(7));

        // Register Android device bound to androidToken
        $androidRes = $this->withToken($androidToken->plainTextToken)
            ->postJson('/api/v1/auth/devices', [
                'platform' => 'android',
                'device_identifier' => 'pixel-9-pro-xyz',
                'fcm_token' => 'private_fcm_secret_token',
                'app_version' => '1.1.0',
                'bind_current_session' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.has_push_token', true);

        $androidDevicePublicId = (string) $androidRes->json('data.public_id');
        $this->assertStringNotContainsString('private_fcm_secret_token', (string) $androidRes->getContent());

        // List devices & active sessions (confirm no internal IDs or raw token hashes exposed)
        $devicesRes = $this->withToken($webToken->plainTextToken)
            ->getJson('/api/v1/auth/devices')
            ->assertOk();
        $this->assertCount(1, $devicesRes->json('data'));
        $this->assertArrayNotHasKey('id', $devicesRes->json('data.0'));

        $sessionsRes = $this->withToken($webToken->plainTextToken)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk();
        $this->assertCount(2, $sessionsRes->json('data'));
        $this->assertArrayNotHasKey('token', $sessionsRes->json('data.0'));
        $this->assertArrayNotHasKey('id', $sessionsRes->json('data.0'));

        // Revoking Android device deletes device and ONLY the Android-linked Sanctum token
        $this->withToken($webToken->plainTextToken)
            ->deleteJson("/api/v1/auth/devices/{$androidDevicePublicId}")
            ->assertOk()
            ->assertJsonPath('data.revoked_tokens_count', 1);

        $this->assertDatabaseMissing('user_devices', ['public_id' => $androidDevicePublicId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $androidToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $webToken->accessToken->id]);

        auth()->forgetGuards();
        $this->withToken($androidToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        auth()->forgetGuards();
        $this->withToken($webToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_cross_user_idor_blocked_by_policies_independently_of_token_abilities(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();

        $victimDevice = UserDevice::factory()->create(['user_id' => $victim->id]);
        $victimToken = $victim->createToken('victim-phone', ['customer:read'], now()->addDays(7));
        /** @var PersonalAccessToken $victimSession */
        $victimSession = $victimToken->accessToken;

        // Even if attacker somehow held a wildcard ['*'] Sanctum token, Policies must still block IDOR!
        $attackerWildcardToken = $attacker->createToken('attacker-wildcard', ['*'], now()->addDays(7));

        $this->withToken($attackerWildcardToken->plainTextToken)
            ->deleteJson("/api/v1/auth/devices/{$victimDevice->public_id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->withToken($attackerWildcardToken->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$victimSession->public_id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // Confirm victim resources remain intact
        $this->assertDatabaseHas('user_devices', ['id' => $victimDevice->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $victimSession->id]);

        // Confirm Policy assignRole allows only super_admin
        $this->assertFalse(Gate::forUser($attacker)->allows('assignRole', $victim));

        $superAdmin = User::factory()->create();
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $superAdmin->roles()->attach($superAdminRole->id);
        $this->assertTrue(Gate::forUser($superAdmin)->allows('assignRole', $victim));
    }

    public function test_role_and_permission_middleware_enforce_live_db_rbac_independently_of_abilities(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'active', 'role:business_owner'])
            ->get('/api/v1/_test/owner-only', static fn () => response()->json(['ok' => true]));

        Route::middleware(['api', 'auth:sanctum', 'active', 'permission:leads.manage_own'])
            ->get('/api/v1/_test/leads-perm', static fn () => response()->json(['ok' => true]));

        $customer = User::factory()->create();
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
        $customer->roles()->attach($customerRole->id);

        // Give customer a broad wildcard token -> live DB RBAC middleware must STILL deny access!
        $customerToken = $customer->createToken('wildcard-customer', ['*'], now()->addDays(7));

        $this->withToken($customerToken->plainTextToken)
            ->getJson('/api/v1/_test/owner-only')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->withToken($customerToken->plainTextToken)
            ->getJson('/api/v1/_test/leads-perm')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // Assign business_owner role in DB -> access now granted immediately
        $ownerRole = Role::query()->where('name', 'business_owner')->firstOrFail();
        $customer->roles()->attach($ownerRole->id);

        $this->withToken($customerToken->plainTextToken)
            ->getJson('/api/v1/_test/owner-only')
            ->assertOk();

        $this->withToken($customerToken->plainTextToken)
            ->getJson('/api/v1/_test/leads-perm')
            ->assertOk();
    }
}
