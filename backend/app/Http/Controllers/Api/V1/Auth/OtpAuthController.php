<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Services\AuthTokenService;
use App\Domains\Identity\Services\OtpChallengeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RequestOtpRequest;
use App\Http\Requests\Api\V1\Auth\ResendOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\Api\V1\Identity\UserDeviceResource;
use App\Http\Resources\Api\V1\Identity\UserResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

final class OtpAuthController extends Controller
{
    public function __construct(
        private readonly OtpChallengeService $otpChallenges,
        private readonly AuthTokenService $authTokens,
    ) {}

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payload = $this->otpChallenges->requestChallenge(
            rawPhone: (string) $validated['phone_e164'],
            purpose: OtpPurpose::from((string) $validated['purpose']),
            ipAddress: (string) $request->ip(),
        );

        return ApiResponse::success($payload);
    }

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payload = $this->otpChallenges->resendChallenge(
            challengeId: (string) $validated['challenge_id'],
            rawPhone: (string) $validated['phone_e164'],
            purpose: OtpPurpose::from((string) $validated['purpose']),
            ipAddress: (string) $request->ip(),
        );

        return ApiResponse::success($payload);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $purpose = OtpPurpose::from((string) $validated['purpose']);

        $challenge = $this->otpChallenges->verifyAndConsume(
            challengeId: (string) $validated['challenge_id'],
            rawPhone: (string) $validated['phone_e164'],
            purpose: $purpose,
            otpCode: (string) $validated['otp'],
            ipAddress: (string) $request->ip(),
        );

        $deviceName = (string) ($validated['device_name']
            ?? $request->header('X-Client-Platform')
            ?? 'api-client');

        $authResult = $this->authTokens->authenticateFromVerifiedChallenge(
            challenge: $challenge,
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            deviceName: $deviceName,
            platform: isset($validated['platform']) ? (string) $validated['platform'] : null,
            deviceIdentifier: isset($validated['device_identifier']) ? (string) $validated['device_identifier'] : null,
            fcmToken: isset($validated['fcm_token']) ? (string) $validated['fcm_token'] : null,
            appVersion: isset($validated['app_version']) ? (string) $validated['app_version'] : null,
        );

        $response = [
            'token_type' => $authResult['token_type'],
            'access_token' => $authResult['access_token'],
            'session_id' => $authResult['session_id'],
            'expires_at' => $authResult['expires_at'],
            'user' => (new UserResource($authResult['user']))->resolve($request),
        ];

        if ($authResult['device'] !== null) {
            $response['device'] = (new UserDeviceResource($authResult['device']))->resolve($request);
        }

        return ApiResponse::success($response);
    }
}
