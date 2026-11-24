<?php

declare(strict_types=1);

namespace App\Domains\Leads\Services;

use App\Domains\Directory\Exceptions\DirectoryException;
use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Services\AuthTokenService;
use App\Domains\Identity\Services\OtpChallengeService;
use App\Domains\Identity\Support\PhoneNormalizer;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\EnquiryType;
use App\Domains\Leads\Enums\EnquiryUrgency;
use App\Domains\Leads\Jobs\DispatchEnquiryLeadsJob;
use App\Domains\Leads\Models\ContactReveal;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Notifications\Notifications\EnquiryCancelledNotification;
use App\Domains\Notifications\Notifications\EnquirySubmittedNotification;
use App\Domains\Notifications\Support\CancellationReasonMapper;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class EnquiryManagementService
{
    public function __construct(
        private readonly OtpChallengeService $otpService,
        private readonly AuthTokenService $authTokenService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   enquiry: Enquiry,
     *   requires_otp: bool,
     *   challenge_id?: string,
     *   expires_in_seconds?: int,
     *   resend_after_seconds?: int
     * }
     */
    public function createEnquiry(array $data, ?User $actor, string $ipAddress): array
    {
        $phoneE164 = PhoneNormalizer::normalizeAndValidate((string) $data['phone_e164']);
        $ipHash = hash('sha256', $ipAddress.config('app.key'));

        $this->enforceRateLimits($phoneE164, $ipHash);

        $category = Category::query()->where('slug', (string) $data['category_slug'])->first();
        if ($category === null) {
            throw new DirectoryException('INVALID_CATEGORY', 'Selected category does not exist.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $city = City::query()->where('slug', (string) $data['city_slug'])->first();
        if ($city === null) {
            throw new DirectoryException('INVALID_CITY', 'Selected city does not exist.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $locality = null;
        if (! empty($data['locality_slug'])) {
            $locality = Locality::query()
                ->where('city_id', $city->id)
                ->where('slug', (string) $data['locality_slug'])
                ->first();
        }

        $targetBusiness = null;
        $enquiryType = EnquiryType::Broadcast;
        if (! empty($data['target_business_slug'])) {
            $targetBusiness = Business::query()
                ->where('slug', (string) $data['target_business_slug'])
                ->first();
            if ($targetBusiness !== null) {
                $enquiryType = EnquiryType::Direct;
            }
        }

        // Formulate affirmative DPDP disclosure text
        $consentVersion = (string) ($data['consent_version'] ?? '2026.1');
        if ($enquiryType === EnquiryType::Direct) {
            $consentText = sprintf(
                'I consent to NearGrid sharing my name, phone number, and enquiry details directly with %s for receiving callbacks and quotes.',
                $targetBusiness->name
            );
        } else {
            $localityOrCity = $locality?->name ?? $city->name;
            $consentText = sprintf(
                'I consent to NearGrid sharing my name, phone number, and requirement with up to 4 eligible, verified businesses matching %s in %s for callbacks and competitive quotes.',
                $category->name,
                $localityOrCity
            );
        }

        $urgency = EnquiryUrgency::tryFrom((string) ($data['urgency'] ?? 'flexible')) ?? EnquiryUrgency::Flexible;

        $isAuthenticated = $actor !== null;

        if ($isAuthenticated) {
            $enquiry = DB::transaction(function () use (
                $actor, $targetBusiness, $category, $city, $locality,
                $data, $phoneE164, $urgency, $enquiryType, $consentVersion,
                $consentText, $ipHash
            ): Enquiry {
                $enquiry = Enquiry::query()->create([
                    'user_id' => $actor->id,
                    'target_business_id' => $targetBusiness?->id,
                    'category_id' => $category->id,
                    'city_id' => $city->id,
                    'locality_id' => $locality?->id,
                    'customer_name' => trim((string) $data['customer_name']),
                    'phone_e164' => $phoneE164,
                    'email' => isset($data['email']) ? trim((string) $data['email']) : null,
                    'requirement' => trim((string) $data['requirement']),
                    'urgency' => $urgency,
                    'enquiry_type' => $enquiryType,
                    'status' => EnquiryStatus::Active,
                    'consent_given' => true,
                    'consent_version' => $consentVersion,
                    'consent_text' => $consentText,
                    'consent_purpose' => 'lead_matching_and_contact',
                    'consent_timestamp' => now(),
                    'ip_address_hash' => $ipHash,
                    'phone_verified_at' => now(),
                ]);

                return $enquiry;
            });

            $this->hitRateLimits($phoneE164, $ipHash);
            DispatchEnquiryLeadsJob::dispatch($enquiry->id);

            $actor->notify(new EnquirySubmittedNotification(
                enquiryId: $enquiry->id,
                enquiryUlid: $enquiry->public_id,
                categoryName: $category->name,
                targetBusinessName: $targetBusiness?->name,
            ));

            return [
                'enquiry' => $enquiry,
                'requires_otp' => false,
            ];
        }

        // Guest Flow: Trigger OTP challenge via OtpChallengeService
        $challenge = $this->otpService->requestChallenge(
            $phoneE164,
            OtpPurpose::EnquiryVerify,
            $ipAddress
        );

        $enquiry = Enquiry::query()->create([
            'user_id' => null,
            'target_business_id' => $targetBusiness?->id,
            'category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality?->id,
            'customer_name' => trim((string) $data['customer_name']),
            'phone_e164' => $phoneE164,
            'email' => isset($data['email']) ? trim((string) $data['email']) : null,
            'requirement' => trim((string) $data['requirement']),
            'urgency' => $urgency,
            'enquiry_type' => $enquiryType,
            'status' => EnquiryStatus::PendingOtp,
            'consent_given' => true,
            'consent_version' => $consentVersion,
            'consent_text' => $consentText,
            'consent_purpose' => 'lead_matching_and_contact',
            'consent_timestamp' => now(),
            'ip_address_hash' => $ipHash,
            'otp_challenge_id' => $challenge['challenge_id'],
        ]);

        $this->hitRateLimits($phoneE164, $ipHash);

        return [
            'enquiry' => $enquiry,
            'requires_otp' => true,
            'challenge_id' => $challenge['challenge_id'],
            'expires_in_seconds' => $challenge['expires_in_seconds'],
            'resend_after_seconds' => $challenge['resend_after_seconds'],
        ];
    }

    /**
     * @return array{enquiry: Enquiry, auth: array<string, mixed>}
     */
    public function verifyEnquiryOtp(Enquiry $enquiry, string $otp, string $ipAddress, ?string $deviceName = 'Web Browser'): array
    {
        if ($enquiry->status !== EnquiryStatus::PendingOtp || $enquiry->otp_challenge_id === null) {
            throw new DirectoryException('INVALID_ENQUIRY_STATE', 'This enquiry is not awaiting OTP verification.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $verifiedChallenge = $this->otpService->verifyAndConsume(
            $enquiry->otp_challenge_id,
            $enquiry->phone_e164,
            OtpPurpose::EnquiryVerify,
            $otp,
            $ipAddress
        );

        // Authenticate guest customer and establish standard customer account
        $authResult = $this->authTokenService->authenticateFromVerifiedChallenge(
            $verifiedChallenge,
            $enquiry->customer_name,
            $deviceName ?? 'Web Browser'
        );

        $enquiry->user_id = $authResult['user']->id;
        $enquiry->status = EnquiryStatus::Active;
        $enquiry->phone_verified_at = now();
        $enquiry->save();

        DispatchEnquiryLeadsJob::dispatch($enquiry->id);

        $enquiry->loadMissing(['category', 'targetBusiness']);
        $authResult['user']->notify(new EnquirySubmittedNotification(
            enquiryId: $enquiry->id,
            enquiryUlid: $enquiry->public_id,
            categoryName: $enquiry->category->name,
            targetBusinessName: $enquiry->targetBusiness?->name,
        ));

        return [
            'enquiry' => $enquiry,
            'auth' => $authResult,
        ];
    }

    public function recordContactReveal(Business $business, ?User $actor, string $channel, string $ipAddress): ContactReveal
    {
        $ipHash = hash('sha256', $ipAddress.config('app.key'));

        $rateKey = "reveal_ip:{$ipHash}";
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            throw new DirectoryException('RATE_LIMITED', 'Too many contact reveal requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($rateKey, 3600);

        return ContactReveal::query()->create([
            'business_id' => $business->id,
            'user_id' => $actor?->id,
            'channel' => in_array($channel, ['phone', 'whatsapp'], true) ? $channel : 'phone',
            'ip_address_hash' => $ipHash,
        ]);
    }

    public function cancelEnquiryByAdmin(Enquiry $enquiry, User $admin, string $reason): Enquiry
    {
        $oldStatus = $enquiry->status->value;
        $enquiry->status = EnquiryStatus::Cancelled;
        $enquiry->cancellation_reason = trim($reason);
        $enquiry->cancelled_at = now();
        $enquiry->cancelled_by_user_id = $admin->id;
        $enquiry->save();

        $this->auditLogger->record(
            actor: $admin,
            action: 'enquiry.cancel',
            auditable: $enquiry,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'cancelled', 'reason' => $reason]
        );

        $enquiry->loadMissing('user');
        if ($enquiry->user !== null) {
            $safeReason = CancellationReasonMapper::toCustomerFacingMessage($reason);
            $enquiry->user->notify(new EnquiryCancelledNotification(
                enquiryId: $enquiry->id,
                enquiryUlid: $enquiry->public_id,
                safeReason: $safeReason,
            ));
        }

        return $enquiry;
    }

    private function enforceRateLimits(string $phoneE164, string $ipHash): void
    {
        $phoneKey = "enquiry_phone:{$phoneE164}";
        if (RateLimiter::tooManyAttempts($phoneKey, 5)) {
            $seconds = RateLimiter::availableIn($phoneKey);
            throw new DirectoryException(
                'RATE_LIMITED',
                "Too many enquiries submitted from this phone number. Please try again in {$seconds} seconds.",
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $ipKey = "enquiry_ip:{$ipHash}";
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            $seconds = RateLimiter::availableIn($ipKey);
            throw new DirectoryException(
                'RATE_LIMITED',
                "Too many enquiries submitted from your network. Please try again in {$seconds} seconds.",
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }
    }

    private function hitRateLimits(string $phoneE164, string $ipHash): void
    {
        RateLimiter::hit("enquiry_phone:{$phoneE164}", 3600);
        RateLimiter::hit("enquiry_ip:{$ipHash}", 3600);
    }
}
