<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Exceptions\IdentityException;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Notifications\Notifications\AccountSecurityNotification;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class AuthTokenService
{
    /**
     * @var list<string>
     */
    private const CUSTOMER_ABILITIES = [
        'customer:read',
        'customer:review',
        'customer:enquiry',
        'customer:bookmark',
    ];

    /**
     * @var list<string>
     */
    private const OWNER_ABILITIES = [
        'owner:business:manage',
        'owner:leads:manage',
        'owner:reviews:reply',
        'owner:billing:manage',
    ];

    /**
     * Authenticates or registers a standard customer after OTP consumption.
     * Administrator roles are strictly excluded from self-registration and Bearer issuance.
     *
     * @return array{
     *   token_type: string,
     *   access_token: string,
     *   session_id: string,
     *   expires_at: string,
     *   user: User,
     *   device: ?UserDevice
     * }
     */
    public function authenticateFromVerifiedChallenge(
        OtpChallenge $challenge,
        ?string $name,
        string $deviceName,
        ?string $platform = null,
        ?string $deviceIdentifier = null,
        ?string $fcmToken = null,
        ?string $appVersion = null,
    ): array {
        return DB::transaction(function () use (
            $challenge,
            $name,
            $deviceName,
            $platform,
            $deviceIdentifier,
            $fcmToken,
            $appVersion
        ): array {
            $user = User::query()->where('phone_e164', $challenge->phone_e164)->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $name,
                    'phone_e164' => $challenge->phone_e164,
                    'status' => 'active',
                ]);
            }

            if ($user->status !== 'active') {
                throw new IdentityException(
                    errorCode: 'ACCOUNT_SUSPENDED',
                    message: 'This account is not permitted to sign in.',
                    httpStatus: Response::HTTP_FORBIDDEN,
                );
            }

            if ($user->name === null && $name !== null && trim($name) !== '') {
                $user->name = trim($name);
            }

            $user->phone_verified_at = now();
            $user->last_login_at = now();
            $user->save();

            // Always ensure baseline customer role; never self-grant privileged/admin roles.
            $customerRole = Role::query()->firstOrCreate(
                ['name' => 'customer'],
                ['label' => 'Customer', 'description' => 'Standard consumer account.']
            );
            $user->roles()->syncWithoutDetaching([$customerRole->id]);
            $user->load('roles.permissions');

            $device = null;
            if ($platform !== null && $deviceIdentifier !== null) {
                $device = $this->upsertDevice(
                    user: $user,
                    platform: $platform,
                    deviceIdentifier: $deviceIdentifier,
                    fcmToken: $fcmToken,
                    appVersion: $appVersion,
                );
            }

            $abilities = $this->resolveApiAbilitiesForUser($user);
            $expirationMinutes = max(1, (int) config('sanctum.expiration', 43200));
            $expiresAt = now()->addMinutes($expirationMinutes);

            $newToken = $user->createToken($deviceName, $abilities, $expiresAt);

            /** @var PersonalAccessToken $accessTokenModel */
            $accessTokenModel = $newToken->accessToken;
            if ($device !== null) {
                $accessTokenModel->device_public_id = $device->public_id;
                $accessTokenModel->save();
            }

            return [
                'token_type' => 'Bearer',
                'access_token' => $newToken->plainTextToken,
                'session_id' => (string) $accessTokenModel->public_id,
                'expires_at' => $expiresAt->utc()->toIso8601ZuluString(),
                'user' => $user,
                'device' => $device,
            ];
        });
    }

    public function upsertDevice(
        User $user,
        string $platform,
        string $deviceIdentifier,
        ?string $fcmToken = null,
        ?string $appVersion = null,
    ): UserDevice {
        $device = UserDevice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'device_identifier' => $deviceIdentifier,
            ],
            [
                'platform' => $platform,
                'fcm_token' => $fcmToken,
                'app_version' => $appVersion,
                'last_active_at' => now(),
            ]
        );

        if ($device->wasRecentlyCreated) {
            $user->notify(new AccountSecurityNotification(
                eventType: 'new_device',
                title: 'New Device Recognized',
                message: "A new {$device->platform} device was linked to your account.",
                uniqueEventId: (string) $device->public_id,
                actionUrl: '/dashboard/settings',
                extraMetadata: [
                    'device_id' => (string) $device->public_id,
                    'platform' => $device->platform,
                ]
            ));
        }

        return $device;
    }

    public function revokeDeviceAndTokens(UserDevice $device): int
    {
        return DB::transaction(function () use ($device): int {
            $revoked = PersonalAccessToken::query()
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $device->user_id)
                ->where('device_public_id', $device->public_id)
                ->delete();

            $device->delete();

            return $revoked;
        });
    }

    public function revokeSession(PersonalAccessToken $token): void
    {
        $token->delete();
    }

    public function revokeAllUserSessions(User $user): int
    {
        return (int) $user->tokens()->delete();
    }

    /**
     * Coarse Sanctum abilities only; object-level authorization is ALWAYS enforced
     * independently by Laravel Policies. Admin permissions are never mapped to Bearer tokens.
     *
     * @return list<string>
     */
    public function resolveApiAbilitiesForUser(User $user): array
    {
        $abilities = self::CUSTOMER_ABILITIES;

        if ($user->hasRole('business_owner')) {
            $abilities = array_merge($abilities, self::OWNER_ABILITIES);
        }

        return array_values(array_unique($abilities));
    }
}
