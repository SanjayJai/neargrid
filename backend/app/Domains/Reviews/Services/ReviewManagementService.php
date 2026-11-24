<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Services;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Jobs\DispatchRevalidationWebhookJob;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Services\OtpChallengeService;
use App\Domains\Identity\Support\PhoneNormalizer;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\LeadStatus;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Domains\Notifications\Notifications\ReviewReplyReceivedNotification;
use App\Domains\Reviews\Enums\ReviewModerationStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Enums\ReviewTrustBadge;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\Review;
use App\Domains\Reviews\Models\ReviewReport;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class ReviewManagementService
{
    public function __construct(
        private readonly OtpChallengeService $otpService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{
     *   customer_name: string,
     *   phone_e164: string,
     *   rating: int,
     *   title?: ?string,
     *   content?: ?string,
     * }  $data
     * @return array{
     *   review: Review,
     *   requires_otp: bool,
     *   challenge_id?: string,
     *   expires_in_seconds?: int,
     *   resend_after_seconds?: int,
     * }
     */
    public function submitReview(Business $business, array $data, ?User $actor, string $ipAddress): array
    {
        if ($business->status !== BusinessStatus::Active) {
            throw new ReviewException(
                'BUSINESS_NOT_ACTIVE',
                'Reviews can only be submitted for active business listings.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $phoneE164 = PhoneNormalizer::normalizeAndValidate($data['phone_e164']);
        $ipHash = hash('sha256', $ipAddress);

        // Normalize atomic lock key: shared across authenticated and guest submissions.
        // Lock key is bound to the target business and the normalized reviewer phone.
        $phoneLockKey = "review_lock:biz_{$business->id}:phone_{$phoneE164}";

        try {
            $lock = Cache::lock($phoneLockKey, 10);

            return $lock->block(2, function () use ($business, $data, $actor, $ipAddress, $phoneE164, $ipHash): array {
                // If authenticated, also acquire user-scoped lock to prevent concurrent submissions with alternate phone
                if ($actor !== null) {
                    $userLock = Cache::lock("review_lock:biz_{$business->id}:user_{$actor->id}", 10);

                    return $userLock->block(2, function () use ($business, $data, $actor, $ipAddress, $phoneE164, $ipHash): array {
                        return $this->processSubmissionWithinLock($business, $data, $actor, $ipAddress, $phoneE164, $ipHash);
                    });
                }

                return $this->processSubmissionWithinLock($business, $data, $actor, $ipAddress, $phoneE164, $ipHash);
            });
        } catch (LockTimeoutException) {
            throw new ReviewException(
                'CONCURRENT_SUBMISSION',
                'A review submission is currently being processed. Please wait a moment.',
                Response::HTTP_CONFLICT
            );
        }
    }

    /**
     * @param  array{
     *   customer_name: string,
     *   phone_e164: string,
     *   rating: int,
     *   title?: ?string,
     *   content?: ?string,
     * }  $data
     * @return array{
     *   review: Review,
     *   requires_otp: bool,
     *   challenge_id?: string,
     *   expires_in_seconds?: int,
     *   resend_after_seconds?: int,
     * }
     */
    private function processSubmissionWithinLock(
        Business $business,
        array $data,
        ?User $actor,
        string $ipAddress,
        string $phoneE164,
        string $ipHash
    ): array {
        // Anti-shill rule 1: Authenticated owner or active staff member cannot review their own business
        if ($actor !== null) {
            $isAssociated = $business->isOwnedBy($actor) || $business->activeMemberFor($actor) !== null;
            if ($isAssociated) {
                throw new ReviewException(
                    'CANNOT_REVIEW_OWN_BUSINESS',
                    'Business owners and team members cannot review their own business.',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // Anti-shill rule 2: Normalized phone matches business primary phone
        if ($business->primary_phone_e164 !== null && $business->primary_phone_e164 === $phoneE164) {
            throw new ReviewException(
                'CANNOT_REVIEW_OWN_BUSINESS',
                'Reviews cannot be submitted using the business primary phone number.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Anti-shill rule 3: Normalized phone belongs to the business owner
        $owner = $business->owner;
        if ($owner !== null && $owner->phone_e164 !== null && $owner->phone_e164 === $phoneE164) {
            throw new ReviewException(
                'CANNOT_REVIEW_OWN_BUSINESS',
                'Business owners and team members cannot review their own business.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Anti-shill rule 4: Normalized phone belongs to an active staff member of the business
        $isStaffPhone = BusinessMember::query()
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->whereHas('user', function ($u) use ($phoneE164): void {
                $u->where('phone_e164', $phoneE164);
            })
            ->exists();

        if ($isStaffPhone) {
            throw new ReviewException(
                'CANNOT_REVIEW_OWN_BUSINESS',
                'Business owners and team members cannot review their own business.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // One-review-per-business 30-day duplicate protection
        $thirtyDaysAgo = now()->subDays(30);

        if ($actor !== null) {
            $duplicateUser = Review::query()
                ->where('business_id', $business->id)
                ->where('user_id', $actor->id)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->whereNotIn('status', [ReviewStatus::Rejected->value])
                ->exists();

            if ($duplicateUser) {
                throw new ReviewException(
                    'DUPLICATE_REVIEW_30_DAYS',
                    'You have already submitted a review for this business in the last 30 days.',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $duplicatePhone = Review::query()
            ->where('business_id', $business->id)
            ->where('phone_e164', $phoneE164)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereNotIn('status', [ReviewStatus::Rejected->value])
            ->exists();

        if ($duplicatePhone) {
            throw new ReviewException(
                'DUPLICATE_REVIEW_30_DAYS',
                'A review has already been submitted from this phone number for this business in the last 30 days.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Trust badge determination (Verified Customer, Verified Lead, or None)
        $trustResolution = $this->resolveVerifiedInteraction($business, $actor, $phoneE164);

        $rating = max(1, min(5, (int) $data['rating']));
        $title = isset($data['title']) && trim((string) $data['title']) !== '' ? trim((string) $data['title']) : null;
        $content = isset($data['content']) && trim((string) $data['content']) !== '' ? trim((string) $data['content']) : null;

        if ($actor !== null) {
            // Authenticated customer: auto-publish immediately (documented post-moderation policy)
            $review = Review::query()->create([
                'business_id' => $business->id,
                'user_id' => $actor->id,
                'customer_name' => trim($data['customer_name']),
                'phone_e164' => $phoneE164,
                'rating' => $rating,
                'title' => $title,
                'content' => $content,
                'is_verified_interaction' => $trustResolution['is_verified'],
                'trust_badge' => $trustResolution['trust_badge'],
                'interaction_type' => $trustResolution['type'],
                'interaction_id' => $trustResolution['id'],
                'status' => ReviewStatus::Published,
                'moderation_status' => ReviewModerationStatus::Approved,
                'phone_verified_at' => now(),
                'ip_address_hash' => $ipHash,
            ]);

            $this->recalculateBusinessRating($business);

            $citySlug = $business->city?->slug ?? 'chennai';
            $paths = ["/{$citySlug}/business/{$business->slug}", "/{$citySlug}"];
            $categorySlug = $business->primaryCategory?->slug;
            if ($categorySlug) {
                $paths[] = "/{$citySlug}/{$categorySlug}";
            }
            DispatchRevalidationWebhookJob::dispatch($paths, 'review_published')->afterCommit();

            return [
                'review' => $review,
                'requires_otp' => false,
            ];
        }

        // Unauthenticated guest: persist OTP challenge and review inside DB transaction.
        // SMS notification is dispatched asynchronously via Laravel queue after transaction commit.
        /** @var array{challenge: array{challenge_id: string, expires_in_seconds: int, resend_after_seconds: int}, review: Review} $guestResult */
        $guestResult = DB::transaction(function () use ($business, $data, $phoneE164, $rating, $title, $content, $trustResolution, $ipHash, $ipAddress): array {
            $challenge = $this->otpService->requestChallenge($phoneE164, OtpPurpose::ReviewVerify, $ipAddress);

            $otpRecord = OtpChallenge::query()
                ->where('challenge_id', $challenge['challenge_id'])
                ->first();

            $review = Review::query()->create([
                'business_id' => $business->id,
                'user_id' => null,
                'customer_name' => trim($data['customer_name']),
                'phone_e164' => $phoneE164,
                'rating' => $rating,
                'title' => $title,
                'content' => $content,
                'is_verified_interaction' => $trustResolution['is_verified'],
                'trust_badge' => $trustResolution['trust_badge'],
                'interaction_type' => $trustResolution['type'],
                'interaction_id' => $trustResolution['id'],
                'status' => ReviewStatus::PendingVerification,
                'moderation_status' => ReviewModerationStatus::Pending,
                'otp_challenge_id' => $otpRecord?->id,
                'phone_verified_at' => null,
                'ip_address_hash' => $ipHash,
            ]);

            return [
                'challenge' => $challenge,
                'review' => $review,
            ];
        });

        return [
            'review' => $guestResult['review'],
            'requires_otp' => true,
            'challenge_id' => $guestResult['challenge']['challenge_id'],
            'expires_in_seconds' => $guestResult['challenge']['expires_in_seconds'],
            'resend_after_seconds' => $guestResult['challenge']['resend_after_seconds'],
        ];
    }

    public function verifyGuestReviewOtp(Review $review, string $code, string $ipAddress): Review
    {
        if ($review->status !== ReviewStatus::PendingVerification) {
            throw new ReviewException(
                'REVIEW_ALREADY_VERIFIED',
                'This review has already been verified and processed.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $challenge = $review->otpChallenge ?? OtpChallenge::find($review->otp_challenge_id);
        if ($challenge === null) {
            throw new ReviewException(
                'INVALID_CHALLENGE',
                'No active verification challenge found for this review.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->otpService->verifyAndConsume(
            $challenge->challenge_id,
            $review->phone_e164,
            OtpPurpose::ReviewVerify,
            $code,
            $ipAddress
        );

        $review->status = ReviewStatus::Published;
        $review->moderation_status = ReviewModerationStatus::Approved;
        $review->phone_verified_at = now();
        $review->save();

        $this->recalculateBusinessRating($review->business);

        $business = $review->business;
        $citySlug = $business?->city?->slug ?? 'chennai';
        if ($business?->slug) {
            $paths = ["/{$citySlug}/business/{$business->slug}", "/{$citySlug}"];
            $categorySlug = $business->primaryCategory?->slug;
            if ($categorySlug) {
                $paths[] = "/{$citySlug}/{$categorySlug}";
            }
            DispatchRevalidationWebhookJob::dispatch($paths, 'guest_review_verified')->afterCommit();
        }

        return $review;
    }

    public function recalculateBusinessRating(Business $business): void
    {
        $approvedReviews = Review::query()
            ->where('business_id', $business->id)
            ->where('status', ReviewStatus::Published->value)
            ->where('moderation_status', ReviewModerationStatus::Approved->value);

        $count = $approvedReviews->count();
        $avg = $count > 0 ? (float) $approvedReviews->avg('rating') : 0.0;

        $priorMean = (float) config('search.ranking.bayesian.prior_mean', 3.5);
        $priorWeight = (float) config('search.ranking.bayesian.prior_weight', 10);

        // When count === 0, bayesian_score is aligned to the 3.5 baseline, while rating_avg is 0.00
        $bayesianScore = $count > 0
            ? (($count * $avg) + ($priorWeight * $priorMean)) / ($count + $priorWeight)
            : $priorMean;

        $business->forceFill([
            'rating_avg' => round($avg, 2),
            'review_count' => $count,
            'bayesian_score' => round($bayesianScore, 3),
        ])->save();
    }

    public function addOrUpdateOwnerReply(Review $review, Business $business, string $replyText, User $actor): Review
    {
        if ($review->business_id !== $business->id) {
            throw new ReviewException(
                'REVIEW_NOT_FOUND',
                'The specified review does not belong to this business.',
                Response::HTTP_NOT_FOUND
            );
        }

        $isInitialReply = $review->owner_replied_at === null && empty($review->owner_reply_text);

        $review->owner_reply_text = trim($replyText);
        $review->owner_replied_at = now();
        $review->owner_reply_user_id = $actor->id;
        $review->save();

        if ($isInitialReply && $review->user_id !== null) {
            $review->loadMissing('user');
            if ($review->user !== null) {
                $citySlug = $business->city?->slug ?? 'chennai';
                $review->user->notify(new ReviewReplyReceivedNotification(
                    reviewId: $review->id,
                    reviewUlid: $review->public_id,
                    businessName: $business->name,
                    businessSlug: $business->slug,
                    citySlug: $citySlug,
                    replyText: $review->owner_reply_text,
                    replyVersion: 'initial'
                ));
            }
        }

        $citySlug = $business->city?->slug ?? 'chennai';
        DispatchRevalidationWebhookJob::dispatch([
            "/{$citySlug}/business/{$business->slug}",
        ], 'owner_reply')->afterCommit();

        return $review;
    }

    public function deleteOwnerReply(Review $review, Business $business): Review
    {
        if ($review->business_id !== $business->id) {
            throw new ReviewException(
                'REVIEW_NOT_FOUND',
                'The specified review does not belong to this business.',
                Response::HTTP_NOT_FOUND
            );
        }

        $review->owner_reply_text = null;
        $review->owner_replied_at = null;
        $review->owner_reply_user_id = null;
        $review->save();

        $citySlug = $business->city?->slug ?? 'chennai';
        DispatchRevalidationWebhookJob::dispatch([
            "/{$citySlug}/business/{$business->slug}",
        ], 'owner_reply_deleted')->afterCommit();

        return $review;
    }

    public function reportReview(Review $review, array $data, ?User $actor, string $ipAddress): ReviewReport
    {
        $rateLimitKey = 'review_report:'.hash('sha256', $ipAddress).':'.$review->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            throw new ReviewException(
                'TOO_MANY_REPORTS',
                'You have submitted too many reports recently. Please try again later.',
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }
        RateLimiter::hit($rateLimitKey, 3600);

        $report = ReviewReport::query()->create([
            'review_id' => $review->id,
            'reported_by_user_id' => $actor?->id,
            'reporter_ip_hash' => hash('sha256', $ipAddress),
            'reason' => (string) $data['reason'],
            'details' => isset($data['details']) ? trim((string) $data['details']) : null,
            'status' => 'pending',
        ]);

        // Auto-flag review if multiple reports accumulate
        $pendingReportsCount = ReviewReport::query()
            ->where('review_id', $review->id)
            ->where('status', 'pending')
            ->count();

        if ($pendingReportsCount >= 3 && $review->moderation_status === ReviewModerationStatus::Approved) {
            $review->moderation_status = ReviewModerationStatus::Flagged;
            $review->status = ReviewStatus::Flagged;
            $review->save();
        }

        return $report;
    }

    public function moderateReview(
        Review $review,
        ReviewModerationStatus $newStatus,
        ?string $reason,
        User $adminUser
    ): Review {
        $oldModerationStatus = $review->moderation_status;
        $oldStatus = $review->status;

        $review->moderation_status = $newStatus;
        $review->moderated_at = now();
        $review->moderated_by_admin_id = $adminUser->id;
        $review->moderation_notes = $reason;

        match ($newStatus) {
            ReviewModerationStatus::Approved => $review->status = ReviewStatus::Published,
            ReviewModerationStatus::Hidden => $review->status = ReviewStatus::Hidden,
            ReviewModerationStatus::Rejected => $review->status = ReviewStatus::Rejected,
            ReviewModerationStatus::Flagged => $review->status = ReviewStatus::Flagged,
            ReviewModerationStatus::Pending => $review->status = ReviewStatus::PendingVerification,
        };

        $review->save();

        // Always recalculate business Bayesian rating when moderation status changes
        $this->recalculateBusinessRating($review->business);

        $this->auditLogger->record(
            actor: $adminUser,
            action: 'review.moderated',
            auditable: $review,
            oldValues: [
                'moderation_status' => $oldModerationStatus->value,
                'status' => $oldStatus->value,
            ],
            newValues: [
                'moderation_status' => $newStatus->value,
                'status' => $review->status->value,
                'notes' => $reason,
            ]
        );

        $business = $review->business;
        $citySlug = $business?->city?->slug ?? 'chennai';
        if ($business?->slug) {
            $paths = ["/{$citySlug}/business/{$business->slug}", "/{$citySlug}"];
            $categorySlug = $business->primaryCategory?->slug;
            if ($categorySlug) {
                $paths[] = "/{$citySlug}/{$categorySlug}";
            }
            DispatchRevalidationWebhookJob::dispatch($paths, 'review_moderation')->afterCommit();
        }

        return $review;
    }

    /**
     * @return array{is_verified: bool, trust_badge: ReviewTrustBadge, type: ?string, id: ?int}
     */
    private function resolveVerifiedInteraction(Business $business, ?User $actor, string $phoneE164): array
    {
        // 1. Confirmed Completed / Converted Interaction -> Verified Customer
        // The reviewer must have an EnquiryRecipient for this business where lead_status is Converted.
        $convertedRecipient = EnquiryRecipient::query()
            ->where('business_id', $business->id)
            ->where('lead_status', LeadStatus::Converted->value)
            ->whereHas('enquiry', function ($eq) use ($actor, $phoneE164): void {
                $eq->whereNotNull('phone_verified_at')
                    ->where(function ($q) use ($actor, $phoneE164): void {
                        if ($actor !== null) {
                            $q->where('user_id', $actor->id)->orWhere('phone_e164', $phoneE164);
                        } else {
                            $q->where('phone_e164', $phoneE164);
                        }
                    });
            })
            ->latest('id')
            ->first();

        if ($convertedRecipient !== null) {
            return [
                'is_verified' => true,
                'trust_badge' => ReviewTrustBadge::VerifiedCustomer,
                'type' => 'enquiry_converted',
                'id' => $convertedRecipient->enquiry_id,
            ];
        }

        // 2. Qualifying Verified Enquiry (Direct or Dispatched Lead, active, not cancelled/archived) -> Verified Lead
        $verifiedLeadRecipient = EnquiryRecipient::query()
            ->where('business_id', $business->id)
            ->whereNotIn('lead_status', [LeadStatus::Archived->value])
            ->whereHas('enquiry', function ($eq) use ($actor, $phoneE164): void {
                $eq->whereNotNull('phone_verified_at')
                    ->where('status', '!=', EnquiryStatus::Cancelled->value)
                    ->where(function ($q) use ($actor, $phoneE164): void {
                        if ($actor !== null) {
                            $q->where('user_id', $actor->id)->orWhere('phone_e164', $phoneE164);
                        } else {
                            $q->where('phone_e164', $phoneE164);
                        }
                    });
            })
            ->latest('id')
            ->first();

        if ($verifiedLeadRecipient !== null) {
            return [
                'is_verified' => false,
                'trust_badge' => ReviewTrustBadge::VerifiedLead,
                'type' => 'enquiry_lead',
                'id' => $verifiedLeadRecipient->enquiry_id,
            ];
        }

        // Direct enquiry without recipient record yet (e.g. pending dispatch worker)
        $directEnquiry = Enquiry::query()
            ->where('target_business_id', $business->id)
            ->whereNotNull('phone_verified_at')
            ->where('status', '!=', EnquiryStatus::Cancelled->value)
            ->where(function ($q) use ($actor, $phoneE164): void {
                if ($actor !== null) {
                    $q->where('user_id', $actor->id)->orWhere('phone_e164', $phoneE164);
                } else {
                    $q->where('phone_e164', $phoneE164);
                }
            })
            ->latest('id')
            ->first();

        if ($directEnquiry !== null) {
            return [
                'is_verified' => false,
                'trust_badge' => ReviewTrustBadge::VerifiedLead,
                'type' => 'enquiry_lead',
                'id' => $directEnquiry->id,
            ];
        }

        // 3. Contact reveal alone NEVER qualifies for a badge
        // Contact reveal records unmasked phone on profile, not a verified transaction or verified lead.
        return [
            'is_verified' => false,
            'trust_badge' => ReviewTrustBadge::None,
            'type' => null,
            'id' => null,
        ];
    }
}
