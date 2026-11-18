<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Services\AuthTokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterDeviceRequest;
use App\Http\Resources\Api\V1\Identity\SessionTokenResource;
use App\Http\Resources\Api\V1\Identity\UserDeviceResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DeviceSessionController extends Controller
{
    public function __construct(
        private readonly AuthTokenService $authTokens,
    ) {}

    public function indexDevices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $devices = $user->devices()
            ->orderByDesc('last_active_at')
            ->get();

        return ApiResponse::success(
            UserDeviceResource::collection($devices)->resolve($request)
        );
    }

    public function storeDevice(RegisterDeviceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $device = $this->authTokens->upsertDevice(
            user: $user,
            platform: (string) $validated['platform'],
            deviceIdentifier: (string) $validated['device_identifier'],
            fcmToken: isset($validated['fcm_token']) ? (string) $validated['fcm_token'] : null,
            appVersion: isset($validated['app_version']) ? (string) $validated['app_version'] : null,
        );

        if ((bool) ($validated['bind_current_session'] ?? true)) {
            $currentToken = $user->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken) {
                Gate::forUser($user)->authorize('view', $currentToken);
                $currentToken->device_public_id = $device->public_id;
                $currentToken->save();
            }
        }

        return ApiResponse::created((new UserDeviceResource($device))->resolve($request));
    }

    public function destroyDevice(Request $request, UserDevice $userDevice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', $userDevice);

        $revokedTokens = $this->authTokens->revokeDeviceAndTokens($userDevice);

        return ApiResponse::success([
            'revoked_tokens_count' => $revokedTokens,
        ]);
    }

    public function indexSessions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sessions = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(
            SessionTokenResource::collection($sessions)->resolve($request)
        );
    }

    public function destroySession(Request $request, PersonalAccessToken $session): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', $session);

        $this->authTokens->revokeSession($session);

        return ApiResponse::success([
            'revoked' => true,
        ]);
    }
}
