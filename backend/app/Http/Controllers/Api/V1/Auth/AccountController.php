<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Services\AuthTokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\Identity\UserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AccountController extends Controller
{
    public function __construct(
        private readonly AuthTokenService $authTokens,
    ) {}

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $user);

        $user->load('roles.permissions');

        return ApiResponse::success((new UserResource($user))->resolve($request));
    }

    public function updateMe(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $user);

        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('email', $validated)) {
            if ($user->email !== $validated['email']) {
                $user->email_verified_at = null;
            }
            $user->email = $validated['email'];
        }

        $user->save();
        $user->load('roles.permissions');

        return ApiResponse::success((new UserResource($user))->resolve($request));
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            Gate::forUser($user)->authorize('delete', $token);
            $this->authTokens->revokeSession($token);
        }

        return ApiResponse::noContent();
    }

    public function logoutAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $user);

        $this->authTokens->revokeAllUserSessions($user);

        return ApiResponse::noContent();
    }
}
